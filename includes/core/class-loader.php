<?php
namespace Bayarku\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton loader — registers all hooks and bootstraps gateways.
 *
 * To add a new gateway, add its class to the GATEWAYS constant array.
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

    private static ?self $instance = null;

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

        // Register QR payment page rewrite
        add_action( 'init', [ $this, 'add_rewrite_rules' ] );
        add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
        add_action( 'template_redirect', [ $this, 'handle_payment_page' ] );

        // Register AJAX handlers explicitly (gateways may not be instantiated during AJAX)
        add_action( 'wp_ajax_bayarku_poll_qris',        [ $this, 'dispatch_poll_qris' ] );
        add_action( 'wp_ajax_nopriv_bayarku_poll_qris', [ $this, 'dispatch_poll_qris' ] );

        // Enqueue frontend assets
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function dispatch_poll_qris(): void {
        $gateway = new \Bayarku\Providers\Doku\Gateways\DokuQris();
        $gateway->ajax_poll_qris();
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
        $allowed_types = [ 'qr', 'va', 'ewallet' ];
        if ( ! in_array( $type, $allowed_types, true ) ) {
            wp_die( 'Tipe pembayaran tidak valid.', 'Bayarku', [ 'response' => 400 ] );
        }

        $order = wc_get_order( $order_id );

        if ( ! $order || $order->get_order_key() !== $key ) {
            wp_die( 'Pesanan tidak valid.', 'Bayarku', [ 'response' => 403 ] );
        }

        $template = BAYARKU_DIR . "templates/{$type}-page.php";
        if ( file_exists( $template ) ) {
            $GLOBALS['_bayarku_tpl_order'] = $order;
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

            wp_localize_script( 'bayarku-qr-polling', 'BayarkuQR', [
                'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
                'orderId'     => $order_id,
                'orderKey'    => $order ? $order->get_order_key() : '',
                'nonce'       => wp_create_nonce( 'bayarku_poll_' . $order_id ),
                'thankYouUrl' => $order ? $order->get_checkout_order_received_url() : home_url(),
                'pollInterval'=> 4000, // ms
                'expireMsg'   => 'QR Code kadaluarsa. Silakan buat pesanan baru.',
                'qrContent'   => $order ? (string) $order->get_meta( '_bayarku_doku_qr_content' ) : '',
            ] );
        }
    }
}
