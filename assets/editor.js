/**
 * Snippets Album Designer: the client-facing editor.
 *
 * The client uploads photos and lays out each spread with photo boxes that can be moved, resized,
 * rotated and stacked anywhere, starting from a layout or from scratch, then approves the album.
 * Everything autosaves, and changes can be undone.
 *
 * Box positions are fractions of the trimmed spread (see sad_get_design() in includes/albums.php).
 * Crop maths must match sad_draw_item() in includes/render.php: in "fill" a photo covers its box,
 * is enlarged by `zoom`, and `px`/`py` (0 to 1) choose which part of the overflow shows, which is
 * exactly how CSS background-position percentages work; in "fit" it is contained and centred.
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

	var HANDLES = [ 'nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w' ];
	var MIN_BOX_MM = 5;

	var state = {
		album: null,
		design: null,
		photos: {},
		photoOrder: [],
		current: 0,
		selectedItem: null,
		adjusting: false,
		swapFrom: null,
		armedPhoto: null,
		selecting: false,
		selected: {},
		template: config.templates && config.templates.length ? config.templates[0].id : null,
		tab: 'photos',
		history: [],
		future: [],
		checkpointKey: null,
		checkpointTime: 0,
		scale: 1,
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

	function clamp( value, min, max ) {
		return Math.min( max, Math.max( min, value ) );
	}

	/** Spread sizes in mm: trimmed (tw x th), bleed per edge, safe margin. */
	function geo() {
		var size = state.album.size;
		var tw = size.width_mm * 2;
		var th = size.height_mm;
		var b = state.album.bleedMm || 0;
		return { tw: tw, th: th, b: b, cw: tw + 2 * b, ch: th + 2 * b, safe: state.album.safeMm || 0 };
	}

	function spreadAspect() {
		var g = geo();
		return g.tw / g.th;
	}

	/** The spread being edited. The cover is spread -1: back cover on the left, front on the right. */
	function currentSpread() {
		return state.current === -1 ? state.design.cover : state.design.spreads[ state.current ];
	}

	function onCover() {
		return state.current === -1;
	}

	/** A leather or linen cover: chosen, not designed, so it holds no photo boxes. */
	function boundCover() {
		return onCover() && state.design.cover.type !== 'photo';
	}

	function coverMaterial( cover ) {
		return ( state.album.covers || {} )[ cover.type ] || { name: '', colours: {} };
	}

	function coverColour( cover ) {
		var colour = coverMaterial( cover ).colours[ cover.colour ];
		return colour ? colour.hex : '#3b2619';
	}

	/** Where a spread is, in words: "the cover" or "spread 3". */
	function spreadName( index ) {
		return index === -1 ? 'the cover' : 'spread ' + ( index + 1 );
	}

	function spreadExists( spread ) {
		return spread === state.design.cover || state.design.spreads.indexOf( spread ) !== -1;
	}

	function selectedItem() {
		var spread = currentSpread();
		return state.selectedItem !== null && spread ? spread.items[ state.selectedItem ] || null : null;
	}

	/** A box in mm from the top left of the trimmed spread. */
	function itemMm( item ) {
		var g = geo();
		return { x: item.x * g.tw, y: item.y * g.th, w: item.w * g.tw, h: item.h * g.th };
	}

	function setItemMm( item, rect ) {
		var g = geo();
		item.x = rect.x / g.tw;
		item.y = rect.y / g.th;
		item.w = rect.w / g.tw;
		item.h = rect.h / g.th;
	}

	function newItem( photo, x, y, w, h ) {
		return { photo: photo || null, x: x, y: y, w: w, h: h, rot: 0, fit: 'fill', zoom: 1, px: 0.5, py: 0.5 };
	}

	/** Boxes for a layout; edges touching the trim run out into the bleed. Matches sad_layout_items(). */
	function layoutItems( layoutId, photoIds ) {
		var g = geo();
		var bx = g.b / g.tw;
		var by = g.b / g.th;
		var eps = 0.001;
		var slots = ( layouts[ layoutId ] || layouts[ 'two-full' ] ).slots;
		return slots.map( function ( r, i ) {
			var x = r[ 0 ] <= eps ? -bx : r[ 0 ];
			var y = r[ 1 ] <= eps ? -by : r[ 1 ];
			var x2 = r[ 0 ] + r[ 2 ] >= 1 - eps ? 1 + bx : r[ 0 ] + r[ 2 ];
			var y2 = r[ 1 ] + r[ 3 ] >= 1 - eps ? 1 + by : r[ 1 ] + r[ 3 ];
			return newItem( ( photoIds || [] )[ i ], x, y, x2 - x, y2 - y );
		} );
	}

	/** Spread numbers (from 1) each photo is used on. */
	/** Where each photo is used: a list of "Cover" and spread numbers. */
	function usage() {
		var used = {};
		function note( spread, label ) {
			spread.items.forEach( function ( item ) {
				if ( item.photo ) {
					used[ item.photo ] = used[ item.photo ] || [];
					if ( used[ item.photo ].indexOf( label ) === -1 ) {
						used[ item.photo ].push( label );
					}
				}
			} );
		}
		if ( state.design.cover.type === 'photo' ) {
			note( state.design.cover, 'Cover' );
		}
		state.design.spreads.forEach( function ( spread, n ) {
			note( spread, n + 1 );
		} );
		return used;
	}

	function usageText( list ) {
		return list.map( function ( where ) {
			return where === 'Cover' ? 'cover' : 'spread ' + where;
		} ).join( ', ' );
	}

	/** Effective print resolution of a photo in its box, in dots per inch. */
	function itemDpi( item ) {
		var photo = item.photo && state.photos[ item.photo ];
		if ( ! photo ) {
			return null;
		}
		var m = itemMm( item );
		var mmPerPx = item.fit === 'fit'
			? Math.min( m.w / photo.width, m.h / photo.height )
			: Math.max( m.w / photo.width, m.h / photo.height ) * item.zoom;
		return Math.round( 25.4 / mmPerPx );
	}

	function isLowRes( item ) {
		var dpi = itemDpi( item );
		return dpi !== null && dpi < state.album.minDpi;
	}

	/* ---------- undo ---------- */

	/**
	 * Call before changing the design. Changes with the same key in quick succession (a slider
	 * being dragged, arrow-key nudges) undo together.
	 */
	function checkpoint( key ) {
		var now = Date.now();
		if ( key && key === state.checkpointKey && now - state.checkpointTime < 1200 ) {
			state.checkpointTime = now;
			return;
		}
		state.checkpointKey = key || null;
		state.checkpointTime = now;
		state.history.push( JSON.stringify( state.design ) );
		if ( state.history.length > 80 ) {
			state.history.shift();
		}
		state.future = [];
		renderUndo();
	}

	function undo() {
		if ( ! state.history.length || isLocked() ) {
			return;
		}
		state.future.push( JSON.stringify( state.design ) );
		state.design = JSON.parse( state.history.pop() );
		afterHistory();
	}

	function redo() {
		if ( ! state.future.length || isLocked() ) {
			return;
		}
		state.history.push( JSON.stringify( state.design ) );
		state.design = JSON.parse( state.future.pop() );
		afterHistory();
	}

	function afterHistory() {
		state.checkpointKey = null;
		state.current = clamp( state.current, -1, state.design.spreads.length - 1 );
		state.selectedItem = null;
		state.adjusting = false;
		state.swapFrom = null;
		scheduleSave();
		renderAll();
	}

	function renderUndo() {
		if ( dom.undo ) {
			dom.undo.disabled = ! state.history.length;
			dom.redo.disabled = ! state.future.length;
		}
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

	/** Saves, and redraws everything that shows the design. */
	function changed() {
		scheduleSave();
		renderAll();
	}

	/* ---------- design changes ---------- */

	function setLayout( layoutId ) {
		if ( boundCover() ) {
			toast( 'Choose "Printed photo cover" on the right to put photos on the cover.' );
			return;
		}
		var spread = currentSpread();
		var placed = spread.items.filter( function ( item ) {
			return item.photo;
		} ).map( function ( item ) {
			return item.photo;
		} );
		var count = layouts[ layoutId ].slots.length;
		checkpoint();
		spread.layout = layoutId;
		spread.items = layoutItems( layoutId, placed );
		state.selectedItem = null;
		if ( placed.length > count ) {
			toast( 'This layout has fewer boxes, so ' + ( placed.length - count ) + ' photo(s) were taken off this spread. Undo brings them back.' );
		}
		changed();
	}

	function selectItem( index ) {
		if ( state.selectedItem !== index ) {
			state.adjusting = false;
		}
		state.selectedItem = index;
		renderSelection();
		renderItemTools();
	}

	function placeInItem( index, photoId ) {
		var item = currentSpread().items[ index ];
		checkpoint();
		item.photo = photoId;
		item.zoom = 1;
		item.px = 0.5;
		item.py = 0.5;
		state.selectedItem = index;
		state.armedPhoto = null;
		changed();
	}

	/**
	 * Adds a box for a photo, shaped like the photo and centred on a point (mm from the top left of
	 * the trimmed spread), or on the right-hand page.
	 */
	function addItem( photoId, at ) {
		if ( boundCover() ) {
			toast( 'Choose "Printed photo cover" on the right to put photos on the cover.' );
			return;
		}
		var spread = currentSpread();
		var g = geo();
		var photo = photoId && state.photos[ photoId ];
		var ratio = photo ? photo.width / photo.height : 1.5;
		var h = g.th * 0.5;
		var w = h * ratio;
		if ( w > g.tw * 0.45 ) {
			w = g.tw * 0.45;
			h = w / ratio;
		}
		var cx = at ? at.x : g.tw * 0.75;
		var cy = at ? at.y : g.th / 2;
		cx = clamp( cx, w / 2 - g.b, g.tw + g.b - w / 2 );
		cy = clamp( cy, h / 2 - g.b, g.th + g.b - h / 2 );
		var item = newItem( photoId, 0, 0, 0, 0 );
		setItemMm( item, { x: cx - w / 2, y: cy - h / 2, w: w, h: h } );
		checkpoint();
		spread.items.push( item );
		spread.layout = null;
		state.selectedItem = spread.items.length - 1;
		state.armedPhoto = null;
		changed();
	}

	function deleteItem( index ) {
		checkpoint();
		currentSpread().items.splice( index, 1 );
		currentSpread().layout = null;
		state.selectedItem = null;
		state.adjusting = false;
		changed();
	}

	function duplicateItem( index ) {
		var spread = currentSpread();
		var copy = JSON.parse( JSON.stringify( spread.items[ index ] ) );
		var g = geo();
		copy.x += 8 / g.tw;
		copy.y += 8 / g.th;
		checkpoint();
		spread.items.push( copy );
		spread.layout = null;
		state.selectedItem = spread.items.length - 1;
		changed();
	}

	/** Moves a box up (+1) or down (-1) the stack, or to the top/bottom (+/-Infinity). */
	function restack( index, delta ) {
		var items = currentSpread().items;
		var to = clamp( delta === Infinity ? items.length - 1 : delta === -Infinity ? 0 : index + delta, 0, items.length - 1 );
		if ( to === index ) {
			return;
		}
		checkpoint();
		items.splice( to, 0, items.splice( index, 1 )[ 0 ] );
		state.selectedItem = to;
		changed();
	}

	/** Swaps the photos of two boxes, each keeping its own crop. */
	function swapItems( from, to ) {
		var items = currentSpread().items;
		if ( from === to || ! items[ from ] || ! items[ to ] ) {
			return;
		}
		checkpoint();
		[ 'photo', 'zoom', 'px', 'py', 'fit' ].forEach( function ( key ) {
			var value = items[ from ][ key ];
			items[ from ][ key ] = items[ to ][ key ];
			items[ to ][ key ] = value;
		} );
		state.swapFrom = null;
		state.selectedItem = to;
		changed();
	}

	function setFit( item, fit ) {
		if ( item.fit === fit ) {
			return;
		}
		checkpoint();
		item.fit = fit;
		item.zoom = 1;
		item.px = 0.5;
		item.py = 0.5;
		changed();
	}

	/** Reshapes a box to match its photo, keeping its width and centre, so nothing is cropped. */
	function matchPhotoShape( item ) {
		var photo = state.photos[ item.photo ];
		if ( ! photo ) {
			return;
		}
		var m = itemMm( item );
		var h = m.w * photo.height / photo.width;
		checkpoint();
		setItemMm( item, { x: m.x, y: m.y + ( m.h - h ) / 2, w: m.w, h: h } );
		item.zoom = 1;
		item.px = 0.5;
		item.py = 0.5;
		changed();
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
			}
			next += ids.length;
			result.push( { layout: layout, items: layoutItems( layout, ids ) } );
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
			return spread.items.some( function ( item ) {
				return item.photo;
			} );
		} );
		if ( hasWork && ! window.confirm( 'Auto-fill replaces the spreads you have now with a new layout. You can undo it afterwards. Continue?' ) ) {
			return;
		}
		var filled = buildAutoFill( template, photoIds );
		checkpoint();
		state.design.spreads = filled.spreads;
		state.current = 0;
		state.selectedItem = null;
		state.armedPhoto = null;
		state.selecting = false;
		changed();
		var message = 'Placed ' + filled.placed + ' photos on ' + filled.spreads.length + ' spreads. Move, resize or swap anything you like.';
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
		checkpoint();
		state.design.spreads.splice( state.current + 1, 0, { layout: 'two-full', items: layoutItems( 'two-full' ) } );
		state.current += 1;
		state.selectedItem = null;
		changed();
	}

	function deleteSpread() {
		if ( onCover() || state.design.spreads.length <= 1 ) {
			return;
		}
		if ( ! window.confirm( 'Delete spread ' + ( state.current + 1 ) + '? The photos stay in your photo tray.' ) ) {
			return;
		}
		checkpoint();
		state.design.spreads.splice( state.current, 1 );
		state.current = Math.min( state.current, state.design.spreads.length - 1 );
		state.selectedItem = null;
		changed();
	}

	function moveSpread( delta ) {
		var to = state.current + delta;
		if ( onCover() || to < 0 || to >= state.design.spreads.length ) {
			return;
		}
		checkpoint();
		var spreads = state.design.spreads;
		spreads.splice( to, 0, spreads.splice( state.current, 1 )[ 0 ] );
		state.current = to;
		changed();
	}

	function goTo( index ) {
		state.current = clamp( index, -1, state.design.spreads.length - 1 );
		state.selectedItem = null;
		state.adjusting = false;
		state.swapFrom = null;
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
	 * @param {Object}   [target] { spread, item } to place the first photo in a box once uploaded,
	 *                            or { spread, at: {x, y} } to add a box for it there (mm).
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
						if ( target && spreadExists( target.spread ) && ! isLocked() ) {
							var place = target;
							target = null;
							if ( place.item && place.spread.items.indexOf( place.item ) !== -1 ) {
								checkpoint();
								place.item.photo = photo.id;
								place.item.zoom = 1;
								place.item.px = 0.5;
								place.item.py = 0.5;
								changed();
								return;
							}
							if ( place.at && place.spread === currentSpread() ) {
								addItem( photo.id, place.at );
								return;
							}
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
		var used = usage()[ photoId ];
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
				spread.items.forEach( function ( item ) {
					if ( item.photo === photoId ) {
						item.photo = null;
					}
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
		var cover = state.design.cover;
		if ( cover.type === 'photo' ) {
			if ( ! cover.items.length ) {
				list.push( 'The printed cover has no photos. Add some, or choose leather or linen.' );
			} else if ( cover.items.some( function ( item ) {
				return ! item.photo || ! state.photos[ item.photo ];
			} ) ) {
				list.push( 'The cover has an empty photo box. Add a photo or delete the box.' );
			}
		}
		if ( state.design.spreads.length < state.album.minSpreads ) {
			list.push( 'Your album needs at least ' + state.album.minSpreads + ' spreads (it has ' + state.design.spreads.length + ').' );
		}
		state.design.spreads.forEach( function ( spread, n ) {
			if ( ! spread.items.length ) {
				list.push( 'Spread ' + ( n + 1 ) + ' is blank.' );
			} else if ( spread.items.some( function ( item ) {
				return ! item.photo || ! state.photos[ item.photo ];
			} ) ) {
				list.push( 'Spread ' + ( n + 1 ) + ' has an empty photo box. Add a photo or delete the box.' );
			}
		} );
		return list;
	}

	function lowResolution() {
		var list = [];
		if ( state.design.cover.type === 'photo' && state.design.cover.items.some( isLowRes ) ) {
			list.push( 'cover' );
		}
		state.design.spreads.forEach( function ( spread, n ) {
			if ( spread.items.some( isLowRes ) ) {
				list.push( n + 1 );
			}
		} );
		return list;
	}

	function approve() {
		var issues = problems();
		if ( issues.length ) {
			window.alert( 'Before approving:\n\n' + issues.slice( 0, 8 ).join( '\n' ) + ( issues.length > 8 ? '\n…' : '' ) );
			return;
		}
		var soft = lowResolution();
		var message = 'Approve your album for printing?\n\nAfter you approve, the design is locked and goes to print. Please check every spread first, and keep faces and text inside the green safe lines.';
		if ( soft.length ) {
			message += '\n\nSome photos may print soft because they are enlarged a lot (' + soft.map( function ( where ) {
				return where === 'cover' ? 'the cover' : 'spread ' + where;
			} ).join( ', ' ) + ').';
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

	/* ---------- drawing ---------- */

	/** Positions a box (or its selection frame) at `scale` px per mm, offset by `off` mm. */
	function position( node, item, scale, off ) {
		var m = itemMm( item );
		node.style.left = ( off + m.x ) * scale + 'px';
		node.style.top = ( off + m.y ) * scale + 'px';
		node.style.width = m.w * scale + 'px';
		node.style.height = m.h * scale + 'px';
		node.style.transform = item.rot ? 'rotate(' + item.rot + 'deg)' : '';
	}

	function paint( node, item, scale ) {
		var photo = item.photo && state.photos[ item.photo ];
		node.style.backgroundImage = photo ? 'url("' + photo.preview + '")' : '';
		node.classList.toggle( 'sad-item__img--empty', ! photo );
		if ( ! photo ) {
			return;
		}
		if ( item.fit === 'fit' ) {
			node.style.backgroundSize = 'contain';
			node.style.backgroundPosition = '50% 50%';
			return;
		}
		var m = itemMm( item );
		var w = m.w * scale;
		var h = m.h * scale;
		var s = Math.max( w / photo.width, h / photo.height ) * item.zoom;
		node.style.backgroundSize = photo.width * s + 'px ' + photo.height * s + 'px';
		node.style.backgroundPosition = item.px * 100 + '% ' + item.py * 100 + '%';
	}

	/**
	 * Draws a spread's boxes into `container`. The editor stage shows the bleed (offset b mm);
	 * thumbnails show the trimmed spread only (offset 0, overflow hidden).
	 */
	function drawItems( container, spread, scale, off, interactive ) {
		var nodes = [];
		spread.items.forEach( function ( item, index ) {
			var img = el( 'div', { class: 'sad-item__img' } );
			var node = el( 'div', { class: 'sad-item', 'data-index': String( index ) }, [ img ] );
			position( node, item, scale, off );
			paint( img, item, scale );
			if ( interactive ) {
				decorateItem( node, item, index );
			}
			container.appendChild( node );
			nodes.push( node );
		} );
		return nodes;
	}

	function decorateItem( node, item, index ) {
		var photo = item.photo && state.photos[ item.photo ];
		node.setAttribute( 'tabindex', '0' );
		node.setAttribute( 'role', 'button' );
		node.setAttribute( 'aria-label', photo ? 'Photo box ' + ( index + 1 ) + ': ' + photo.name : 'Empty photo box ' + ( index + 1 ) );
		if ( ! photo && ! isLocked() ) {
			node.appendChild( el( 'span', { class: 'sad-item__hint', text: state.armedPhoto ? 'Tap to place' : 'Drop a photo here' } ) );
		}
		if ( photo && isLowRes( item ) ) {
			node.appendChild( el( 'span', {
				class: 'sad-item__warn',
				title: 'About ' + itemDpi( item ) + ' dpi. This photo may print soft at this size.',
				text: '⚠',
			} ) );
		}
		if ( isLocked() ) {
			return;
		}

		node.addEventListener( 'pointerdown', function ( event ) {
			if ( event.button !== 0 ) {
				return;
			}
			if ( state.armedPhoto ) {
				placeInItem( index, state.armedPhoto );
				return;
			}
			if ( state.swapFrom !== null ) {
				if ( state.swapFrom !== index ) {
					swapItems( state.swapFrom, index );
				}
				return;
			}
			event.preventDefault();
			node.focus( { preventScroll: true } );
			if ( state.selectedItem !== index ) {
				selectItem( index );
			}
			if ( state.adjusting && photo && item.fit === 'fill' ) {
				startPan( event, node, item );
			} else {
				startMove( event, node, item, index );
			}
		} );
		node.addEventListener( 'dblclick', function () {
			if ( photo && item.fit === 'fill' ) {
				state.adjusting = ! state.adjusting;
				renderSelection();
				renderItemTools();
			}
		} );
		node.addEventListener( 'dragover', function ( event ) {
			event.preventDefault();
			node.classList.add( 'sad-item--over' );
		} );
		node.addEventListener( 'dragleave', function () {
			node.classList.remove( 'sad-item--over' );
		} );
		node.addEventListener( 'drop', function ( event ) {
			node.classList.remove( 'sad-item--over' );
			if ( hasFiles( event ) ) {
				return; // Handled by the page-wide file drop, which knows this box.
			}
			event.preventDefault();
			event.stopPropagation();
			var id = event.dataTransfer.getData( 'text/x-sad-photo' );
			if ( id && state.photos[ id ] ) {
				placeInItem( index, id );
			}
		} );
		node.addEventListener( 'wheel', function ( event ) {
			if ( state.selectedItem !== index || ! photo || item.fit !== 'fill' ) {
				return;
			}
			event.preventDefault();
			setZoom( item, item.zoom * Math.exp( -event.deltaY * 0.0015 ) );
		}, { passive: false } );
	}

	/** Redraws one box and its selection frame after a drag step, without rebuilding the stage. */
	function refreshItem( index ) {
		var item = currentSpread().items[ index ];
		var node = dom.itemNodes[ index ];
		if ( ! item || ! node ) {
			return;
		}
		var g = geo();
		position( node, item, state.scale, g.b );
		paint( node.firstChild, item, state.scale );
		if ( state.selectedItem === index ) {
			updateSelection();
		}
	}

	/* ---------- dragging ---------- */

	/** Follows a pointer drag on `node`; `move(dxPx, dyPx, event)` runs on each step. */
	function drag( event, node, move, done ) {
		var startX = event.clientX;
		var startY = event.clientY;
		var started = false;
		node.setPointerCapture( event.pointerId );
		function onMove( e ) {
			var dx = e.clientX - startX;
			var dy = e.clientY - startY;
			if ( ! started && Math.abs( dx ) + Math.abs( dy ) < 3 ) {
				return;
			}
			if ( ! started ) {
				started = true;
				checkpoint();
			}
			move( dx, dy, e );
		}
		function onEnd() {
			node.removeEventListener( 'pointermove', onMove );
			node.removeEventListener( 'pointerup', onEnd );
			node.removeEventListener( 'pointercancel', onEnd );
			showSnaps( null, null );
			if ( started ) {
				if ( done ) {
					done();
				}
				scheduleSave();
				renderStrip();
				renderLowResNotice();
				renderItemTools();
			}
		}
		node.addEventListener( 'pointermove', onMove );
		node.addEventListener( 'pointerup', onEnd );
		node.addEventListener( 'pointercancel', onEnd );
	}

	/** Guide lines a box edge or centre snaps to, in mm along one axis of the trimmed spread. */
	function snapLines( axis, except ) {
		var g = geo();
		var len = axis === 'x' ? g.tw : g.th;
		var lines = [ -g.b, 0, len / 2, len, len + g.b ];
		if ( g.safe ) {
			lines.push( g.safe, len - g.safe );
		}
		if ( axis === 'x' ) {
			lines.push( len / 4, len * 3 / 4 );
			if ( g.safe ) {
				lines.push( len / 2 - g.safe, len / 2 + g.safe );
			}
		}
		currentSpread().items.forEach( function ( other, i ) {
			if ( i === except || other.rot ) {
				return;
			}
			var m = itemMm( other );
			var start = axis === 'x' ? m.x : m.y;
			var size = axis === 'x' ? m.w : m.h;
			lines.push( start, start + size / 2, start + size );
		} );
		return lines;
	}

	/** The nearest snap within reach for any of `values`: { d: mm to move, line }. */
	function nearestSnap( values, lines ) {
		var reach = 6 / state.scale;
		var best = null;
		values.forEach( function ( value ) {
			lines.forEach( function ( line ) {
				var d = line - value;
				if ( Math.abs( d ) <= reach && ( ! best || Math.abs( d ) < Math.abs( best.d ) ) ) {
					best = { d: d, line: line };
				}
			} );
		} );
		return best;
	}

	function showSnaps( x, y ) {
		var g = geo();
		dom.snapX.hidden = x === null;
		dom.snapY.hidden = y === null;
		if ( x !== null ) {
			dom.snapX.style.left = ( g.b + x ) * state.scale + 'px';
		}
		if ( y !== null ) {
			dom.snapY.style.top = ( g.b + y ) * state.scale + 'px';
		}
	}

	function startMove( event, node, item, index ) {
		var start = itemMm( item );
		drag( event, node, function ( dx, dy, e ) {
			var rect = { x: start.x + dx / state.scale, y: start.y + dy / state.scale, w: start.w, h: start.h };
			var sx = null;
			var sy = null;
			if ( ! item.rot && ! e.altKey ) {
				var snapX = nearestSnap( [ rect.x, rect.x + rect.w / 2, rect.x + rect.w ], snapLines( 'x', index ) );
				var snapY = nearestSnap( [ rect.y, rect.y + rect.h / 2, rect.y + rect.h ], snapLines( 'y', index ) );
				if ( snapX ) {
					rect.x += snapX.d;
					sx = snapX.line;
				}
				if ( snapY ) {
					rect.y += snapY.d;
					sy = snapY.line;
				}
			}
			showSnaps( sx, sy );
			setItemMm( item, rect );
			currentSpread().layout = null;
			refreshItem( index );
		} );
	}

	/** Drags the photo inside its box (fill mode). */
	function startPan( event, node, item ) {
		var photo = state.photos[ item.photo ];
		var m = itemMm( item );
		var w = m.w * state.scale;
		var h = m.h * state.scale;
		var s = Math.max( w / photo.width, h / photo.height ) * item.zoom;
		var overX = photo.width * s - w;
		var overY = photo.height * s - h;
		var startX = item.px;
		var startY = item.py;
		var a = item.rot * Math.PI / 180;
		var index = state.selectedItem;
		drag( event, node, function ( dx, dy ) {
			// Pointer movement in the box's own (rotated) direction.
			var lx = dx * Math.cos( a ) + dy * Math.sin( a );
			var ly = -dx * Math.sin( a ) + dy * Math.cos( a );
			if ( overX > 0.5 ) {
				item.px = clamp( startX - lx / overX, 0, 1 );
			}
			if ( overY > 0.5 ) {
				item.py = clamp( startY - ly / overY, 0, 1 );
			}
			refreshItem( index );
		} );
	}

	/** Resizes from a handle; the opposite side stays put. Shift keeps the shape. */
	function startResize( event, handle, index ) {
		var item = currentSpread().items[ index ];
		var start = itemMm( item );
		var a = item.rot * Math.PI / 180;
		var cos = Math.cos( a );
		var sin = Math.sin( a );
		var hx = handle.indexOf( 'e' ) !== -1 ? 1 : handle.indexOf( 'w' ) !== -1 ? -1 : 0;
		var hy = handle.indexOf( 's' ) !== -1 ? 1 : handle.indexOf( 'n' ) !== -1 ? -1 : 0;
		var cx = start.x + start.w / 2;
		var cy = start.y + start.h / 2;
		var target = event.currentTarget;
		event.stopPropagation();
		drag( event, target, function ( dx, dy, e ) {
			var mx = dx / state.scale;
			var my = dy / state.scale;
			var lx = mx * cos + my * sin;
			var ly = -mx * sin + my * cos;
			var w = hx ? Math.max( MIN_BOX_MM, start.w + hx * lx ) : start.w;
			var h = hy ? Math.max( MIN_BOX_MM, start.h + hy * ly ) : start.h;
			if ( hx && hy && e.shiftKey ) {
				var f = Math.max( w / start.w, h / start.h );
				w = start.w * f;
				h = start.h * f;
			}
			var sx = null;
			var sy = null;
			if ( ! item.rot && ! e.altKey ) {
				// Snap the edges being dragged.
				if ( hx ) {
					var edgeX = hx > 0 ? start.x + w : start.x + start.w - w;
					var snapX = nearestSnap( [ edgeX ], snapLines( 'x', index ) );
					if ( snapX ) {
						w = Math.max( MIN_BOX_MM, w + hx * snapX.d );
						sx = snapX.line;
					}
				}
				if ( hy ) {
					var edgeY = hy > 0 ? start.y + h : start.y + start.h - h;
					var snapY = nearestSnap( [ edgeY ], snapLines( 'y', index ) );
					if ( snapY ) {
						h = Math.max( MIN_BOX_MM, h + hy * snapY.d );
						sy = snapY.line;
					}
				}
			}
			showSnaps( sx, sy );
			// Move the centre so the opposite edge stays where it was.
			var ox = hx * ( w - start.w ) / 2;
			var oy = hy * ( h - start.h ) / 2;
			var ncx = cx + ox * cos - oy * sin;
			var ncy = cy + ox * sin + oy * cos;
			setItemMm( item, { x: ncx - w / 2, y: ncy - h / 2, w: w, h: h } );
			currentSpread().layout = null;
			refreshItem( index );
		} );
	}

	/** Turns the box around its centre. Snaps to straight angles; Shift turns in 15° steps. */
	function startRotate( event, index ) {
		var item = currentSpread().items[ index ];
		var rect = dom.itemNodes[ index ].getBoundingClientRect();
		var cx = rect.left + rect.width / 2;
		var cy = rect.top + rect.height / 2;
		var target = event.currentTarget;
		event.stopPropagation();
		var startRot = item.rot;
		var startAngle = Math.atan2( event.clientY - cy, event.clientX - cx ) * 180 / Math.PI;
		drag( event, target, function ( dx, dy, e ) {
			var angle = startRot + Math.atan2( e.clientY - cy, e.clientX - cx ) * 180 / Math.PI - startAngle;
			if ( e.shiftKey ) {
				angle = Math.round( angle / 15 ) * 15;
			} else {
				var straight = Math.round( angle / 90 ) * 90;
				if ( Math.abs( angle - straight ) < 4 ) {
					angle = straight;
				}
			}
			angle = ( ( angle + 540 ) % 360 ) - 180;
			item.rot = Math.round( angle * 10 ) / 10;
			if ( item.rot === -180 ) {
				item.rot = 180;
			}
			refreshItem( index );
		} );
	}

	/* ---------- stage ---------- */

	function renderStage() {
		var spread = currentSpread();
		var g = geo();
		var wrapStyle = window.getComputedStyle( dom.stageWrap );
		var inner = dom.stageWrap.clientWidth - parseFloat( wrapStyle.paddingLeft ) - parseFloat( wrapStyle.paddingRight );
		var width = Math.min( inner, 1600 );
		if ( isFullScreen() ) {
			var innerH = dom.stageWrap.clientHeight - parseFloat( wrapStyle.paddingTop ) - parseFloat( wrapStyle.paddingBottom );
			width = Math.min( width, innerH * g.cw / g.ch );
		} else if ( window.innerHeight > 300 ) {
			width = Math.min( width, ( window.innerHeight - 220 - ( state.headerOffset || 0 ) ) * g.cw / g.ch );
		}
		width = Math.max( width, 240 );
		state.scale = width / g.cw;

		dom.stage.style.width = width + 'px';
		dom.stage.style.height = g.ch * state.scale + 'px';
		dom.canvas.innerHTML = '';
		paintCover( dom.canvas, onCover() ? spread : null, state.scale, g.b );
		dom.itemNodes = boundCover() ? [] : drawItems( dom.canvas, spread, state.scale, g.b, true );
		dom.canvas.classList.toggle( 'sad-canvas--armed', !! state.armedPhoto );
		dom.pageLabels.hidden = ! onCover();
		dom.guides.classList.toggle( 'sad-guides--hidden', boundCover() );
		dom.legend.hidden = boundCover();
		renderGuides();
		renderSelection();
		renderLowResNotice();

		dom.spreadLabel.textContent = onCover() ? 'Cover' : 'Spread ' + ( state.current + 1 ) + ' of ' + state.design.spreads.length;
		dom.prev.disabled = state.current === -1;
		dom.spreadTools.hidden = isLocked() || onCover();
		dom.next.disabled = state.current === state.design.spreads.length - 1;
	}

	/**
	 * Shows a leather or linen cover's material and stitching on `node`, or clears it. Stitching
	 * runs round each board (back and front), clear of the spine.
	 */
	function paintCover( node, cover, scale, off ) {
		var bound = cover && cover.type !== 'photo';
		node.classList.remove( 'sad-material--leather', 'sad-material--linen' );
		node.style.backgroundColor = '';
		node.style.removeProperty( '--sad-stitch' );
		if ( ! bound ) {
			return;
		}
		var hex = coverColour( cover );
		node.classList.add( 'sad-material--' + cover.type );
		node.style.backgroundColor = hex;
		var rgb = parseInt( hex.slice( 1 ), 16 );
		var light = ( ( rgb >> 16 ) * 0.299 + ( ( rgb >> 8 ) & 255 ) * 0.587 + ( rgb & 255 ) * 0.114 ) > 150;
		node.style.setProperty( '--sad-stitch', light ? 'rgba(60, 45, 30, 0.45)' : 'rgba(255, 244, 225, 0.55)' );
		var g = geo();
		var inset = Math.min( g.th, g.tw / 2 ) * 0.07;
		[ 0, g.tw / 2 ].forEach( function ( left ) {
			var stitch = el( 'div', { class: 'sad-stitch', 'aria-hidden': 'true' } );
			stitch.style.left = ( off + left + inset ) * scale + 'px';
			stitch.style.top = ( off + inset ) * scale + 'px';
			stitch.style.width = ( g.tw / 2 - 2 * inset ) * scale + 'px';
			stitch.style.height = ( g.th - 2 * inset ) * scale + 'px';
			node.appendChild( stitch );
		} );
		var spine = el( 'div', { class: 'sad-spine', 'aria-hidden': 'true' } );
		spine.style.left = ( off + g.tw / 2 ) * scale + 'px';
		node.appendChild( spine );
	}

	function box( node, x, y, w, h ) {
		node.style.left = x * state.scale + 'px';
		node.style.top = y * state.scale + 'px';
		node.style.width = w * state.scale + 'px';
		node.style.height = h * state.scale + 'px';
		return node;
	}

	/** Bleed (shaded, red trim line), safe area (green) and the middle fold. */
	function renderGuides() {
		var g = geo();
		dom.guides.innerHTML = '';
		dom.guides.appendChild( box( el( 'div', { class: 'sad-guide-trim' } ), g.b, g.b, g.tw, g.th ) );
		if ( g.safe ) {
			dom.guides.appendChild( box( el( 'div', { class: 'sad-guide-safe' } ), g.b + g.safe, g.b + g.safe, g.tw / 2 - 2 * g.safe, g.th - 2 * g.safe ) );
			dom.guides.appendChild( box( el( 'div', { class: 'sad-guide-safe' } ), g.b + g.tw / 2 + g.safe, g.b + g.safe, g.tw / 2 - 2 * g.safe, g.th - 2 * g.safe ) );
		}
		dom.guides.appendChild( box( el( 'div', { class: 'sad-guide-fold' } ), g.b + g.tw / 2, g.b, 0, g.th ) );
		dom.snapX = el( 'div', { class: 'sad-snap sad-snap--x', hidden: true } );
		dom.snapY = el( 'div', { class: 'sad-snap sad-snap--y', hidden: true } );
		dom.guides.appendChild( dom.snapX );
		dom.guides.appendChild( dom.snapY );
	}

	/** The selection frame with resize and rotate handles, size readout and quick buttons. */
	function renderSelection() {
		dom.overlay.innerHTML = '';
		dom.frame = null;
		var item = selectedItem();
		dom.itemNodes.forEach( function ( node, i ) {
			node.classList.toggle( 'sad-item--selected', i === state.selectedItem );
			node.classList.toggle( 'sad-item--adjusting', i === state.selectedItem && state.adjusting );
			node.classList.toggle( 'sad-item--swap', state.swapFrom !== null && i !== state.swapFrom );
		} );
		if ( ! item || isLocked() ) {
			return;
		}
		var index = state.selectedItem;
		dom.frame = el( 'div', { class: 'sad-frame' + ( state.adjusting ? ' sad-frame--adjusting' : '' ) } );
		if ( ! state.adjusting ) {
			HANDLES.forEach( function ( h ) {
				var handle = el( 'span', { class: 'sad-handle sad-handle--' + h, 'aria-hidden': 'true' } );
				handle.addEventListener( 'pointerdown', function ( event ) {
					if ( event.button === 0 ) {
						startResize( event, h, index );
					}
				} );
				dom.frame.appendChild( handle );
			} );
			var rotate = el( 'span', { class: 'sad-rotate', title: 'Drag to rotate (hold Shift for 15° steps)', 'aria-hidden': 'true', text: '↻' } );
			rotate.addEventListener( 'pointerdown', function ( event ) {
				if ( event.button === 0 ) {
					startRotate( event, index );
				}
			} );
			dom.frame.appendChild( rotate );
		}
		dom.dims = el( 'span', { class: 'sad-dims' } );
		dom.frame.appendChild( dom.dims );
		dom.bar = el( 'div', { class: 'sad-float' }, quickButtons( item, index ) );
		dom.overlay.appendChild( dom.frame );
		dom.overlay.appendChild( dom.bar );
		updateSelection();
	}

	/** Moves the selection frame and quick buttons to follow the selected box. */
	function updateSelection() {
		var item = selectedItem();
		if ( ! item || ! dom.frame || ! dom.frame.parentNode ) {
			return;
		}
		var g = geo();
		var m = itemMm( item );
		position( dom.frame, item, state.scale, g.b );
		dom.dims.style.transform = item.rot ? 'translate(-50%, 6px) rotate(' + -item.rot + 'deg)' : '';
		dom.dims.textContent = state.adjusting
			? 'Drag to move the photo inside the box · scroll to zoom'
			: ( item.photo && state.photos[ item.photo ] ? state.photos[ item.photo ].name + ' · ' : '' ) + Math.round( m.w ) + ' × ' + Math.round( m.h ) + ' mm' + ( item.rot ? ' · ' + Math.round( item.rot ) + '°' : '' );

		// Quick buttons above the box (below it when the box is at the top).
		var a = Math.abs( item.rot * Math.PI / 180 );
		var bh = m.w * Math.abs( Math.sin( a ) ) + m.h * Math.abs( Math.cos( a ) );
		var top = ( g.b + m.y + m.h / 2 - bh / 2 ) * state.scale;
		var bottom = ( g.b + m.y + m.h / 2 + bh / 2 ) * state.scale;
		var half = dom.bar.offsetWidth / 2;
		dom.bar.style.left = clamp( ( g.b + m.x + m.w / 2 ) * state.scale, half - 20, dom.stage.clientWidth - half + 20 ) + 'px';
		// Above the box, else below it (clear of the rotate handle), else inside its top edge.
		var barTop = top - 44;
		if ( top <= 52 ) {
			barTop = bottom + 84 <= dom.stage.clientHeight + 48 ? bottom + 44 : top + 36;
		}
		dom.bar.style.top = barTop + 'px';
	}

	function iconButton( label, text, onclick, pressed ) {
		return el( 'button', {
			type: 'button',
			class: 'sad-float__btn',
			title: label,
			'aria-label': label,
			'aria-pressed': pressed === undefined ? null : ( pressed ? 'true' : 'false' ),
			text: text,
			onclick: function ( event ) {
				event.stopPropagation();
				onclick();
			},
			onpointerdown: function ( event ) {
				event.stopPropagation();
			},
		} );
	}

	function quickButtons( item, index ) {
		var photo = item.photo && state.photos[ item.photo ];
		var buttons = [];
		if ( photo && item.fit === 'fill' ) {
			buttons.push( iconButton( 'Move the photo inside the box', '✥', function () {
				state.adjusting = ! state.adjusting;
				renderSelection();
				renderItemTools();
			}, state.adjusting ) );
		}
		if ( photo ) {
			buttons.push( iconButton( 'Swap with another photo box', '⇄', function () {
				state.swapFrom = index;
				state.adjusting = false;
				renderSelection();
				toast( 'Now click the photo box to swap with.' );
			} ) );
		}
		buttons.push( iconButton( 'Bring forward', '▲', function () {
			restack( index, 1 );
		} ) );
		buttons.push( iconButton( 'Send backward', '▼', function () {
			restack( index, -1 );
		} ) );
		buttons.push( iconButton( 'Duplicate', '⧉', function () {
			duplicateItem( index );
		} ) );
		buttons.push( iconButton( 'Delete box', '🗑', function () {
			deleteItem( index );
		} ) );
		return buttons;
	}

	function renderLowResNotice() {
		var spread = currentSpread();
		dom.lowres.hidden = ! spread.items.some( isLowRes );
	}

	var zoomSaveTimer;

	/** Sets a photo's zoom (1 to 4) without rebuilding the stage. */
	function setZoom( item, value ) {
		checkpoint( 'zoom' );
		item.zoom = clamp( value, 1, 4 );
		refreshItem( currentSpread().items.indexOf( item ) );
		if ( dom.zoomInput ) {
			dom.zoomInput.value = String( item.zoom );
		}
		clearTimeout( zoomSaveTimer );
		zoomSaveTimer = setTimeout( function () {
			scheduleSave();
			renderStrip();
			renderLowResNotice();
		}, 300 );
	}

	function segmented( options, value, onchange ) {
		return el( 'span', { class: 'sad-seg', role: 'group' }, options.map( function ( option ) {
			return el( 'button', {
				type: 'button',
				class: 'sad-seg__btn',
				'aria-pressed': option[ 0 ] === value ? 'true' : 'false',
				text: option[ 1 ],
				onclick: function () {
					onchange( option[ 0 ] );
				},
			} );
		} ) );
	}

	/** Settings for the selected box, under the stage. */
	function renderItemTools() {
		dom.slotTools.innerHTML = '';
		dom.zoomInput = null;
		if ( isLocked() ) {
			return;
		}
		if ( state.armedPhoto ) {
			dom.slotTools.appendChild( el( 'p', { class: 'sad-help', text: 'Now tap a photo box to put the photo in it, or tap an empty part of the spread to add it there.' } ) );
			dom.slotTools.appendChild( el( 'button', { type: 'button', class: 'sad-btn sad-btn--ghost', text: 'Cancel', onclick: function () {
				state.armedPhoto = null;
				renderAll();
			} } ) );
			return;
		}
		if ( state.swapFrom !== null ) {
			dom.slotTools.appendChild( el( 'p', { class: 'sad-help', text: 'Click the photo box to swap with.' } ) );
			dom.slotTools.appendChild( el( 'button', { type: 'button', class: 'sad-btn sad-btn--ghost', text: 'Cancel', onclick: function () {
				state.swapFrom = null;
				renderSelection();
				renderItemTools();
			} } ) );
			return;
		}
		var item = selectedItem();
		if ( ! item && onCover() ) {
			renderCoverTools();
			return;
		}
		if ( ! item ) {
			dom.slotTools.appendChild( el( 'p', { class: 'sad-help', text: 'Drag photos onto the spread to add them, or onto a box to fill it. Click a box to move it, resize it from the corners, or turn it with the round handle. Keep faces inside the green lines; anything past the red line is trimmed off.' } ) );
			return;
		}
		var index = state.selectedItem;
		var photo = item.photo && state.photos[ item.photo ];
		dom.slotTools.appendChild( el( 'h3', { class: 'sad-tools__title', text: photo ? 'Photo' : 'Empty photo box' } ) );
		if ( photo ) {
			dom.slotTools.appendChild( el( 'div', { class: 'sad-tools__photo' }, [
				el( 'img', { src: photo.preview, alt: '', draggable: 'false' } ),
				el( 'span', {}, [
					el( 'strong', { class: 'sad-tools__name', title: photo.name, text: photo.name } ),
					el( 'span', { class: 'sad-help', text: photo.width + ' × ' + photo.height + ' px' } ),
				] ),
			] ) );
		}
		if ( photo ) {
			dom.slotTools.appendChild( segmented( [ [ 'fill', 'Fill box' ], [ 'fit', 'Show whole photo' ] ], item.fit, function ( fit ) {
				setFit( item, fit );
			} ) );
		}
		if ( photo && item.fit === 'fill' ) {
			var zoom = el( 'input', { type: 'range', min: '1', max: '4', step: '0.01', value: String( item.zoom ), 'aria-label': 'Zoom' } );
			dom.zoomInput = zoom;
			zoom.addEventListener( 'input', function () {
				setZoom( item, parseFloat( zoom.value ) );
			} );
			dom.slotTools.appendChild( el( 'span', { class: 'sad-zoom' }, [
				el( 'button', { type: 'button', class: 'sad-btn sad-btn--ghost sad-btn--icon', 'aria-label': 'Zoom out', text: '−', onclick: function () {
					setZoom( item, item.zoom / 1.15 );
				} } ),
				zoom,
				el( 'button', { type: 'button', class: 'sad-btn sad-btn--ghost sad-btn--icon', 'aria-label': 'Zoom in', text: '+', onclick: function () {
					setZoom( item, item.zoom * 1.15 );
				} } ),
			] ) );
			dom.slotTools.appendChild( el( 'button', {
				type: 'button',
				class: 'sad-btn sad-btn--ghost',
				'aria-pressed': state.adjusting ? 'true' : 'false',
				text: state.adjusting ? 'Done moving photo' : 'Move photo in box',
				onclick: function () {
					state.adjusting = ! state.adjusting;
					renderSelection();
					renderItemTools();
				},
			} ) );
		}
		var rotation = el( 'input', { type: 'number', min: '-180', max: '180', step: '1', value: String( Math.round( item.rot ) ), class: 'sad-num', 'aria-label': 'Rotation in degrees' } );
		rotation.addEventListener( 'change', function () {
			var value = parseFloat( rotation.value );
			if ( isNaN( value ) ) {
				return;
			}
			checkpoint();
			item.rot = ( ( value + 540 ) % 360 ) - 180;
			changed();
		} );
		dom.slotTools.appendChild( el( 'label', { class: 'sad-zoom' }, [ 'Rotate', rotation, '°' ] ) );
		if ( photo ) {
			dom.slotTools.appendChild( el( 'button', { type: 'button', class: 'sad-btn sad-btn--ghost', text: 'Match photo shape', title: 'Reshape the box so the whole photo shows with no cropping', onclick: function () {
				matchPhotoShape( item );
			} } ) );
			dom.slotTools.appendChild( el( 'button', { type: 'button', class: 'sad-btn sad-btn--ghost', text: 'Remove photo', onclick: function () {
				checkpoint();
				item.photo = null;
				state.adjusting = false;
				changed();
			} } ) );
		}
		dom.slotTools.appendChild( el( 'button', { type: 'button', class: 'sad-btn sad-btn--ghost sad-btn--danger', text: 'Delete box', onclick: function () {
			deleteItem( index );
		} } ) );
		var dpi = itemDpi( item );
		if ( dpi ) {
			dom.slotTools.appendChild( el( 'span', { class: 'sad-help' + ( dpi < state.album.minDpi ? ' sad-help--warn' : '' ), text: 'Print quality: about ' + dpi + ' dpi' + ( dpi < state.album.minDpi ? ' (may look soft)' : '' ) } ) );
		}
	}

	/** Cover material and colour, shown when the cover is open and no photo box is selected. */
	function renderCoverTools() {
		var cover = state.design.cover;
		var covers = state.album.covers || {};
		dom.slotTools.appendChild( el( 'h3', { class: 'sad-tools__title', text: 'Cover' } ) );
		var types = el( 'div', { class: 'sad-choice', role: 'radiogroup', 'aria-label': 'Cover type' } );
		Object.keys( covers ).forEach( function ( type ) {
			types.appendChild( el( 'button', {
				type: 'button',
				role: 'radio',
				class: 'sad-choice__btn',
				'aria-checked': cover.type === type ? 'true' : 'false',
				text: covers[ type ].name,
				onclick: function () {
					if ( cover.type === type ) {
						return;
					}
					checkpoint();
					cover.type = type;
					var colours = Object.keys( covers[ type ].colours || {} );
					if ( colours.length && colours.indexOf( cover.colour ) === -1 ) {
						cover.colour = colours[ 0 ];
					}
					if ( type === 'photo' && ! cover.items.length ) {
						// Start with one photo on the front cover.
						var g = geo();
						var bx = g.b / g.tw;
						var by = g.b / g.th;
						cover.items = [ newItem( null, 0.5, -by, 0.5 + bx, 1 + 2 * by ) ];
					}
					changed();
				},
			} ) );
		} );
		dom.slotTools.appendChild( types );

		var colours = ( covers[ cover.type ] || {} ).colours || {};
		if ( Object.keys( colours ).length ) {
			dom.slotTools.appendChild( el( 'p', { class: 'sad-tools__label', text: 'Colour: ' + ( colours[ cover.colour ] ? colours[ cover.colour ].name : '' ) } ) );
			var swatches = el( 'div', { class: 'sad-swatches', role: 'radiogroup', 'aria-label': 'Cover colour' } );
			Object.keys( colours ).forEach( function ( id ) {
				var swatch = el( 'button', {
					type: 'button',
					role: 'radio',
					class: 'sad-swatch sad-material--' + cover.type,
					title: colours[ id ].name,
					'aria-label': colours[ id ].name,
					'aria-checked': cover.colour === id ? 'true' : 'false',
					onclick: function () {
						checkpoint();
						cover.colour = id;
						changed();
					},
				} );
				swatch.style.backgroundColor = colours[ id ].hex;
				swatches.appendChild( swatch );
			} );
			dom.slotTools.appendChild( swatches );
			dom.slotTools.appendChild( el( 'p', { class: 'sad-help', text: 'A bound ' + covers[ cover.type ].name.toLowerCase() + ' cover. The colour on screen is a guide; real materials vary slightly.' } ) );
		} else {
			dom.slotTools.appendChild( el( 'p', { class: 'sad-help', text: 'Design the cover like a spread: the left half is the back cover and the right half is the front. Drag photos onto it, or pick a layout.' } ) );
		}
	}

	/* ---------- panels ---------- */

	function layoutIcon( slots ) {
		var icon = el( 'span', { class: 'sad-layout__icon' } );
		icon.style.aspectRatio = String( spreadAspect() );
		slots.forEach( function ( rect ) {
			var part = el( 'span', { class: 'sad-layout__box' } );
			part.style.left = rect[ 0 ] * 100 + '%';
			part.style.top = rect[ 1 ] * 100 + '%';
			part.style.width = rect[ 2 ] * 100 + '%';
			part.style.height = rect[ 3 ] * 100 + '%';
			icon.appendChild( part );
		} );
		return icon;
	}

	function renderLayouts() {
		dom.layouts.innerHTML = '';
		if ( isLocked() ) {
			return;
		}
		var spread = currentSpread();
		config.layouts.forEach( function ( layout ) {
			dom.layouts.appendChild( el( 'button', {
				type: 'button',
				class: 'sad-layout' + ( spread.layout === layout.id ? ' sad-layout--active' : '' ),
				title: layout.name + ' (' + layout.slots.length + ')',
				'aria-label': layout.name,
				'aria-pressed': spread.layout === layout.id ? 'true' : 'false',
				onclick: function () {
					setLayout( layout.id );
				},
			}, [ layoutIcon( layout.slots ) ] ) );
		} );
	}

	function drawThumb( container, spread, width ) {
		var g = geo();
		var scale = width / g.tw;
		container.innerHTML = '';
		container.style.width = width + 'px';
		container.style.height = g.th * scale + 'px';
		var cover = spread === state.design.cover;
		paintCover( container, cover ? spread : null, scale, 0 );
		if ( ! cover || spread.type === 'photo' ) {
			drawItems( container, spread, scale, 0, false );
		}
		container.appendChild( el( 'div', { class: 'sad-fold', 'aria-hidden': 'true' } ) );
	}

	function renderStrip() {
		dom.strip.innerHTML = '';
		var coverMini = el( 'div', { class: 'sad-mini' } );
		drawThumb( coverMini, state.design.cover, 120 );
		dom.strip.appendChild( el( 'button', {
			type: 'button',
			class: 'sad-thumb sad-thumb--cover' + ( onCover() ? ' sad-thumb--active' : '' ),
			'aria-label': 'Go to the cover',
			onclick: function () {
				goTo( -1 );
			},
		}, [ coverMini, el( 'span', { class: 'sad-thumb__num sad-thumb__pages' }, [ el( 'span', { text: 'Back' } ), el( 'span', { text: 'Cover' } ), el( 'span', { text: 'Front' } ) ] ) ] ) );
		state.design.spreads.forEach( function ( spread, index ) {
			var mini = el( 'div', { class: 'sad-mini' } );
			drawThumb( mini, spread, 120 );
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
		// Scroll the strip itself, never the page, to show the current spread.
		var active = dom.strip.querySelector( '.sad-thumb--active' );
		if ( active ) {
			var left = active.offsetLeft - dom.strip.offsetLeft;
			if ( left < dom.strip.scrollLeft || left + active.offsetWidth > dom.strip.scrollLeft + dom.strip.clientWidth ) {
				dom.strip.scrollLeft = left - ( dom.strip.clientWidth - active.offsetWidth ) / 2;
			}
		}
	}

	function renderTray() {
		dom.tray.innerHTML = '';
		var used = usage();
		dom.trayCount.textContent = state.photoOrder.length ? '(' + state.photoOrder.length + ')' : '';
		if ( ! state.photoOrder.length ) {
			dom.tray.appendChild( el( 'p', { class: 'sad-help', text: isLocked() ? 'No photos.' : 'Add your photos to start. JPEG or PNG, full size is best for printing.' } ) );
		}
		state.photoOrder.forEach( function ( id ) {
			var photo = state.photos[ id ];
			var where = used[ id ] ? 'Used on ' + usageText( used[ id ] ) : '';
			var img = el( 'img', { src: photo.preview, alt: photo.name, loading: 'lazy', draggable: 'false' } );
			var item = el( 'div', {
				class: 'sad-photo' + ( state.armedPhoto === id ? ' sad-photo--armed' : '' ) + ( used[ id ] ? ' sad-photo--used' : '' ) + ( state.selected[ id ] ? ' sad-photo--selected' : '' ),
				draggable: isLocked() || state.selecting ? 'false' : 'true',
				'aria-pressed': state.selecting ? ( state.selected[ id ] ? 'true' : 'false' ) : null,
				tabindex: '0',
				role: 'button',
				'aria-label': photo.name + ( where ? ', ' + where.toLowerCase() : '' ),
				title: photo.name + ( where ? '\n' + where : '\nNot used yet. Drag it onto the spread, or double-click to add it.' ),
			}, [
				img,
				used[ id ] && ! state.selecting ? el( 'span', { class: 'sad-photo__used', text: '✓' } ) : null,
				used[ id ] && ! state.selecting ? el( 'span', { class: 'sad-photo__where', text: ( used[ id ][ 0 ] === 'Cover' ? '' : 'Spread ' ) + used[ id ].join( ', ' ) } ) : null,
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
					var box = selectedItem();
					if ( box && ! box.photo ) {
						placeInItem( state.selectedItem, id );
						return;
					}
					state.armedPhoto = state.armedPhoto === id ? null : id;
					renderAll();
				} );
				item.addEventListener( 'dblclick', function () {
					if ( ! state.selecting ) {
						addItem( id );
					}
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
		if ( isLocked() || ! config.templates || ! config.templates.length ) {
			return;
		}
		dom.templates.innerHTML = '';
		config.templates.forEach( function ( template ) {
			var icons = el( 'span', { class: 'sad-template__icons', 'aria-hidden': 'true' } );
			template.layouts.slice( 0, 3 ).forEach( function ( id ) {
				if ( layouts[ id ] ) {
					icons.appendChild( layoutIcon( layouts[ id ].slots ) );
				}
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
				: 'Uses all ' + state.photoOrder.length + ' photos, in order. To use fewer, tap "Select photos" in Your photos.';
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
		dom.editTools.hidden = isLocked();
		dom.upload.hidden = isLocked();
	}

	function renderAll() {
		renderHeader();
		renderStage();
		renderItemTools();
		renderLayouts();
		renderStrip();
		renderTray();
		renderAutoFill();
		renderUndo();
		// Layouts and auto-fill are only for editing.
		var editable = ! isLocked();
		dom.tabs.layouts.button.hidden = ! editable;
		dom.tabs.auto.button.hidden = ! editable || ! config.templates || ! config.templates.length;
		setTab( dom.tabs[ state.tab ].button.hidden ? 'photos' : state.tab );
	}

	/* ---------- setup ---------- */

	var fullQuery = window.matchMedia ? window.matchMedia( '(min-width: 900px) and (min-height: 560px)' ) : null;

	/** On computers the editor fills the window like a desktop app; on phones it scrolls. */
	function isFullScreen() {
		return !! ( fullQuery && fullQuery.matches );
	}

	function applyScreenMode() {
		var full = isFullScreen();
		root.classList.toggle( 'sad-app--full', full );
		document.documentElement.classList.toggle( 'sad-locked', full );
	}

	function setTab( tab ) {
		state.tab = tab;
		Object.keys( dom.tabs ).forEach( function ( key ) {
			dom.tabs[ key ].button.setAttribute( 'aria-selected', key === tab ? 'true' : 'false' );
			dom.tabs[ key ].panel.hidden = key !== tab;
		} );
	}

	/** Where a pointer or drop lands on the stage, in mm from the top left of the trimmed spread. */
	function stagePoint( event ) {
		var rect = dom.stage.getBoundingClientRect();
		var g = geo();
		return { x: ( event.clientX - rect.left ) / state.scale - g.b, y: ( event.clientY - rect.top ) / state.scale - g.b };
	}

	function enableCanvas() {
		// Clicking empty space deselects, or adds the photo picked in the tray.
		dom.canvas.addEventListener( 'pointerdown', function ( event ) {
			if ( event.target !== dom.canvas || isLocked() ) {
				return;
			}
			if ( state.armedPhoto ) {
				addItem( state.armedPhoto, stagePoint( event ) );
				return;
			}
			if ( boundCover() ) {
				return;
			}
			if ( state.selectedItem !== null || state.swapFrom !== null ) {
				state.selectedItem = null;
				state.adjusting = false;
				state.swapFrom = null;
				renderSelection();
				renderItemTools();
			}
		} );
		dom.canvas.addEventListener( 'dragover', function ( event ) {
			if ( ! isLocked() ) {
				event.preventDefault();
			}
		} );
		dom.canvas.addEventListener( 'drop', function ( event ) {
			if ( hasFiles( event ) || isLocked() ) {
				return;
			}
			event.preventDefault();
			var id = event.dataTransfer.getData( 'text/x-sad-photo' );
			if ( id && state.photos[ id ] ) {
				addItem( id, stagePoint( event ) );
			}
		} );
	}

	function typing( event ) {
		var tag = event.target && event.target.tagName;
		return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || ( event.target && event.target.isContentEditable );
	}

	function enableKeys() {
		document.addEventListener( 'keydown', function ( event ) {
			if ( ! state.album || isLocked() || typing( event ) ) {
				return;
			}
			var mod = event.ctrlKey || event.metaKey;
			var key = event.key.toLowerCase();
			if ( mod && key === 'z' ) {
				event.preventDefault();
				if ( event.shiftKey ) {
					redo();
				} else {
					undo();
				}
				return;
			}
			if ( mod && key === 'y' ) {
				event.preventDefault();
				redo();
				return;
			}
			var item = selectedItem();
			if ( ! item || ! root.contains( document.activeElement ) && document.activeElement !== document.body ) {
				return;
			}
			if ( event.key === 'Escape' ) {
				state.selectedItem = null;
				state.adjusting = false;
				state.swapFrom = null;
				renderSelection();
				renderItemTools();
			} else if ( event.key === 'Delete' || event.key === 'Backspace' ) {
				event.preventDefault();
				deleteItem( state.selectedItem );
			} else if ( /^Arrow/.test( event.key ) ) {
				event.preventDefault();
				var step = event.shiftKey ? 10 : 1;
				var m = itemMm( item );
				m.x += event.key === 'ArrowLeft' ? -step : event.key === 'ArrowRight' ? step : 0;
				m.y += event.key === 'ArrowUp' ? -step : event.key === 'ArrowDown' ? step : 0;
				checkpoint( 'nudge' );
				setItemMm( item, m );
				currentSpread().layout = null;
				refreshItem( state.selectedItem );
				scheduleSave();
				clearTimeout( zoomSaveTimer );
				zoomSaveTimer = setTimeout( renderStrip, 300 );
			}
		} );
	}

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
		dom.undo = el( 'button', { type: 'button', class: 'sad-btn sad-btn--ghost sad-btn--icon', title: 'Undo (Ctrl+Z)', 'aria-label': 'Undo', text: '↶', onclick: undo } );
		dom.redo = el( 'button', { type: 'button', class: 'sad-btn sad-btn--ghost sad-btn--icon', title: 'Redo (Ctrl+Y)', 'aria-label': 'Redo', text: '↷', onclick: redo } );
		dom.editTools = el( 'span', { class: 'sad-edit-tools' }, [
			dom.undo,
			dom.redo,
			el( 'button', { type: 'button', class: 'sad-btn sad-btn--ghost', title: 'Add an empty photo box', text: '+ Photo box', onclick: function () {
				addItem( null );
			} } ),
		] );
		dom.spreadTools = el( 'span', { class: 'sad-spread-tools' }, [
			el( 'button', { type: 'button', class: 'sad-btn sad-btn--ghost', title: 'Move spread earlier', text: '← Move', onclick: function () {
				moveSpread( -1 );
			} } ),
			el( 'button', { type: 'button', class: 'sad-btn sad-btn--ghost', title: 'Move spread later', text: 'Move →', onclick: function () {
				moveSpread( 1 );
			} } ),
			el( 'button', { type: 'button', class: 'sad-btn sad-btn--ghost sad-btn--danger', text: 'Delete spread', onclick: deleteSpread } ),
		] );

		dom.canvas = el( 'div', { class: 'sad-canvas' } );
		dom.guides = el( 'div', { class: 'sad-guides', 'aria-hidden': 'true' } );
		dom.overlay = el( 'div', { class: 'sad-overlay' } );
		dom.pageLabels = el( 'div', { class: 'sad-page-labels', 'aria-hidden': 'true', hidden: true }, [ el( 'span', { text: 'Back cover' } ), el( 'span', { text: 'Front cover' } ) ] );
		dom.stage = el( 'div', { class: 'sad-stage' }, [ dom.canvas, dom.guides, dom.overlay, dom.pageLabels ] );
		dom.stageWrap = el( 'div', { class: 'sad-stage-wrap' }, [ dom.stage ] );
		dom.itemNodes = [];
		dom.lowres = el( 'div', { class: 'sad-lowres', hidden: true, text: '⚠ A photo on this spread is enlarged more than its resolution allows and may print soft. Make its box smaller, zoom out, or choose a larger photo.' } );
		dom.legend = el( 'div', { class: 'sad-legend' }, [
			el( 'span', { class: 'sad-legend__trim', text: 'Trim: anything past this is cut off' } ),
			el( 'span', { class: 'sad-legend__safe', text: 'Safe area: keep faces and text inside' } ),
		] );
		dom.slotTools = el( 'div', { class: 'sad-slot-tools' } );
		dom.layouts = el( 'div', { class: 'sad-layouts' } );
		dom.layoutsSection = el( 'section', { class: 'sad-section' }, [
			el( 'h3', { text: 'Layouts' } ),
			el( 'p', { class: 'sad-help', text: 'Start this spread from a layout. Your photos move into the new boxes, and you can still move and resize everything.' } ),
			dom.layouts,
		] );
		dom.strip = el( 'div', { class: 'sad-strip' } );

		var fileInput = el( 'input', { type: 'file', accept: 'image/jpeg,image/png', multiple: true, class: 'sad-file' } );
		fileInput.addEventListener( 'change', function () {
			uploadFiles( fileInput.files );
			fileInput.value = '';
		} );
		dom.upload = el( 'label', { class: 'sad-btn sad-btn--primary sad-upload' }, [ '+ Upload photos', fileInput ] );
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

		dom.tabs = {
			photos: { label: 'Photos', panel: traySection },
			layouts: { label: 'Layouts', panel: dom.layoutsSection },
			auto: { label: 'Auto-fill', panel: dom.autoSection },
		};
		var tabBar = el( 'div', { class: 'sad-tabs', role: 'tablist' } );
		Object.keys( dom.tabs ).forEach( function ( key ) {
			dom.tabs[ key ].button = el( 'button', { type: 'button', role: 'tab', class: 'sad-tab', text: dom.tabs[ key ].label, onclick: function () {
				setTab( key );
			} } );
			tabBar.appendChild( dom.tabs[ key ].button );
		} );

		root.appendChild( el( 'div', { class: 'sad-header' }, [
			el( 'div', { class: 'sad-header__name' }, [
				config.home ? el( 'a', { class: 'sad-home', href: config.home, title: 'Back to the website', text: '←' } ) : null,
				el( 'div', {}, [ dom.title, dom.size ] ),
			] ),
			dom.editTools,
			el( 'div', { class: 'sad-header__actions' }, [ dom.status, dom.approve ] ),
		] ) );
		root.appendChild( dom.banner );
		// Computers: photos and layouts on the left, the spread in the middle, box settings on the
		// right and all spreads along the bottom, all in view at once. Phones: one scrolling column
		// with the photo panel pinned to the bottom of the screen.
		root.appendChild( el( 'div', { class: 'sad-workspace' }, [
			el( 'aside', { class: 'sad-side' }, [ tabBar, traySection, dom.layoutsSection, dom.autoSection ] ),
			el( 'div', { class: 'sad-main' }, [
				el( 'div', { class: 'sad-nav' }, [ dom.prev, dom.spreadLabel, dom.next, dom.spreadTools ] ),
				dom.stageWrap,
				el( 'section', { class: 'sad-section sad-strip-section' }, [ dom.strip ] ),
			] ),
			el( 'aside', { class: 'sad-tools' }, [ dom.lowres, dom.slotTools, dom.legend ] ),
		] ) );
		setTab( 'photos' );
		dom.dropOverlay = el( 'div', { class: 'sad-drop-overlay', hidden: true }, [ el( 'span', { text: 'Drop your photos to add them' } ) ] );
		root.appendChild( dom.dropOverlay );
		root.appendChild( dom.toasts );
		enableCanvas();
		enableKeys();
		enableFileDrop();
		applyScreenMode();
		measureStickyHeader();
		if ( fullQuery && fullQuery.addEventListener ) {
			fullQuery.addEventListener( 'change', function () {
				applyScreenMode();
				renderStage();
			} );
		}

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
	 * box the first one fills it, and dropped on the spread it gets a new box there, once uploaded.
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
			var spread = currentSpread();
			var node = event.target.closest ? event.target.closest( '.sad-stage .sad-item' ) : null;
			var target = null;
			if ( node ) {
				target = { spread: spread, item: spread.items[ parseInt( node.getAttribute( 'data-index' ), 10 ) ] };
			} else if ( dom.stage.contains( event.target ) ) {
				target = { spread: spread, at: stagePoint( event ) };
			}
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
		state.current = clamp( state.current, -1, state.design.spreads.length - 1 );
		state.selectedItem = null;
		state.adjusting = false;
		state.swapFrom = null;
		state.armedPhoto = null;
		state.history = [];
		state.future = [];
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
