( function () {
	'use strict';

	var cfg = window.forwpSeoInventoryGsc;
	if ( ! cfg ) {
		return;
	}

	function escHtml( text ) {
		var div = document.createElement( 'div' );
		div.textContent = text == null ? '' : String( text );
		return div.innerHTML;
	}

	function escAttr( text ) {
		return escHtml( text ).replace( /"/g, '&quot;' );
	}

	function formatDateTime( value, isUnix ) {
		var date;
		if ( isUnix ) {
			var ts = parseInt( value, 10 );
			if ( ! ts ) {
				return '—';
			}
			date = new Date( ts * 1000 );
		} else {
			if ( ! value ) {
				return '—';
			}
			date = new Date( value );
		}
		if ( Number.isNaN( date.getTime() ) ) {
			return String( value || '—' );
		}
		return date.toLocaleString( undefined, {
			day: '2-digit',
			month: '2-digit',
			year: 'numeric',
			hour: '2-digit',
			minute: '2-digit',
		} );
	}

	function coverageTone( coverage, verdict ) {
		var text = ( coverage || '' ) + ' ' + ( verdict || '' );
		if ( /indexed/i.test( text ) && ! /not indexed|excluded/i.test( text ) ) {
			return 'good';
		}
		if ( /not indexed|excluded|error|fail/i.test( text ) ) {
			return 'bad';
		}
		return 'ok';
	}

	function renderIndexStatus( inspect ) {
		var coverage = inspect.coverage || '';
		var verdict = inspect.verdict || '';
		var error = inspect.error || '';
		var link = inspect.inspectLink || '';

		function iconHtml( tone, title, dashicon ) {
			var html = '<span class="forwp-seo-index-icon forwp-seo-index-icon--' + escAttr( tone ) +
				'" title="' + escAttr( title ) + '" aria-label="' + escAttr( title ) + '">' +
				'<span class="dashicons ' + escAttr( dashicon ) + '" aria-hidden="true"></span>' +
				'</span>';
			if ( link ) {
				html = '<a href="' + escAttr( link ) + '" target="_blank" rel="noopener noreferrer" class="forwp-seo-index-icon-link">' +
					html + '</a>';
			}
			return html;
		}

		if ( error && ! coverage && ! verdict ) {
			return iconHtml( 'bad', error || cfg.i18n.inspectError, 'dashicons-dismiss' );
		}

		var label = coverage || verdict;
		if ( ! label ) {
			return '<span class="forwp-seo-index-icon forwp-seo-index-icon--na" title="' +
				escAttr( cfg.i18n.notInspected || 'Not inspected yet' ) + '">' +
				'<span class="dashicons dashicons-minus" aria-hidden="true"></span></span>';
		}

		var tone = coverageTone( coverage, verdict );
		var dashicon = 'good' === tone ? 'dashicons-yes-alt' : ( 'bad' === tone ? 'dashicons-dismiss' : 'dashicons-warning' );
		return iconHtml( tone, label, dashicon );
	}

	function updateRowFromResponse( row, response ) {
		var inspect = response.inspect || {};
		var statusCell = row.querySelector( '.column-gsc_index_status' );
		var requestedCell = row.querySelector( '.column-gsc_index_requested' );
		var crawlCell = row.querySelector( '.column-gsc_last_crawl' );

		if ( statusCell ) {
			statusCell.innerHTML = renderIndexStatus( inspect );
		}
		if ( requestedCell ) {
			requestedCell.textContent = formatDateTime( response.requestedAt, true );
		}
		if ( crawlCell ) {
			crawlCell.textContent = formatDateTime( inspect.lastCrawl, false );
		}
	}

	function setRowBusy( row, busy ) {
		if ( ! row ) {
			return;
		}
		row.classList.toggle( 'is-gsc-busy', !! busy );
		row.querySelectorAll( '.forwp-seo-gsc-actions .button' ).forEach( function ( button ) {
			if ( busy ) {
				button.disabled = true;
				return;
			}
			button.disabled = button.getAttribute( 'aria-disabled' ) === 'true';
		} );
	}

	function setButtonLabel( button, label ) {
		if ( button ) {
			button.textContent = label;
		}
	}

	function restoreButtonLabels( row ) {
		if ( ! row ) {
			return;
		}
		var refresh = row.querySelector( '.forwp-seo-gsc-refresh' );
		var request = row.querySelector( '.forwp-seo-gsc-request-index' );
		setButtonLabel( refresh, cfg.i18n.refresh );
		setButtonLabel( request, cfg.i18n.requestIndex );
	}

	function apiRequest( path, method, body ) {
		var options = {
			method: method || 'GET',
			headers: {
				'X-WP-Nonce': cfg.nonce,
			},
			credentials: 'same-origin',
		};
		if ( body ) {
			options.headers['Content-Type'] = 'application/json';
			options.body = JSON.stringify( body );
		}
		return fetch( cfg.restRoot + path, options ).then( function ( response ) {
			return response.json().then( function ( data ) {
				if ( ! response.ok ) {
					var err = new Error( data && data.message ? data.message : cfg.i18n.error );
					err.response = data;
					throw err;
				}
				return data;
			} );
		} );
	}

	function objectQuery( button ) {
		var termId = button.getAttribute( 'data-term-id' );
		if ( termId ) {
			return 'term_id=' + encodeURIComponent( termId );
		}
		return 'post_id=' + encodeURIComponent( button.getAttribute( 'data-post-id' ) || '' );
	}

	function objectBody( button ) {
		var termId = button.getAttribute( 'data-term-id' );
		if ( termId ) {
			return { term_id: parseInt( termId, 10 ) };
		}
		return { post_id: parseInt( button.getAttribute( 'data-post-id' ), 10 ) };
	}

	function handleRefresh( row, button ) {
		if ( ! button.getAttribute( 'data-post-id' ) && ! button.getAttribute( 'data-term-id' ) ) {
			return;
		}

		setRowBusy( row, true );
		setButtonLabel( button, cfg.i18n.refreshing );

		apiRequest( 'gsc/post-index?' + objectQuery( button ) + '&inspect=1' )
			.then( function ( response ) {
				if ( ! response.ready && response.message ) {
					window.alert( response.message );
					return;
				}
				updateRowFromResponse( row, response );
			} )
			.catch( function ( err ) {
				window.alert( err.message || cfg.i18n.error );
			} )
			.finally( function () {
				setRowBusy( row, false );
				restoreButtonLabels( row );
			} );
	}

	function handleRequestIndex( row, button ) {
		if ( ! button.getAttribute( 'data-post-id' ) && ! button.getAttribute( 'data-term-id' ) ) {
			return;
		}

		setRowBusy( row, true );
		setButtonLabel( button, cfg.i18n.requesting );

		apiRequest( 'gsc/request-index', 'POST', objectBody( button ) )
			.then( function ( response ) {
				if ( ! response.ready && response.message ) {
					window.alert( response.message );
					return;
				}
				updateRowFromResponse( row, response );
				var inspectUrl = response.gscInspectUrl || ( response.inspect && response.inspect.inspectLink ) || '';
				if ( inspectUrl ) {
					window.open( inspectUrl, '_blank', 'noopener,noreferrer' );
				}
			} )
			.catch( function ( err ) {
				window.alert( err.message || cfg.i18n.error );
			} )
			.finally( function () {
				setRowBusy( row, false );
				restoreButtonLabels( row );
			} );
	}

	document.addEventListener( 'click', function ( event ) {
		var target = event.target;
		while ( target && target.nodeType !== 1 ) {
			target = target.parentElement;
		}
		if ( ! target ) {
			return;
		}

		var refreshBtn = target.closest( '.forwp-seo-gsc-refresh' );
		var requestBtn = target.closest( '.forwp-seo-gsc-request-index' );
		if ( ! refreshBtn && ! requestBtn ) {
			return;
		}

		var button = refreshBtn || requestBtn;
		if ( button.disabled || button.getAttribute( 'aria-disabled' ) === 'true' ) {
			return;
		}

		event.preventDefault();

		var row = button.closest( 'tr.forwp-seo-inventory-row' );
		if ( ! row ) {
			return;
		}

		if ( refreshBtn ) {
			handleRefresh( row, refreshBtn );
		} else {
			handleRequestIndex( row, requestBtn );
		}
	} );
}() );
