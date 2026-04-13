<?php
/**
 * Template: QRIS QR Code payment page.
 *
 * Variables passed via load_template():
 *   $order WC_Order
 *
 * @package Bayarku
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template variables, not global scope.
$order = $GLOBALS['bayarku_tpl_order'];

get_header();

$order_id     = $order->get_id();
$qr_content   = $order->get_meta( '_bayarku_doku_qr_content' );
$qr_image_b64 = $order->get_meta( '_bayarku_doku_qr_image' );
$expires_at   = (int) $order->get_meta( '_bayarku_doku_qr_expires_at' );
$total        = $order->get_formatted_order_total();
$order_number = $order->get_order_number();

// Seconds remaining until QR expires
$seconds_left = max( 0, $expires_at - time() );
?>

<div class="bayarku-qr-wrapper" id="bayarku-qr-page">

    <div class="bayarku-qr-header">
        <h2>Selesaikan Pembayaran</h2>
        <p>Pesanan #<?php echo esc_html( $order_number ); ?> &middot; Total: <?php echo wp_kses_post( $total ); ?></p>
    </div>

    <div class="bayarku-qr-body">

        <div class="bayarku-qr-image-wrap" id="bayarku-qr-image-wrap">
            <?php if ( $qr_image_b64 ) : ?>
                <img
                    src="data:image/png;base64,<?php echo esc_attr( $qr_image_b64 ); ?>"
                    alt="QR Code QRIS"
                    width="280"
                    height="280"
                    id="bayarku-qr-img"
                />
            <?php elseif ( $qr_content ) : ?>
                <div id="bayarku-qr-canvas" data-qr="<?php echo esc_attr( $qr_content ); ?>"></div>
            <?php else : ?>
                <p class="bayarku-error">QR Code tidak tersedia. Silakan hubungi toko.</p>
            <?php endif; ?>
        </div>

        <p class="bayarku-qr-hint">
            Scan QR di atas menggunakan aplikasi mobile banking atau e-wallet apapun yang mendukung QRIS.
        </p>

        <div class="bayarku-countdown-wrap" id="bayarku-countdown-wrap">
            <span class="bayarku-countdown-label">QR berlaku selama</span>
            <span class="bayarku-countdown" id="bayarku-countdown" data-expires="<?php echo (int) $expires_at; ?>">
                <?php echo esc_html( gmdate( 'i:s', $seconds_left ) ); ?>
            </span>
        </div>

        <div class="bayarku-status" id="bayarku-status">
            <span class="bayarku-status-dot"></span>
            <span id="bayarku-status-text">Menunggu pembayaran&hellip;</span>
        </div>

        <div class="bayarku-actions">
            <a href="<?php echo esc_url( wc_get_cart_url() ); ?>" class="bayarku-btn-secondary" id="bayarku-back-link">
                Kembali ke keranjang
            </a>
        </div>

    </div><!-- .bayarku-qr-body -->

</div><!-- .bayarku-qr-wrapper -->

<div class="bayarku-overlay" id="bayarku-overlay" style="display:none;">
    <div class="bayarku-overlay-inner">
        <div class="bayarku-checkmark">&#10003;</div>
        <p>Pembayaran berhasil! Mengalihkan&hellip;</p>
    </div>
</div>

<?php get_footer(); ?>
