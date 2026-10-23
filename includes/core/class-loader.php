<?php
namespace Bayarku\Core;

defined( 'ABSPATH' ) || exit;

use Bayarku\Abstracts\Gateway;

/**
 * Singleton loader — registers all hooks and bootstraps gateways.
 *
 * To add a new gateway, add its class to the GATEWAYS constant array.
 * The loader never references a concrete provider: every order-specific
 * behaviour (payment page data, AJAX polling, cancellation) is resolved via
 * the gateway stored as the order's payment method.
 */
class Loader {

    /** Fully-qualified class names of all registered gateways */
    private const GATEWAYS = [
        \Bayarku\Providers\Doku\Gateways\DokuQris::class,
        // \Bayarku\Providers\Doku\Gateways\DokuVa::class,
        // \Bayarku\Providers\Doku\Gateways\DokuEwallet::class,
        // \Bayarku\Providers\Midtrans\Gateways\MidtransQris::class,
        // \Bayarku\Providers\Xendit\Gateways\XenditQris::class,
        // \Bayarku\Providers\Tripay\Gateways\TripayQris::class,
        // \Bayarku\Providers\Duitku\Gateways\DuitkuQris::class,
    ];

    /** Payment page types that have a template in templates/{type}-page.php */
    private const PAGE_TYPES = [ 'qr', 'va', 'ewallet' ];

    private static ?self $instance = null;

    /** Payment page data for the order being rendered (shared between template and assets) */
    private array $page_data = [];

    public static function instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
            self::$instance->boot();
        }
        return self::$instance;
    }

    private function boot(): void {
        // Register gateways with WooCommerce
        add_filter( 'woocommerce_payment_gateways', [ $this, 'register_gateways' ] );

        // Register REST API webhook routes
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );

        // Register payment page rewrite
        add_action( 'init', [ $this, 'add_rewrite_rules' ] );
        add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
        add_action( 'template_redirect', [ $this, 'handle_payment_page' ] );

        // Generic AJAX polling — gateway is resolved from the order's payment method.
        // `bayarku_poll_qris` is kept so cached copies of the old JS keep working.
        foreach ( [ 'bayarku_poll', 'bayarku_poll_qris' ] as $action ) {
            add_action( 'wp_ajax_' . $action,        [ $this, 'dispatch_poll' ] );
            add_action( 'wp_ajax_nopriv_' . $action, [ $this, 'dispatch_poll' ] );
        }

        // Let the gateway react when an order is cancelled (expire QR, close VA, ...)
        add_action( 'woocommerce_order_status_cancelled', [ $this, 'dispatch_order_cancelled' ] );

        // Enqueue frontend assets
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // WooCommerce Cart/Checkout Blocks support
        add_action( 'woocommerce_blocks_loaded', [ $this, 'register_blocks_support' ] );
    }

    /**
     * Daftarkan setiap gateway Bayarku ke registry metode pembayaran WooCommerce Blocks.
     * Aman dijalankan pada WooCommerce tanpa Blocks: kelas abstraknya dicek dulu.
     */
    public function register_blocks_support(): void {
        if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
            return;
        }

        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function ( \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry ): void {
                if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
                    return;
                }

                // Pakai instance yang sudah dibuat WooCommerce agar hook gateway tidak terdaftar dua kali.
                foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
                    if ( in_array( get_class( $gateway ), self::GATEWAYS, true ) ) {
                        $registry->register( new BlocksSupport( $gateway ) );
                    }
                }
            }
        );
    }

    public function register_gateways( array $gateways ): array {
        foreach ( self::GATEWAYS as $class ) {
            $gateways[] = $class;
        }
        return $gateways;
    }

    public function register_routes(): void {
        WebhookRouter::instance()->register();
    }

    // -------------------------------------------------------------------------
    // Gateway resolution
    // -------------------------------------------------------------------------

    /**
     * Return the Bayarku gateway the order was paid with, or null.
     */
    public static function gateway_for_order( \WC_Order $order ): ?Gateway {
        $method = $order->get_payment_method();
        if ( ! $method || strpos( $method, 'bayarku_' ) !== 0 ) {
            return null;
        }

        $gateways = WC()->payment_gateways()->payment_gateways();
        $gateway  = $gateways[ $method ] ?? null;

        return $gateway instanceof Gateway ? $gateway : null;
    }

    // -------------------------------------------------------------------------
    // AJAX polling
    // -------------------------------------------------------------------------

    /**
     * POST order_id + nonce → { status: paid|pending|expired, redirect? }.
     */
    public function dispatch_poll(): void {
        $order_id = absint( wp_unslash( $_POST['order_id'] ?? 0 ) );
        $nonce    = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );

        if ( ! wp_verify_nonce( $nonce, 'bayarku_poll_' . $order_id ) ) {
            wp_send_json_error( [ 'message' => 'Sesi tidak valid.' ], 403 );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => 'Pesanan tidak ditemukan.' ], 404 );
        }

        $gateway = self::gateway_for_order( $order );
        if ( ! $gateway ) {
            wp_send_json_error( [ 'message' => 'Metode pembayaran tidak dikenali.' ], 404 );
        }

        $result = $gateway->ajax_poll( $order );

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( [ 'message' => (string) $result['error'] ], (int) ( $result['code'] ?? 500 ) );
        }

        wp_send_json_success( $result );
    }

    // -------------------------------------------------------------------------
    // Order cancelled
    // -------------------------------------------------------------------------

    public function dispatch_order_cancelled( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $gateway = self::gateway_for_order( $order );
        if ( $gateway && method_exists( $gateway, 'on_order_cancelled' ) ) {
            $gateway->on_order_cancelled( $order );
        }
    }

    // -------------------------------------------------------------------------
    // Payment page
    // -------------------------------------------------------------------------

    public function add_rewrite_rules(): void {
        add_rewrite_rule(
            '^bayarku-payment/?$',
            'index.php?bayarku_payment=1',
            'top'
        );
    }

    public function add_query_vars( array $vars ): array {
        $vars[] = 'bayarku_payment';
        $vars[] = 'bayarku_order';
        $vars[] = 'bayarku_type';
        $vars[] = 'bayarku_key';
        return $vars;
    }

    public function handle_payment_page(): void {
        if ( ! get_query_var( 'bayarku_payment' ) ) {
            return;
        }

        $order_id = (int) get_query_var( 'bayarku_order' );
        $type     = sanitize_key( get_query_var( 'bayarku_type', 'qr' ) );
        $key      = sanitize_text_field( get_query_var( 'bayarku_key' ) );

        // Explicit whitelist — prevents any path traversal attempt
        if ( ! in_array( $type, self::PAGE_TYPES, true ) ) {
            wp_die( 'Tipe pembayaran tidak valid.', 'Bayarku', [ 'response' => 400 ] );
        }

        $order = wc_get_order( $order_id );

        if ( ! $order || $order->get_order_key() !== $key ) {
            wp_die( 'Pesanan tidak valid.', 'Bayarku', [ 'response' => 403 ] );
        }

        $template = BAYARKU_DIR . "templates/{$type}-page.php";
        if ( file_exists( $template ) ) {
            $gateway = self::gateway_for_order( $order );

            $this->page_data = $gateway ? $gateway->payment_page_data( $order ) : [];

            $GLOBALS['bayarku_tpl_order']   = $order;
            $GLOBALS['bayarku_tpl_gateway'] = $gateway;
            $GLOBALS['bayarku_tpl_data']    = $this->page_data;
            load_template( $template, false );
            exit;
        }

        wp_die( 'Template tidak ditemukan.', 'Bayarku', [ 'response' => 404 ] );
    }

    public function enqueue_assets(): void {
        if ( ! get_query_var( 'bayarku_payment' ) ) {
            return;
        }

        $type = sanitize_key( get_query_var( 'bayarku_type', 'qr' ) );

        wp_enqueue_style(
            'bayarku-checkout',
            BAYARKU_URL . 'assets/css/checkout.css',
            [],
            BAYARKU_VERSION
        );

        if ( $type === 'qr' ) {
            wp_enqueue_script(
                'bayarku-qrcodejs',
                BAYARKU_URL . 'assets/js/qrcode.min.js',
                [],
                '1.4.4',
                true
            );

            wp_enqueue_script(
                'bayarku-qr-polling',
                BAYARKU_URL . 'assets/js/qr-polling.js',
                [ 'jquery', 'bayarku-qrcodejs' ],
                BAYARKU_VERSION,
                true
            );

            $order_id = (int) get_query_var( 'bayarku_order' );
            $order    = wc_get_order( $order_id );
            $data     = $this->page_data;

            if ( ! $data && $order ) {
                $gateway = self::gateway_for_order( $order );
                $data    = $gateway ? $gateway->payment_page_data( $order ) : [];
            }

            wp_localize_script( 'bayarku-qr-polling', 'BayarkuQR', [
                'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
                'action'      => 'bayarku_poll',
                'orderId'     => $order_id,
                'orderKey'    => $order ? $order->get_order_key() : '',
                'nonce'       => wp_create_nonce( 'bayarku_poll_' . $order_id ),
                'thankYouUrl' => $order ? $order->get_checkout_order_received_url() : home_url(),
                'pollInterval'=> 4000, // ms
                'expireMsg'   => 'QR Code kadaluarsa. Silakan buat pesanan baru.',
                'qrContent'   => (string) ( $data['qr_content'] ?? '' ),
            ] );
        }
    }
}
