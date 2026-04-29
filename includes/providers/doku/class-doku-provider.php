<?php
namespace Bayarku\Providers\Doku;

defined( 'ABSPATH' ) || exit;

use Bayarku\Abstracts\Provider;

/**
 * DOKU SNAP API client.
 *
 * Handles:
 * - B2B access token (OAuth2 client_credentials via asymmetric signature)
 * - Request signature (HMAC_SHA512) per DOKU SNAP spec
 * - Token caching via WP transients
 *
 * Credentials are read from WP options set in the gateway admin UI:
 *   bayarku_doku_client_id
 *   bayarku_doku_client_secret
 *   bayarku_doku_merchant_id
 *   bayarku_doku_terminal_id
 *   bayarku_doku_sandbox  (yes|no)
 */
class DokuProvider extends Provider {

    protected string $base_url_production = 'https://api.doku.com';
    protected string $base_url_sandbox    = 'https://api-sandbox.doku.com';

    private string $client_id;
    private string $client_secret;
    private string $private_key;
    private string $merchant_id;
    private string $terminal_id;

    public function __construct( bool $sandbox = true ) {
        parent::__construct( $sandbox );

        $this->client_id     = get_option( 'bayarku_doku_client_id', '' );
        $this->client_secret = trim( get_option( 'bayarku_doku_client_secret', '' ) );
        $this->private_key   = get_option( 'bayarku_doku_private_key', '' );
        $this->merchant_id   = get_option( 'bayarku_doku_merchant_id', '' );
        $this->terminal_id   = get_option( 'bayarku_doku_terminal_id', '' );
    }

    // -------------------------------------------------------------------------
    // Access Token
    // -------------------------------------------------------------------------

    /**
     * Fetch or return cached B2B access token.
     * DOKU B2B token uses a different (asymmetric) signature — see DOKU docs.
     * Token is cached for 14 minutes (expires in 15).
     */
    public function get_access_token(): string {
        $cache_key = 'bayarku_doku_token_' . ( $this->sandbox ? 'sandbox' : 'prod' );
        $cached    = get_transient( $cache_key );

        if ( $cached ) {
            return $cached;
        }

        $timestamp      = $this->timestamp();
        $string_to_sign = $this->client_id . '|' . $timestamp;
        $signature      = $this->rsa_sha256( $string_to_sign );

        $response = wp_remote_post( $this->base_url() . '/authorization/v1/access-token/b2b', [
            'timeout' => 15,
            'headers' => [
                'Content-Type'  => 'application/json',
                'X-CLIENT-KEY'  => $this->client_id,
                'X-TIMESTAMP'   => $timestamp,
                'X-SIGNATURE'   => $signature,
            ],
            'body' => wp_json_encode( [ 'grantType' => 'client_credentials' ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new \RuntimeException( 'DOKU token request failed: ' . $response->get_error_message() );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['accessToken'] ) ) {
            $this->log( 'DOKU token error', $body );
            throw new \RuntimeException( 'DOKU: gagal mendapatkan access token.' );
        }

        // Cache for 14 minutes
        set_transient( $cache_key, $body['accessToken'], 14 * MINUTE_IN_SECONDS );

        return $body['accessToken'];
    }

    // -------------------------------------------------------------------------
    // QRIS — Generate QR
    // -------------------------------------------------------------------------

    /**
     * Generate QRIS QR code for an order.
     *
     * @param int    $order_id          WC order ID (used as partnerReferenceNo)
     * @param int    $amount_idr        Order total in IDR (integer)
     * @param string $validity_period   ISO 8601 duration, e.g. 'PT30M' for 30 minutes
     * @return array{qrContent: string, referenceNo: string}
     * @throws \RuntimeException
     */
    public function qris_generate( int $order_id, int $amount_idr, string $validity_period = 'PT30M', string $postal_code = '' ): array {
        $body = [
            'partnerReferenceNo' => $order_id . '-' . time(),
            'amount'             => [
                'value'    => number_format( $amount_idr, 2, '.', '' ),
                'currency' => 'IDR',
            ],
            'merchantId'     => $this->merchant_id,
            'terminalId'     => $this->terminal_id,
            'additionalInfo' => [
                'postalCode' => $postal_code,
                'feeType'    => 1,
            ],
        ];

        $endpoint = '/snap-adapter/b2b/v1.0/qr/qr-mpm-generate';
        $response = $this->post( $endpoint, $body );

        if ( ( $response['body']['responseCode'] ?? '' ) !== '2004700' ) {
            $this->log( 'QRIS generate failed', $response['body'] );
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new \RuntimeException(
                'DOKU QRIS generate error: ' . ( $response['body']['responseMessage'] ?? 'unknown' )
            );
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        return [
            'qrContent'          => $response['body']['qrContent'],
            'referenceNo'        => $response['body']['referenceNo'],
            'partnerReferenceNo' => $body['partnerReferenceNo'],
        ];
    }

    // -------------------------------------------------------------------------
    // QRIS — Query Status
    // -------------------------------------------------------------------------

    /**
     * Query payment status for a QRIS transaction.
     *
     * @param string $reference_no          DOKU referenceNo from generate response
     * @param string $partner_reference_no  Original order ID
     * @return array Full response body
     */
    public function qris_query( string $reference_no, string $partner_reference_no ): array {
        $body = [
            'originalReferenceNo'        => $reference_no,
            'originalPartnerReferenceNo' => $partner_reference_no,
            'serviceCode'                => '47',
            'merchantId'                 => $this->merchant_id,
        ];

        $endpoint = '/snap-adapter/b2b/v1.0/qr/qr-mpm-query';
        $response = $this->post( $endpoint, $body );

        return $response['body'];
    }

    // -------------------------------------------------------------------------
    // QRIS — Expire/Cancel
    // -------------------------------------------------------------------------

    /**
     * Cancel / expire a QRIS transaction.
     *
     * @param string $reference_no
     * @param string $partner_reference_no
     */
    public function qris_expire( string $reference_no, string $partner_reference_no ): void {
        $body = [
            'originalReferenceNo'        => $reference_no,
            'originalPartnerReferenceNo' => $partner_reference_no,
            'merchantId'                 => $this->merchant_id,
        ];

        $this->post( '/snap-adapter/b2b/v1.0/qr/qr-expire', $body );
    }

    // -------------------------------------------------------------------------
    // Internal — Signature & Headers
    // -------------------------------------------------------------------------

    /**
     * Build signed headers for every SNAP API request.
     */
    protected function default_headers( string $endpoint, string $payload ): array {
        $token     = $this->get_access_token();
        $timestamp = $this->timestamp();
        $external_id = $this->external_id();

        return [
            'Content-Type'   => 'application/json',
            'Accept'         => 'application/json',
            'Authorization'  => 'Bearer ' . $token,
            'X-PARTNER-ID'   => $this->client_id,
            'X-EXTERNAL-ID'  => $external_id,
            'X-TIMESTAMP'    => $timestamp,
            'X-SIGNATURE'    => $this->request_signature( 'POST', $endpoint, $token, $payload, $timestamp ),
            'CHANNEL-ID'     => 'H2H',
        ];
    }

    /**
     * Build SNAP request signature.
     *
     * stringToSign = HTTPMethod + ":" + EndpointUrl + ":" + AccessToken + ":"
     *              + lowercase(hex(sha256(minify(body)))) + ":" + Timestamp
     * X-SIGNATURE  = HMAC_SHA512(clientSecret, stringToSign)
     */
    private function request_signature(
        string $method,
        string $endpoint,
        string $token,
        string $payload,
        string $timestamp
    ): string {
        $body_hash      = strtolower( hash( 'sha256', $payload ) );
        $string_to_sign = strtoupper( $method ) . ':' . $endpoint . ':' . $token . ':' . $body_hash . ':' . $timestamp;

        return $this->hmac_sha512( $string_to_sign );
    }

    /**
     * HMAC-SHA512 with client secret as key, base64-encoded.
     * Used for signing regular SNAP API requests.
     */
    private function hmac_sha512( string $data ): string {
        return base64_encode( hash_hmac( 'sha512', $data, $this->client_secret, true ) );
    }

    /**
     * RSA-SHA256 signature, base64-encoded.
     * Used for signing B2B access token requests.
     * Private key must be PEM format (PKCS#1 or PKCS#8).
     *
     * @throws \RuntimeException if private key is missing or invalid
     */
    private function rsa_sha256( string $data ): string {
        if ( empty( $this->private_key ) ) {
            throw new \RuntimeException( 'DOKU: Private Key belum diisi di pengaturan payment gateway.' );
        }

        $pkey = openssl_pkey_get_private( $this->private_key );
        if ( $pkey === false ) {
            throw new \RuntimeException( 'DOKU: Private Key tidak valid. Pastikan format PEM sudah benar.' );
        }

        $signature = '';
        if ( ! openssl_sign( $data, $signature, $pkey, OPENSSL_ALGO_SHA256 ) ) {
            throw new \RuntimeException( 'DOKU: Gagal membuat RSA signature.' );
        }

        return base64_encode( $signature );
    }

    /**
     * ISO 8601 timestamp in WIB/UTC+7 format required by DOKU.
     * Format: YYYY-MM-DDTHH:mm:ss+07:00
     */
    private function timestamp(): string {
        return ( new \DateTime( 'now', new \DateTimeZone( 'Asia/Jakarta' ) ) )
            ->format( 'Y-m-d\TH:i:sP' );
    }

    /**
     * Unique external ID per request per day.
     * DOKU requires alphanumeric only (A-Z, a-z, 0-9), max 36 chars.
     */
    private function external_id(): string {
        return gmdate( 'Ymd' ) . bin2hex( random_bytes( 8 ) );
    }

    // -------------------------------------------------------------------------
    // Getters for gateway use
    // -------------------------------------------------------------------------

    public function get_merchant_id(): string {
        return $this->merchant_id;
    }

    public function get_client_id(): string {
        return $this->client_id;
    }

    /**
     * Validate webhook notification from DOKU.
     *
     * DOKU SNAP notify signature:
     *   stringToSign = "NOTIFY" + ":" + relativeNotifyPath + ":" + accessToken + ":" + sha256(body) + ":" + timestamp
     *   X-SIGNATURE  = HMAC-SHA512(clientSecret, stringToSign)
     *
     * relativeNotifyPath = path component of our REST notify URL (e.g. /wp-json/bayarku/v1/notify/doku)
     * accessToken        = Bearer token from DOKU's Authorization header (empty string if absent)
     *
     * @param string $payload      Raw request body
     * @param string $timestamp    X-TIMESTAMP header value
     * @param string $external_id  X-EXTERNAL-ID header value
     * @param string $signature    X-SIGNATURE header value to verify
     * @param string $access_token Bearer token from Authorization header sent by DOKU
     */
    public function verify_webhook_signature(
        string $payload,
        string $timestamp,
        string $external_id,
        string $signature,
        string $access_token = ''
    ): bool {
        $notify_path    = wp_parse_url( get_rest_url( null, 'bayarku/v1/notify/doku' ), PHP_URL_PATH );
        $body_hash      = strtolower( hash( 'sha256', $payload ) );
        $string_to_sign = 'NOTIFY:' . $notify_path . ':' . $access_token . ':' . $body_hash . ':' . $timestamp;
        $expected       = $this->hmac_sha512( $string_to_sign );

        $result = hash_equals( $expected, $signature );

        // Debug log — hapus setelah webhook terkonfirmasi berjalan
        $this->log( 'DOKU webhook verify', [
            'notify_path'    => $notify_path,
            'has_auth_token' => ! empty( $access_token ),
            'body_hash'      => $body_hash,
            'timestamp'      => $timestamp,
            'signature_ok'   => $result,
        ] );

        return $result;
    }
}
