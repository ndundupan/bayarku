<?php
namespace Bayarku\Providers\Doku\Gateways;

defined( 'ABSPATH' ) || exit;

use Bayarku\Abstracts\Gateway;

/**
 * DOKU eWallet Gateway — stub.
 * Fee ditanggung buyer (absorb_fee = false).
 *
 * TODO: implement using DOKU SNAP eWallet endpoints (OVO, GoPay, Dana, etc).
 */
class DokuEwallet extends Gateway {

    protected bool   $absorb_fee = false; // buyer pays fee
    protected string $provider   = 'doku';

    public function __construct() {
        $this->id                 = 'bayarku_doku_ewallet';
        $this->method_title       = 'eWallet (DOKU)';
        $this->method_description = 'OVO, GoPay, Dana via DOKU. Belum aktif.';

        parent::__construct();
    }

    public function process_payment( $order_id ): array {
        wc_add_notice( 'eWallet DOKU belum tersedia.', 'error' );
        return [ 'result' => 'failure' ];
    }

    public function validate_webhook( \WP_REST_Request $request ): bool {
        return false;
    }
}
