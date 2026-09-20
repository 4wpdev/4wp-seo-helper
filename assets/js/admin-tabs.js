( function () {
	'use strict';

	function setActiveTab( shell, tabId ) {
		shell.querySelectorAll( '.forwp-seo-tab' ).forEach( function ( btn ) {
			var active = btn.getAttribute( 'data-tab' ) === tabId;
			btn.classList.toggle( 'is-active', active );
			btn.setAttribute( 'aria-selected', active ? 'true' : 'false' );
			btn.tabIndex = active ? 0 : -1;
		} );

		shell.querySelectorAll( '[role="tabpanel"]' ).forEach( function ( panel ) {
			var show = panel.id === 'forwp-seo-panel-' + tabId;
			panel.hidden = ! show;
		} );

		var rangeBar = shell.querySelector( '#forwp-seo-gsc-range-bar' );
		if ( rangeBar ) {
			rangeBar.hidden = tabId === 'sync' || tabId === 'inspection';
			var tabInput = rangeBar.querySelector( 'input[name="tab"]' );
			if ( tabInput ) {
				tabInput.value = tabId;
			}
		}

		if ( window.history && window.history.replaceState ) {
			var url = new URL( window.location.href );
			url.searchParams.set( 'tab', tabId );
			if ( tabId === 'sync' || tabId === 'inspection' ) {
				url.searchParams.delete( 'range' );
			}
			window.history.replaceState( {}, '', url.toString() );
		}
	}

	function initTabPanel( shell ) {
		shell.querySelectorAll( '.forwp-seo-tab' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var tab = btn.getAttribute( 'data-tab' );
				if ( tab ) {
					setActiveTab( shell, tab );
				}
			} );
		} );
	}

	function initRangeSelects() {
		document.querySelectorAll( '.forwp-seo-range-select' ).forEach( function ( select ) {
			var form = select.closest( 'form' );
			if ( form ) {
				var url = new URL( window.location.href );
				var tabInput = form.querySelector( 'input[name="tab"]' );
				if ( tabInput ) {
					tabInput.value = url.searchParams.get( 'tab' ) || tabInput.value || 'overview';
				}
			}

			select.addEventListener( 'change', function () {
				var url = new URL( window.location.href );
				url.searchParams.set( 'range', select.value );
				var tabInput = form ? form.querySelector( 'input[name="tab"]' ) : null;
				if ( tabInput && ! url.searchParams.get( 'tab' ) ) {
					url.searchParams.set( 'tab', tabInput.value || 'overview' );
				}
				var nonceInput = form ? form.querySelector( 'input[name="forwp_seo_gsc_range_nonce"]' ) : null;
				if ( nonceInput && nonceInput.value ) {
					url.searchParams.set( 'forwp_seo_gsc_range_nonce', nonceInput.value );
				} else {
					url.searchParams.delete( 'forwp_seo_gsc_range_nonce' );
				}
				url.searchParams.delete( 'orderby' );
				url.searchParams.delete( 'order' );
				window.location.assign( url.toString() );
			} );
		} );
	}

	function initUseSuggestedRedirect() {
		var btn = document.getElementById( 'forwp-seo-gsc-use-suggested-redirect' );
		var input = document.getElementById( 'forwp_seo_gsc_redirect_uri' );
		if ( ! btn || ! input ) {
			return;
		}

		btn.addEventListener( 'click', function () {
			var uri = btn.getAttribute( 'data-uri' ) || '';
			if ( uri ) {
				input.value = uri;
			}
		} );
	}

	function initProviderRegistry() {
		var wrap = document.getElementById( 'forwp-seo-provider-detail-wrap' );
		var intro = document.getElementById( 'forwp-seo-positions-intro' );
		var title = document.getElementById( 'forwp-seo-provider-detail-title' );
		var back = document.getElementById( 'forwp-seo-provider-back' );
		var openInput = document.getElementById( 'forwp_seo_open_provider' );
		var cards = document.querySelectorAll( '.forwp-seo-provider-card' );
		var details = wrap ? wrap.querySelectorAll( '.forwp-seo-provider-detail' ) : [];

		if ( ! wrap || ! cards.length ) {
			return;
		}

		function showOverview() {
			if ( intro ) {
				intro.hidden = false;
			}
			wrap.hidden = true;
			details.forEach( function ( detail ) {
				detail.hidden = true;
			} );
			cards.forEach( function ( card ) {
				card.classList.remove( 'is-selected' );
				card.setAttribute( 'aria-pressed', 'false' );
			} );
			if ( openInput ) {
				openInput.value = '';
			}
		}

		function openProvider( slug ) {
			if ( ! slug ) {
				showOverview();
				return;
			}

			var card = document.querySelector( '.forwp-seo-provider-card[data-provider="' + slug + '"]' );
			var detail = wrap.querySelector( '.forwp-seo-provider-detail[data-provider="' + slug + '"]' );
			if ( ! detail ) {
				return;
			}

			if ( intro ) {
				intro.hidden = true;
			}
			wrap.hidden = false;
			details.forEach( function ( panel ) {
				panel.hidden = panel !== detail;
			} );
			cards.forEach( function ( item ) {
				var on = item.getAttribute( 'data-provider' ) === slug;
				item.classList.toggle( 'is-selected', on );
				item.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			} );
			if ( title ) {
				title.textContent = ( card && card.getAttribute( 'data-label' ) ) || slug;
			}
			if ( openInput ) {
				openInput.value = slug;
			}
		}

		cards.forEach( function ( card ) {
			card.addEventListener( 'click', function () {
				openProvider( card.getAttribute( 'data-provider' ) );
			} );
			card.addEventListener( 'keydown', function ( event ) {
				if ( event.key === 'Enter' || event.key === ' ' ) {
					event.preventDefault();
					openProvider( card.getAttribute( 'data-provider' ) );
				}
			} );
		} );

		if ( back ) {
			back.addEventListener( 'click', showOverview );
		}

		openProvider( wrap.getAttribute( 'data-open-provider' ) || '' );
	}

	function initComboBoxes() {
		document.querySelectorAll( '[data-combo]' ).forEach( function ( combo ) {
			var search = combo.querySelector( '.forwp-seo-combo__search' );
			var hidden = combo.querySelector( 'input[type="hidden"]' );
			var list = combo.querySelector( '.forwp-seo-combo__list' );
			if ( ! search || ! hidden || ! list ) {
				return;
			}

			var items = Array.prototype.slice.call( list.querySelectorAll( 'li' ) );
			var selectedLabel = search.value;

			function setOpen( open ) {
				combo.classList.toggle( 'is-open', open );
				list.hidden = ! open;
				search.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			}

			function filter( query ) {
				var q = ( query || '' ).toLowerCase().trim();
				var first = null;
				items.forEach( function ( item ) {
					var hay = ( item.getAttribute( 'data-label' ) || item.textContent || '' ).toLowerCase();
					var show = ! q || hay.indexOf( q ) !== -1;
					item.classList.toggle( 'is-hidden', ! show );
					item.classList.remove( 'is-active' );
					if ( show && ! first ) {
						first = item;
					}
				} );
				if ( first ) {
					first.classList.add( 'is-active' );
				}
			}

			function choose( item ) {
				if ( ! item ) {
					return;
				}
				hidden.value = item.getAttribute( 'data-value' ) || '';
				selectedLabel = item.getAttribute( 'data-label' ) || item.textContent || '';
				search.value = selectedLabel;
				items.forEach( function ( row ) {
					row.setAttribute( 'aria-selected', row === item ? 'true' : 'false' );
				} );
				setOpen( false );
			}

			search.addEventListener( 'focus', function () {
				search.select();
				filter( '' );
				setOpen( true );
			} );

			search.addEventListener( 'input', function () {
				filter( search.value );
				setOpen( true );
			} );

			search.addEventListener( 'keydown', function ( event ) {
				var visible = items.filter( function ( item ) {
					return ! item.classList.contains( 'is-hidden' );
				} );
				var active = visible.filter( function ( item ) {
					return item.classList.contains( 'is-active' );
				} )[ 0 ];
				var index = visible.indexOf( active );

				if ( event.key === 'ArrowDown' ) {
					event.preventDefault();
					setOpen( true );
					var next = visible[ index + 1 ] || visible[ 0 ];
					visible.forEach( function ( item ) {
						item.classList.toggle( 'is-active', item === next );
					} );
					if ( next ) {
						next.scrollIntoView( { block: 'nearest' } );
					}
				} else if ( event.key === 'ArrowUp' ) {
					event.preventDefault();
					var prev = visible[ index - 1 ] || visible[ visible.length - 1 ];
					visible.forEach( function ( item ) {
						item.classList.toggle( 'is-active', item === prev );
					} );
					if ( prev ) {
						prev.scrollIntoView( { block: 'nearest' } );
					}
				} else if ( event.key === 'Enter' ) {
					event.preventDefault();
					choose( active || visible[ 0 ] );
				} else if ( event.key === 'Escape' ) {
					search.value = selectedLabel;
					setOpen( false );
				}
			} );

			items.forEach( function ( item ) {
				item.addEventListener( 'mousedown', function ( event ) {
					event.preventDefault();
					choose( item );
				} );
			} );

			document.addEventListener( 'click', function ( event ) {
				if ( ! combo.contains( event.target ) ) {
					if ( combo.classList.contains( 'is-open' ) ) {
						search.value = selectedLabel;
					}
					setOpen( false );
				}
			} );
		} );
	}

	function initPositionsDepth() {
		var master = document.querySelector( '.forwp-positions-depth-default' );
		var rows = document.querySelectorAll( '.forwp-positions-depth' );
		if ( ! master || ! rows.length ) {
			return;
		}

		function sync() {
			rows.forEach( function ( select ) {
				select.value = master.value;
			} );
		}

		master.addEventListener( 'change', sync );
		sync();
	}

	function initTabs() {
		document.querySelectorAll( '.forwp-seo-tab-panel' ).forEach( initTabPanel );
		initRangeSelects();
		initUseSuggestedRedirect();
		initProviderRegistry();
		initComboBoxes();
		initPositionsDepth();
	}

	document.addEventListener( 'DOMContentLoaded', initTabs );
} )();
