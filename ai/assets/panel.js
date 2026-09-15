/**
 * Shared AI field panel. Case config: window.forwpAiField.
 *
 * Mapping: slots[].id = JSON key, slots[].field = form selector.
 * lazy: resolve fields later via window.forwpAiPanel.bind({ root, entityId }).
 */
( function () {
	'use strict';

	const config = window.forwpAiField || {};
	if ( ! config.promptPath || ! config.generatePath || typeof wp === 'undefined' || ! wp.apiFetch ) {
		return;
	}

	const lazy = !! config.lazy;
	const i18n = config.i18n || {};
	const posKey = config.storageKey || 'forwpAiPanelPos';
	const sparkleSvg =
		'<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l1.6 4.9L18.5 9.5l-4.9 1.6L12 16l-1.6-4.9L5.5 9.5l4.9-1.6L12 3z"/><path d="M19 15l.7 2.1L22 18l-2.3.7L19 21l-.7-2.3L16 18l2.3-.9L19 15z"/><path d="M5 14l.6 1.8L7.5 16.5l-1.9.6L5 19l-.6-1.9L2.5 16.5l1.9-.7L5 14z"/></svg>';

	function resolveSlots( scope ) {
		const root = scope && scope.querySelector ? scope : document;
		const list = Array.isArray( config.slots ) ? config.slots : [];
		const slots = [];
		list.forEach( function ( slot ) {
			if ( ! slot || ! slot.id || ! slot.field ) {
				return;
			}
			const el = root.querySelector( slot.field );
			if ( ! el || ! ( 'value' in el ) ) {
				return;
			}
			slots.push( {
				id: String( slot.id ),
				field: String( slot.field ),
				label: slot.label ? String( slot.label ) : String( slot.id ),
				el: el,
			} );
		} );
		return slots;
	}

	let slots = lazy ? [] : resolveSlots( document );
	if ( ! lazy && ! slots.length ) {
		return;
	}

	function iconButton( extraClass, dashicon ) {
		return (
			'<button type="button" class="forwp-ai-icon ' +
			extraClass +
			'">' +
			'<span class="dashicons ' +
			dashicon +
			'" aria-hidden="true"></span>' +
			'</button>'
		);
	}

	function fieldBlock( fieldId, fieldClass, rows, actionsHtml ) {
		return (
			'<div class="forwp-ai-block">' +
				'<label class="forwp-ai-label" for="' +
				fieldId +
				'"></label>' +
				'<textarea id="' +
				fieldId +
				'" class="' +
				fieldClass +
				'" rows="' +
				String( rows ) +
				'"></textarea>' +
				'<div class="forwp-ai-actions">' +
				actionsHtml +
				'</div>' +
			'</div>'
		);
	}

	const openButton = document.createElement( 'button' );
	openButton.type = 'button';
	openButton.className = 'button forwp-ai-field-open';
	openButton.textContent = i18n.generate || 'Generate with AI';

	const root = document.createElement( 'div' );
	root.id = 'forwp-ai-root';
	root.innerHTML =
		'<div class="forwp-ai-fab-wrap">' +
			'<button type="button" class="forwp-ai-fab" aria-expanded="false">' +
				sparkleSvg +
			'</button>' +
		'</div>' +
		'<div class="forwp-ai-stack" hidden>' +
			'<div class="forwp-ai-panel">' +
				'<div class="forwp-ai-panel__head">' +
					'<span class="forwp-ai-panel__drag dashicons dashicons-move" aria-hidden="true"></span>' +
					'<h2 class="forwp-ai-panel__title"></h2>' +
					iconButton( 'forwp-ai-panel__close', 'dashicons-no-alt' ) +
				'</div>' +
				'<div class="forwp-ai-context" hidden></div>' +
				fieldBlock(
					'forwp-ai-prompt',
					'forwp-ai-prompt',
					5,
					iconButton( 'forwp-ai-send', 'dashicons-arrow-right-alt' ) +
						iconButton( 'forwp-ai-clear', 'dashicons-dismiss' ) +
						iconButton( 'forwp-ai-restore', 'dashicons-image-rotate' )
				) +
				'<div class="forwp-ai-slots" hidden></div>' +
				'<div class="forwp-ai-actions forwp-ai-slots-actions" hidden>' +
					'<button type="button" class="button button-small forwp-ai-apply-all"></button>' +
				'</div>' +
				fieldBlock(
					'forwp-ai-refine',
					'forwp-ai-refine',
					3,
					iconButton( 'forwp-ai-send-refine', 'dashicons-arrow-right-alt' )
				) +
				'<p class="forwp-ai-status" role="status"></p>' +
			'</div>' +
		'</div>';
	document.body.appendChild( root );

	const fabWrap = root.querySelector( '.forwp-ai-fab-wrap' );
	const fab = root.querySelector( '.forwp-ai-fab' );
	const stack = root.querySelector( '.forwp-ai-stack' );
	const head = root.querySelector( '.forwp-ai-panel__head' );
	const closeBtn = root.querySelector( '.forwp-ai-panel__close' );
	const contextEl = root.querySelector( '.forwp-ai-context' );
	const promptField = root.querySelector( '.forwp-ai-prompt' );
	const refineField = root.querySelector( '.forwp-ai-refine' );
	const sendBtn = root.querySelector( '.forwp-ai-send' );
	const clearBtn = root.querySelector( '.forwp-ai-clear' );
	const restoreBtn = root.querySelector( '.forwp-ai-restore' );
	const sendRefineBtn = root.querySelector( '.forwp-ai-send-refine' );
	const applyAllBtn = root.querySelector( '.forwp-ai-apply-all' );
	const slotsEl = root.querySelector( '.forwp-ai-slots' );
	const slotsActions = root.querySelector( '.forwp-ai-slots-actions' );
	const statusEl = root.querySelector( '.forwp-ai-status' );

	let promptLoaded = false;
	let busy = false;
	let drag = null;
	let lastFields = {};
	let promptPath = String( config.promptPath );
	let generatePath = String( config.generatePath );

	function contextModes() {
		return Array.isArray( config.contextModes ) ? config.contextModes : [];
	}

	function fillPath( path, id ) {
		return String( path || '' ).replace( /\{id\}/g, encodeURIComponent( String( id ) ) );
	}

	function currentContext() {
		const checked = contextEl.querySelector( 'input[name="forwp-ai-context"]:checked' );
		if ( checked && checked.value ) {
			return String( checked.value );
		}
		return config.defaultContext ? String( config.defaultContext ) : 'content';
	}

	function withContext( path ) {
		if ( ! contextModes().length ) {
			return path;
		}
		const sep = path.indexOf( '?' ) === -1 ? '?' : '&';
		return path + sep + 'context=' + encodeURIComponent( currentContext() );
	}

	function slotValueToText( value ) {
		if ( Array.isArray( value ) ) {
			return value.map( function ( item ) {
				return String( item );
			} ).join( '\n' );
		}
		return value == null ? '' : String( value );
	}

	function setStatus( text ) {
		if ( statusEl ) {
			statusEl.textContent = text || '';
		}
	}

	function clearResults() {
		lastFields = {};
		slotsEl.innerHTML = '';
		slotsEl.hidden = true;
		slotsActions.hidden = true;
	}

	function placeOpenButton() {
		if ( ! slots.length ) {
			if ( openButton.parentNode ) {
				openButton.parentNode.removeChild( openButton );
			}
			return;
		}
		const target = slots[ 0 ].el;
		const host = target.closest( 'label' ) || target;
		host.insertAdjacentElement( 'afterend', openButton );
	}

	function setChromeVisible( show ) {
		if ( show ) {
			fabWrap.removeAttribute( 'hidden' );
			return;
		}
		fabWrap.setAttribute( 'hidden', '' );
	}

	function busyControls() {
		return root.querySelectorAll( '.forwp-ai-icon, .forwp-ai-apply-all, .forwp-ai-fab, .forwp-ai-field-open' );
	}

	function setBusy( next, message ) {
		busy = !! next;
		busyControls().forEach( function ( btn ) {
			btn.disabled = busy;
		} );
		openButton.disabled = busy;
		if ( busy && message ) {
			setStatus( message );
		}
	}

	function isOpen() {
		return ! stack.hasAttribute( 'hidden' );
	}

	function clampPos( left, top ) {
		const width = stack.offsetWidth;
		const height = stack.offsetHeight;
		const maxLeft = Math.max( 8, window.innerWidth - width - 8 );
		const maxTop = Math.max( 8, window.innerHeight - height - 8 );
		return {
			left: Math.min( Math.max( 8, left ), maxLeft ),
			top: Math.min( Math.max( 8, top ), maxTop ),
		};
	}

	function applyPos( pos ) {
		stack.style.left = String( pos.left ) + 'px';
		stack.style.top = String( pos.top ) + 'px';
		stack.style.right = 'auto';
		stack.style.bottom = 'auto';
	}

	function readSavedPos() {
		try {
			const raw = window.sessionStorage.getItem( posKey );
			if ( ! raw ) {
				return null;
			}
			const pos = JSON.parse( raw );
			if ( typeof pos.left !== 'number' || typeof pos.top !== 'number' ) {
				return null;
			}
			return pos;
		} catch ( err ) {
			return null;
		}
	}

	function savePos() {
		const rect = stack.getBoundingClientRect();
		try {
			window.sessionStorage.setItem(
				posKey,
				JSON.stringify( {
					left: Math.round( rect.left ),
					top: Math.round( rect.top ),
				} )
			);
		} catch ( err ) {
			/* Ignore quota / private mode. */
		}
	}

	function restorePos() {
		const saved = readSavedPos();
		if ( ! saved ) {
			return;
		}
		applyPos( clampPos( saved.left, saved.top ) );
	}

	function openPanel() {
		if ( ! slots.length ) {
			return;
		}
		stack.removeAttribute( 'hidden' );
		fab.setAttribute( 'aria-expanded', 'true' );
		restorePos();
		if ( ! promptLoaded ) {
			loadPrompt();
			return;
		}
		promptField.focus();
	}

	function closePanel() {
		stack.setAttribute( 'hidden', '' );
		fab.setAttribute( 'aria-expanded', 'false' );
	}

	function togglePanel() {
		if ( isOpen() ) {
			closePanel();
			return;
		}
		openPanel();
	}

	function loadPrompt() {
		setBusy( true, i18n.loading || '' );
		wp.apiFetch( { path: withContext( promptPath ) } )
			.then( function ( res ) {
				if ( res && typeof res.prompt === 'string' ) {
					promptField.value = res.prompt;
					promptLoaded = true;
					setStatus( '' );
					promptField.focus();
					return;
				}
				setStatus( i18n.error || '' );
			} )
			.catch( function ( err ) {
				setStatus( ( err && err.message ) || i18n.error || '' );
			} )
			.finally( function () {
				setBusy( false );
			} );
	}

	function renderFields( fields ) {
		lastFields = fields && typeof fields === 'object' ? fields : {};
		slotsEl.innerHTML = '';

		slots.forEach( function ( slot ) {
			const value = slotValueToText( lastFields[ slot.id ] );
			const wrap = document.createElement( 'div' );
			wrap.className = 'forwp-ai-slot';
			wrap.setAttribute( 'data-slot-id', slot.id );

			const fieldId = 'forwp-ai-slot-' + slot.id;
			const headRow = document.createElement( 'div' );
			headRow.className = 'forwp-ai-slot__head';

			const label = document.createElement( 'label' );
			label.className = 'forwp-ai-label';
			label.setAttribute( 'for', fieldId );
			label.textContent = slot.label;

			const apply = document.createElement( 'button' );
			apply.type = 'button';
			apply.className = 'forwp-ai-icon forwp-ai-apply-slot';
			apply.setAttribute( 'data-slot-id', slot.id );
			apply.innerHTML = '<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>';
			labelIcon( apply, i18n.apply || 'Apply' );

			headRow.appendChild( label );
			headRow.appendChild( apply );

			const ta = document.createElement( 'textarea' );
			ta.id = fieldId;
			ta.className = 'forwp-ai-slot-value';
			ta.rows = value.length > 120 ? 3 : 2;
			ta.value = value;

			wrap.appendChild( headRow );
			wrap.appendChild( ta );
			slotsEl.appendChild( wrap );
		} );

		const hasResults = slotsEl.children.length > 0;
		slotsEl.hidden = ! hasResults;
		slotsActions.hidden = ! hasResults;
	}

	function readPanelFields() {
		const out = {};
		slotsEl.querySelectorAll( '.forwp-ai-slot' ).forEach( function ( wrap ) {
			const id = wrap.getAttribute( 'data-slot-id' );
			const ta = wrap.querySelector( '.forwp-ai-slot-value' );
			if ( id && ta ) {
				out[ id ] = ta.value;
			}
		} );
		return out;
	}

	function fieldsFromResponse( res ) {
		if ( res && res.fields && typeof res.fields === 'object' ) {
			return res.fields;
		}
		if ( res && typeof res.text === 'string' && slots.length === 1 ) {
			const single = {};
			single[ slots[ 0 ].id ] = res.text;
			return single;
		}
		return null;
	}

	function sendPrompt( text ) {
		const value = ( text || '' ).trim();
		if ( ! value ) {
			setStatus( i18n.empty || '' );
			promptField.focus();
			return;
		}

		setBusy( true, i18n.working || '' );
		const data = { prompt: value };
		if ( contextModes().length ) {
			data.context = currentContext();
		}
		wp.apiFetch( {
			path: generatePath,
			method: 'POST',
			data: data,
		} )
			.then( function ( res ) {
				const fields = fieldsFromResponse( res );
				if ( fields ) {
					renderFields( fields );
					setStatus( '' );
					const first = slotsEl.querySelector( '.forwp-ai-slot-value' );
					if ( first ) {
						first.focus();
					}
					return;
				}
				setStatus( i18n.error || '' );
			} )
			.catch( function ( err ) {
				setStatus( ( err && err.message ) || i18n.error || '' );
			} )
			.finally( function () {
				setBusy( false );
			} );
	}

	function destinationHasValue( slot ) {
		return String( slot.el.value || '' ).trim() !== '';
	}

	function applySlot( id, skipConfirm ) {
		const slot = slots.filter( function ( item ) {
			return item.id === id;
		} )[ 0 ];
		if ( ! slot ) {
			return false;
		}

		const values = readPanelFields();
		const next = slotValueToText( values[ id ] ).trim();
		if ( ! next ) {
			setStatus( i18n.error || '' );
			return false;
		}

		if ( ! skipConfirm && destinationHasValue( slot ) && i18n.confirm && ! window.confirm( i18n.confirm ) ) {
			return false;
		}

		slot.el.value = next;
		return true;
	}

	function applyAll() {
		const values = readPanelFields();
		const filled = slots.filter( function ( slot ) {
			return slotValueToText( values[ slot.id ] ).trim() !== '';
		} );
		if ( ! filled.length ) {
			setStatus( i18n.error || '' );
			return;
		}

		const overwrite = filled.some( destinationHasValue );
		if ( overwrite && i18n.confirm && ! window.confirm( i18n.confirm ) ) {
			return;
		}

		filled.forEach( function ( slot ) {
			applySlot( slot.id, true );
		} );
		setStatus( i18n.done || '' );
	}

	function sendRefine() {
		const note = refineField.value.trim();
		if ( ! note ) {
			refineField.focus();
			return;
		}

		const parts = [];
		if ( promptField.value.trim() ) {
			parts.push( promptField.value.trim() );
		}

		const current = readPanelFields();
		if ( Object.keys( current ).length ) {
			parts.push( 'Previous draft (JSON):\n' + JSON.stringify( current ) );
		}

		parts.push( 'Revise as follows:\n' + note );

		const combined = parts.join( '\n\n' );
		promptField.value = combined;
		refineField.value = '';
		sendPrompt( combined );
	}

	function bindEntity( opts ) {
		const options = opts && typeof opts === 'object' ? opts : {};
		const scope = options.root || document;
		const entityId = options.entityId != null ? String( options.entityId ) : '';
		slots = resolveSlots( scope );
		if ( ! slots.length ) {
			unbindEntity();
			return false;
		}
		promptPath = fillPath( config.promptPath, entityId );
		generatePath = fillPath( config.generatePath, entityId );
		promptLoaded = false;
		promptField.value = '';
		refineField.value = '';
		clearResults();
		placeOpenButton();
		setChromeVisible( true );
		return true;
	}

	function unbindEntity() {
		closePanel();
		slots = [];
		promptLoaded = false;
		promptField.value = '';
		refineField.value = '';
		clearResults();
		if ( openButton.parentNode ) {
			openButton.parentNode.removeChild( openButton );
		}
		if ( lazy ) {
			setChromeVisible( false );
		}
	}

	function renderContextModes() {
		const modes = contextModes();
		if ( ! modes.length ) {
			contextEl.setAttribute( 'hidden', '' );
			contextEl.innerHTML = '';
			return;
		}

		const def = config.defaultContext ? String( config.defaultContext ) : String( modes[ 0 ].id || 'content' );
		contextEl.innerHTML = '';
		modes.forEach( function ( mode ) {
			if ( ! mode || ! mode.id ) {
				return;
			}
			const id = String( mode.id );
			const wrap = document.createElement( 'label' );
			const input = document.createElement( 'input' );
			input.type = 'radio';
			input.name = 'forwp-ai-context';
			input.value = id;
			if ( id === def ) {
				input.checked = true;
			}
			wrap.appendChild( input );
			wrap.appendChild( document.createTextNode( ' ' + ( mode.label || id ) ) );
			contextEl.appendChild( wrap );
		} );
		if ( i18n.contextHelp ) {
			const help = document.createElement( 'p' );
			help.className = 'forwp-ai-context__help';
			help.textContent = i18n.contextHelp;
			contextEl.appendChild( help );
		}
		contextEl.removeAttribute( 'hidden' );
	}

	head.addEventListener( 'pointerdown', function ( event ) {
		if ( event.button !== 0 ) {
			return;
		}
		if ( event.target.closest( 'button' ) ) {
			return;
		}
		const rect = stack.getBoundingClientRect();
		drag = {
			id: event.pointerId,
			dx: event.clientX - rect.left,
			dy: event.clientY - rect.top,
		};
		stack.classList.add( 'is-dragging' );
		event.preventDefault();
	} );
	document.addEventListener( 'pointermove', function ( event ) {
		if ( ! drag || event.pointerId !== drag.id ) {
			return;
		}
		applyPos( clampPos( event.clientX - drag.dx, event.clientY - drag.dy ) );
	} );
	document.addEventListener( 'pointerup', function ( event ) {
		if ( ! drag || event.pointerId !== drag.id ) {
			return;
		}
		drag = null;
		stack.classList.remove( 'is-dragging' );
		savePos();
	} );
	window.addEventListener( 'resize', function () {
		if ( ! isOpen() || ! readSavedPos() ) {
			return;
		}
		restorePos();
	} );

	contextEl.addEventListener( 'change', function () {
		promptLoaded = false;
		if ( isOpen() ) {
			loadPrompt();
		}
	} );

	document.addEventListener( 'click', function ( event ) {
		const node = event.target && event.target.closest ? event.target.closest( 'button' ) : null;
		if ( ! node ) {
			return;
		}
		if ( node.classList.contains( 'forwp-ai-field-open' ) ) {
			event.preventDefault();
			openPanel();
			return;
		}
		if ( node.classList.contains( 'forwp-ai-fab' ) ) {
			event.preventDefault();
			togglePanel();
			return;
		}
		if ( node.classList.contains( 'forwp-ai-panel__close' ) ) {
			event.preventDefault();
			closePanel();
			return;
		}
		if ( node.classList.contains( 'forwp-ai-send' ) && ! node.classList.contains( 'forwp-ai-send-refine' ) ) {
			event.preventDefault();
			sendPrompt( promptField.value );
			return;
		}
		if ( node.classList.contains( 'forwp-ai-clear' ) ) {
			event.preventDefault();
			promptField.value = '';
			setStatus( '' );
			promptField.focus();
			return;
		}
		if ( node.classList.contains( 'forwp-ai-restore' ) ) {
			event.preventDefault();
			promptLoaded = false;
			loadPrompt();
			return;
		}
		if ( node.classList.contains( 'forwp-ai-apply-all' ) ) {
			event.preventDefault();
			applyAll();
			return;
		}
		if ( node.classList.contains( 'forwp-ai-apply-slot' ) ) {
			event.preventDefault();
			const id = node.getAttribute( 'data-slot-id' );
			if ( id && applySlot( id, false ) ) {
				setStatus( i18n.done || '' );
			}
			return;
		}
		if ( node.classList.contains( 'forwp-ai-send-refine' ) ) {
			event.preventDefault();
			sendRefine();
		}
	}, true );

	function isModEnter( event ) {
		return ( event.key === 'Enter' || event.key === 'NumpadEnter' )
			&& ( event.ctrlKey || event.metaKey )
			&& ! event.shiftKey
			&& ! event.altKey;
	}

	function sendShortcutHint() {
		return /Mac|iPhone|iPad/.test( navigator.platform || '' ) ? '⌘↩' : 'Ctrl+Enter';
	}

	document.addEventListener( 'keydown', function ( event ) {
		if ( ! isOpen() ) {
			return;
		}

		if ( event.key === 'Escape' && ! busy ) {
			closePanel();
			return;
		}

		if ( busy || ! isModEnter( event ) ) {
			return;
		}

		if ( event.target === promptField ) {
			event.preventDefault();
			sendPrompt( promptField.value );
			return;
		}

		if ( event.target === refineField ) {
			event.preventDefault();
			sendRefine();
		}
	} );

	function labelIcon( btn, text ) {
		btn.setAttribute( 'aria-label', text );
		btn.setAttribute( 'title', text );
	}

	fab.setAttribute( 'aria-label', i18n.fab || 'AI prompt' );
	fab.setAttribute( 'title', i18n.fab || 'AI prompt' );
	head.setAttribute( 'title', i18n.move || 'Drag to move' );
	root.querySelector( '.forwp-ai-panel__title' ).textContent = i18n.title || 'Prompt';
	labelIcon( closeBtn, i18n.close || 'Close' );
	root.querySelector( 'label[for="forwp-ai-prompt"]' ).textContent = i18n.prompt || 'Prompt';
	root.querySelector( 'label[for="forwp-ai-refine"]' ).textContent = i18n.refine || 'Refine';
	labelIcon( sendBtn, ( i18n.send || 'Send' ) + ' (' + sendShortcutHint() + ')' );
	labelIcon( clearBtn, i18n.clear || 'Clear' );
	labelIcon( restoreBtn, i18n.restore || 'Restore default' );
	labelIcon( sendRefineBtn, ( i18n.send || 'Send' ) + ' (' + sendShortcutHint() + ')' );
	applyAllBtn.textContent = i18n.applyAll || 'Apply all';
	renderContextModes();

	if ( ! lazy ) {
		placeOpenButton();
		setChromeVisible( true );
	} else {
		setChromeVisible( false );
	}

	root.setAttribute( 'data-forwp-ai-bound', '1' );
	window.forwpAiPanel = {
		bind: bindEntity,
		unbind: unbindEntity,
	};
}() );
