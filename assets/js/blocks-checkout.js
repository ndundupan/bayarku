/**
 * Bayarku — pendaftaran metode pembayaran untuk WooCommerce Checkout Block.
 *
 * Vanilla JS tanpa build step. Mengandalkan global yang disediakan WooCommerce
 * Blocks dan WordPress: window.wc.wcBlocksRegistry, window.wc.wcSettings,
 * window.wp.element, window.wp.htmlEntities.
 *
 * Daftar id gateway dikirim PHP lewat window.BayarkuBlocks.gateways; data tiap
 * gateway (name, title, description, icon, supports) dibaca dari
 * wcSettings '<id>_data' yang diisi BlocksSupport::get_payment_method_data().
 */
( function () {
    'use strict';

    var wc = window.wc || {};
    var wp = window.wp || {};

    if ( ! wc.wcBlocksRegistry || ! wc.wcSettings || ! wp.element ) {
        return;
    }

    var registerPaymentMethod = wc.wcBlocksRegistry.registerPaymentMethod;
    var getSetting            = wc.wcSettings.getSetting;
    var el                    = wp.element.createElement;
    var decodeEntities        = ( wp.htmlEntities && wp.htmlEntities.decodeEntities ) || function ( s ) { return s; };

    var ids = ( window.BayarkuBlocks && Array.isArray( window.BayarkuBlocks.gateways ) )
        ? window.BayarkuBlocks.gateways
        : [];

    ids.forEach( function ( id ) {
        var data = getSetting( id + '_data', null );
        if ( ! data || ! data.name ) {
            return;
        }

        var title       = decodeEntities( data.title || data.name );
        var description = decodeEntities( data.description || '' );
        var icon        = data.icon || '';

        /** Label: ikon + judul. Menerima `components` dari WooCommerce Blocks. */
        var Label = function ( props ) {
            var components = props.components || {};
            var children   = [];

            if ( components.PaymentMethodLabel ) {
                children.push( el( components.PaymentMethodLabel, { key: 'text', text: title } ) );
            } else {
                children.push( el( 'span', { key: 'text' }, title ) );
            }

            if ( icon ) {
                if ( components.PaymentMethodIcons ) {
                    children.push( el( components.PaymentMethodIcons, {
                        key:   'icon',
                        align: 'right',
                        icons: [ { id: data.name, src: icon, alt: title } ]
                    } ) );
                } else {
                    children.push( el( 'img', {
                        key:   'icon',
                        src:   icon,
                        alt:   title,
                        style: { maxHeight: '24px', marginLeft: 'auto' }
                    } ) );
                }
            }

            return el( 'span', { className: 'bayarku-blocks-label', style: { display: 'flex', alignItems: 'center', width: '100%' } }, children );
        };

        /** Konten: deskripsi gateway (teks biasa). */
        var Content = function () {
            if ( ! description ) {
                return null;
            }
            return el( 'p', { className: 'bayarku-blocks-description' }, description );
        };

        registerPaymentMethod( {
            name:           data.name,
            label:          el( Label, null ),
            content:        el( Content, null ),
            edit:           el( Content, null ),
            ariaLabel:      title,
            canMakePayment: function () { return true; },
            supports: {
                features: Array.isArray( data.supports ) && data.supports.length ? data.supports : [ 'products' ]
            }
        } );
    } );
} )();
