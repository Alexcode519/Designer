/* Builds print files one spread at a time (printed cover first), then packages them into a PDF and ZIP. */
( function () {
	var box = document.getElementById( 'sad-export' );
	if ( ! box ) {
		return;
	}
	var button = document.getElementById( 'sad-export-btn' );
	var progress = document.getElementById( 'sad-export-progress' );
	var list = document.getElementById( 'sad-export-links' );
	var albumId = box.getAttribute( 'data-album' );
	var spreads = parseInt( box.getAttribute( 'data-spreads' ), 10 );
	var printedCover = box.getAttribute( 'data-cover' ) === '1';
	var base = '/snippets-album/v1/admin/albums/' + albumId;

	function fail( error ) {
		progress.textContent = 'Stopped: ' + ( error && error.message ? error.message : 'something went wrong.' );
		button.disabled = false;
	}

	function showLinks( result ) {
		list.innerHTML = '';
		result.links.forEach( function ( link ) {
			var li = document.createElement( 'li' );
			var a = document.createElement( 'a' );
			a.href = link.url;
			a.textContent = link.label;
			li.appendChild( a );
			li.appendChild( document.createTextNode( ' (' + link.size + ')' ) );
			list.appendChild( li );
		} );
	}

	function renderFrom( index ) {
		if ( index >= spreads ) {
			progress.textContent = 'Putting the PDF and ZIP together…';
			return wp.apiFetch( { path: base + '/package', method: 'POST' } ).then( function ( result ) {
				showLinks( result );
				progress.textContent = 'Done. Download the files below and send them to your printer.';
				button.disabled = false;
			} );
		}
		progress.textContent = 'Building spread ' + ( index + 1 ) + ' of ' + spreads + '…';
		return wp.apiFetch( { path: base + '/render/' + index, method: 'POST' } ).then( function () {
			return renderFrom( index + 1 );
		} );
	}

	function renderCover() {
		if ( ! printedCover ) {
			return Promise.resolve();
		}
		progress.textContent = 'Building the cover…';
		return wp.apiFetch( { path: base + '/render/cover', method: 'POST' } );
	}

	button.addEventListener( 'click', function () {
		button.disabled = true;
		renderCover().then( function () {
			return renderFrom( 0 );
		} ).catch( fail );
	} );
} )();
