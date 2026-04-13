/* global BayarkuQR, qrcode, jQuery */
(function ($) {
    'use strict';

    var cfg         = BayarkuQR;
    var pollTimer   = null;
    var countTimer  = null;
    var expired     = false;

    // ------------------------------------------------------------------
    // QR Code rendering (local — no external requests)
    // ------------------------------------------------------------------
    function renderQR() {
        var el = document.getElementById('bayarku-qr-canvas');
        if ( ! el ) return;

        var qrContent = el.getAttribute('data-qr') || cfg.qrContent;
        if ( ! qrContent ) return;

        var qr = qrcode( 0, 'M' ); // type 0 = auto-detect version
        qr.addData( qrContent );
        qr.make();

        var img = new Image();
        img.src    = qr.createDataURL( 5, 0 ); // cellSize=5, margin=0
        img.alt    = 'QR Code QRIS';
        img.width  = 280;
        img.height = 280;
        img.id     = 'bayarku-qr-img';

        el.appendChild( img );
    }

    // ------------------------------------------------------------------
    // Countdown timer
    // ------------------------------------------------------------------
    function startCountdown() {
        var $el      = $('#bayarku-countdown');
        var expires  = parseInt( $el.data('expires'), 10 ) * 1000; // ms

        countTimer = setInterval(function () {
            var remaining = Math.max( 0, expires - Date.now() );
            var mins      = Math.floor( remaining / 60000 );
            var secs      = Math.floor( (remaining % 60000) / 1000 );

            $el.text(
                (mins < 10 ? '0' : '') + mins + ':' + (secs < 10 ? '0' : '') + secs
            );

            if ( remaining <= 0 ) {
                clearInterval( countTimer );
                onExpired();
            }
        }, 1000);
    }

    // ------------------------------------------------------------------
    // Polling
    // ------------------------------------------------------------------
    function poll() {
        if ( expired ) return;

        $.ajax({
            url:    cfg.ajaxUrl,
            method: 'POST',
            data: {
                action:   'bayarku_poll_qris',
                order_id: cfg.orderId,
                nonce:    cfg.nonce,
            },
            success: function (res) {
                if ( ! res.success ) return; // keep polling silently

                var data   = res.data || {};
                var status = data.status;

                if ( status === 'paid' ) {
                    onPaid( data.redirect );
                } else if ( status === 'expired' ) {
                    onExpired();
                }
                // else still 'pending' — keep polling
            },
            error: function () {
                // Network error — keep polling, don't alarm user
            },
            complete: function () {
                if ( ! expired ) {
                    pollTimer = setTimeout( poll, cfg.pollInterval );
                }
            },
        });
    }

    // ------------------------------------------------------------------
    // State handlers
    // ------------------------------------------------------------------
    function onPaid( redirectUrl ) {
        clearTimeout( pollTimer );
        clearInterval( countTimer );

        $('#bayarku-overlay').fadeIn(300);

        setTimeout(function () {
            window.location.href = redirectUrl || cfg.thankYouUrl;
        }, 1500);
    }

    function onExpired() {
        expired = true;
        clearTimeout( pollTimer );

        $('#bayarku-qr-img, #bayarku-qr-canvas img').css('opacity', '0.25');
        $('#bayarku-status-text').text( cfg.expireMsg || 'QR kadaluarsa.' );
        $('#bayarku-countdown-wrap').hide();
        $('#bayarku-status').addClass('bayarku-status--expired');
        $('#bayarku-back-link').text('Buat pesanan baru').attr('href', cfg.thankYouUrl);
    }

    // ------------------------------------------------------------------
    // Init
    // ------------------------------------------------------------------
    $(function () {
        renderQR();
        startCountdown();
        pollTimer = setTimeout( poll, cfg.pollInterval );
    });

}(jQuery));
