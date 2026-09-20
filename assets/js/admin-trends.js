( function () {
	'use strict';

	var input = document.getElementById( 'forwp_trends_file' );
	if ( ! input ) {
		return;
	}

	var drop = input.closest( '.forwp-seo-file-drop' );
	var name = drop ? drop.querySelector( '.forwp-seo-file-drop__name' ) : null;

	function setName( fileName ) {
		if ( ! name ) {
			return;
		}
		var empty = name.getAttribute( 'data-empty' ) || '';
		name.textContent = fileName || empty;
	}

	input.addEventListener( 'change', function () {
		var file = input.files && input.files[ 0 ];
		setName( file ? file.name : '' );
	} );

	if ( ! drop ) {
		return;
	}

	[ 'dragenter', 'dragover' ].forEach( function ( eventName ) {
		drop.addEventListener( eventName, function ( event ) {
			event.preventDefault();
			drop.classList.add( 'is-dragover' );
		} );
	} );

	[ 'dragleave', 'drop' ].forEach( function ( eventName ) {
		drop.addEventListener( eventName, function ( event ) {
			event.preventDefault();
			drop.classList.remove( 'is-dragover' );
		} );
	} );

	drop.addEventListener( 'drop', function ( event ) {
		var files = event.dataTransfer && event.dataTransfer.files;
		if ( ! files || ! files.length ) {
			return;
		}
		input.files = files;
		setName( files[ 0 ].name );
	} );
} )();
