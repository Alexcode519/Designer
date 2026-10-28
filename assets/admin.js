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

/* Loads photos into the album from WordPress, sent in pieces like the client editor does. */
( function () {
	var box = document.getElementById( 'sad-photos' );
	if ( ! box ) {
		return;
	}
	var input = document.getElementById( 'sad-photos-input' );
	var progress = document.getElementById( 'sad-photos-progress' );
	var count = document.getElementById( 'sad-photos-count' );
	var grid = document.getElementById( 'sad-photos-grid' );
	var path = '/snippets-album/v1/admin/albums/' + box.getAttribute( 'data-album' ) + '/photos';
	var chunkSize = parseInt( box.getAttribute( 'data-chunk' ), 10 ) || 1048576;

	function randomId() {
		var chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
		var id = '';
		for ( var i = 0; i < 24; i++ ) {
			id += chars.charAt( Math.floor( Math.random() * chars.length ) );
		}
		return id;
	}

	function uploadOne( file ) {
		var uploadId = randomId();
		var total = Math.max( 1, Math.ceil( file.size / chunkSize ) );
		function send( index ) {
			var form = new FormData();
			form.append( 'chunk', file.slice( index * chunkSize, ( index + 1 ) * chunkSize ), 'chunk' );
			form.append( 'upload_id', uploadId );
			form.append( 'index', index );
			form.append( 'total', total );
			form.append( 'name', file.name );
			return wp.apiFetch( { path: path, method: 'POST', body: form } ).then( function ( result ) {
				return result.done ? result.photo : send( index + 1 );
			} );
		}
		return send( 0 );
	}

	input.addEventListener( 'change', function () {
		var files = Array.prototype.filter.call( input.files, function ( file ) {
			return /\.(jpe?g|png)$/i.test( file.name );
		} );
		input.value = '';
		var done = 0;
		var failed = [];
		var chain = Promise.resolve();
		files.forEach( function ( file ) {
			chain = chain.then( function () {
				progress.textContent = 'Uploading ' + ( done + 1 ) + ' of ' + files.length + '… keep this page open.';
				return uploadOne( file ).then( function ( photo ) {
					var img = document.createElement( 'img' );
					img.src = photo.preview;
					img.alt = photo.name;
					img.title = photo.name;
					img.style.cssText = 'height:64px;width:auto;border-radius:2px';
					grid.appendChild( img );
					count.textContent = String( parseInt( count.textContent, 10 ) + 1 );
				} ).catch( function ( error ) {
					failed.push( file.name + ( error && error.message ? ' (' + error.message + ')' : '' ) );
				} ).then( function () {
					done += 1;
				} );
			} );
		} );
		chain.then( function () {
			progress.textContent = 'Added ' + ( done - failed.length ) + ' photos.' + ( failed.length ? ' Not added: ' + failed.join( ', ' ) : '' );
		} );
	} );
} )();

/* Notes on spreads: add a studio note, or mark one done. */
( function () {
	var box = document.getElementById( 'sad-notes' );
	if ( ! box ) {
		return;
	}
	var base = '/snippets-album/v1/admin/albums/' + box.getAttribute( 'data-album' ) + '/comments';
	var status = document.getElementById( 'sad-note-status' );

	function fail( error ) {
		status.textContent = error && error.message ? error.message : 'Something went wrong.';
	}

	function reloadAtNotes() {
		window.location.hash = 'sad_album_notes';
		window.location.reload();
	}

	document.getElementById( 'sad-note-add' ).addEventListener( 'click', function () {
		var text = document.getElementById( 'sad-note-text' ).value;
		if ( ! text.trim() ) {
			return;
		}
		status.textContent = 'Saving…';
		wp.apiFetch( { path: base, method: 'POST', data: { spread: document.getElementById( 'sad-note-spread' ).value, text: text } } ).then( reloadAtNotes ).catch( fail );
	} );
	Array.prototype.forEach.call( box.querySelectorAll( '.sad-note-resolve' ), function ( button ) {
		button.addEventListener( 'click', function () {
			wp.apiFetch( { path: base + '/' + button.getAttribute( 'data-comment' ), method: 'POST', data: { resolved: button.getAttribute( 'data-resolved' ) === '1' } } ).then( reloadAtNotes ).catch( fail );
		} );
	} );
} )();
