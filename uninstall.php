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
$standalone_options = [
    'bayarku_doku_client_id',
    'bayarku_doku_client_secret',
    'bayarku_doku_private_key',
    'bayarku_doku_merchant_id',
    'bayarku_doku_terminal_id',
];

foreach ( $standalone_options as $option ) {
    delete_option( $option );
}

// -------------------------------------------------------------------------
// Remove WooCommerce gateway settings (stored as woocommerce_{id}_settings)
// -------------------------------------------------------------------------
$gateway_settings = [
    'woocommerce_bayarku_doku_qris_settings',
    'woocommerce_bayarku_doku_va_settings',
    'woocommerce_bayarku_doku_ewallet_settings',
    'woocommerce_bayarku_midtrans_qris_settings',
    'woocommerce_bayarku_xendit_qris_settings',
    'woocommerce_bayarku_tripay_qris_settings',
    'woocommerce_bayarku_duitku_qris_settings',
];

foreach ( $gateway_settings as $option ) {
    delete_option( $option );
}

// -------------------------------------------------------------------------
// Remove cached access tokens
// -------------------------------------------------------------------------
delete_transient( 'bayarku_doku_token_sandbox' );
delete_transient( 'bayarku_doku_token_prod' );
