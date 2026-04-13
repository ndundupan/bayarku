<?php
/**
 * Plugin Name: Bayarku DOKU for WooCommerce
 * Plugin URI:  https://berdikaristudio.com/bayarku
 * Description: DOKU payment gateway for WooCommerce. Mulai dari QRIS — QR Code ditampilkan langsung di website Anda, tanpa redirect, polling otomatis, webhook backup.
 * Version:     1.0.0
 * Author:      Panduaji
 * Author URI:  https://panduaji.com
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: bayarku
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 * WC tested up to: 10.6
 */

defined( 'ABSPATH' ) || exit;

define( 'BAYARKU_VERSION',  '1.0.0' );
define( 'BAYARKU_DIR',      plugin_dir_path( __FILE__ ) );
define( 'BAYARKU_URL',      plugin_dir_url( __FILE__ ) );
define( 'BAYARKU_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Check WooCommerce is active before loading anything.
 */
function bayarku_check_woocommerce(): void {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="error"><p><strong>Bayarku for WooCommerce</strong> membutuhkan WooCommerce untuk berjalan.</p></div>';
        } );
        return;
    }

    bayarku_init();
}
add_action( 'plugins_loaded', 'bayarku_check_woocommerce' );

function bayarku_init(): void {
    // Declare HPOS compatibility
    add_action( 'before_woocommerce_init', function () {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables', __FILE__, true
            );
        }
    } );

    // Autoloader
    spl_autoload_register( 'bayarku_autoload' );

    // Boot core
    Bayarku\Core\Loader::instance();
}

/**
 * Custom thank you message for Bayarku orders.
 */
add_action( 'woocommerce_thankyou', function ( int $order_id ): void {
    $order = wc_get_order( $order_id );
    if ( ! $order || strpos( $order->get_payment_method(), 'bayarku_' ) !== 0 ) {
        return;
    }
    echo '<div style="text-align:center;padding:16px 0;font-size:1.05em;">'
        . '<p>Terima kasih telah memesan di <strong>' . esc_html( get_bloginfo( 'name' ) ) . '</strong>.</p>'
        . '<p>Tim kami akan segera memproses pesanan Anda. Kami akan menghubungi Anda jika diperlukan.</p>'
        . '</div>';
}, 5 );

function bayarku_autoload( string $class ): void {
    // Only handle our namespace
    if ( strpos( $class, 'Bayarku\\' ) !== 0 ) {
        return;
    }

    // Bayarku\Core\Loader → includes/core/class-loader.php
    // Bayarku\Abstracts\Provider → includes/abstracts/abstract-bayarku-provider.php
    // Bayarku\Providers\Doku\DokuProvider → includes/providers/doku/class-doku-provider.php
    $relative = substr( $class, strlen( 'Bayarku\\' ) );
    $parts    = explode( '\\', $relative );

    $segment  = strtolower( $parts[0] ); // core | abstracts | providers
    $classname = end( $parts );

    // Build filename from class name: DokuQris → class-doku-qris.php
    $filename = 'class-' . strtolower( preg_replace( '/([A-Z])/', '-$1', lcfirst( $classname ) ) ) . '.php';

    if ( $segment === 'abstracts' ) {
        // Abstract files use the prefix: abstract-bayarku-{classname}.php
        $abstract_name = strtolower( preg_replace( '/([A-Z])/', '-$1', lcfirst( $classname ) ) );
        $file = BAYARKU_DIR . 'includes/abstracts/abstract-bayarku-' . $abstract_name . '.php';
    } elseif ( $segment === 'core' ) {
        $file = BAYARKU_DIR . 'includes/core/' . $filename;
    } elseif ( $segment === 'providers' ) {
        // Bayarku\Providers\Doku\Gateways\DokuQris → providers/doku/gateways/class-doku-qris.php
        $provider_parts = array_slice( $parts, 1 ); // [ Doku, Gateways, DokuQris ]
        $sub_path = implode( '/', array_map( 'strtolower', array_slice( $provider_parts, 0, -1 ) ) );
        $file = BAYARKU_DIR . 'includes/providers/' . $sub_path . '/' . $filename;
    } else {
        return;
    }

    if ( file_exists( $file ) ) {
        require_once $file;
    }
}
