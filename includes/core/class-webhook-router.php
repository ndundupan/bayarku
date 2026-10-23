<?php
namespace Bayarku\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Routes incoming webhook notifications to the correct provider gateway.
 *
 * Endpoint: POST /wp-json/bayarku/v1/notify/{provider}
 * Example:  POST /wp-json/bayarku/v1/notify/doku
 *
 * One provider may expose several gateways (QRIS, VA, eWallet) that all share
 * the same notify URL. The router therefore resolves the order first and picks
 * the gateway the buyer actually paid with ($order->get_payment_method()). When
 * the order cannot be found, it falls back to the first gateway whose id starts
 * with `bayarku_{provider}_` so that gateway can answer with the provider's own
 * "order not found" response.
 */
class WebhookRouter {

    private static ?self $instance = null;

    public static function instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function register(): void {
        register_rest_route( 'bayarku/v1', '/notify/(?P<provider>[a-z0-9_-]+)', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle' ],
            'permission_callback' => '__return_true', // Auth done inside handle()
        ] );
    }

    public function handle( \WP_REST_Request $request ): \WP_REST_Response {
        $provider = sanitize_key( $request->get_param( 'provider' ) );
        $gateway  = $this->resolve_gateway( $provider, $request );

        if ( ! $gateway ) {
            wc_get_logger()->warning( "Bayarku webhook: unknown provider '$provider'", [ 'source' => 'bayarku' ] );
            return new \WP_REST_Response( [ 'status' => 'unknown_provider' ], 404 );
        }

        // Gateway must implement validate_webhook()
        if ( ! method_exists( $gateway, 'validate_webhook' ) || ! $gateway->validate_webhook( $request ) ) {
            wc_get_logger()->warning( "Bayarku webhook: signature invalid for '$provider'", [ 'source' => 'bayarku' ] );
            return new \WP_REST_Response( [ 'status' => 'invalid_signature' ], 401 );
        }

        // Delegate to gateway's handle_webhook()
        if ( method_exists( $gateway, 'handle_webhook' ) ) {
            return $gateway->handle_webhook( $request );
        }

        return new \WP_REST_Response( [ 'status' => 'ok' ], 200 );
    }

    /**
     * Pick the gateway that should handle this notification.
     *
     * 1. Order found → the gateway recorded as the order's payment method,
     *    provided its id starts with `bayarku_{provider}_`.
     * 2. Otherwise → first registered gateway with that prefix (legacy behaviour).
     */
    private function resolve_gateway( string $provider, \WP_REST_Request $request ): ?\WC_Payment_Gateway {
        $prefix   = 'bayarku_' . $provider . '_';
        $gateways = WC()->payment_gateways()->payment_gateways();

        $order = $this->resolve_order( $provider, $request );
        if ( $order ) {
            $method = $order->get_payment_method();
            if ( strpos( $method, $prefix ) === 0 && isset( $gateways[ $method ] ) ) {
                return $gateways[ $method ];
            }
        }

        foreach ( $gateways as $id => $gw ) {
            if ( strpos( $id, $prefix ) === 0 ) {
                return $gw;
            }
        }

        return null;
    }

    /**
     * Find the order a notification refers to.
     *
     * Bayarku sends `{order_id}-{timestamp}` as partnerReferenceNo, so the
     * leading digits are the order id. As a fallback, look the full value up in
     * the `_bayarku_{provider}_partner_reference_no` order meta.
     */
    private function resolve_order( string $provider, \WP_REST_Request $request ): ?\WC_Order {
        $body = $request->get_json_params();
        if ( ! is_array( $body ) ) {
            return null;
        }

        $partner_ref = (string) ( $body['originalPartnerReferenceNo'] ?? $body['partnerReferenceNo'] ?? '' );
        if ( $partner_ref === '' ) {
            return null;
        }

        $order_id = (int) $partner_ref;
        if ( $order_id > 0 ) {
            $order = wc_get_order( $order_id );
            if ( $order instanceof \WC_Order ) {
                return $order;
            }
        }

        $ids = wc_get_orders( [
            'limit'      => 1,
            'return'     => 'ids',
            'meta_key'   => "_bayarku_{$provider}_partner_reference_no", // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_value' => $partner_ref, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        ] );
        if ( ! empty( $ids ) ) {
            $order = wc_get_order( (int) $ids[0] );
            if ( $order instanceof \WC_Order ) {
                return $order;
            }
        }

        return null;
    }
}
