<?php
namespace Bayarku\Abstracts;

defined( 'ABSPATH' ) || exit;

/**
 * Abstract base for all Bayarku payment gateway methods.
 *
 * Extends WC_Payment_Gateway and wires up:
 * - Common admin settings (sandbox toggle, fee fields)
 * - Fee surcharge via woocommerce_cart_calculate_fees
 * - QR / VA page redirect after process_payment()
 * - Webhook verification stub
 *
 * Concrete gateways override:
 *   - init_form_fields()    → provider-specific settings
 *   - process_payment()     → call provider API, store result to order meta
 *   - validate_webhook()    → verify HMAC/signature from provider
 *   - handle_webhook()      → apply the notification to the order
 *   - payment_page_type()   → 'qr' | 'va' | 'ewallet' (template to render)
 *   - payment_page_data()   → data the template/JS need (qr_content, expires_at, ...)
 *   - ajax_poll()           → status check behind the generic `bayarku_poll` AJAX action
 *   - on_order_cancelled()  → optional clean-up at the provider (expire QR, close VA)
 *
 * The core (Loader, WebhookRouter, templates) only talks to these methods and
 * never reads provider-specific order meta directly.
 */
abstract class Gateway extends \WC_Payment_Gateway {

    /**
     * When true, this gateway absorbs the payment fee itself — no surcharge added to buyer.
     * QRIS gateways set this to true. VA/eWallet/CC gateways leave it false.
     */
    protected bool $absorb_fee = false;

    /**
     * Fee percentage charged to buyer (e.g. 0.7 = 0.7%).
     * Only used when $absorb_fee === false.
     */
    protected float $fee_percent = 0.0;

    /**
     * Flat fee (IDR) charged to buyer on top of percentage.
     * Only used when $absorb_fee === false.
     */
    protected int $fee_flat = 0;

    /** Provider slug — used to namespace order meta keys and transients */
    protected string $provider = '';

    public function __construct() {
        $this->has_fields = false;

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option( 'title' );
        $this->description = $this->get_option( 'description' );

        // Load fee settings from WP options (set by concrete class via init_form_fields)
        if ( ! $this->absorb_fee ) {
            $this->fee_percent = (float) $this->get_option( 'fee_percent', 0 );
            $this->fee_flat    = (int) $this->get_option( 'fee_flat', 0 );
        }

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );

        if ( ! $this->absorb_fee ) {
            add_action( 'woocommerce_cart_calculate_fees', [ $this, 'maybe_add_fee' ] );
        }
    }

    // -------------------------------------------------------------------------
    // Common admin form fields — concrete classes call parent then add their own
    // -------------------------------------------------------------------------

    public function init_form_fields(): void {
        $base = [
            'enabled' => [
                'title'   => 'Aktifkan/Nonaktifkan',
                'type'    => 'checkbox',
                'label'   => 'Aktifkan ' . $this->method_title,
                'default' => 'no',
            ],
            'title' => [
                'title'       => 'Judul',
                'type'        => 'text',
                'description' => 'Judul yang ditampilkan di halaman checkout.',
                'default'     => $this->method_title,
                'desc_tip'    => true,
            ],
            'description' => [
                'title'   => 'Deskripsi',
                'type'    => 'textarea',
                'default' => '',
            ],
            'sandbox' => [
                'title'   => 'Mode Sandbox',
                'type'    => 'checkbox',
                'label'   => 'Gunakan endpoint sandbox (untuk testing)',
                'default' => 'yes',
            ],
        ];

        // Fee fields only for gateways that charge buyers
        if ( ! $this->absorb_fee ) {
            $base['fee_percent'] = [
                'title'       => 'Fee (%)',
                'type'        => 'number',
                'description' => 'Persentase biaya yang dibebankan ke buyer. Contoh: 0.7 untuk 0.7%.',
                'default'     => '0',
                'custom_attributes' => [ 'step' => '0.01', 'min' => '0' ],
            ];
            $base['fee_flat'] = [
                'title'       => 'Fee Flat (IDR)',
                'type'        => 'number',
                'description' => 'Biaya flat tambahan dalam Rupiah.',
                'default'     => '0',
                'custom_attributes' => [ 'step' => '1', 'min' => '0' ],
            ];
        }

        $this->form_fields = $base;
    }

    // -------------------------------------------------------------------------
    // Fee surcharge — only registered when absorb_fee === false
    // -------------------------------------------------------------------------

    /**
     * Add fee to cart when this gateway is selected at checkout.
     */
    public function maybe_add_fee( \WC_Cart $cart ): void {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        if ( WC()->session && WC()->session->get( 'chosen_payment_method' ) !== $this->id ) {
            return;
        }

        if ( $this->fee_percent <= 0 && $this->fee_flat <= 0 ) {
            return;
        }

        $subtotal = $cart->get_subtotal() + $cart->get_subtotal_tax();
        $fee      = round( $subtotal * ( $this->fee_percent / 100 ) ) + $this->fee_flat;

        if ( $fee > 0 ) {
            $cart->add_fee(
                sprintf( 'Biaya %s', $this->title ),
                $fee,
                false // not taxable
            );
        }
    }

    // -------------------------------------------------------------------------
    // Helpers available to concrete gateways
    // -------------------------------------------------------------------------

    protected function is_sandbox(): bool {
        return $this->get_option( 'sandbox' ) === 'yes';
    }

    /**
     * Save key→value pairs to order meta using namespaced keys.
     *
     * @param \WC_Order $order
     * @param array     $data  e.g. [ 'qr_content' => '...', 'reference_no' => '...' ]
     */
    protected function save_order_meta( \WC_Order $order, array $data ): void {
        foreach ( $data as $key => $value ) {
            $order->update_meta_data( "_bayarku_{$this->provider}_{$key}", $value );
        }
        $order->save();
    }

    /**
     * Read a namespaced meta value from an order.
     */
    protected function get_order_meta( \WC_Order $order, string $key ): mixed {
        return $order->get_meta( "_bayarku_{$this->provider}_{$key}" );
    }

    /**
     * Redirect buyer to the custom QR/VA payment page after checkout.
     * The page slug must contain [bayarku_payment_page] shortcode or be handled by a rewrite rule.
     *
     * @param int    $order_id
     * @param string $type  'qr' | 'va'
     */
    protected function redirect_to_payment_page( int $order_id, string $type = 'qr' ): array {
        $url = add_query_arg( [
            'bayarku_order' => $order_id,
            'bayarku_type'  => $type,
            'bayarku_key'   => wc_get_order( $order_id )->get_order_key(),
        ], home_url( '/bayarku-payment/' ) );

        return [
            'result'   => 'success',
            'redirect' => $url,
        ];
    }

    // -------------------------------------------------------------------------
    // Abstract methods — each gateway must implement
    // -------------------------------------------------------------------------

    /**
     * Handle payment processing: call provider API, save meta, return redirect array.
     * Concrete gateways must override this method.
     *
     * @param int $order_id
     * @return array{result: string, redirect: string}
     */
    public function process_payment( $order_id ): array {
        throw new \LogicException( 'process_payment() not implemented in ' . static::class );
    }

    /**
     * Validate an incoming webhook notification from the provider.
     * Should verify signature/HMAC and return true/false.
     *
     * @param \WP_REST_Request $request
     * @return bool
     */
    abstract public function validate_webhook( \WP_REST_Request $request ): bool;

    // -------------------------------------------------------------------------
    // Payment page + polling contract — used generically by Loader and templates
    // -------------------------------------------------------------------------

    /**
     * Which payment page template renders this gateway: 'qr' | 'va' | 'ewallet'.
     * Must match templates/{type}-page.php.
     */
    public function payment_page_type(): string {
        return 'qr';
    }

    /**
     * Data the payment page template and its JS need for this order.
     *
     * Expected keys (depending on payment_page_type()):
     *   qr:      qr_content (string), qr_image (base64 PNG, optional), expires_at (unix ts)
     *   va:      va_number, bank_code, bank_name, expires_at
     *   ewallet: redirect_url / deeplink, expires_at
     *
     * Templates must read values from this array only — never from `_bayarku_*` meta.
     *
     * @return array<string, mixed>
     */
    public function payment_page_data( \WC_Order $order ): array {
        return [];
    }

    /**
     * Check the payment status for the generic `bayarku_poll` AJAX action.
     *
     * The Loader has already verified the nonce and resolved the order and
     * gateway; this method only decides what the page should do next.
     *
     * Return one of:
     *   [ 'status' => 'paid', 'redirect' => $url ]
     *   [ 'status' => 'pending' ]
     *   [ 'status' => 'expired' ]
     *   [ 'error'  => 'message', 'code' => 500 ]   → sent as wp_send_json_error()
     *
     * @return array<string, mixed>
     */
    public function ajax_poll( \WC_Order $order ): array {
        if ( $order->is_paid() ) {
            return [
                'status'   => 'paid',
                'redirect' => $order->get_checkout_order_received_url(),
            ];
        }
        return [ 'status' => 'pending' ];
    }

    /**
     * Called by the Loader when an order paid with this gateway is cancelled.
     * Override to expire the QR / close the VA at the provider. No-op by default.
     */
    public function on_order_cancelled( \WC_Order $order ): void {}
}
