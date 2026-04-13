<?php
/**
 * Bayarku for WooCommerce — Uninstall
 *
 * Runs when the plugin is deleted (not just deactivated) from the WP admin.
 * Removes all options and cached transients created by the plugin.
 *
 * @package Bayarku
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// -------------------------------------------------------------------------
// Remove individual credential options (mirrored by DokuProvider)
// -------------------------------------------------------------------------
$bayarku_standalone_options = [
    'bayarku_doku_client_id',
    'bayarku_doku_client_secret',
    'bayarku_doku_private_key',
    'bayarku_doku_merchant_id',
    'bayarku_doku_terminal_id',
];

foreach ( $bayarku_standalone_options as $bayarku_option ) {
    delete_option( $bayarku_option );
}

// -------------------------------------------------------------------------
// Remove WooCommerce gateway settings (stored as woocommerce_{id}_settings)
// -------------------------------------------------------------------------
$bayarku_gateway_settings = [
    'woocommerce_bayarku_doku_qris_settings',
    'woocommerce_bayarku_doku_va_settings',
    'woocommerce_bayarku_doku_ewallet_settings',
    'woocommerce_bayarku_midtrans_qris_settings',
    'woocommerce_bayarku_xendit_qris_settings',
    'woocommerce_bayarku_tripay_qris_settings',
    'woocommerce_bayarku_duitku_qris_settings',
];

foreach ( $bayarku_gateway_settings as $bayarku_option ) {
    delete_option( $bayarku_option );
}

// -------------------------------------------------------------------------
// Remove cached access tokens
// -------------------------------------------------------------------------
delete_transient( 'bayarku_doku_token_sandbox' );
delete_transient( 'bayarku_doku_token_prod' );
