( function () {
	var cfg = window.forwpSeoPlatformOrder || {};
	if ( ! cfg.on ) {
		return;
	}

	var list = document.getElementById( "the-list" );
	if ( ! list ) {
		return;
	}

	var dragged = null;
	var moved = false;

	var place = function ( event ) {
		var row = event.target.closest( "tr" );
		if ( ! dragged || ! row || row === dragged || row.parentNode !== list ) {
			return;
		}
		var rect = row.getBoundingClientRect();
		var after = event.clientY > rect.top + rect.height / 2;
		list.insertBefore( dragged, after ? row.nextSibling : row );
		moved = true;
	};

	var save = function () {
		var order = [];
		list.querySelectorAll( "tr" ).forEach( function ( row ) {
			var id = String( row.id || "" ).replace( /^tag-/, "" );
			if ( id ) {
				order.push( id );
			}
		} );
		var body = new URLSearchParams();
		body.set( "action", "forwp_seo_platform_order" );
		body.set( "nonce", cfg.nonce );
		body.set( "start", String( cfg.start || 0 ) );
		order.forEach( function ( id ) {
			body.append( "order[]", id );
		} );
		window.fetch( window.ajaxurl, {
			method: "POST",
			credentials: "same-origin",
			body: body,
		} );
	};

	list.querySelectorAll( ".forwp-platform-drag" ).forEach( function ( handle ) {
		handle.draggable = true;
		handle.addEventListener( "dragstart", function ( event ) {
			dragged = handle.closest( "tr" );
			moved = false;
			event.dataTransfer.effectAllowed = "move";
			event.dataTransfer.setData( "text/plain", dragged ? dragged.id : "" );
			if ( dragged ) {
				dragged.classList.add( "is-dragging" );
			}
		} );
		handle.addEventListener( "dragend", function () {
			if ( dragged ) {
				dragged.classList.remove( "is-dragging" );
			}
			if ( moved ) {
				save();
			}
			dragged = null;
			moved = false;
		} );
	} );

	list.querySelectorAll( "tr" ).forEach( function ( row ) {
		row.addEventListener( "dragover", function ( event ) {
			if ( ! dragged ) {
				return;
			}
			event.preventDefault();
			place( event );
		} );
		row.addEventListener( "drop", function ( event ) {
			if ( ! dragged ) {
				return;
			}
			event.preventDefault();
			if ( ! moved ) {
				place( event );
			}
		} );
	} );
}() );
