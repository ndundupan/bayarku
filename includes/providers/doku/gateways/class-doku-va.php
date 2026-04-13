<?php
namespace Bayarku\Providers\Doku\Gateways;

defined( 'ABSPATH' ) || exit;

use Bayarku\Abstracts\Gateway;

/**
 * DOKU Virtual Account Gateway — stub.
 * Fee ditanggung buyer (absorb_fee = false).
 *
 * TODO: implement using DOKU SNAP VA endpoints.
 */
class DokuVa extends Gateway {

    protected bool   $absorb_fee = false; // buyer pays fee
    protected string $provider   = 'doku';

    public function __construct() {
        $this->id                 = 'bayarku_doku_va';
        $this->method_title       = 'Transfer Bank / VA (DOKU)';
        $this->method_description = 'Virtual Account via DOKU. Belum aktif.';

        parent::__construct();
    }

    public function process_payment( $order_id ): array {
        wc_add_notice( 'VA DOKU belum tersedia.', 'error' );
        return [ 'result' => 'failure' ];
    }

    public function validate_webhook( \WP_REST_Request $request ): bool {
        return false;
    }
}
