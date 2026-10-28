/**
 * Snippets Album Designer: the client-facing editor.
 *
 * The client uploads photos, picks a layout for each spread, places photos in the spaces,
 * pans and zooms them, and approves the album. Everything autosaves.
 *
 * Crop maths must match sad_source_crop() in includes/render.php: a photo covers its space,
 * is enlarged by `zoom`, and `x`/`y` (0 to 1) choose which part of the overflow shows.
 * CSS background-position percentages work exactly that way.
 */
( function () {
	'use strict';

	var config = window.SAD_CONFIG;
	var root = document.getElementById( 'sad-app' );
	if ( ! config || ! root ) {
		return;
	}

	var layouts = {};
	config.layouts.forEach( function ( layout ) {
		layouts[ layout.id ] = layout;
	} );

	var state = {
		album: null,
		design: null,
		photos: {},
		photoOrder: [],
		current: 0,
		selectedSlot: null,
		armedPhoto: null,
		selecting: false,
		selected: {},
		template: config.templates && config.templates.length ? config.templates[0].id : null,
		saveTimer: null,
		saving: false,
		dirty: false,
		uploading: 0,
		uploadDone: 0,
	};

	var dom = {};

	/* ---------- helpers ---------- */

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			var value = attrs[ key ];
			if ( key === 'class' ) {
				node.className = value;
			} else if ( key === 'text' ) {
				node.textContent = value;
			} else if ( key.indexOf( 'on' ) === 0 ) {
				node.addEventListener( key.slice( 2 ), value );
			} else if ( value !== false && value !== null && value !== undefined ) {
				node.setAttribute( key, value === true ? '' : value );
			}
		} );
		( children || [] ).forEach( function ( child ) {
			if ( child ) {
				node.appendChild( typeof child === 'string' ? document.createTextNode( child ) : child );
			}
		} );
		return node;
	}

	function api( path, options ) {
		options = options || {};
		var init = { method: options.method || 'GET', credentials: 'same-origin', keepalive: !! options.keepalive };
		if ( options.json ) {
			init.headers = { 'Content-Type': 'application/json' };
			init.body = JSON.stringify( options.json );
		} else if ( options.body ) {
			init.body = options.body;
		}
		return fetch( config.api + path, init ).then( function ( response ) {
			return response.json().catch( function () {
				return {};
			} ).then( function ( data ) {
				if ( ! response.ok ) {
					var error = new Error( data && data.message ? data.message : 'Something went wrong (' + response.status + ').' );
					error.status = response.status;
					throw error;
				}
				return data;
			} );
		} );
	}

	function toast( message, kind ) {
		var node = el( 'div', { class: 'sad-toast' + ( kind ? ' sad-toast--' + kind : '' ), role: 'status', text: message } );
		dom.toasts.appendChild( node );
		setTimeout( function () {
			node.remove();
		}, kind === 'error' ? 7000 : 3500 );
	}

	function isLocked() {
		return state.album.status === 'approved';
	}

	function spreadAspect() {
		return ( state.album.size.width_mm * 2 ) / state.album.size.height_mm;
	}

	function slotsFor( spread ) {
		return layouts[ spread.layout ] ? layouts[ spread.layout ].slots : layouts[ 'two-full' ].slots;
	}

	function emptySlot() {
		return { photo: null, zoom: 1, x: 0.5, y: 0.5 };
	}

	function usageCounts() {
		var counts = {};
		state.design.spreads.forEach( function ( spread ) {
			spread.slots.forEach( function ( slot ) {
				if ( slot.photo ) {
					counts[ slot.photo ] = ( counts[ slot.photo ] || 0 ) + 1;
				}
			} );
		} );
		return counts;
	}

	/** Effective print resolution of a photo in a space, in dots per inch. */
	function slotDpi( spread, index ) {
		var slot = spread.slots[ index ];
		var photo = slot && state.photos[ slot.photo ];
		if ( ! photo ) {
			return null;
		}
		var rect = slotsFor( spread )[ index ];
		var slotW = rect[ 2 ] * state.album.size.width_mm * 2;
		var slotH = rect[ 3 ] * state.album.size.height_mm;
		var scale = Math.max( slotW / photo.width, slotH / photo.height ) * slot.zoom; // mm per pixel
		return Math.round( 25.4 / scale );
	}

	/* ---------- saving ---------- */

	function scheduleSave() {
		state.dirty = true;
		setStatus( 'Unsaved changes…' );
		clearTimeout( state.saveTimer );
		state.saveTimer = setTimeout( save, 800 );
	}

	function save( keepalive ) {
		clearTimeout( state.saveTimer );
		if ( ! state.dirty || isLocked() ) {
			return Promise.resolve();
		}
		state.dirty = false;
		state.saving = true;
		setStatus( 'Saving…' );
		return api( '/design', { method: 'POST', json: { design: state.design }, keepalive: keepalive } )
			.then( function () {
				state.saving = false;
				setStatus( state.dirty ? 'Unsaved changes…' : 'All changes saved' );
			} )
			.catch( function ( error ) {
				state.saving = false;
				state.dirty = true;
				setStatus( 'Not saved' );
				toast( error.message, 'error' );
				if ( error.status === 423 ) {
					load();
				}
			} );
	}

	function setStatus( text ) {
		if ( dom.status ) {
			dom.status.textContent = text;
		}
	}

	window.addEventListener( 'beforeunload', function ( event ) {
		if ( state.dirty ) {
			save( true );
		}
		if ( state.dirty || state.uploading ) {
			event.preventDefault();
			event.returnValue = '';
		}
	} );

	/* ---------- design changes ---------- */

	function setLayout( layoutId ) {
		var spread = state.design.spreads[ state.current ];
		var placed = spread.slots.filter( function ( slot ) {
			return slot.photo;
		} );
		var count = layouts[ layoutId ].slots.length;
		var slots = [];
		for ( var i = 0; i < count; i++ ) {
			var previous = placed[ i ];
			slots.push( previous ? { photo: previous.photo, zoom: 1, x: 0.5, y: 0.5 } : emptySlot() );
		}
		spread.layout = layoutId;
		spread.slots = slots;
		state.selectedSlot = null;
		if ( placed.length > count ) {
			toast( 'This layout has fewer spaces, so ' + ( placed.length - count ) + ' photo(s) were taken off this spread.' );
		}
		scheduleSave();
		renderAll();
	}

	function placePhoto( slotIndex, photoId ) {
		var spread = state.design.spreads[ state.current ];
		spread.slots[ slotIndex ] = { photo: photoId, zoom: 1, x: 0.5, y: 0.5 };
		state.selectedSlot = slotIndex;
		state.armedPhoto = null;
		scheduleSave();
		renderAll();
	}

	/** Swaps two spaces on the current spread, keeping each photo's own zoom and position. */
	function swapSlots( from, to ) {
		var slots = state.design.spreads[ state.current ].slots;
		if ( from === to || ! slots[ from ] || ! slots[ to ] ) {
			return;
		}
		var moving = slots[ from ];
		slots[ from ] = { photo: slots[ to ].photo, zoom: 1, x: 0.5, y: 0.5 };
		slots[ to ] = { photo: moving.photo, zoom: 1, x: 0.5, y: 0.5 };
		state.selectedSlot = to;
		scheduleSave();
		renderAll();
	}

	/* ---------- auto-fill ---------- */

	function layoutCapacity( id ) {
		return layouts[ id ] ? layouts[ id ].slots.length : 0;
	}

	function chosenPhotos() {
		var picked = state.photoOrder.filter( function ( id ) {
			return state.selected[ id ];
		} );
		return picked.length ? picked : state.photoOrder.slice();
	}

	/**
	 * Lays the photos out across the album in order, using the template's layouts.
	 * Picks how many spreads to use from the template's average photos per spread, within the
	 * album's minimum and maximum, then gives each spread the template layout that best fits the
	 * photos still to place.
	 */
	function buildAutoFill( template, photoIds ) {
		var maxCap = 0;
		Object.keys( layouts ).forEach( function ( id ) {
			maxCap = Math.max( maxCap, layoutCapacity( id ) );
		} );
		var pattern = template.layouts.filter( function ( id ) {
			return layouts[ id ];
		} );
		var average = pattern.reduce( function ( sum, id ) {
			return sum + layoutCapacity( id );
		}, 0 ) / pattern.length;

		var total = photoIds.length;
		var spreads = Math.round( total / average ) || 1;
		spreads = Math.max( spreads, Math.ceil( total / maxCap ) );
		spreads = Math.min( state.album.maxSpreads, Math.max( state.album.minSpreads, spreads ) );
		var usable = Math.min( total, spreads * maxCap );

		var result = [];
		var next = 0;
		for ( var i = 0; i < spreads; i++ ) {
			var remaining = usable - next;
			var spreadsLeft = spreads - i;
			var low = Math.max( remaining >= spreadsLeft ? 1 : 0, remaining - ( spreadsLeft - 1 ) * maxCap );
			var high = remaining >= spreadsLeft ? Math.min( maxCap, remaining - ( spreadsLeft - 1 ) ) : Math.min( 1, remaining );
			var target = remaining / spreadsLeft;

			// Prefer the template's own rhythm, starting where this spread falls in the pattern.
			var layout = null;
			var bestGap = Infinity;
			for ( var k = 0; k < pattern.length; k++ ) {
				var candidate = pattern[ ( i + k ) % pattern.length ];
				var cap = layoutCapacity( candidate );
				if ( cap < low || cap > high ) {
					continue;
				}
				var gap = Math.abs( cap - target );
				if ( gap < bestGap - 0.5 ) {
					bestGap = gap;
					layout = candidate;
				}
			}
			if ( ! layout ) {
				var wanted = Math.min( high, Math.max( low, Math.round( target ) ) );
				layout = Object.keys( layouts ).filter( function ( id ) {
					return layoutCapacity( id ) === wanted;
				} )[ 0 ] || pattern[ 0 ];
			}

			var count = layoutCapacity( layout );
			var ids = photoIds.slice( next, next + Math.min( count, remaining ) );
			// One upright photo across a whole spread crops badly; give it one page instead.
			if ( ids.length === 1 && layout === 'full' && state.photos[ ids[ 0 ] ].height > state.photos[ ids[ 0 ] ].width && layouts[ 'right-only' ] ) {
				layout = 'right-only';
				count = 1;
			}
			next += ids.length;

			var slots = [];
			for ( var s = 0; s < layoutCapacity( layout ); s++ ) {
				slots.push( ids[ s ] ? { photo: ids[ s ], zoom: 1, x: 0.5, y: 0.5 } : emptySlot() );
			}
			result.push( { layout: layout, slots: slots } );
		}
		return { spreads: result, placed: next, skipped: total - next };
	}

	function autoFill() {
		var template = config.templates.filter( function ( t ) {
			return t.id === state.template;
		} )[ 0 ];
		var photoIds = chosenPhotos();
		if ( ! template || ! photoIds.length ) {
			toast( 'Add some photos first.' );
			return;
		}
		var hasWork = state.design.spreads.some( function ( spread ) {
			return spread.slots.some( function ( slot ) {
				return slot.photo;
			} );
		} );
		if ( hasWork && ! window.confirm( 'Auto-fill replaces the spreads you have now with a new layout. Continue?' ) ) {
			return;
		}
		var filled = buildAutoFill( template, photoIds );
		state.design.spreads = filled.spreads;
		state.current = 0;
		state.selectedSlot = null;
		state.armedPhoto = null;
		state.selecting = false;
		scheduleSave();
		renderAll();
		var message = 'Placed ' + filled.placed + ' photos on ' + filled.spreads.length + ' spreads. Swap or move anything you like.';
		if ( filled.skipped > 0 ) {
			message = 'Placed ' + filled.placed + ' photos. ' + filled.skipped + ' did not fit in the ' + state.album.maxSpreads + ' spreads allowed.';
		}
		toast( message, filled.skipped > 0 ? 'error' : 'success' );
	}

	function addSpread() {
		if ( state.design.spreads.length >= state.album.maxSpreads ) {
			toast( 'Your album can have up to ' + state.album.maxSpreads + ' spreads.' );
			return;
		}
		state.design.spreads.splice( state.current + 1, 0, { layout: 'two-full', slots: [ emptySlot(), emptySlot() ] } );
		state.current += 1;
		state.selectedSlot = null;
		scheduleSave();
		renderAll();
	}

	function deleteSpread() {
		if ( state.design.spreads.length <= 1 ) {
			return;
		}
		if ( ! window.confirm( 'Delete spread ' + ( state.current + 1 ) + '? The photos stay in your photo tray.' ) ) {
			return;
		}
		state.design.spreads.splice( state.current, 1 );
		state.current = Math.min( state.current, state.design.spreads.length - 1 );
		state.selectedSlot = null;
		scheduleSave();
		renderAll();
	}

	function moveSpread( delta ) {
		var to = state.current + delta;
		if ( to < 0 || to >= state.design.spreads.length ) {
			return;
		}
		var spreads = state.design.spreads;
		var moving = spreads.splice( state.current, 1 )[ 0 ];
		spreads.splice( to, 0, moving );
		state.current = to;
		scheduleSave();
		renderAll();
	}

	function goTo( index ) {
		state.current = Math.max( 0, Math.min( index, state.design.spreads.length - 1 ) );
		state.selectedSlot = null;
		renderAll();
	}

	/* ---------- uploads ---------- */

	function randomId() {
		var chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
		var id = '';
		for ( var i = 0; i < 24; i++ ) {
			id += chars.charAt( Math.floor( Math.random() * chars.length ) );
		}
		return id;
	}

	/**
	 * @param {FileList} fileList
	 * @param {Object}   [target] { spread, slot }: place the first photo there once uploaded.
	 */
	function uploadFiles( fileList, target ) {
		var files = Array.prototype.filter.call( fileList, function ( file ) {
			var ok = /^image\/(jpeg|png)$/.test( file.type ) || /\.(jpe?g|png)$/i.test( file.name );
			if ( ! ok ) {
				toast( file.name + ' was skipped. Please use JPEG or PNG photos.', 'error' );
			}
			return ok;
		} );
		if ( ! files.length ) {
			return;
		}
		state.uploading += files.length;
		renderUploadStatus();

		var chain = Promise.resolve();
		files.forEach( function ( file ) {
			chain = chain.then( function () {
				return uploadOne( file )
					.then( function ( photo ) {
						state.photos[ photo.id ] = photo;
						state.photoOrder.push( photo.id );
						if ( target && state.design.spreads.indexOf( target.spread ) !== -1 && target.spread.slots[ target.slot ] ) {
							target.spread.slots[ target.slot ] = { photo: photo.id, zoom: 1, x: 0.5, y: 0.5 };
							target = null;
							scheduleSave();
							renderAll();
							return;
						}
						renderTray();
					} )
					.catch( function ( error ) {
						toast( file.name + ': ' + error.message, 'error' );
					} )
					.then( function () {
						state.uploading -= 1;
						state.uploadDone += 1;
						renderUploadStatus();
					} );
			} );
		} );
	}

	function uploadOne( file ) {
		var uploadId = randomId();
		var size = config.chunkSize;
		var total = Math.max( 1, Math.ceil( file.size / size ) );

		function sendChunk( index, attempt ) {
			var form = new FormData();
			form.append( 'chunk', file.slice( index * size, ( index + 1 ) * size ), 'chunk' );
			form.append( 'upload_id', uploadId );
			form.append( 'index', index );
			form.append( 'total', total );
			form.append( 'name', file.name );
			return api( '/photos', { method: 'POST', body: form } ).catch( function ( error ) {
				// Retry network hiccups once, but not errors the server explained.
				if ( attempt < 1 && ! error.status ) {
					return sendChunk( index, attempt + 1 );
				}
				throw error;
			} );
		}

		function next( index ) {
			return sendChunk( index, 0 ).then( function ( result ) {
				if ( result.done ) {
					return result.photo;
				}
				return next( index + 1 );
			} );
		}
		return next( 0 );
	}

	function deletePhoto( photoId ) {
		var used = usageCounts()[ photoId ];
		var message = used ? 'This photo is used in your album. Remove it from the album and delete it?' : 'Delete this photo?';
		if ( ! window.confirm( message ) ) {
			return;
		}
		save().then( function () {
			return api( '/photos/' + photoId, { method: 'DELETE' } );
		} ).then( function () {
			delete state.photos[ photoId ];
			state.photoOrder = state.photoOrder.filter( function ( id ) {
				return id !== photoId;
			} );
			state.design.spreads.forEach( function ( spread ) {
				spread.slots = spread.slots.map( function ( slot ) {
					return slot.photo === photoId ? emptySlot() : slot;
				} );
			} );
			if ( used ) {
				scheduleSave();
			}
			renderAll();
		} ).catch( function ( error ) {
			toast( error.message, 'error' );
		} );
	}

	/* ---------- approval ---------- */

	function problems() {
		var list = [];
		if ( state.design.spreads.length < state.album.minSpreads ) {
			list.push( 'Your album needs at least ' + state.album.minSpreads + ' spreads (it has ' + state.design.spreads.length + ').' );
		}
		state.design.spreads.forEach( function ( spread, n ) {
			if ( spread.slots.some( function ( slot ) {
				return ! slot.photo;
			} ) ) {
				list.push( 'Spread ' + ( n + 1 ) + ' has an empty photo space.' );
			}
		} );
		return list;
	}

	function lowResolution() {
		var list = [];
		state.design.spreads.forEach( function ( spread, n ) {
			spread.slots.forEach( function ( slot, i ) {
				var dpi = slotDpi( spread, i );
				if ( dpi && dpi < state.album.minDpi ) {
					list.push( n + 1 );
				}
			} );
		} );
		return list.filter( function ( value, index ) {
			return list.indexOf( value ) === index;
		} );
	}

	function approve() {
		var issues = problems();
		if ( issues.length ) {
			window.alert( 'Before approving:\n\n' + issues.slice( 0, 8 ).join( '\n' ) + ( issues.length > 8 ? '\n…' : '' ) );
			return;
		}
		var soft = lowResolution();
		var message = 'Approve your album for printing?\n\nAfter you approve, the design is locked and goes to print. Please check every spread first.';
		if ( soft.length ) {
			message += '\n\nSome photos may print soft because they are enlarged a lot (spreads ' + soft.join( ', ' ) + ').';
		}
		if ( ! window.confirm( message ) ) {
			return;
		}
		clearTimeout( state.saveTimer );
		dom.approve.disabled = true;
		api( '/approve', { method: 'POST', json: { design: state.design } } )
			.then( function ( data ) {
				state.dirty = false;
				applyAlbum( data );
				toast( 'Thank you! Your album is approved and on its way to print.', 'success' );
			} )
			.catch( function ( error ) {
				dom.approve.disabled = false;
				toast( error.message, 'error' );
			} );
	}

	/* ---------- rendering ---------- */

	/** Draws a spread into `stage`, sized `width` px wide. */
	function drawSpread( stage, spread, width, interactive ) {
		var height = width / spreadAspect();
		stage.innerHTML = '';
		stage.style.width = width + 'px';
		stage.style.height = height + 'px';

		slotsFor( spread ).forEach( function ( rect, index ) {
			var slot = spread.slots[ index ] || emptySlot();
			var w = rect[ 2 ] * width;
			var h = rect[ 3 ] * height;
			var node = el( 'div', { class: 'sad-slot', 'data-index': String( index ) } );
			node.style.left = rect[ 0 ] * 100 + '%';
			node.style.top = rect[ 1 ] * 100 + '%';
			node.style.width = rect[ 2 ] * 100 + '%';
			node.style.height = rect[ 3 ] * 100 + '%';

			var photo = slot.photo && state.photos[ slot.photo ];
			if ( photo ) {
				var scale = Math.max( w / photo.width, h / photo.height ) * slot.zoom;
				node.style.backgroundImage = 'url("' + photo.preview + '")';
				node.style.backgroundSize = photo.width * scale + 'px ' + photo.height * scale + 'px';
				node.style.backgroundPosition = slot.x * 100 + '% ' + slot.y * 100 + '%';
				node.classList.add( 'sad-slot--filled' );
			}

			if ( interactive ) {
				decorateSlot( node, spread, index, photo );
			}
			stage.appendChild( node );
		} );

		stage.appendChild( el( 'div', { class: 'sad-fold', 'aria-hidden': 'true' } ) );
	}

	function decorateSlot( node, spread, index, photo ) {
		node.setAttribute( 'tabindex', '0' );
		node.setAttribute( 'role', 'button' );
		node.setAttribute( 'aria-label', photo ? 'Photo space ' + ( index + 1 ) + ': ' + photo.name : 'Empty photo space ' + ( index + 1 ) );
		if ( state.selectedSlot === index ) {
			node.classList.add( 'sad-slot--selected' );
		}
		if ( ! photo ) {
			node.appendChild( el( 'span', { class: 'sad-slot__hint', text: isLocked() ? '' : ( state.armedPhoto ? 'Tap to place' : 'Drop a photo here' ) } ) );
		} else {
			var dpi = slotDpi( spread, index );
			if ( dpi && dpi < state.album.minDpi ) {
				node.appendChild( el( 'span', { class: 'sad-slot__warn', title: 'About ' + dpi + ' dpi. This photo may print soft at this size.', text: 'Low quality' } ) );
			}
		}
		if ( isLocked() ) {
			return;
		}

		node.addEventListener( 'click', function () {
			if ( state.armedPhoto ) {
				placePhoto( index, state.armedPhoto );
				return;
			}
			state.selectedSlot = index;
			renderAll();
		} );
		node.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Enter' || event.key === ' ' ) {
				event.preventDefault();
				node.click();
			}
		} );
		node.addEventListener( 'dragover', function ( event ) {
			event.preventDefault();
			node.classList.add( 'sad-slot--over' );
		} );
		node.addEventListener( 'dragleave', function () {
			node.classList.remove( 'sad-slot--over' );
		} );
		node.addEventListener( 'drop', function ( event ) {
			node.classList.remove( 'sad-slot--over' );
			if ( hasFiles( event ) ) {
				return; // Handled by the page-wide file drop, which knows this space.
			}
			event.preventDefault();
			var from = event.dataTransfer.getData( 'text/x-sad-slot' );
			if ( from !== '' && ! isNaN( parseInt( from, 10 ) ) ) {
				swapSlots( parseInt( from, 10 ), index );
				return;
			}
			var id = event.dataTransfer.getData( 'text/x-sad-photo' );
			if ( id && state.photos[ id ] ) {
				placePhoto( index, id );
			}
		} );

		if ( photo ) {
			enablePan( node, spread, index );
			node.addEventListener( 'wheel', function ( event ) {
				if ( state.selectedSlot !== index ) {
					return;
				}
				event.preventDefault();
				setZoom( spread.slots[ index ], spread.slots[ index ].zoom * Math.exp( -event.deltaY * 0.0015 ) );
			}, { passive: false } );
			// A placed photo that isn't selected can be dragged onto another space to swap them.
			// The selected one is panned instead.
			if ( state.selectedSlot !== index ) {
				node.setAttribute( 'draggable', 'true' );
				node.addEventListener( 'dragstart', function ( event ) {
					event.dataTransfer.setData( 'text/x-sad-slot', String( index ) );
					event.dataTransfer.effectAllowed = 'move';
				} );
			}
		}
	}

	/** Drag inside a photo to move it within its space. */
	function enablePan( node, spread, index ) {
		var start = null;
		node.addEventListener( 'pointerdown', function ( event ) {
			if ( state.selectedSlot !== index ) {
				return;
			}
			var slot = spread.slots[ index ];
			var photo = state.photos[ slot.photo ];
			var w = node.clientWidth;
			var h = node.clientHeight;
			var scale = Math.max( w / photo.width, h / photo.height ) * slot.zoom;
			start = {
				x: event.clientX,
				y: event.clientY,
				slotX: slot.x,
				slotY: slot.y,
				overX: photo.width * scale - w,
				overY: photo.height * scale - h,
			};
			node.setPointerCapture( event.pointerId );
			node.classList.add( 'sad-slot--panning' );
		} );
		node.addEventListener( 'pointermove', function ( event ) {
			if ( ! start ) {
				return;
			}
			var slot = spread.slots[ index ];
			if ( start.overX > 0.5 ) {
				slot.x = Math.min( 1, Math.max( 0, start.slotX - ( event.clientX - start.x ) / start.overX ) );
			}
			if ( start.overY > 0.5 ) {
				slot.y = Math.min( 1, Math.max( 0, start.slotY - ( event.clientY - start.y ) / start.overY ) );
			}
			node.style.backgroundPosition = slot.x * 100 + '% ' + slot.y * 100 + '%';
		} );
		function end() {
			if ( start ) {
				start = null;
				node.classList.remove( 'sad-slot--panning' );
				scheduleSave();
				renderStrip();
			}
		}
		node.addEventListener( 'pointerup', end );
		node.addEventListener( 'pointercancel', end );
	}

	function renderStage() {
		var spread = state.design.spreads[ state.current ];
		var wrapStyle = window.getComputedStyle( dom.stageWrap );
		var inner = dom.stageWrap.clientWidth - parseFloat( wrapStyle.paddingLeft ) - parseFloat( wrapStyle.paddingRight );
		var width = Math.min( inner, 1100 );
		if ( window.innerHeight > 300 ) {
			width = Math.min( width, ( window.innerHeight - 200 - ( state.headerOffset || 0 ) ) * spreadAspect() );
		}
		width = Math.max( width, 240 );
		drawSpread( dom.stage, spread, width, true );
		dom.spreadLabel.textContent = 'Spread ' + ( state.current + 1 ) + ' of ' + state.design.spreads.length;
		dom.prev.disabled = state.current === 0;
		dom.next.disabled = state.current === state.design.spreads.length - 1;
	}

	var zoomSaveTimer;

	/** Sets a placed photo's zoom (1 to 4) and updates the stage and slider without a full redraw. */
	function setZoom( slot, value ) {
		slot.zoom = Math.min( 4, Math.max( 1, value ) );
		renderStage();
		if ( dom.zoomInput ) {
			dom.zoomInput.value = String( slot.zoom );
		}
		clearTimeout( zoomSaveTimer );
		zoomSaveTimer = setTimeout( function () {
			scheduleSave();
			renderStrip();
		}, 300 );
	}

	function renderSlotTools() {
		dom.slotTools.innerHTML = '';
		dom.zoomInput = null;
		var spread = state.design.spreads[ state.current ];
		var slot = state.selectedSlot !== null ? spread.slots[ state.selectedSlot ] : null;
		if ( isLocked() ) {
			return;
		}
		if ( state.armedPhoto ) {
			dom.slotTools.appendChild( el( 'p', { class: 'sad-help', text: 'Now tap a photo space to place the photo.' } ) );
			dom.slotTools.appendChild( el( 'button', { type: 'button', class: 'sad-btn sad-btn--ghost', text: 'Cancel', onclick: function () {
				state.armedPhoto = null;
				renderAll();
			} } ) );
			return;
		}
		if ( ! slot || ! slot.photo ) {
			dom.slotTools.appendChild( el( 'p', { class: 'sad-help', text: 'Drag photos from "Your photos" (or straight from your computer) onto a space, or tap a photo then tap a space. Click a placed photo to move or resize it.' } ) );
			return;
		}
		var zoom = el( 'input', { type: 'range', min: '1', max: '4', step: '0.01', value: String( slot.zoom ), 'aria-label': 'Zoom' } );
		dom.zoomInput = zoom;
		zoom.addEventListener( 'input', function () {
			setZoom( slot, parseFloat( zoom.value ) );
		} );
		dom.slotTools.appendChild( el( 'span', { class: 'sad-zoom' }, [
			el( 'button', { type: 'button', class: 'sad-btn sad-btn--ghost sad-btn--icon', 'aria-label': 'Zoom out', text: '−', onclick: function () {
				setZoom( slot, slot.zoom / 1.15 );
			} } ),
			zoom,
			el( 'button', { type: 'button', class: 'sad-btn sad-btn--ghost sad-btn--icon', 'aria-label': 'Zoom in', text: '+', onclick: function () {
				setZoom( slot, slot.zoom * 1.15 );
			} } ),
		] ) );
		dom.slotTools.appendChild( el( 'button', { type: 'button', class: 'sad-btn sad-btn--ghost', text: 'Reset', onclick: function () {
			slot.zoom = 1;
			slot.x = 0.5;
			slot.y = 0.5;
			scheduleSave();
			renderAll();
		} } ) );
		dom.slotTools.appendChild( el( 'button', { type: 'button', class: 'sad-btn sad-btn--ghost', text: 'Remove photo', onclick: function () {
			spread.slots[ state.selectedSlot ] = emptySlot();
			scheduleSave();
			renderAll();
		} } ) );
		dom.slotTools.appendChild( el( 'span', { class: 'sad-help', text: 'Drag the photo to move it. Scroll or use + and − to make it bigger or smaller.' } ) );
	}

	function renderLayouts() {
		dom.layouts.innerHTML = '';
		if ( isLocked() ) {
			dom.layoutsSection.hidden = true;
			return;
		}
		var spread = state.design.spreads[ state.current ];
		config.layouts.forEach( function ( layout ) {
			var icon = el( 'span', { class: 'sad-layout__icon' } );
			icon.style.aspectRatio = String( spreadAspect() );
			layout.slots.forEach( function ( rect ) {
				var box = el( 'span', { class: 'sad-layout__box' } );
				box.style.left = rect[ 0 ] * 100 + '%';
				box.style.top = rect[ 1 ] * 100 + '%';
				box.style.width = rect[ 2 ] * 100 + '%';
				box.style.height = rect[ 3 ] * 100 + '%';
				icon.appendChild( box );
			} );
			dom.layouts.appendChild( el( 'button', {
				type: 'button',
				class: 'sad-layout' + ( spread.layout === layout.id ? ' sad-layout--active' : '' ),
				title: layout.name,
				'aria-label': layout.name,
				'aria-pressed': spread.layout === layout.id ? 'true' : 'false',
				onclick: function () {
					if ( spread.layout !== layout.id ) {
						setLayout( layout.id );
					}
				},
			}, [ icon ] ) );
		} );
	}

	function renderStrip() {
		dom.strip.innerHTML = '';
		state.design.spreads.forEach( function ( spread, index ) {
			var mini = el( 'div', { class: 'sad-mini' } );
			drawSpread( mini, spread, 120, false );
			dom.strip.appendChild( el( 'button', {
				type: 'button',
				class: 'sad-thumb' + ( index === state.current ? ' sad-thumb--active' : '' ),
				'aria-label': 'Go to spread ' + ( index + 1 ),
				onclick: function () {
					goTo( index );
				},
			}, [ mini, el( 'span', { class: 'sad-thumb__num', text: String( index + 1 ) } ) ] ) );
		} );
		if ( ! isLocked() ) {
			dom.strip.appendChild( el( 'button', { type: 'button', class: 'sad-thumb sad-thumb--add', text: '+ Add spread', onclick: addSpread } ) );
		}
		var active = dom.strip.querySelector( '.sad-thumb--active' );
		if ( active && active.scrollIntoView ) {
			active.scrollIntoView( { block: 'nearest', inline: 'nearest' } );
		}
	}

	function renderTray() {
		dom.tray.innerHTML = '';
		var counts = usageCounts();
		dom.trayCount.textContent = state.photoOrder.length ? '(' + state.photoOrder.length + ')' : '';
		if ( ! state.photoOrder.length ) {
			dom.tray.appendChild( el( 'p', { class: 'sad-help', text: isLocked() ? 'No photos.' : 'Add your photos to start. JPEG or PNG, full size is best for printing.' } ) );
		}
		state.photoOrder.forEach( function ( id ) {
			var photo = state.photos[ id ];
			var img = el( 'img', { src: photo.preview, alt: photo.name, loading: 'lazy', draggable: 'false' } );
			var item = el( 'div', {
				class: 'sad-photo' + ( state.armedPhoto === id ? ' sad-photo--armed' : '' ) + ( counts[ id ] ? ' sad-photo--used' : '' ) + ( state.selected[ id ] ? ' sad-photo--selected' : '' ),
				draggable: isLocked() || state.selecting ? 'false' : 'true',
				'aria-pressed': state.selecting ? ( state.selected[ id ] ? 'true' : 'false' ) : null,
				tabindex: '0',
				role: 'button',
				'aria-label': photo.name + ( counts[ id ] ? ', used in album' : '' ),
				title: photo.name,
			}, [
				img,
				counts[ id ] && ! state.selecting ? el( 'span', { class: 'sad-photo__used', text: '✓' } ) : null,
				state.selecting ? el( 'span', { class: 'sad-photo__check', 'aria-hidden': 'true', text: state.selected[ id ] ? '✓' : '' } ) : null,
			] );

			if ( ! isLocked() ) {
				item.addEventListener( 'dragstart', function ( event ) {
					event.dataTransfer.setData( 'text/x-sad-photo', id );
					event.dataTransfer.effectAllowed = 'copy';
				} );
				item.addEventListener( 'click', function () {
					if ( state.selecting ) {
						if ( state.selected[ id ] ) {
							delete state.selected[ id ];
						} else {
							state.selected[ id ] = true;
						}
						renderTray();
						renderAutoFill();
						return;
					}
					var spread = state.design.spreads[ state.current ];
					if ( state.selectedSlot !== null && spread.slots[ state.selectedSlot ] && ! spread.slots[ state.selectedSlot ].photo ) {
						placePhoto( state.selectedSlot, id );
						return;
					}
					state.armedPhoto = state.armedPhoto === id ? null : id;
					renderAll();
				} );
				item.addEventListener( 'keydown', function ( event ) {
					if ( event.key === 'Enter' || event.key === ' ' ) {
						event.preventDefault();
						item.click();
					}
				} );
				if ( ! state.selecting ) {
					item.appendChild( el( 'button', {
						type: 'button',
						class: 'sad-photo__delete',
						'aria-label': 'Delete ' + photo.name,
						text: '×',
						onclick: function ( event ) {
							event.stopPropagation();
							deletePhoto( id );
						},
					} ) );
				}
			}
			dom.tray.appendChild( item );
		} );
	}

	function renderTrayTools() {
		var count = Object.keys( state.selected ).filter( function ( id ) {
			return state.photos[ id ];
		} ).length;
		dom.selectToggle.hidden = isLocked() || ! state.photoOrder.length;
		dom.selectToggle.textContent = state.selecting ? 'Done selecting' : 'Select photos';
		dom.selectAll.hidden = ! state.selecting;
		dom.selectNone.hidden = ! state.selecting || ! count;
		dom.selectHelp.hidden = ! state.selecting;
		dom.selectHelp.textContent = count ? count + ' selected' : 'Tap photos to choose the ones for your album.';
	}

	function renderAutoFill() {
		renderTrayTools();
		dom.autoSection.hidden = isLocked() || ! config.templates || ! config.templates.length;
		if ( dom.autoSection.hidden ) {
			return;
		}
		dom.templates.innerHTML = '';
		config.templates.forEach( function ( template ) {
			var icons = el( 'span', { class: 'sad-template__icons', 'aria-hidden': 'true' } );
			template.layouts.slice( 0, 3 ).forEach( function ( id ) {
				if ( ! layouts[ id ] ) {
					return;
				}
				var icon = el( 'span', { class: 'sad-layout__icon' } );
				icon.style.aspectRatio = String( spreadAspect() );
				layouts[ id ].slots.forEach( function ( rect ) {
					var box = el( 'span', { class: 'sad-layout__box' } );
					box.style.left = rect[ 0 ] * 100 + '%';
					box.style.top = rect[ 1 ] * 100 + '%';
					box.style.width = rect[ 2 ] * 100 + '%';
					box.style.height = rect[ 3 ] * 100 + '%';
					icon.appendChild( box );
				} );
				icons.appendChild( icon );
			} );
			dom.templates.appendChild( el( 'button', {
				type: 'button',
				class: 'sad-template' + ( state.template === template.id ? ' sad-template--active' : '' ),
				'aria-pressed': state.template === template.id ? 'true' : 'false',
				onclick: function () {
					state.template = template.id;
					renderAutoFill();
				},
			}, [ icons, el( 'strong', { text: template.name } ), el( 'span', { class: 'sad-help', text: template.description } ) ] ) );
		} );
		var picked = Object.keys( state.selected ).filter( function ( id ) {
			return state.photos[ id ];
		} ).length;
		dom.autoInfo.textContent = ! state.photoOrder.length
			? 'Add photos first.'
			: picked
				? 'Uses your ' + picked + ' selected photos, in order.'
				: 'Uses all ' + state.photoOrder.length + ' photos, in order. To use fewer, tap "Select photos" below.';
		dom.autoButton.disabled = ! state.photoOrder.length;
	}

	function renderUploadStatus() {
		if ( ! dom.uploadStatus ) {
			return;
		}
		if ( state.uploading > 0 ) {
			dom.uploadStatus.textContent = 'Uploading… ' + state.uploadDone + ' done, ' + state.uploading + ' to go. Keep this page open.';
		} else {
			dom.uploadStatus.textContent = state.uploadDone ? 'Uploads finished.' : '';
			state.uploadDone = 0;
		}
	}

	function renderHeader() {
		dom.title.textContent = state.album.title;
		dom.size.textContent = state.album.size.name;
		dom.approve.hidden = isLocked();
		dom.approve.disabled = false;
		dom.banner.hidden = ! isLocked();
		dom.status.hidden = isLocked();
		dom.spreadTools.hidden = isLocked();
		dom.upload.hidden = isLocked();
	}

	function renderAll() {
		renderHeader();
		renderStage();
		renderSlotTools();
		renderLayouts();
		renderStrip();
		renderTray();
		renderAutoFill();
	}

	/* ---------- setup ---------- */

	function build() {
		root.innerHTML = '';
		dom.toasts = el( 'div', { class: 'sad-toasts', 'aria-live': 'polite' } );
		dom.title = el( 'h2', { class: 'sad-title' } );
		dom.size = el( 'span', { class: 'sad-size' } );
		dom.status = el( 'span', { class: 'sad-status', text: 'All changes saved' } );
		dom.approve = el( 'button', { type: 'button', class: 'sad-btn sad-btn--primary', text: 'Approve for printing', onclick: approve } );
		dom.banner = el( 'div', { class: 'sad-banner', text: 'This album is approved and locked for printing. Contact us if you need a change.' } );

		dom.prev = el( 'button', { type: 'button', class: 'sad-btn sad-btn--ghost', 'aria-label': 'Previous spread', text: '‹ Prev', onclick: function () {
			goTo( state.current - 1 );
		} } );
		dom.next = el( 'button', { type: 'button', class: 'sad-btn sad-btn--ghost', 'aria-label': 'Next spread', text: 'Next ›', onclick: function () {
			goTo( state.current + 1 );
		} } );
		dom.spreadLabel = el( 'span', { class: 'sad-spread-label' } );
		dom.spreadTools = el( 'span', { class: 'sad-spread-tools' }, [
			el( 'button', { type: 'button', class: 'sad-btn sad-btn--ghost', title: 'Move spread earlier', text: '← Move', onclick: function () {
				moveSpread( -1 );
			} } ),
			el( 'button', { type: 'button', class: 'sad-btn sad-btn--ghost', title: 'Move spread later', text: 'Move →', onclick: function () {
				moveSpread( 1 );
			} } ),
			el( 'button', { type: 'button', class: 'sad-btn sad-btn--ghost sad-btn--danger', text: 'Delete spread', onclick: deleteSpread } ),
		] );

		dom.stage = el( 'div', { class: 'sad-stage' } );
		dom.stageWrap = el( 'div', { class: 'sad-stage-wrap' }, [ dom.stage ] );
		dom.slotTools = el( 'div', { class: 'sad-slot-tools' } );
		dom.layouts = el( 'div', { class: 'sad-layouts' } );
		dom.layoutsSection = el( 'section', { class: 'sad-section' }, [ el( 'h3', { text: 'Layout for this spread' } ), dom.layouts ] );
		dom.strip = el( 'div', { class: 'sad-strip' } );

		var fileInput = el( 'input', { type: 'file', accept: 'image/jpeg,image/png', multiple: true, class: 'sad-file' } );
		fileInput.addEventListener( 'change', function () {
			uploadFiles( fileInput.files );
			fileInput.value = '';
		} );
		dom.upload = el( 'label', { class: 'sad-btn sad-btn--primary sad-upload' }, [ 'Add photos', fileInput ] );
		dom.uploadStatus = el( 'span', { class: 'sad-help', 'aria-live': 'polite' } );
		dom.trayCount = el( 'span', { class: 'sad-count' } );
		dom.tray = el( 'div', { class: 'sad-tray' } );
		dom.selectToggle = el( 'button', { type: 'button', class: 'sad-btn sad-btn--ghost', onclick: function () {
			state.selecting = ! state.selecting;
			state.armedPhoto = null;
			renderTray();
			renderAutoFill();
		} } );
		dom.selectAll = el( 'button', { type: 'button', class: 'sad-btn sad-btn--ghost', text: 'Select all', onclick: function () {
			state.photoOrder.forEach( function ( id ) {
				state.selected[ id ] = true;
			} );
			renderTray();
			renderAutoFill();
		} } );
		dom.selectNone = el( 'button', { type: 'button', class: 'sad-btn sad-btn--ghost', text: 'Clear', onclick: function () {
			state.selected = {};
			renderTray();
			renderAutoFill();
		} } );
		dom.selectHelp = el( 'span', { class: 'sad-help' } );
		var traySection = el( 'section', { class: 'sad-section sad-tray-section' }, [
			el( 'div', { class: 'sad-row' }, [ el( 'h3', {}, [ 'Your photos ', dom.trayCount ] ), dom.upload, dom.selectToggle, dom.selectAll, dom.selectNone, dom.selectHelp, dom.uploadStatus ] ),
			dom.tray,
		] );

		dom.templates = el( 'div', { class: 'sad-templates' } );
		dom.autoInfo = el( 'p', { class: 'sad-help' } );
		dom.autoButton = el( 'button', { type: 'button', class: 'sad-btn sad-btn--primary', text: 'Auto-fill my album', onclick: autoFill } );
		dom.autoSection = el( 'section', { class: 'sad-section sad-auto' }, [
			el( 'h3', { text: 'Auto-fill' } ),
			el( 'p', { class: 'sad-help', text: 'Pick a style and we will lay your photos out across the album for you. You can change anything afterwards.' } ),
			dom.templates,
			el( 'div', { class: 'sad-row' }, [ dom.autoButton, dom.autoInfo ] ),
		] );

		root.appendChild( el( 'div', { class: 'sad-header' }, [
			el( 'div', {}, [ dom.title, dom.size ] ),
			el( 'div', { class: 'sad-header__actions' }, [ dom.status, dom.approve ] ),
		] ) );
		root.appendChild( dom.banner );
		// The photo tray sits beside the spread (pinned to the bottom on narrow screens) and stays in
		// view while scrolling, so photos can always be dragged straight onto the spread.
		root.appendChild( el( 'div', { class: 'sad-workspace' }, [
			el( 'div', { class: 'sad-main' }, [
				el( 'div', { class: 'sad-nav' }, [ dom.prev, dom.spreadLabel, dom.next, dom.spreadTools ] ),
				dom.stageWrap,
				dom.slotTools,
				el( 'section', { class: 'sad-section' }, [ el( 'h3', { text: 'All spreads' } ), dom.strip ] ),
				dom.layoutsSection,
				dom.autoSection,
			] ),
			el( 'aside', { class: 'sad-side' }, [ traySection ] ),
		] ) );
		dom.dropOverlay = el( 'div', { class: 'sad-drop-overlay', hidden: true }, [ el( 'span', { text: 'Drop your photos to add them' } ) ] );
		root.appendChild( dom.dropOverlay );
		root.appendChild( dom.toasts );
		enableFileDrop();
		measureStickyHeader();

		var resizeTimer;
		window.addEventListener( 'resize', function () {
			clearTimeout( resizeTimer );
			resizeTimer = setTimeout( function () {
				measureStickyHeader();
				renderStage();
			}, 100 );
		} );
	}

	function hasFiles( event ) {
		return event.dataTransfer && Array.prototype.indexOf.call( event.dataTransfer.types || [], 'Files' ) !== -1;
	}

	/**
	 * Photos dragged in from the computer can be dropped anywhere on the page. Dropped on a photo
	 * space, the first one is placed there once it has uploaded.
	 */
	function enableFileDrop() {
		var depth = 0;
		document.addEventListener( 'dragenter', function ( event ) {
			if ( ! hasFiles( event ) || isLocked() ) {
				return;
			}
			depth += 1;
			dom.dropOverlay.hidden = false;
		} );
		document.addEventListener( 'dragleave', function ( event ) {
			if ( ! hasFiles( event ) ) {
				return;
			}
			depth = Math.max( 0, depth - 1 );
			if ( ! depth ) {
				dom.dropOverlay.hidden = true;
			}
		} );
		document.addEventListener( 'dragover', function ( event ) {
			if ( hasFiles( event ) ) {
				// Stops the browser opening the photo instead of uploading it.
				event.preventDefault();
				event.dataTransfer.dropEffect = isLocked() ? 'none' : 'copy';
			}
		} );
		document.addEventListener( 'drop', function ( event ) {
			if ( ! hasFiles( event ) ) {
				return;
			}
			event.preventDefault();
			depth = 0;
			dom.dropOverlay.hidden = true;
			if ( isLocked() || ! event.dataTransfer.files.length ) {
				return;
			}
			var slot = event.target.closest ? event.target.closest( '.sad-stage .sad-slot' ) : null;
			var target = slot ? { spread: state.design.spreads[ state.current ], slot: parseInt( slot.getAttribute( 'data-index' ), 10 ) } : null;
			uploadFiles( event.dataTransfer.files, target );
		} );
	}

	/** Keeps the tray and the editor clear of a theme's fixed or sticky header. */
	function measureStickyHeader() {
		var offset = 0;
		var nodes = document.elementsFromPoint ? document.elementsFromPoint( window.innerWidth / 2, 2 ) : [];
		nodes.forEach( function ( node ) {
			for ( var n = node; n && n !== document.body && n !== root; n = n.parentElement ) {
				var position = window.getComputedStyle( n ).position;
				if ( position === 'fixed' || position === 'sticky' ) {
					offset = Math.max( offset, Math.round( n.getBoundingClientRect().bottom ) );
					break;
				}
			}
		} );
		state.headerOffset = Math.min( offset, 200 );
		root.style.setProperty( '--sad-top', state.headerOffset + 16 + 'px' );
	}

	function applyAlbum( data ) {
		state.album = data;
		state.design = data.design;
		state.photos = {};
		state.photoOrder = [];
		data.photos.forEach( function ( photo ) {
			state.photos[ photo.id ] = photo;
			state.photoOrder.push( photo.id );
		} );
		state.current = Math.min( state.current, state.design.spreads.length - 1 );
		state.selectedSlot = null;
		state.armedPhoto = null;
		renderAll();
	}

	function load() {
		return api( '' ).then( function ( data ) {
			if ( ! dom.stage ) {
				build();
			}
			applyAlbum( data );
			if ( config.welcome ) {
				config.welcome = false;
				toast( 'Your album is ready! We have emailed you this private link so you can come back any time.', 'success' );
				if ( window.history.replaceState ) {
					window.history.replaceState( null, '', window.location.href.replace( /[?&]sad_welcome=1/, '' ) );
				}
			}
		} ).catch( function ( error ) {
			root.innerHTML = '';
			root.appendChild( el( 'div', { class: 'sad-message', text: error.message } ) );
		} );
	}

	load();
} )();
