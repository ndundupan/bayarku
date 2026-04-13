<?php
namespace Bayarku\Providers\Doku\Gateways;

defined( 'ABSPATH' ) || exit;

use Bayarku\Abstracts\Gateway;
use Bayarku\Providers\Doku\DokuProvider;

/**
 * DOKU QRIS Payment Gateway.
 *
 * Fee policy: $absorb_fee = true → merchant covers fee, no surcharge to buyer.
 *
 * Flow:
 *   1. process_payment() → call DokuProvider::qris_generate() → save QR data to order meta
 *   2. Redirect buyer to /bayarku-payment/?bayarku_type=qr
 *   3. qr-page.php renders QR + countdown; JS polls /wp-admin/admin-ajax.php?action=bayarku_poll_qris
 *   4. AJAX handler queries DOKU → if paid, mark order complete → return thank-you URL
 *   5. Webhook (backup): POST /wp-json/bayarku/v1/notify/doku → update order status
 *
 * Admin settings (stored in woocommerce_bayarku_doku_qris_settings option):
 *   - enabled, title, description, sandbox
 *   - client_id, client_secret, merchant_id, terminal_id
 *   - qr_validity: QR expiry in minutes (default 30)
 */
class DokuQris extends Gateway {

    protected bool   $absorb_fee = true; // QRIS: merchant covers fee
    protected string $provider   = 'doku';

    public function __construct() {
        $this->id                 = 'bayarku_doku_qris';
        $this->method_title       = 'QRIS (DOKU)';
        $this->method_description = 'Terima pembayaran QRIS melalui DOKU SNAP API. QR ditampilkan langsung di website.';
        $this->icon               = BAYARKU_URL . 'assets/images/qris-logo.png';

        parent::__construct();

        // AJAX handlers
        add_action( 'wp_ajax_bayarku_poll_qris',        [ $this, 'ajax_poll_qris' ] );
        add_action( 'wp_ajax_nopriv_bayarku_poll_qris', [ $this, 'ajax_poll_qris' ] );

        // Cancel expired QR when order is cancelled
        add_action( 'woocommerce_order_status_cancelled', [ $this, 'cancel_qr_on_order_cancel' ] );
    }

    // -------------------------------------------------------------------------
    // Admin Settings
    // -------------------------------------------------------------------------

    public function init_form_fields(): void {
        parent::init_form_fields();

        $this->form_fields = array_merge( $this->form_fields, [
            'credential_section' => [
                'title'       => 'Kredensial DOKU SNAP',
                'type'        => 'title',
                'description' => 'Ada dua set kredensial yang diperlukan dari dashboard DOKU:<br>'
                    . '&bull; <strong>Token SNAP</strong>: Client ID (BRN) + Private Key → dari menu <em>Pengaturan SNAP</em> atau <em>API Keys</em>.<br>'
                    . '&bull; <strong>QRIS</strong>: Client Secret + QRIS Client ID → dari tab <em>Pengaturan Kredensial QRIS</em>.',
            ],
            'client_id' => [
                'title'       => 'Client ID (BRN)',
                'type'        => 'text',
                'description' => 'BRN Client ID untuk autentikasi token B2B. Format: <code>BRN-XXXX-...</code>. Ditemukan di dashboard DOKU → Pengaturan SNAP / API Keys.',
                'default'     => '',
                'desc_tip'    => false,
            ],
            'client_secret' => [
                'title'       => 'Client Secret (QRIS)',
                'type'        => 'password',
                'description' => 'Nilai <strong>clientSecret</strong> dari tab <em>Pengaturan Kredensial QRIS</em>. Bukan "sharedKey" — sharedKey untuk API lama.',
                'default'     => '',
                'desc_tip'    => false,
            ],
            'private_key' => [
                'title'       => 'Private Key (RSA)',
                'type'        => 'textarea',
                'description' => 'RSA private key format PKCS#8 untuk signing token B2B. Generate pasangan RSA key, lalu daftarkan public key ke DOKU.<br>'
                    . 'Format: <code>-----BEGIN PRIVATE KEY-----</code> ... <code>-----END PRIVATE KEY-----</code>',
                'default'     => '',
                'desc_tip'    => false,
                'css'         => 'font-family: monospace; font-size: 11px; height: 150px;',
            ],
            'merchant_id' => [
                'title'       => 'QRIS Client ID',
                'type'        => 'text',
                'default'     => '',
                'desc_tip'    => false,
                'description' => 'Nilai <strong>clientId</strong> (angka saja, contoh: <code>75316</code>) dari tab <em>Pengaturan Kredensial QRIS</em>. Bukan NMID dan bukan BRN Client ID.',
            ],
            'terminal_id' => [
                'title'             => 'Terminal ID',
                'type'              => 'text',
                'default'           => 'A01',
                'desc_tip'          => false,
                'description'       => 'Terminal ID dari DOKU (alphanumeric, maks 4 karakter). Gunakan <code>A01</code> jika tidak ada instruksi lain dari DOKU.',
                'custom_attributes' => [ 'maxlength' => '4' ],
            ],
            'postal_code' => [
                'title'       => 'Kode Pos Toko',
                'type'        => 'text',
                'default'     => '',
                'desc_tip'    => true,
                'description' => 'Kode pos lokasi toko (5 digit angka). Wajib diisi untuk QRIS DOKU.',
                'custom_attributes' => [ 'maxlength' => '5', 'pattern' => '[0-9]{5}' ],
            ],
            'qr_validity' => [
                'title'       => 'Masa Berlaku QR (menit)',
                'type'        => 'number',
                'default'     => '30',
                'description' => 'QR akan kadaluarsa setelah menit ini.',
                'custom_attributes' => [ 'min' => '5', 'max' => '60', 'step' => '5' ],
            ],
            'webhook_section' => [
                'title'       => 'Webhook / Notify URL',
                'type'        => 'title',
                'description' => 'Salin URL di bawah ini dan tempelkan ke dashboard DOKU → <em>Update Notify URL</em> (atau <em>QRIS Notify URL</em>).',
            ],
            'notify_url_display' => [
                'title'             => 'Notify URL',
                'type'              => 'text',
                'default'           => get_rest_url( null, 'bayarku/v1/notify/doku' ),
                'description'       => 'URL ini hanya untuk referensi. Nilai tidak disimpan.',
                'custom_attributes' => [ 'readonly' => 'readonly', 'onclick' => 'this.select()' ],
                'css'               => 'color:#555; background:#f9f9f9; cursor:text;',
            ],
        ] );
    }

    /**
     * Save credential settings to individual wp_options so DokuProvider can read them.
     */
    public function process_admin_options(): bool {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return false;
        }

        $result = parent::process_admin_options();

        // Mirror individual credential options for DokuProvider
        update_option( 'bayarku_doku_client_id',     $this->get_option( 'client_id' ) );
        update_option( 'bayarku_doku_client_secret', $this->get_option( 'client_secret' ) );
        update_option( 'bayarku_doku_private_key',   $this->get_option( 'private_key' ) );
        update_option( 'bayarku_doku_merchant_id',   $this->get_option( 'merchant_id' ) );
        update_option( 'bayarku_doku_terminal_id',   $this->get_option( 'terminal_id' ) );

        // Clear cached token when credentials change
        delete_transient( 'bayarku_doku_token_sandbox' );
        delete_transient( 'bayarku_doku_token_prod' );

        return $result;
    }

    // -------------------------------------------------------------------------
    // process_payment()
    // -------------------------------------------------------------------------

    public function process_payment( $order_id ): array {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            wc_add_notice( 'Pesanan tidak ditemukan.', 'error' );
            return [ 'result' => 'failure' ];
        }

        try {
            $provider = new DokuProvider( $this->is_sandbox() );
            $amount   = (int) round( $order->get_total() );
            $validity_minutes = (int) $this->get_option( 'qr_validity', 30 );
            $validity = ( new \DateTime( 'now', new \DateTimeZone( 'Asia/Jakarta' ) ) )
                ->modify( "+{$validity_minutes} minutes" )
                ->format( 'Y-m-d\TH:i:sP' );

            $postal_code = substr( preg_replace( '/\D/', '', $this->get_option( 'postal_code', '' ) ), 0, 5 );
            $result = $provider->qris_generate( $order_id, $amount, $validity, $postal_code );

            // Generate QR image server-side and store as base64 (no browser external request)
            $qr_image_b64 = $this->fetch_qr_image( $result['qrContent'] );

            // Save QR data to order
            $meta = [
                'qr_content'           => $result['qrContent'],
                'reference_no'         => $result['referenceNo'],
                'partner_reference_no' => $result['partnerReferenceNo'],
                'qr_expires_at'        => time() + ( (int) $this->get_option( 'qr_validity', 30 ) * 60 ),
                'status'               => 'pending',
            ];
            if ( $qr_image_b64 ) {
                $meta['qr_image'] = $qr_image_b64;
            }
            $this->save_order_meta( $order, $meta );

            // Mark order as pending payment
            $order->update_status( 'pending', 'Menunggu pembayaran QRIS DOKU.' );

            // Empty cart
            WC()->cart->empty_cart();

            // Redirect to QR display page
            return $this->redirect_to_payment_page( $order_id, 'qr' );

        } catch ( \Exception $e ) {
            wc_get_logger()->error( 'DOKU QRIS process_payment: ' . $e->getMessage(), [ 'source' => 'bayarku' ] );
            wc_add_notice( 'Gagal memproses pembayaran QRIS. Silakan coba lagi.', 'error' );
            return [ 'result' => 'failure' ];
        }
    }

    // -------------------------------------------------------------------------
    // AJAX polling
    // -------------------------------------------------------------------------

    public function ajax_poll_qris(): void {
        $order_id = absint( wp_unslash( $_POST['order_id'] ?? 0 ) );
        $nonce    = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );

        if ( ! wp_verify_nonce( $nonce, 'bayarku_poll_' . $order_id ) ) {
            wp_send_json_error( [ 'message' => 'Sesi tidak valid.' ], 403 );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => 'Pesanan tidak ditemukan.' ], 404 );
        }

        // Check if already paid (may have been updated by webhook)
        if ( $order->is_paid() ) {
            wp_send_json_success( [
                'status'      => 'paid',
                'redirect'    => $order->get_checkout_order_received_url(),
            ] );
        }

        // Check QR expiry
        $expires_at = (int) $this->get_order_meta( $order, 'qr_expires_at' );
        if ( $expires_at && time() > $expires_at ) {
            wp_send_json_success( [ 'status' => 'expired' ] );
        }

        // Query DOKU
        try {
            $reference_no = $this->get_order_meta( $order, 'reference_no' );
            if ( ! $reference_no ) {
                wp_send_json_error( [ 'message' => 'Data transaksi tidak ditemukan.' ], 500 );
            }

            $partner_reference_no = $this->get_order_meta( $order, 'partner_reference_no' ) ?: (string) $order_id;
            $provider = new DokuProvider( $this->is_sandbox() );
            $result   = $provider->qris_query( $reference_no, $partner_reference_no );

            $tx_status = $result['latestTransactionStatus'] ?? '';

            if ( $tx_status === '00' ) {
                // Idempotency lock — prevents double payment_complete from concurrent poll + webhook
                $lock_key = 'bayarku_completing_' . $order_id;
                if ( get_transient( $lock_key ) ) {
                    // Another process is completing this payment; return paid so JS redirects
                    wp_send_json_success( [
                        'status'   => 'paid',
                        'redirect' => $order->get_checkout_order_received_url(),
                    ] );
                }
                set_transient( $lock_key, 1, 60 );

                // Re-read order from DB to get latest status after acquiring lock
                $order = wc_get_order( $order_id );
                if ( ! $order->is_paid() ) {
                    $order->payment_complete( $reference_no );
                    $order->add_order_note(
                        sprintf(
                            'QRIS DOKU: pembayaran dikonfirmasi. referenceNo: %s, approvalCode: %s',
                            esc_html( $reference_no ),
                            esc_html( $result['additionalInfo']['approvalCode'] ?? '-' )
                        )
                    );
                    $this->save_order_meta( $order, [ 'status' => 'paid' ] );
                }

                delete_transient( $lock_key );

                wp_send_json_success( [
                    'status'   => 'paid',
                    'redirect' => $order->get_checkout_order_received_url(),
                ] );
            }

            wp_send_json_success( [ 'status' => 'pending' ] );

        } catch ( \Exception $e ) {
            wc_get_logger()->error( 'DOKU QRIS poll: ' . $e->getMessage(), [ 'source' => 'bayarku' ] );
            // Don't expose internals; just say still pending so polling continues
            wp_send_json_success( [ 'status' => 'pending' ] );
        }
    }

    // -------------------------------------------------------------------------
    // Webhook
    // -------------------------------------------------------------------------

    public function validate_webhook( \WP_REST_Request $request ): bool {
        $signature   = $request->get_header( 'x-signature' ) ?? '';
        $timestamp   = $request->get_header( 'x-timestamp' ) ?? '';
        $external_id = $request->get_header( 'x-external-id' ) ?? '';
        $raw_body    = $request->get_body();

        $provider = new DokuProvider( $this->is_sandbox() );
        return $provider->verify_webhook_signature( $raw_body, $timestamp, $external_id, $signature );
    }

    public function handle_webhook( \WP_REST_Request $request ): \WP_REST_Response {
        $body = $request->get_json_params();

        $partner_ref = $body['originalPartnerReferenceNo'] ?? '';
        $reference   = $body['originalReferenceNo'] ?? '';
        $tx_status   = $body['latestTransactionStatus'] ?? '';

        $order_id = (int) $partner_ref;
        $order    = wc_get_order( $order_id );

        if ( ! $order ) {
            return new \WP_REST_Response( [ 'responseCode' => '4042512', 'responseMessage' => 'Order not found' ], 404 );
        }

        if ( $tx_status === '00' ) {
            // Same idempotency lock used by AJAX polling — prevents double payment_complete
            $lock_key = 'bayarku_completing_' . $order_id;
            if ( ! get_transient( $lock_key ) ) {
                set_transient( $lock_key, 1, 60 );

                // Re-read fresh order status after acquiring lock
                $order = wc_get_order( $order_id );
                if ( $order && ! $order->is_paid() ) {
                    $order->payment_complete( $reference );
                    $order->add_order_note( 'QRIS DOKU: pembayaran dikonfirmasi via webhook. referenceNo: ' . esc_html( $reference ) );
                    $this->save_order_meta( $order, [ 'status' => 'paid' ] );
                }

                delete_transient( $lock_key );
            }
        }

        return new \WP_REST_Response( [
            'responseCode'    => '2002500',
            'responseMessage' => 'Success',
        ], 200 );
    }

    // -------------------------------------------------------------------------
    // Helper: fetch QR image server-side
    // -------------------------------------------------------------------------

    /**
     * Generate a PNG QR code image locally using the bundled PHP QR library + GD.
     * Returns base64-encoded PNG string, or empty string on failure.
     */
    private function fetch_qr_image( string $qr_content ): string {
        if ( ! extension_loaded( 'gd' ) ) {
            return '';
        }

        try {
            require_once BAYARKU_DIR . 'includes/lib/qrcode.php';

            // Convert trigger_error(E_USER_ERROR) → catchable exception
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
            set_error_handler( function ( int $errno, string $errstr ): bool {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw new \RuntimeException( $errstr, $errno );
            }, E_USER_ERROR );

            // Auto-detect minimum QR version (type 1–40) that fits the data
            $qr = null;
            for ( $type = 1; $type <= 40; $type++ ) {
                try {
                    $attempt = new \QRCode();
                    $attempt->setTypeNumber( $type );
                    $attempt->setErrorCorrectLevel( QR_ERROR_CORRECT_LEVEL_M );
                    $attempt->addData( $qr_content );
                    $attempt->make();
                    $qr = $attempt;
                    break;
                } catch ( \Throwable $e ) {
                    // Type too small for data length — try next
                }
            }

            restore_error_handler();

            if ( ! $qr ) {
                return '';
            }

            $module_count = $qr->getModuleCount();
            $cell_size    = (int) max( 4, floor( 280 / $module_count ) );
            $margin       = $cell_size * 2;
            $image_size   = $module_count * $cell_size + $margin * 2;

            $image = imagecreatetruecolor( $image_size, $image_size );
            $white = imagecolorallocate( $image, 255, 255, 255 );
            $black = imagecolorallocate( $image, 0, 0, 0 );
            imagefill( $image, 0, 0, $white );

            for ( $row = 0; $row < $module_count; $row++ ) {
                for ( $col = 0; $col < $module_count; $col++ ) {
                    if ( $qr->isDark( $row, $col ) ) {
                        $x = $margin + $col * $cell_size;
                        $y = $margin + $row * $cell_size;
                        imagefilledrectangle( $image, $x, $y, $x + $cell_size - 1, $y + $cell_size - 1, $black );
                    }
                }
            }

            ob_start();
            imagepng( $image );
            $png = ob_get_clean();
            imagedestroy( $image );

            return base64_encode( $png );

        } catch ( \Throwable $e ) {
            restore_error_handler();
            wc_get_logger()->warning( 'Bayarku: gagal generate QR image lokal: ' . $e->getMessage(), [ 'source' => 'bayarku' ] );
            return '';
        }
    }

    // -------------------------------------------------------------------------
    // Cancel QR when order cancelled
    // -------------------------------------------------------------------------

    public function cancel_qr_on_order_cancel( int $order_id ): void {
        $order        = wc_get_order( $order_id );
        $payment_method = $order ? $order->get_payment_method() : '';

        if ( $payment_method !== $this->id ) {
            return;
        }

        $reference_no = $this->get_order_meta( $order, 'reference_no' );
        if ( ! $reference_no ) {
            return;
        }

        try {
            $partner_reference_no = $this->get_order_meta( $order, 'partner_reference_no' ) ?: (string) $order_id;
            $provider = new DokuProvider( $this->is_sandbox() );
            $provider->qris_expire( $reference_no, $partner_reference_no );
            $order->add_order_note( 'QRIS DOKU: QR di-expire karena pesanan dibatalkan.' );
        } catch ( \Exception $e ) {
            wc_get_logger()->warning( 'DOKU QRIS expire on cancel: ' . $e->getMessage(), [ 'source' => 'bayarku' ] );
        }
    }
}
