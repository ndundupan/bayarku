<?php
namespace Bayarku\Core;

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Integrasi generik gateway Bayarku dengan WooCommerce Cart/Checkout Blocks.
 *
 * Satu instance per gateway. Semua data (judul, deskripsi, ikon, fitur yang
 * didukung) dibaca langsung dari objek WC_Payment_Gateway, sehingga tidak ada
 * konfigurasi khusus per gateway. Di sisi klien, assets/js/blocks-checkout.js
 * membaca daftar id gateway dari `BayarkuBlocks.gateways` lalu memanggil
 * `wc.wcSettings.getSetting( '<id>_data' )` untuk setiap gateway.
 *
 * Kelas ini hanya boleh dimuat setelah dipastikan AbstractPaymentMethodType
 * tersedia (lihat Loader::register_blocks_support()).
 */
final class BlocksSupport extends AbstractPaymentMethodType {

    public const SCRIPT_HANDLE = 'bayarku-blocks-checkout';

    /** Id semua gateway yang sudah didaftarkan — dikirim ke JS. */
    private static array $registered_ids = [];

    /** Apakah daftar id sudah disuntikkan ke skrip. */
    private static bool $ids_injected = false;

    private \WC_Payment_Gateway $gateway;

    public function __construct( \WC_Payment_Gateway $gateway ) {
        $this->gateway = $gateway;
        $this->name    = $gateway->id;

        if ( ! in_array( $gateway->id, self::$registered_ids, true ) ) {
            self::$registered_ids[] = $gateway->id;
        }
    }

    /**
     * Dipanggil registry WooCommerce Blocks setelah semua metode terdaftar.
     */
    public function initialize(): void {
        $this->settings = get_option( 'woocommerce_' . $this->name . '_settings', [] );
        if ( ! is_array( $this->settings ) ) {
            $this->settings = [];
        }
    }

    public function is_active(): bool {
        return $this->gateway->is_available();
    }

    /**
     * Daftarkan skrip vanilla (tanpa build) dan kembalikan handle-nya.
     * Dipanggil sekali per gateway; pendaftaran skrip dilindungi wp_script_is().
     *
     * @return string[]
     */
    public function get_payment_method_script_handles(): array {
        if ( ! wp_script_is( self::SCRIPT_HANDLE, 'registered' ) ) {
            wp_register_script(
                self::SCRIPT_HANDLE,
                BAYARKU_URL . 'assets/js/blocks-checkout.js',
                [ 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ],
                BAYARKU_VERSION,
                true
            );
        }

        // Registry membuat semua instance dulu baru meminta handle skrip, jadi daftar id
        // sudah lengkap di panggilan pertama. Cukup disuntikkan sekali.
        if ( ! self::$ids_injected ) {
            self::$ids_injected = true;
            wp_add_inline_script(
                self::SCRIPT_HANDLE,
                'window.BayarkuBlocks = ' . wp_json_encode( [ 'gateways' => array_values( self::$registered_ids ) ] ) . ';',
                'before'
            );
        }

        return [ self::SCRIPT_HANDLE ];
    }

    /**
     * Data yang dibaca JS lewat getSetting( '<id>_data' ).
     */
    public function get_payment_method_data(): array {
        return [
            'name'        => $this->name,
            'title'       => $this->gateway->get_title(),
            'description' => $this->gateway->get_description(),
            'icon'        => is_string( $this->gateway->icon ) ? $this->gateway->icon : '',
            'supports'    => $this->get_supported_features(),
        ];
    }

    /**
     * @return string[]
     */
    public function get_supported_features(): array {
        $supports = is_array( $this->gateway->supports ) ? $this->gateway->supports : [ 'products' ];
        return array_values( array_filter( $supports, 'is_string' ) );
    }
}
