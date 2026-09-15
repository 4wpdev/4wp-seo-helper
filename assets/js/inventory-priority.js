( function () {
	'use strict';

	var cfg = window.forwpSeoInventoryPriority;
	if ( ! cfg ) {
		return;
	}

	var dragEl = null;
	var saving = false;
	var board = document.getElementById( 'forwp-seo-priority-board' );
	var table = document.querySelector( '.forwp-seo-inventory table.widefat' );

	function setStatus( message, state ) {
		var statusEl = document.querySelector( '.forwp-seo-inventory__drag-status' );
		if ( ! statusEl ) {
			return;
		}
		statusEl.textContent = message || '';
		statusEl.className = 'forwp-seo-inventory__drag-status' + ( state ? ' is-' + state : '' );
	}

	function getColspan() {
		if ( ! table ) {
			return 1;
		}
		var header = table.querySelector( '.forwp-seo-priority-group td[colspan]' );
		if ( header ) {
			return parseInt( header.getAttribute( 'colspan' ), 10 ) || 1;
		}
		return table.querySelectorAll( 'thead th' ).length || 1;
	}

	function countRowsInGroup( priority ) {
		if ( ! table ) {
			return 0;
		}
		return table.querySelectorAll( '.forwp-seo-inventory-row[data-priority-group="' + priority + '"]' ).length;
	}

	function collectVisibleIds() {
		var ids = [];
		if ( ! table ) {
			return ids;
		}
		table.querySelectorAll( '.forwp-seo-inventory-row[data-post-id]' ).forEach( function ( row ) {
			var id = parseInt( row.getAttribute( 'data-post-id' ), 10 );
			if ( id ) {
				ids.push( id );
			}
		} );
		return ids;
	}

	function collectLanesFromTable() {
		var lanes = { '1': [], '2': [], '3': [] };
		if ( ! table ) {
			return lanes;
		}
		var currentPriority = null;
		table.querySelectorAll( 'tbody tr' ).forEach( function ( row ) {
			if ( row.classList.contains( 'forwp-seo-priority-group' ) ) {
				currentPriority = row.getAttribute( 'data-priority' );
				return;
			}
			if ( ! row.classList.contains( 'forwp-seo-inventory-row' ) ) {
				return;
			}
			var postId = parseInt( row.getAttribute( 'data-post-id' ), 10 );
			if ( ! postId ) {
				return;
			}
			if ( currentPriority === '1' || currentPriority === '2' || currentPriority === '3' ) {
				lanes[ currentPriority ].push( postId );
			}
		} );
		return lanes;
	}

	function updateRowPriorityBadge( row, priority ) {
		var titleCell = row.querySelector( '.column-wp_title' );
		if ( ! titleCell ) {
			return;
		}

		var existing = titleCell.querySelector( '.forwp-seo-priority-badge' );
		if ( existing ) {
			existing.remove();
		}

		if ( priority === '1' || priority === '2' || priority === '3' ) {
			var badge = document.createElement( 'span' );
			badge.className = 'forwp-seo-priority-badge forwp-seo-priority-badge--p' + priority;
			badge.textContent = 'P' + priority;
			if ( cfg.priorityLabels && cfg.priorityLabels[ priority ] ) {
				badge.title = cfg.priorityLabels[ priority ];
			}
			var drag = titleCell.querySelector( '.forwp-seo-row-drag' );
			if ( drag ) {
				drag.insertAdjacentElement( 'afterend', badge );
				badge.insertAdjacentText( 'afterend', ' ' );
			} else {
				titleCell.insertBefore( badge, titleCell.firstChild );
			}
			row.classList.add( 'forwp-seo-inventory-row--queued' );
		} else {
			row.classList.remove( 'forwp-seo-inventory-row--queued' );
		}
	}

	function bindDropTarget( target ) {
		target.addEventListener( 'dragover', onDragOver );
		target.addEventListener( 'dragleave', onDragLeave );
		target.addEventListener( 'drop', target.classList.contains( 'forwp-seo-priority-group-empty' ) ? onEmptyDrop : onGroupDrop );
	}

	function ensureEmptyPlaceholder( priority ) {
		if ( ! table ) {
			return;
		}

		var tbody = table.querySelector( 'tbody' );
		if ( ! tbody ) {
			return;
		}

		var header = tbody.querySelector( '.forwp-seo-priority-group[data-priority="' + priority + '"]' );
		if ( ! header ) {
			return;
		}

		var count = countRowsInGroup( priority );
		var empty = tbody.querySelector( '.forwp-seo-priority-group-empty[data-priority-group="' + priority + '"]' );

		if ( count === 0 && ! empty ) {
			var row = document.createElement( 'tr' );
			row.className = 'forwp-seo-priority-group-empty';
			row.setAttribute( 'data-priority-group', priority );
			row.setAttribute( 'data-dropzone', '1' );

			var cell = document.createElement( 'td' );
			cell.colSpan = getColspan();
			cell.textContent = cfg.i18n.emptyGroup || 'No items — drop here';
			row.appendChild( cell );

			var insertBefore = null;
			var scan = header.nextElementSibling;
			while ( scan && ! scan.classList.contains( 'forwp-seo-priority-group' ) ) {
				insertBefore = scan.nextElementSibling;
				if ( scan.classList.contains( 'forwp-seo-priority-group-empty' ) ) {
					return;
				}
				scan = scan.nextElementSibling;
			}

			if ( insertBefore ) {
				tbody.insertBefore( row, insertBefore );
			} else {
				var last = header;
				scan = header.nextElementSibling;
				while ( scan && ! scan.classList.contains( 'forwp-seo-priority-group' ) ) {
					last = scan;
					scan = scan.nextElementSibling;
				}
				if ( last.nextSibling ) {
					tbody.insertBefore( row, last.nextSibling );
				} else {
					tbody.appendChild( row );
				}
			}

			bindDropTarget( row );
		} else if ( count > 0 && empty ) {
			empty.remove();
		}
	}

	function syncGroupCounts() {
		if ( ! table ) {
			return;
		}
		[ '0', '1', '2', '3' ].forEach( function ( priority ) {
			var header = table.querySelector( '.forwp-seo-priority-group[data-priority="' + priority + '"]' );
			if ( ! header ) {
				return;
			}
			var countEl = header.querySelector( '.forwp-seo-priority-group__count' );
			if ( countEl ) {
				countEl.textContent = String( countRowsInGroup( priority ) );
			}
		} );
	}

	function computeGroupStats( priority ) {
		var rows = table.querySelectorAll( '.forwp-seo-inventory-row[data-priority-group="' + priority + '"]' );
		var sum = 0;
		var gaps = 0;

		rows.forEach( function ( row ) {
			sum += getRowScore( row );
			var missingCell = row.querySelector( '.column-missing' );
			var missingText = missingCell ? missingCell.textContent.trim() : '';
			if ( missingText && missingText !== '—' ) {
				gaps += 1;
			}
		} );

		return {
			count: rows.length,
			avg: rows.length ? Math.round( sum / rows.length ) : 0,
			gaps: gaps,
		};
	}

	function syncGroupStats() {
		if ( ! table ) {
			return;
		}

		[ '0', '1', '2', '3' ].forEach( function ( priority ) {
			var header = table.querySelector( '.forwp-seo-priority-group[data-priority="' + priority + '"]' );
			if ( ! header ) {
				return;
			}

			var stats = computeGroupStats( priority );
			var avgEl = header.querySelector( '.forwp-seo-priority-group__avg' );
			var gapsEl = header.querySelector( '.forwp-seo-priority-group__gaps' );

			if ( avgEl ) {
				avgEl.textContent = stats.count
					? ( cfg.i18n.avgScore || 'Avg %d%%' ).replace( '%d', String( stats.avg ) )
					: '';
				avgEl.hidden = stats.count === 0;
			}

			if ( gapsEl ) {
				if ( stats.gaps > 0 ) {
					gapsEl.textContent = ( cfg.i18n.withGaps || '%d with gaps' ).replace( '%d', String( stats.gaps ) );
					gapsEl.hidden = false;
				} else {
					gapsEl.textContent = '';
					gapsEl.hidden = true;
				}
			}
		} );
	}

	function getRowTitle( row ) {
		var link = row.querySelector( '.column-wp_title strong' );
		return link ? link.textContent.trim() : '';
	}

	function truncateTitle( title, max ) {
		max = max || 24;
		if ( title.length <= max ) {
			return title;
		}
		return title.slice( 0, max ) + '…';
	}

	function getRowScore( row ) {
		var scoreEl = row.querySelector( '.column-completeness .forwp-seo-score' );
		if ( ! scoreEl ) {
			return 0;
		}
		return parseInt( scoreEl.textContent, 10 ) || 0;
	}

	function getScoreClass( score ) {
		if ( score >= 71 ) {
			return 'good';
		}
		if ( score >= 41 ) {
			return 'medium';
		}
		return 'low';
	}

	function buildPreviewChip( title, score, postId ) {
		var chip = document.createElement( 'div' );
		chip.className = 'forwp-seo-priority-chip forwp-seo-priority-chip--preview';
		if ( postId ) {
			chip.setAttribute( 'data-post-id', String( postId ) );
		}

		var titleEl = document.createElement( 'span' );
		titleEl.className = 'forwp-seo-priority-chip__title';
		titleEl.title = title;
		titleEl.textContent = truncateTitle( title );

		var scoreEl = document.createElement( 'span' );
		scoreEl.className = 'forwp-seo-priority-chip__score forwp-seo-priority-chip__score--' + getScoreClass( score );
		scoreEl.textContent = String( score ) + '%';

		chip.appendChild( titleEl );
		chip.appendChild( scoreEl );
		return chip;
	}

	function updateCompactPanels() {
		if ( ! board || ! table ) {
			return;
		}

		[ '1', '2', '3' ].forEach( function ( priority ) {
			var lane = board.querySelector( '.forwp-seo-priority-lane--p' + priority );
			if ( ! lane ) {
				return;
			}

			var rows = table.querySelectorAll( '.forwp-seo-inventory-row[data-priority-group="' + priority + '"]' );
			var count = rows.length;
			var meta = lane.querySelector( '.forwp-seo-priority-lane__meta' );
			if ( meta ) {
				meta.textContent = count === 1 ? ( cfg.i18n.oneItem || '1 item' ) : String( count ) + ' ' + ( cfg.i18n.items || 'items' );
			}

			var miniList = lane.querySelector( '.forwp-seo-priority-lane__mini-list' );
			if ( ! miniList ) {
				return;
			}

			miniList.innerHTML = '';
			if ( count === 0 ) {
				var placeholder = document.createElement( 'span' );
				placeholder.className = 'forwp-seo-priority-lane__placeholder';
				placeholder.textContent = cfg.i18n.emptyLane || 'Empty';
				miniList.appendChild( placeholder );
				return;
			}

			Array.prototype.slice.call( rows, 0, 3 ).forEach( function ( row ) {
				var postId = parseInt( row.getAttribute( 'data-post-id' ), 10 );
				miniList.appendChild(
					buildPreviewChip( getRowTitle( row ), getRowScore( row ), postId )
				);
			} );

			if ( count > 3 ) {
				var more = document.createElement( 'a' );
				more.className = 'forwp-seo-priority-lane__more';
				more.href = lane.getAttribute( 'data-panel-url' ) || '#';
				more.textContent = '+' + ( count - 3 ) + ' ' + ( cfg.i18n.more || 'more' );
				miniList.appendChild( more );
			}
		} );
	}

	function syncUiAfterSave() {
		if ( ! table ) {
			return;
		}

		table.querySelectorAll( '.forwp-seo-inventory-row' ).forEach( function ( row ) {
			var priority = row.getAttribute( 'data-priority' ) || '0';
			updateRowPriorityBadge( row, priority );
		} );

		[ '0', '1', '2', '3' ].forEach( ensureEmptyPlaceholder );
		syncGroupCounts();
		syncGroupStats();
		updateCompactPanels();
		setStatus( cfg.i18n.saved || 'Saved.', 'saved' );
		window.setTimeout( function () {
			setStatus( '', '' );
		}, 2000 );
	}

	function saveLanes() {
		if ( saving ) {
			return Promise.resolve();
		}

		saving = true;
		setStatus( cfg.i18n.saving, 'saving' );

		return fetch( cfg.restUrl, {
			method: 'PUT',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce,
			},
			body: JSON.stringify( {
				lanes: collectLanesFromTable(),
				visible_ids: collectVisibleIds(),
			} ),
			credentials: 'same-origin',
		} )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					return { ok: response.ok, data: data };
				} );
			} )
			.then( function ( result ) {
				if ( ! result.ok ) {
					throw new Error( ( result.data && result.data.message ) || cfg.i18n.error );
				}
				syncUiAfterSave();
			} )
			.catch( function ( error ) {
				setStatus( error.message || cfg.i18n.error, 'error' );
			} )
			.finally( function () {
				saving = false;
			} );
	}

	function clearDragOver() {
		document.querySelectorAll( '.is-drag-over' ).forEach( function ( node ) {
			node.classList.remove( 'is-drag-over' );
		} );
	}

	function onDragStart( event ) {
		var el = event.currentTarget;
		if ( event.target && event.target.closest( 'a, button, input, textarea, select' ) ) {
			event.preventDefault();
			return;
		}
		dragEl = el;
		el.classList.add( 'is-dragging' );
		if ( event.dataTransfer ) {
			event.dataTransfer.effectAllowed = 'move';
			event.dataTransfer.setData( 'text/plain', el.getAttribute( 'data-post-id' ) || '' );
		}
	}

	function onDragEnd() {
		if ( dragEl ) {
			dragEl.classList.remove( 'is-dragging' );
		}
		clearDragOver();
		dragEl = null;
	}

	function onDragOver( event ) {
		event.preventDefault();
		event.currentTarget.classList.add( 'is-drag-over' );
		if ( event.dataTransfer ) {
			event.dataTransfer.dropEffect = 'move';
		}
	}

	function onDragLeave( event ) {
		event.currentTarget.classList.remove( 'is-drag-over' );
	}

	function moveRowToGroup( row, priority ) {
		if ( ! table ) {
			return;
		}
		var tbody = table.querySelector( 'tbody' );
		if ( ! tbody ) {
			return;
		}
		var header = tbody.querySelector( '.forwp-seo-priority-group[data-priority="' + priority + '"]' );
		if ( ! header ) {
			return;
		}

		var emptyRow = tbody.querySelector( '.forwp-seo-priority-group-empty[data-priority-group="' + priority + '"]' );
		if ( emptyRow ) {
			tbody.insertBefore( row, emptyRow );
			emptyRow.remove();
		} else {
			var last = header;
			var next = header.nextElementSibling;
			while ( next && ! next.classList.contains( 'forwp-seo-priority-group' ) ) {
				if ( next.classList.contains( 'forwp-seo-inventory-row' ) ) {
					last = next;
				}
				next = next.nextElementSibling;
			}
			if ( last.nextSibling ) {
				tbody.insertBefore( row, last.nextSibling );
			} else {
				tbody.appendChild( row );
			}
		}

		row.setAttribute( 'data-priority', priority );
		row.setAttribute( 'data-priority-group', priority );
		updateRowPriorityBadge( row, priority );
	}

	function setGroupCollapsed( priority, collapsed ) {
		if ( ! table ) {
			return;
		}
		var header = table.querySelector( '.forwp-seo-priority-group[data-priority="' + priority + '"]' );
		if ( header ) {
			header.classList.toggle( 'is-collapsed', collapsed );
			header.setAttribute( 'aria-expanded', collapsed ? 'false' : 'true' );
		}
		table.querySelectorAll( '.forwp-seo-inventory-row[data-priority-group="' + priority + '"], .forwp-seo-priority-group-empty[data-priority-group="' + priority + '"]' ).forEach( function ( row ) {
			row.classList.toggle( 'is-collapsed', collapsed );
		} );
		try {
			var state = JSON.parse( window.sessionStorage.getItem( 'forwpSeoPriorityCollapse' ) || '{}' );
			state[ priority ] = collapsed;
			window.sessionStorage.setItem( 'forwpSeoPriorityCollapse', JSON.stringify( state ) );
		} catch ( ignore ) {}
	}

	function toggleGroupCollapsed( priority ) {
		var header = table.querySelector( '.forwp-seo-priority-group[data-priority="' + priority + '"]' );
		if ( ! header ) {
			return;
		}
		setGroupCollapsed( priority, ! header.classList.contains( 'is-collapsed' ) );
	}

	function initGroupCollapse() {
		if ( ! table ) {
			return;
		}
		var saved = {};
		try {
			saved = JSON.parse( window.sessionStorage.getItem( 'forwpSeoPriorityCollapse' ) || '{}' );
		} catch ( ignore ) {}

		[ '0', '1', '2', '3' ].forEach( function ( priority ) {
			if ( saved[ priority ] ) {
				setGroupCollapsed( priority, true );
			}
		} );

		table.querySelectorAll( '.forwp-seo-priority-group--collapsible' ).forEach( function ( header ) {
			header.addEventListener( 'click', function () {
				toggleGroupCollapsed( header.getAttribute( 'data-priority' ) );
			} );
			header.addEventListener( 'keydown', function ( event ) {
				if ( 'Enter' === event.key || ' ' === event.key ) {
					event.preventDefault();
					toggleGroupCollapsed( header.getAttribute( 'data-priority' ) );
				}
			} );
		} );
	}

	function onGroupDrop( event ) {
		event.preventDefault();
		event.currentTarget.classList.remove( 'is-drag-over' );
		if ( ! dragEl || ! table ) {
			return;
		}
		var priority = event.currentTarget.getAttribute( 'data-priority' );
		if ( null === priority ) {
			return;
		}
		var previousPriority = dragEl.getAttribute( 'data-priority-group' );
		moveRowToGroup( dragEl, priority );
		if ( previousPriority && previousPriority !== priority ) {
			ensureEmptyPlaceholder( previousPriority );
		}
		saveLanes();
	}

	function onEmptyDrop( event ) {
		event.preventDefault();
		var emptyRow = event.currentTarget;
		emptyRow.classList.remove( 'is-drag-over' );
		if ( ! dragEl || ! table ) {
			return;
		}
		var priority = emptyRow.getAttribute( 'data-priority-group' ) || '0';
		var previousPriority = dragEl.getAttribute( 'data-priority-group' );
		emptyRow.parentNode.insertBefore( dragEl, emptyRow );
		emptyRow.remove();
		dragEl.setAttribute( 'data-priority', priority );
		dragEl.setAttribute( 'data-priority-group', priority );
		updateRowPriorityBadge( dragEl, priority );
		if ( previousPriority && previousPriority !== priority ) {
			ensureEmptyPlaceholder( previousPriority );
		}
		saveLanes();
	}

	function onRowDrop( event ) {
		event.preventDefault();
		var targetRow = event.currentTarget;
		targetRow.classList.remove( 'is-drag-over' );
		if ( ! dragEl || dragEl === targetRow || ! table ) {
			return;
		}
		var priority = targetRow.getAttribute( 'data-priority' ) || '0';
		var previousPriority = dragEl.getAttribute( 'data-priority-group' );
		var rect = targetRow.getBoundingClientRect();
		var before = event.clientY < rect.top + rect.height / 2;
		if ( before ) {
			targetRow.parentNode.insertBefore( dragEl, targetRow );
		} else {
			targetRow.parentNode.insertBefore( dragEl, targetRow.nextSibling );
		}
		dragEl.setAttribute( 'data-priority', priority );
		dragEl.setAttribute( 'data-priority-group', priority );
		updateRowPriorityBadge( dragEl, priority );
		if ( previousPriority && previousPriority !== priority ) {
			ensureEmptyPlaceholder( previousPriority );
		}
		saveLanes();
	}

	function onLaneStripDrop( event ) {
		event.preventDefault();
		event.currentTarget.classList.remove( 'is-drag-over' );
		if ( ! dragEl ) {
			return;
		}
		var priority = event.currentTarget.getAttribute( 'data-priority' );
		if ( ! priority ) {
			return;
		}
		var previousPriority = dragEl.getAttribute( 'data-priority-group' );
		moveRowToGroup( dragEl, priority );
		if ( previousPriority && previousPriority !== priority ) {
			ensureEmptyPlaceholder( previousPriority );
		}
		saveLanes();
	}

	function initInventoryTable() {
		if ( ! table ) {
			return;
		}
		table.querySelectorAll( '.forwp-seo-inventory-row' ).forEach( function ( row ) {
			row.setAttribute( 'draggable', 'true' );
			row.addEventListener( 'dragstart', onDragStart );
			row.addEventListener( 'dragend', onDragEnd );
			row.addEventListener( 'dragover', onDragOver );
			row.addEventListener( 'dragleave', onDragLeave );
			row.addEventListener( 'drop', onRowDrop );
		} );
		table.querySelectorAll( '.forwp-seo-priority-group, .forwp-seo-priority-group-empty' ).forEach( bindDropTarget );
		if ( board ) {
			board.querySelectorAll( '.forwp-seo-priority-lane--strip' ).forEach( function ( lane ) {
				lane.addEventListener( 'dragover', onDragOver );
				lane.addEventListener( 'dragleave', onDragLeave );
				lane.addEventListener( 'drop', onLaneStripDrop );
			} );
		}
		initGroupCollapse();
	}

	function highlightInventoryRow() {
		var hash = window.location.hash;
		if ( ! hash || hash.indexOf( '#forwp-seo-row-' ) !== 0 ) {
			return;
		}
		var row = document.querySelector( hash );
		if ( ! row ) {
			return;
		}
		var priority = row.getAttribute( 'data-priority-group' );
		if ( null !== priority ) {
			setGroupCollapsed( priority, false );
		}
		row.classList.add( 'is-highlight' );
		row.scrollIntoView( { block: 'center', behavior: 'smooth' } );
	}

	initInventoryTable();
	highlightInventoryRow();
} )();
