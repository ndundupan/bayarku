<?php
namespace Bayarku\Abstracts;

defined( 'ABSPATH' ) || exit;

/**
 * Abstract base for all payment provider API clients.
 *
 * Each concrete provider (DOKU, Midtrans, Xendit, etc.) extends this class
 * and implements the abstract methods. Core HTTP logic lives here so providers
 * only need to handle auth headers and payload building.
 */
abstract class Provider {

    /** @var bool Use sandbox endpoint when true */
    protected bool $sandbox = true;

    /** @var string Base URL for production */
    protected string $base_url_production = '';

    /** @var string Base URL for sandbox */
    protected string $base_url_sandbox = '';

    public function __construct( bool $sandbox = true ) {
        $this->sandbox = $sandbox;
    }

    /** Return the active base URL based on sandbox flag */
    protected function base_url(): string {
        return $this->sandbox ? $this->base_url_sandbox : $this->base_url_production;
    }

    /**
     * Make an HTTP POST request to the provider API.
     *
     * @param string $endpoint  Path after base_url, e.g. '/snap-adapter/b2b/v1.0/qr/qr-mpm-generate'
     * @param array  $body      Associative array, will be JSON-encoded
     * @param array  $headers   Additional HTTP headers (merged with defaults from build_headers())
     * @return array{code: int, body: array} Parsed response
     * @throws \RuntimeException on HTTP or API error
     */
    protected function post( string $endpoint, array $body, array $headers = [] ): array {
        $url     = rtrim( $this->base_url(), '/' ) . $endpoint;
        $payload = wp_json_encode( $body );

        $response = wp_remote_post( $url, [
            'timeout' => 30,
            'headers' => array_merge( $this->default_headers( $endpoint, $payload ), $headers ),
            'body'    => $payload,
        ] );

        if ( is_wp_error( $response ) ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new \RuntimeException( 'HTTP error: ' . $response->get_error_message() );
        }

        $code        = (int) wp_remote_retrieve_response_code( $response );
        $raw         = wp_remote_retrieve_body( $response );
        $parsed      = json_decode( $raw, true ) ?? [];

        if ( $code >= 500 ) {
            // On server errors, log responseMessage to aid debugging
            $this->log( sprintf( '[%s] POST %s → %d ERROR: %s', static::class, $endpoint, $code, $parsed['responseMessage'] ?? 'unknown' ) );
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new \RuntimeException( "Provider returned HTTP $code for $endpoint" );
        }

        // Normal responses: log response code only — never full body (may contain transaction data)
        $this->log( sprintf( '[%s] POST %s → %d | %s', static::class, $endpoint, $code, $parsed['responseCode'] ?? '-' ) );

        return [ 'code' => $code, 'body' => $parsed ];
    }

    /**
     * Build default headers for every request.
     * Providers override this to inject auth/signature headers.
     *
     * @param string $endpoint  The endpoint path (for signature generation)
     * @param string $payload   The raw JSON payload (for signature generation)
     */
    protected function default_headers( string $endpoint, string $payload ): array {
        return [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ];
    }

    /**
     * Log a message using WooCommerce logger.
     *
     * @param string $message
     * @param mixed  $context  Will be JSON-encoded if array
     */
    protected function log( string $message, mixed $context = null ): void {
        $logger = wc_get_logger();
        $ctx    = [ 'source' => 'bayarku' ];

        if ( $context !== null ) {
            $message .= ' | ' . ( is_array( $context ) ? wp_json_encode( $context ) : $context );
        }

        $logger->debug( $message, $ctx );
    }

    // -------------------------------------------------------------------------
    // Abstract methods — each provider must implement these
    // -------------------------------------------------------------------------

    /**
     * Request or refresh an access token from the provider.
     * Result should be cached (e.g. in transients) by the implementation.
     *
     * @return string Access token
     */
    abstract public function get_access_token(): string;
}
