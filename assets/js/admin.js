/* global jQuery, window */
jQuery( function ( $ ) {
    'use strict';

    var config     = window.TIMUHeicSupportData || {};
    var pendingIds = Array.isArray( config.pendingIds ) ? config.pendingIds.slice() : [];
    var strings    = config.strings  || {};
    var actions    = config.actions  || {};
    var nonce      = config.nonce    || '';
    var ajaxUrl    = config.ajaxUrl  || window.ajaxurl;
    var batchSize  = Math.max( 1, parseInt( config.batchSize, 10 ) || 10 );

    var completed        = 0;
    var isCancelled      = false;
    var displayCompleted = 0;
    var spinFrames       = [ '|', '/', '-', '\\' ];
    var spinIndex        = 0;
    var spinTimer        = null;

    /* ── Helpers ──────────────────────────────────────────────── */

    function postJson( payload ) {
        return $.ajax( {
            url:      ajaxUrl,
            method:   'POST',
            data:     payload,
            dataType: 'json'
        } );
    }

    function updateProgress( total ) {
        var shown = Math.min( total, Math.max( 0, displayCompleted ) );
        var pct = total > 0 ? Math.round( ( shown / total ) * 100 ) : 100;
        $( '#fwo-progress-bar' ).css( 'width', pct + '%' );
        $( '#fwo-progress-text' ).text( pct + '% (' + Math.round( shown ) + '/' + total + ')' );
        $( '#p-cnt' ).text( Math.max( 0, total - Math.round( shown ) ) );
    }

    function ensureBusyIndicator() {
        if ( $( '#fwo-active-spinner' ).length ) { return; }
        $( '.fwo-controls' ).append(
            '<span id="fwo-active-spinner" style="display:none;align-items:center;gap:8px;color:#2271b1;font-weight:600;">' +
            '<span class="spinner is-active" style="float:none;margin:0;"></span>' +
            '<span id="fwo-active-text">Working...</span>' +
            '</span>'
        );
    }

    function startBusyIndicator() {
        ensureBusyIndicator();
        $( '#fwo-active-spinner' ).css( 'display', 'inline-flex' );
        if ( spinTimer ) { return; }
        spinTimer = window.setInterval( function () {
            spinIndex = ( spinIndex + 1 ) % spinFrames.length;
            $( '#fwo-active-text' ).text( 'Working ' + spinFrames[ spinIndex ] );
        }, 120 );
    }

    function stopBusyIndicator() {
        if ( spinTimer ) {
            window.clearInterval( spinTimer );
            spinTimer = null;
        }
        $( '#fwo-active-spinner' ).hide();
    }

    function removeFromQueue( ids ) {
        if ( ! Array.isArray( ids ) || ! ids.length ) { return; }
        pendingIds = pendingIds.filter( function ( id ) {
            return ids.indexOf( id ) === -1;
        } );
    }

    /* ── Restore buttons ─────────────────────────────────────── */

    $( document ).on( 'click', '.restore-btn', function () {
        var $btn = $( this );
        $btn.prop( 'disabled', true ).text( '…' );
        postJson( { action: actions.restore, attachment_id: $btn.data( 'id' ), nonce: nonce } )
            .always( function () { window.location.reload(); } );
    } );

    $( '#btn-restore-all' ).on( 'click', function () {
        var ids = ( $( this ).data( 'ids' ) || [] ).slice();
        if ( ! window.confirm( strings.confirmRestoreAll || 'Restore all images?' ) ) { return; }
        $( this ).prop( 'disabled', true ).text( strings.restoring || 'Restoring…' );
        var next = function () {
            if ( ! ids.length ) { window.location.reload(); return; }
            postJson( { action: actions.restore, attachment_id: ids.shift(), nonce: nonce } ).always( next );
        };
        next();
    } );

    /* ── Batch convert ──────────────────────────────────────── */

    $( '#btn-start' ).on( 'click', function () {
        var $btn  = $( this );
        var total = pendingIds.length;
        if ( ! total ) { return; }

        displayCompleted = completed;
        updateProgress( total );

        $btn.prop( 'disabled', true ).text( strings.processing || 'Processing…' );
        $( '#btn-cancel' ).show();
        $( '#fwo-progress-container' ).fadeIn();
        startBusyIndicator();

        var processNextBatch = function () {
            if ( isCancelled || ! pendingIds.length ) {
                stopBusyIndicator();
                return;
            }
            var batch = pendingIds.slice( 0, batchSize );
            var optimisticStart = completed;
            var optimisticEnd   = completed + batch.length;
            var optimisticTick  = null;
            var tickStart       = new Date().getTime();

            optimisticTick = window.setInterval( function () {
                var elapsed = new Date().getTime() - tickStart;
                var ratio   = Math.min( 0.95, elapsed / ( Math.max( 1, batch.length ) * 700 ) );
                displayCompleted = optimisticStart + ( ( optimisticEnd - optimisticStart ) * ratio );
                updateProgress( total );
            }, 180 );

            postJson( { action: actions.batch, attachment_ids: batch, nonce: nonce } )
                .done( function ( res ) {
                    if ( typeof res === 'string' ) {
                        try {
                            res = JSON.parse( res );
                        } catch ( e ) {
                            res = null;
                        }
                    }

                    if ( ! res || ! res.success || ! res.data ) {
                        isCancelled = true;
                        window.alert( 'Batch response was invalid. Processing paused to avoid losing queue state.' );
                        return;
                    }

                    var processed = Array.isArray( res.data.processed_ids ) ? res.data.processed_ids : [];
                    var failed    = Array.isArray( res.data.failed_ids )    ? res.data.failed_ids    : [];
                    var errors    = Array.isArray( res.data.errors )        ? res.data.errors        : [];

                    removeFromQueue( processed.concat( failed ) );

                    processed.forEach( function ( id ) { completed++; $( '#fwo-row-' + id ).remove(); } );
                    failed.forEach( function ( id ) {
                        var $row = $( '#fwo-row-' + id );
                        if ( $row.length ) {
                            $row.addClass( 'timu-failed-row' );
                            if ( ! $row.find( '.timu-failed-msg' ).length ) {
                                $row.find( 'td:last' ).append( '<div class="timu-failed-msg" style="color:#d63638;font-size:11px;margin-top:4px;">Failed in this run</div>' );
                            }
                        }
                    } );

                    if ( errors.length ) {
                        window.alert( ( strings.failedPrefix || 'Some images failed:' ) + '\n- ' + errors.join( '\n- ' ) );
                    }

                    displayCompleted = completed;
                    updateProgress( total );

                    if ( ! pendingIds.length ) {
                        stopBusyIndicator();
                        window.location.reload();
                        return;
                    }
                } )
                .fail( function () {
                    isCancelled = true;
                    window.alert( 'Batch request failed or timed out. Queue was preserved, so refresh and retry will continue from the same items.' );
                } )
                .always( function () {
                    if ( optimisticTick ) {
                        window.clearInterval( optimisticTick );
                        optimisticTick = null;
                    }
                    displayCompleted = completed;
                    updateProgress( total );
                    processNextBatch();
                } );
        };

        processNextBatch();
    } );

    $( '#btn-cancel' ).on( 'click', function () {
        isCancelled = true;
        stopBusyIndicator();
        window.location.reload();
    } );

    /* ── Quality preset toggle ──────────────────────────────── */
    ( function () {
        var custom = document.getElementById( 'timu-custom-quality' );
        if ( ! custom ) { return; }
        document.querySelectorAll( '.timu-preset-radio' ).forEach( function ( radio ) {
            radio.addEventListener( 'change', function () {
                custom.style.display = 'custom' === this.value ? '' : 'none';
            } );
        } );
    }() );

} );
