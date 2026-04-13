<?php
namespace Bayarku\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Routes incoming webhook notifications to the correct provider gateway.
 *
 * Endpoint: POST /wp-json/bayarku/v1/notify/{provider}
 * Example:  POST /wp-json/bayarku/v1/notify/doku
 *
 * The provider slug must match the WC gateway ID prefix.
 * Gateway is looked up from active WC payment gateways.
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
        $provider = $request->get_param( 'provider' );

        // Find gateway that starts with 'bayarku_{provider}'
        $gateways = WC()->payment_gateways()->payment_gateways();
        $gateway  = null;

        foreach ( $gateways as $id => $gw ) {
            if ( strpos( $id, 'bayarku_' . $provider ) === 0 ) {
                $gateway = $gw;
                break;
            }
        }

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
}
