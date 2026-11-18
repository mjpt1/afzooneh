/**
 * ShopHarvester Pro — admin interactions.
 */
( function ( $ ) {
	'use strict';

	var api = ( window.SHP && window.SHP.ajaxUrl ) || window.ajaxurl;
	var nonce = ( window.SHP && window.SHP.nonce ) || '';
	var i18n = ( window.SHP && window.SHP.i18n ) || {};

	function post( action, data ) {
		return $.post( api, $.extend( { action: action, nonce: nonce }, data || {} ) );
	}

	function setStatus( $el, message, ok ) {
		$el.text( message ).removeClass( 'is-ok is-err' ).addClass( ok ? 'is-ok' : 'is-err' );
	}

	/* ---------------------------------------------------------------- *
	 *  Runner form.
	 * ---------------------------------------------------------------- */
	var $runForm = $( '#shp-run-form' );
	if ( $runForm.length ) {
		$runForm.on( 'submit', function ( e ) {
			e.preventDefault();
			var $status = $( '#shp-run-status' );
			setStatus( $status, i18n.submitting || '...', true );

			post( 'shp_submit_run', {
				urls: $runForm.find( '[name=urls]' ).val(),
				subdomain: $runForm.find( '[name=subdomain]' ).val(),
				payload: $runForm.find( '[name=payload]' ).val()
			} ).done( function ( res ) {
				if ( res && res.success ) {
					setStatus( $status, res.data.message, true );
					window.setTimeout( function () { window.location.reload(); }, 4000 );
				} else {
					setStatus( $status, ( res.data && res.data.message ) || i18n.error, false );
				}
			} ).fail( function () {
				// admin-ajax blocked (security firewall)? Fall back to a plain
				// form POST to admin-post.php.
				setStatus( $status, 'ارسال از راه دیگر…', true );
				$runForm.off( 'submit' );
				$runForm.get( 0 ).submit();
			} );
		} );
	}

	/* ---------------------------------------------------------------- *
	 *  Auto-refresh the jobs table while work is in flight.
	 * ---------------------------------------------------------------- */
	var $jobsTable = $( '#shp-jobs-table' );
	if ( $jobsTable.length ) {
		var activeStatuses = /queued|processing|retry|awaiting_runner/;

		// Never reload while the operator is typing — a run queue that is
		// almost always "processing" was reloading this page every 8s and
		// wiping whatever URL they were pasting into the form.
		function userIsTyping() {
			var el = document.activeElement;
			if ( ! el ) { return false; }
			var tag = el.tagName;
			return 'TEXTAREA' === tag || 'INPUT' === tag || 'SELECT' === tag || el.isContentEditable;
		}

		// A busy queue can stay "processing" for a long time; a plain in-memory
		// counter resets every reload and would loop forever. Cap it for real
		// across reloads with sessionStorage.
		var REFRESH_KEY = 'shp_runner_refreshes';
		var refreshes = parseInt( window.sessionStorage.getItem( REFRESH_KEY ) || '0', 10 ) || 0;

		var poll = window.setInterval( function () {
			if ( userIsTyping() || refreshes >= 20 ) { return; }
			var hasActive = false;
			$jobsTable.find( 'tbody .shp-badge' ).each( function () {
				if ( activeStatuses.test( $( this ).attr( 'class' ) || '' ) ) { hasActive = true; }
			} );
			if ( ! hasActive ) {
				window.sessionStorage.removeItem( REFRESH_KEY );
				window.clearInterval( poll );
				return;
			}
			refreshes++;
			window.sessionStorage.setItem( REFRESH_KEY, String( refreshes ) );
			window.location.reload();
		}, 20000 );
	}

	/* ---------------------------------------------------------------- *
	 *  Job row actions.
	 * ---------------------------------------------------------------- */
	$( '#shp-jobs-table' ).on( 'click', 'button[data-action]', function () {
		var $btn = $( this );
		var jobId = $btn.data( 'job' );
		var da = $btn.data( 'action' );
		var action = ( { retry: 'shp_retry_job', cancel: 'shp_cancel_job', run: 'shp_run_job' } )[ da ] || 'shp_run_job';

		if ( ( da === 'retry' || da === 'cancel' ) && ! window.confirm( i18n.confirm || 'OK?' ) ) {
			return;
		}

		$btn.prop( 'disabled', true ).text( i18n.submitting || '...' );

		post( action, { job_id: jobId } ).done( function () {
			window.location.reload();
		} ).fail( function () {
			$btn.prop( 'disabled', false ).text( i18n.error || 'error' );
		} );
	} );

	/* ---------------------------------------------------------------- *
	 *  Dashboard live stats.
	 * ---------------------------------------------------------------- */
	if ( $( '#shp-stat-cards' ).length ) {
		window.setInterval( function () {
			post( 'shp_dashboard_stats' ).done( function ( res ) {
				if ( ! res || ! res.success ) { return; }
				$( '[data-stat]' ).each( function () {
					var key = $( this ).data( 'stat' );
					if ( res.data[ key ] !== undefined ) {
						$( this ).text( Number( res.data[ key ] ).toLocaleString( 'fa-IR' ) );
					}
				} );
			} );
		}, 15000 );
	}

	/* ---------------------------------------------------------------- *
	 *  Logs table.
	 * ---------------------------------------------------------------- */
	var $logsBody = $( '#shp-logs-body' );
	if ( $logsBody.length ) {
		var page = 1;
		var autoTimer = null;

		function esc( str ) {
			return $( '<div>' ).text( str == null ? '' : str ).html();
		}

		function renderRows( items ) {
			if ( ! items.length ) {
				$logsBody.html( '<tr><td colspan="5" class="shp-muted">—</td></tr>' );
				return;
			}
			var html = items.map( function ( row ) {
				var ctx = row.context ? '<pre>' + esc( row.context ) + '</pre>' : '';
				var url = row.source_url ? '<div><a href="' + esc( row.source_url ) + '" target="_blank" rel="noreferrer">' + esc( row.source_url ) + '</a></div>' : '';
				return '<tr>' +
					'<td dir="ltr">' + esc( row.created_at ) + '</td>' +
					'<td><span class="shp-lvl shp-lvl--' + esc( row.level ) + '">' + esc( row.level ) + '</span></td>' +
					'<td>' + esc( row.channel ) + '</td>' +
					'<td>' + esc( row.message ) + url + '</td>' +
					'<td>' + ctx + '</td>' +
				'</tr>';
			} ).join( '' );
			$logsBody.html( html );
		}

		function load() {
			post( 'shp_fetch_logs', {
				level: $( '#shp-log-level' ).val(),
				channel: $( '#shp-log-channel' ).val(),
				search: $( '#shp-log-search' ).val(),
				job_id: $( '#shp-log-job' ).val() || 0,
				paged: page
			} ).done( function ( res ) {
				if ( res && res.success ) {
					renderRows( res.data.items );
					var pages = Math.max( 1, Math.ceil( res.data.total / 25 ) );
					$( '#shp-log-pageinfo' ).text( page + ' / ' + pages );
				}
			} );
		}

		$( '#shp-log-refresh' ).on( 'click', function () { page = 1; load(); } );
		$( '#shp-log-level, #shp-log-channel' ).on( 'change', function () { page = 1; load(); } );
		$( '#shp-log-search, #shp-log-job' ).on( 'keyup', function ( e ) {
			if ( e.key === 'Enter' ) { page = 1; load(); }
		} );
		$( '#shp-log-prev' ).on( 'click', function () { if ( page > 1 ) { page--; load(); } } );
		$( '#shp-log-next' ).on( 'click', function () { page++; load(); } );
		$( '#shp-log-auto' ).on( 'change', function () {
			if ( this.checked ) {
				autoTimer = window.setInterval( load, 5000 );
			} else if ( autoTimer ) {
				window.clearInterval( autoTimer );
			}
		} );

		load();
	}

	/* ---------------------------------------------------------------- *
	 *  Settings form.
	 * ---------------------------------------------------------------- */
	var $settingsForm = $( '#shp-settings-form' );
	if ( $settingsForm.length ) {
		$settingsForm.on( 'submit', function ( e ) {
			e.preventDefault();
			var $status = $( '#shp-settings-status' );
			setStatus( $status, i18n.submitting || '...', true );

			var data = {};
			$.each( $settingsForm.serializeArray(), function ( _, item ) {
				var m = item.name.match( /^settings\[(.+)\]$/ );
				if ( m ) { data[ 'settings[' + m[ 1 ] + ']' ] = item.value; }
			} );
			// Unchecked checkboxes need explicit false values.
			$settingsForm.find( 'input[type=checkbox]' ).each( function () {
				var m = this.name.match( /^settings\[(.+)\]$/ );
				if ( m && ! this.checked ) { data[ 'settings[' + m[ 1 ] + ']' ] = ''; }
			} );

			$.post( api, $.extend( { action: 'shp_save_settings', nonce: nonce }, flatten( data ) ) )
				.done( function ( res ) {
					if ( res && res.success ) {
						setStatus( $status, res.data.message, true );
					} else {
						setStatus( $status, ( res.data && res.data.message ) || i18n.error, false );
					}
				} )
				.fail( function () { setStatus( $status, i18n.error || 'error', false ); } );
		} );

		// Convert "settings[key]" keys into a nested object jQuery can post.
		function flatten( data ) {
			var out = {};
			$.each( data, function ( key, value ) {
				out[ key ] = value;
			} );
			return out;
		}
	}

	/* ---------------------------------------------------------------- *
	 *  System report page.
	 * ---------------------------------------------------------------- */
	var $reportText = $( '#shp-report-text' );
	if ( $reportText.length ) {
		$( '#shp-copy-report' ).on( 'click', function () {
			var $s = $( '#shp-report-status' );
			$reportText.trigger( 'focus' ).trigger( 'select' );
			var ok = false;
			try { ok = document.execCommand( 'copy' ); } catch ( e ) { ok = false; }
			if ( ! ok && navigator.clipboard ) {
				navigator.clipboard.writeText( $reportText.val() ).then( function () {
					setStatus( $s, 'کپی شد.', true );
				} );
				return;
			}
			setStatus( $s, ok ? 'کپی شد.' : 'کپی نشد؛ دستی انتخاب کنید.', ok );
		} );
	}

	$( 'body' ).on( 'click', '#shp-ai-test', function () {
		var $btn = $( this );
		var $s = $( '#shp-ai-test-status' );
		function val( name ) { return $( '[name="settings[' + name + ']"]' ).val() || ''; }
		$btn.prop( 'disabled', true );
		setStatus( $s, i18n.submitting || '...', true );
		post( 'shp_ai_test', {
			ai_provider: val( 'ai_provider' ),
			ai_model: val( 'ai_model' ),
			openai_api_key: val( 'openai_api_key' ),
			gapgpt_api_key: val( 'gapgpt_api_key' ),
			gapgpt_base_url: val( 'gapgpt_base_url' ),
			gemini_api_key: val( 'gemini_api_key' ),
			perplexity_api_key: val( 'perplexity_api_key' )
		} )
			.done( function ( res ) {
				var d = res && res.data ? res.data : {};
				setStatus( $s, d.message || i18n.error, !! d.ok );
			} )
			.fail( function () { setStatus( $s, i18n.error || 'error', false ); } )
			.always( function () { $btn.prop( 'disabled', false ); } );
	} );

	$( 'body' ).on( 'click', '#shp-net-run', function () {
		var $btn = $( this );
		var $box = $( '#shp-net-results' );
		$btn.prop( 'disabled', true );
		$box.text( '...' );
		post( 'shp_network_test', { url: $( '#shp-net-url' ).val() } )
			.done( function ( res ) {
				var rows = ( res && res.data && res.data.results ) || [];
				$box.empty();
				rows.forEach( function ( r ) {
					$( '<div/>' )
						.addClass( r.ok ? 'ok' : 'bad' )
						.text( ( r.ok ? '[OK]  ' : '[FAIL] ' ) + r.url + '  —  ' + r.info + '  (' + r.ms + 'ms)' )
						.appendTo( $box );
				} );
			} )
			.fail( function () { $box.text( i18n.error || 'error' ); } )
			.always( function () { $btn.prop( 'disabled', false ); } );
	} );

	$( 'body' ).on( 'click', '#shp-run-queue', function () {
		var $btn = $( this );
		var $s = $( '#shp-report-status' );
		$btn.prop( 'disabled', true );
		setStatus( $s, i18n.submitting || '...', true );
		post( 'shp_process_queue', {} )
			.done( function ( res ) {
				setStatus( $s, ( res && res.data && res.data.message ) || i18n.done, true );
				window.setTimeout( function () { window.location.reload(); }, 1500 );
			} )
			.fail( function () { setStatus( $s, i18n.error || 'error', false ); $btn.prop( 'disabled', false ); } );
	} );

	/* ---------------------------------------------------------------- *
	 *  Publish all drafts for a store.
	 * ---------------------------------------------------------------- */
	$( 'body' ).on( 'click', 'button[data-action="publish-store"], button[data-action="delete-store-products"]', function () {
		var $btn = $( this );
		var action = $btn.data( 'action' ) === 'delete-store-products' ? 'shp_delete_store_products' : 'shp_publish_store_drafts';
		if ( ! window.confirm( i18n.confirm || 'OK?' ) ) { return; }
		$btn.prop( 'disabled', true ).text( i18n.submitting || '...' );
		post( action, { store_id: $btn.data( 'store' ) } )
			.done( function ( res ) {
				window.alert( ( res && res.data && res.data.message ) || i18n.done );
				window.location.reload();
			} )
			.fail( function () {
				$btn.prop( 'disabled', false ).text( i18n.error || 'error' );
			} );
	} );

	/* The "all stores" batch buttons (publish-all / seo / purge listings) are
	 * handled by an inline script in the stores view so an asset optimiser that
	 * mangles or stale-caches this file cannot disable them. */

	/* ---------------------------------------------------------------- *
	 *  Category mapping table.
	 * ---------------------------------------------------------------- */
	var $mapTable = $( '#shp-map-table' );
	if ( $mapTable.length ) {
		function newRow() {
			return $(
				'<tr>' +
					'<td><input type="text" class="shp-map-source" value="" /></td>' +
					'<td><input type="text" class="shp-map-target" value="" /></td>' +
					'<td><button type="button" class="shp-btn shp-btn--small shp-btn--ghost shp-map-del">&times;</button></td>' +
				'</tr>'
			);
		}

		$( '#shp-map-add' ).on( 'click', function () {
			$mapTable.find( 'tbody' ).append( newRow() );
		} );

		$mapTable.on( 'click', '.shp-map-del', function () {
			$( this ).closest( 'tr' ).remove();
		} );

		$( '#shp-map-save' ).on( 'click', function () {
			var $status = $( '#shp-map-status' );
			var pairs = [];
			$mapTable.find( 'tbody tr' ).each( function () {
				var source = $.trim( $( this ).find( '.shp-map-source' ).val() );
				var target = $.trim( $( this ).find( '.shp-map-target' ).val() );
				if ( source ) { pairs.push( { source: source, target: target } ); }
			} );

			setStatus( $status, i18n.submitting || '...', true );
			post( 'shp_save_mappings', { map: JSON.stringify( pairs ) } )
				.done( function ( res ) {
					if ( res && res.success ) {
						setStatus( $status, res.data.message, true );
					} else {
						setStatus( $status, ( res.data && res.data.message ) || i18n.error, false );
					}
				} )
				.fail( function () { setStatus( $status, i18n.error || 'error', false ); } );
		} );
	}

} )( jQuery );
