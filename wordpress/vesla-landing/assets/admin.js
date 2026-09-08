/* Vesla Landing Page — the editor screen's behaviour.
   Plain JavaScript apart from wp.media, which is WordPress's own image picker.
   Nothing here decides what is saved; it only makes the form easier to use. */
(function () {
	'use strict';

	var L = window.VeslaAdminL10n || {};
	/* The settings form, or the post editor a single car is edited on.

	   Everything below is about FIELDS -- the image pickers, the repeaters, the
	   tick lists, the gallery, the rich editors, the colour boxes -- and those
	   fields now appear on two screens, not one. Bailing out when #vesla-form
	   was missing left the whole of it inert on a car's own screen: the gallery
	   drew its Add photographs button and nothing was listening to it.

	   Scoped to whichever form is present, so a query for a field finds the
	   fields on THIS screen and cannot reach across to another. */
	var form = document.getElementById( 'vesla-form' ) || document.getElementById( 'post' );
	if ( ! form ) { return; }
	var isSettings = 'vesla-form' === form.id;

	/* ═══ 1 · the section list ═════════════════════════════════════════════
	   Jumps within the page rather than navigating, so nothing typed into one
	   section is lost by looking at another. The current section is highlighted
	   as it scrolls past, which is the only way to keep your place on a screen
	   this long. */
	(function sectionNav() {
		var links = Array.prototype.slice.call( document.querySelectorAll( '.vesla-nav a' ) );
		var panels = links.map( function ( a ) { return document.getElementById( a.dataset.target ); } ).filter( Boolean );
		if ( ! panels.length ) { return; }

		links.forEach( function ( a ) {
			a.addEventListener( 'click', function ( e ) {
				var panel = document.getElementById( a.dataset.target );
				if ( ! panel ) { return; }
				e.preventDefault();
				panel.scrollIntoView( { behavior: 'smooth', block: 'start' } );
				/* the address bar still gets the anchor, so a reload comes back
				   to the same place */
				if ( history.replaceState ) { history.replaceState( null, '', '#' + a.dataset.target ); }
			} );
		} );

		if ( ! ( 'IntersectionObserver' in window ) ) { return; }
		var io = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( e ) {
				if ( ! e.isIntersecting ) { return; }
				links.forEach( function ( a ) {
					a.classList.toggle( 'is-current', a.dataset.target === e.target.id );
				} );
			} );
		}, { rootMargin: '-150px 0px -65% 0px' } );
		panels.forEach( function ( p ) { io.observe( p ); } );
	})();

	/* ═══ 2 · the dots beside each section ═════════════════════════════════
	   Mirror that section's on/off switch live, so switching a section off is
	   visible in the list without saving first. */
	(function dots() {
		document.querySelectorAll( '[data-dot-for]' ).forEach( function ( dot ) {
			var input = form.querySelector( '[name="' + dot.dataset.dotFor + '"]' );
			if ( ! input ) { return; }
			var sync = function () { dot.classList.toggle( 'is-off', ! input.checked ); };
			input.addEventListener( 'change', sync );
			sync();
		} );
	})();

	/* ═══ 3 · image pickers ════════════════════════════════════════════════ */
	(function images() {
		document.addEventListener( 'click', function ( e ) {
			var pick = e.target.closest( '.vesla-image-pick' );
			var drop = e.target.closest( '.vesla-image-clear' );
			if ( ! pick && ! drop ) { return; }

			var box = e.target.closest( '[data-vesla-image]' );
			if ( ! box ) { return; }
			var input   = box.querySelector( '.vesla-image-id' );
			var preview = box.querySelector( '.vesla-image-preview' );
			var clear   = box.querySelector( '.vesla-image-clear' );

			if ( drop ) {
				input.value = '';
				preview.innerHTML = '<span>' + ( L.noImage || 'No image chosen' ) + '</span>';
				preview.classList.add( 'is-empty' );
				clear.hidden = true;
				markDirty();
				return;
			}

			e.preventDefault();
			if ( ! window.wp || ! window.wp.media ) { return; }

			var frame = window.wp.media( {
				title: L.chooseImage || 'Choose image',
				button: { text: L.useImage || 'Use this image' },
				library: { type: 'image' },
				multiple: false
			} );
			frame.on( 'select', function () {
				var att = frame.state().get( 'selection' ).first().toJSON();
				var url = ( att.sizes && att.sizes.medium ) ? att.sizes.medium.url : att.url;
				input.value = att.id;
				preview.innerHTML = '<img src="" alt="">';
				preview.querySelector( 'img' ).src = url;
				preview.classList.remove( 'is-empty' );
				clear.hidden = false;
				markDirty();
			} );
			frame.open();
		} );
	})();

	/* ═══ 4 · repeaters ════════════════════════════════════════════════════
	   Rows are collapsed by default and titled by their own content, because a
	   list of two dozen cars all expanded is unusable. */
	(function repeaters() {

		function rows( rep ) {
			return Array.prototype.slice.call( rep.querySelectorAll( ':scope > .vesla-rep-rows > [data-rep-row]' ) );
		}

		/* Row indexes live in the field names (…[cars][3][make]). After adding,
		   deleting or reordering, they are rewritten from the row's position so
		   PHP receives a clean, gapless list. */
		function reindex( rep ) {
			var name = rep.dataset.name;
			rows( rep ).forEach( function ( row, i ) {
				row.querySelectorAll( '[name]' ).forEach( function ( input ) {
					input.name = input.name.replace(
						new RegExp( '^' + name.replace( /[[\]]/g, '\\$&' ) + '\\[[^\\]]*\\]' ),
						name + '[' + i + ']'
					);
				} );
				var n = row.querySelector( '.vesla-rep-n' );
				if ( n ) { n.textContent = ( i + 1 ) + '.'; }
			} );
			var count = rep.querySelector( '.vesla-rep-count' );
			if ( count ) {
				var total = rows( rep ).length;
				count.textContent = total === 1 ? '1 item' : total + ' items';
			}
			var empty = rep.querySelector( '.vesla-rep-empty' );
			if ( empty ) { empty.hidden = rows( rep ).length > 0; }
		}

		/* The collapsed row shows what is actually in it, updated as you type —
		   otherwise every row reads "Car" and the list is useless. */
		function retitle( row ) {
			var title = row.querySelector( '[data-rep-title]' );
			if ( ! title ) { return; }
			var keys = ( title.dataset.keys || '' ).split( ',' ).filter( Boolean );
			var parts = [];
			keys.forEach( function ( k ) {
				var field = row.querySelector( '[data-sub="' + k + '"] input, [data-sub="' + k + '"] textarea, [data-sub="' + k + '"] select' );
				if ( field && field.value ) { parts.push( field.value ); }
			} );
			title.textContent = parts.length ? parts.join( ' ' ) : title.dataset.fallback || title.textContent;
		}

		document.querySelectorAll( '[data-vesla-rep]' ).forEach( function ( rep ) {
			reindex( rep );
			rows( rep ).forEach( retitle );

			var tpl = rep.querySelector( '.vesla-rep-tpl' );

			rep.addEventListener( 'click', function ( e ) {
				var addBtn = e.target.closest( '.vesla-rep-add' );
				var delBtn = e.target.closest( '.vesla-rep-del' );
				var togBtn = e.target.closest( '.vesla-rep-toggle' );

				if ( addBtn && tpl ) {
					e.preventDefault();
					var host = document.createElement( 'div' );
					host.innerHTML = tpl.innerHTML.replace( /__i__/g, String( rows( rep ).length ) );
					var row = host.firstElementChild;
					rep.querySelector( '.vesla-rep-rows' ).appendChild( row );
					reindex( rep );
					openRow( row, true );
					var first = row.querySelector( 'input:not([type=hidden]), textarea, select' );
					if ( first ) { first.focus(); }
					markDirty();
					return;
				}

				if ( delBtn ) {
					e.preventDefault();
					if ( ! window.confirm( L.confirmDrop || 'Remove this item?' ) ) { return; }
					var doomed = delBtn.closest( '[data-rep-row]' );
					doomed.parentNode.removeChild( doomed );
					reindex( rep );
					markDirty();
					return;
				}

				if ( togBtn ) {
					e.preventDefault();
					var r = togBtn.closest( '[data-rep-row]' );
					openRow( r, r.querySelector( '.vesla-rep-row-body' ).hidden );
				}
			} );

			rep.addEventListener( 'input', function ( e ) {
				var row = e.target.closest( '[data-rep-row]' );
				if ( row ) { retitle( row ); }
			} );

			/* ── drag to reorder ──
			   HTML5 drag and drop rather than a library. The handle is what is
			   draggable, not the row, so text inside the row can still be
			   selected with the mouse. */
			var dragged = null;
			rep.addEventListener( 'mousedown', function ( e ) {
				var handle = e.target.closest( '.vesla-rep-drag' );
				if ( ! handle ) { return; }
				var row = handle.closest( '[data-rep-row]' );
				row.draggable = true;
			} );
			rep.addEventListener( 'dragstart', function ( e ) {
				var row = e.target.closest( '[data-rep-row]' );
				if ( ! row ) { return; }
				dragged = row;
				row.classList.add( 'is-dragging' );
				e.dataTransfer.effectAllowed = 'move';
				try { e.dataTransfer.setData( 'text/plain', '' ); } catch ( err ) {}
			} );
			rep.addEventListener( 'dragover', function ( e ) {
				if ( ! dragged ) { return; }
				e.preventDefault();
				var over = e.target.closest( '[data-rep-row]' );
				if ( ! over || over === dragged ) { return; }
				var box = over.getBoundingClientRect();
				var after = ( e.clientY - box.top ) > box.height / 2;
				over.parentNode.insertBefore( dragged, after ? over.nextSibling : over );
			} );
			rep.addEventListener( 'dragend', function () {
				if ( ! dragged ) { return; }
				dragged.classList.remove( 'is-dragging' );
				dragged.draggable = false;
				dragged = null;
				reindex( rep );
				markDirty();
			} );
		} );

		function openRow( row, open ) {
			row.querySelector( '.vesla-rep-row-body' ).hidden = ! open;
			row.classList.toggle( 'is-open', open );
			var t = row.querySelector( '.vesla-rep-toggle' );
			if ( t ) { t.setAttribute( 'aria-expanded', String( open ) ); }
		}
	})();

	/* ═══ 5 · colour boxes ═════════════════════════════════════════════════
	   The swatch and the hex box are two views of one value, kept in step in
	   both directions — some people pick, some people paste a brand hex. */
	(function colours() {
		document.querySelectorAll( '.vesla-color' ).forEach( function ( box ) {
			var pick = box.querySelector( '.vesla-color-pick' );
			var hex  = box.querySelector( '.vesla-input--hex' );
			if ( ! pick || ! hex ) { return; }
			pick.addEventListener( 'input', function () { hex.value = pick.value.toUpperCase(); markDirty(); } );
			hex.addEventListener( 'input', function () {
				var v = hex.value.trim();
				if ( /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test( v ) ) { pick.value = v; }
			} );
		} );
	})();

	/* ═══ 6 · unsaved changes ══════════════════════════════════════════════
	   One screen holding the whole site's content is a lot to lose to a stray
	   click on the back button. */
	var dirty = false;
	function markDirty() { dirty = true; }
	form.addEventListener( 'input', markDirty );
	form.addEventListener( 'change', markDirty );
	form.addEventListener( 'submit', function () { dirty = false; } );
	window.addEventListener( 'beforeunload', function ( e ) {
		if ( ! dirty ) { return; }
		e.preventDefault();
		e.returnValue = '';
	} );

	/* Ctrl/Cmd+S saves, because on a screen this long reaching the button is a
	   scroll in itself. */
	document.addEventListener( 'keydown', function ( e ) {
		if ( ( e.ctrlKey || e.metaKey ) && 's' === e.key.toLowerCase() ) {
			e.preventDefault();
			/* Only the settings form. On a post screen submit() would bypass
			   the editor's own save handling. */
			if ( isSettings ) { form.submit(); }
		}
	} );

	/* ─────────────────────────────────────────────────────────────────────
	   RICH FIELDS

	   Fields the schema marks 'rich' may carry a link or a bold word inside a
	   sentence. They are printed as a plain textarea and upgraded to
	   WordPress's own editor here, on first focus rather than on load.

	   On demand, deliberately. This screen holds the entire page, so starting
	   an editor for every rich field up front would freeze it for seconds
	   before the admin could type. Waiting for the click costs nothing and
	   spends the work only where it is used.

	   wp.editor.initialize keeps the original textarea as its backing store, so
	   the form still posts the same field name and the PHP sanitiser is
	   unchanged. Nothing here decides what is saved.
	   ───────────────────────────────────────────────────────────────────── */
	var richSeq = 0;

	/* Repeater rows are cloned, and reindex() rewrites name but not id — so two
	   rows can carry the same id, and TinyMCE addresses editors by id. Give the
	   duplicate a fresh one, and move the label with it so clicking the caption
	   still focuses the right box. */
	function richId( ta ) {
		var id = ta.id;
		if ( id && document.querySelectorAll( '[id="' + id + '"]' ).length < 2 ) { return id; }
		var fresh = 'vesla-rich-' + ( ++richSeq );
		if ( id ) {
			var lbl = document.querySelector( 'label[for="' + id + '"]' );
			if ( lbl ) { lbl.setAttribute( 'for', fresh ); }
		}
		ta.id = fresh;
		return fresh;
	}

	function upgradeRich( box ) {
		if ( box.dataset.veslaRichOn ) { return; }
		if ( ! window.wp || ! wp.editor || ! wp.editor.initialize ) { return; }
		var ta = box.querySelector( '.vesla-rich-input' );
		if ( ! ta ) { return; }

		box.dataset.veslaRichOn = '1';

		wp.editor.initialize( richId( ta ), {
			tinymce: {
				wpautop: false,
				menubar: false,
				statusbar: false,
				height: 150,
				toolbar1: 'bold italic link unlink bullist numlist undo redo',
				toolbar2: '',
				/* Only the tags the PHP allowlist keeps. Offering a heading button
				   the sanitiser strips on save would teach the admin that the editor
				   lies to them. */
				valid_elements: 'a[href|title|target|rel],strong/b,em/i,br,ul,ol,li',
				setup: function ( ed ) {
					ed.on( 'change keyup SetContent', function () {
						ed.save();   // keep the textarea current
						markDirty(); // and the unsaved-changes warning honest
					} );
				}
			},
			quicktags: { buttons: 'strong,em,link,ul,ol,li' },
			mediaButtons: false
		} );
	}

	/* Delegated, not bound per box: repeater rows are added after this runs, and
	   a row added today must behave like one drawn by PHP. */
	[ 'focusin', 'mousedown' ].forEach( function ( evt ) {
		document.addEventListener( evt, function ( e ) {
			var box = e.target.closest && e.target.closest( '[data-vesla-rich]' );
			if ( box ) { upgradeRich( box ); }
		} );
	} );

	/* TinyMCE holds the current text in its iframe, not in the textarea. Without
	   this, the last edit before pressing Save is the one that gets lost.
	   Editors whose row has since been deleted are skipped rather than saved. */
	form.addEventListener( 'submit', function () {
		if ( ! window.tinymce ) { return; }
		tinymce.editors.slice().forEach( function ( ed ) {
			try {
				var el = document.getElementById( ed.id );
				if ( el && el.isConnected ) { ed.save(); } else { wp.editor.remove( ed.id ); }
			} catch ( err ) { /* a detached editor is not worth failing the save for */ }
		} );
	} );
	/* ─────────────────────────────────────────────────────────────────────
	   FIELDS THAT DEPEND ON A CHECKBOX

	   A row carrying data-show-if="<name of a checkbox in the same section>" is
	   shown only while that checkbox is ticked. PHP renders the opening state,
	   so this only has to keep it in step from here on.

	   Hidden, not removed. The input stays in the form and keeps posting, so
	   wording typed once survives the feature being switched off and on again.
	   Driven from the attribute rather than a list of field names, so a new
	   'show_if' in the schema needs no change here.
	   ───────────────────────────────────────────────────────────────────── */
	function syncDependents( input ) {
		var rows = form.querySelectorAll( '[data-show-if="' + input.name + '"]' );
		for ( var i = 0; i < rows.length; i++ ) {
			rows[ i ].hidden = ! input.checked;
		}
	}

	var controls = {};
	Array.prototype.forEach.call( form.querySelectorAll( '[data-show-if]' ), function ( row ) {
		controls[ row.getAttribute( 'data-show-if' ) ] = true;
	} );
	Object.keys( controls ).forEach( function ( name ) {
		var input = form.querySelector( '[name="' + name + '"]' );
		if ( ! input ) { return; }
		input.addEventListener( 'change', function () { syncDependents( input ); } );
		syncDependents( input );
	} );
	/* ─────────────────────────────────────────────────────────────────────
	   GALLERY FIELDS

	   Several photographs on one field. The hidden input holds their IDs in
	   order, comma separated, because the order IS the running order in the
	   popup — sorting them would quietly rearrange the admin's photographs.

	   Delegated, so a car row added after this runs behaves the same.
	   ───────────────────────────────────────────────────────────────────── */
	function galIds( box ) {
		return Array.prototype.map.call(
			box.querySelectorAll( '.vesla-gal-list li' ),
			function ( li ) { return li.dataset.id; }
		);
	}

	function galSync( box ) {
		var ids = galIds( box );
		box.querySelector( '.vesla-gal-ids' ).value = ids.join( ',' );
		box.querySelector( '.vesla-gal-empty' ).hidden = ids.length > 0;
		var max = parseInt( box.dataset.max, 10 ) || 10;
		var n = box.querySelector( '.vesla-gal-n' );
		if ( n ) { n.textContent = ids.length + ' / ' + max; }
		var add = box.querySelector( '.vesla-gal-add' );
		if ( add ) { add.disabled = ids.length >= max; }
		markDirty();
	}

	Array.prototype.forEach.call( document.querySelectorAll( '[data-vesla-gallery]' ), galSync );

	document.addEventListener( 'click', function ( e ) {
		var box = e.target.closest && e.target.closest( '[data-vesla-gallery]' );
		if ( ! box ) { return; }

		var drop = e.target.closest( '.vesla-gal-x' );
		if ( drop ) {
			e.preventDefault();
			drop.parentNode.remove();
			galSync( box );
			return;
		}

		if ( ! e.target.closest( '.vesla-gal-add' ) ) { return; }
		e.preventDefault();
		if ( ! window.wp || ! window.wp.media ) { return; }

		var max   = parseInt( box.dataset.max, 10 ) || 10;
		var list  = box.querySelector( '.vesla-gal-list' );
		var frame = wp.media( {
			title: L.chooseImage || 'Choose images',
			button: { text: L.useImage || 'Use these' },
			library: { type: 'image' },
			multiple: true
		} );

		frame.on( 'select', function () {
			var have = galIds( box );
			frame.state().get( 'selection' ).toJSON().forEach( function ( att ) {
				if ( have.length >= max ) { return; }
				/* The same photograph twice is a mistake every time, and it is
				   easy to make when adding to a gallery in two goes. */
				if ( have.indexOf( String( att.id ) ) !== -1 ) { return; }
				have.push( String( att.id ) );

				var url = ( att.sizes && att.sizes.thumbnail ) ? att.sizes.thumbnail.url : att.url;
				var li  = document.createElement( 'li' );
				li.dataset.id = att.id;
				var img = document.createElement( 'img' );
				img.src = url; img.alt = '';
				var x = document.createElement( 'button' );
				x.type = 'button'; x.className = 'vesla-gal-x'; x.innerHTML = '&times;';
				li.appendChild( img ); li.appendChild( x );
				list.appendChild( li );
			} );
			galSync( box );
		} );
		frame.open();
	} );
})();

/* ═══════════════════════════════════════════════════════════════════════════
   PACKING THE FORM

   This editor is the whole website on one page: around 1,700 inputs. PHP stops
   reading a POST after max_input_vars variables — 1,000 by default — and gives
   no warning of any kind. The request simply arrives short, the sanitiser sees
   the missing settings as absent, absent is treated as blank, and the bottom
   third of the site is emptied by pressing Save.

   So the form is packed into ONE field before it is submitted. The individual
   inputs are disabled at the same moment, because a disabled control is not
   posted — that is what takes the variable count from 1,700 to about five, and
   it stays at about five however many settings are added later.

   With scripting off none of this runs, and the __start / __end markers printed
   either side of the form let the server notice the truncation and refuse the
   save instead of applying half of it.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
	'use strict';

	var form = document.getElementById( 'vesla-form' );
	if ( !form ) { return; }

	var OPTION = 'vesla_landing';

	/* "vesla_landing[stock][cars][0][make]" -> ["stock","cars","0","make"] */
	function pathOf( name ) {
		if ( name.indexOf( OPTION + '[' ) !== 0 ) { return null; }
		var rest = name.slice( OPTION.length );
		var out = [], m, re = /\[([^\]]*)\]/g;
		while ( ( m = re.exec( rest ) ) ) { out.push( m[ 1 ] ); }
		return out.length ? out : null;
	}

	function put( root, path, value ) {
		var node = root;
		for ( var i = 0; i < path.length - 1; i++ ) {
			var key = path[ i ];
			if ( typeof node[ key ] !== 'object' || node[ key ] === null ) { node[ key ] = {}; }
			node = node[ key ];
		}
		var last = path[ path.length - 1 ];

		/* An empty key is PHP's "append" syntax — name="…[features][]" — which is
		   how checkbox groups and other multi-value fields are posted. */
		if ( last === '' ) {
			if ( !Array.isArray( node.__list ) ) { node.__list = []; }
			node.__list.push( value );
			return;
		}
		node[ last ] = value;
	}

	/* Turn the {__list:[…]} placeholders back into plain arrays, so what the
	   server decodes has the same shape PHP would have built from the POST. */
	function settle( node ) {
		if ( node === null || typeof node !== 'object' ) { return node; }
		if ( Array.isArray( node.__list ) ) { return node.__list; }
		Object.keys( node ).forEach( function ( k ) { node[ k ] = settle( node[ k ] ); } );
		return node;
	}

	/* Every control this handler switched off, so it can switch them on again.
	   Kept outside the handler because the re-enable can be triggered from
	   somewhere other than the submit that disabled them -- see pageshow. */
	var parked = [];
	var sending = false;

	function unpark() {
		/* Everything with a settings name, not only what this handler parked.
		   The state worth recovering from is a form that arrived disabled from
		   somewhere else entirely -- restored from the browser's cache, or left
		   behind by a submit that never navigated -- and in that state `parked`
		   is empty while the fields are not. No settings control is ever meant
		   to sit disabled, so switching them all on is always right. */
		var all = form.querySelectorAll( '[name^="' + OPTION + '["]' );
		Array.prototype.forEach.call( all, function ( el ) { el.disabled = false; } );
		parked = [];
		sending = false;
	}

	/* Coming back to this page with the back button restores it from the
	   browser's cache exactly as it was left -- which, after a save, is with
	   every field disabled. Editing and saving from that state used to pack an
	   empty object and empty the site. */
	window.addEventListener( 'pageshow', unpark );

	form.addEventListener( 'submit', function ( e ) {
		/* A second Save while the first is still going. The browser has already
		   taken its copy of the form; letting this one through would pack a form
		   whose fields are all disabled -- an empty object, which the server
		   would read as every setting cleared. */
		if ( sending ) { e.preventDefault(); return; }

		/* TinyMCE keeps its content in an iframe until it is told to write it
		   back. Without this the rich fields pack as whatever they held when the
		   editor opened, which is the last edit silently lost. */
		if ( window.tinyMCE && window.tinyMCE.triggerSave ) { window.tinyMCE.triggerSave(); }

		var fields = form.querySelectorAll( '[name^="' + OPTION + '["]' );
		var data = {};
		var packed = [];

		Array.prototype.forEach.call( fields, function ( el ) {
			if ( el.disabled ) { return; }
			var path = pathOf( el.name );
			if ( !path ) { return; }

			/* A repeater's blank template row needs no guard: it is stored inside a
			   <script type="text/html"> element, whose contents the parser never
			   turns into nodes, so querySelectorAll cannot reach it. */

			if ( el.type === 'checkbox' || el.type === 'radio' ) {
				if ( !el.checked ) { packed.push( el ); return; }
			}
			if ( el.tagName === 'SELECT' && el.multiple ) {
				Array.prototype.forEach.call( el.selectedOptions, function ( o ) {
					put( data, path, o.value );
				} );
				packed.push( el );
				return;
			}
			put( data, path, el.value );
			packed.push( el );
		} );

		var settled = settle( data );

		/* Nothing is sent unless the pack actually holds something.

		   The server refuses a payload that covers fewer sections than the
		   schema, and this is the same refusal one step earlier, where it can
		   still be explained to the person pressing the button. An empty or
		   near-empty pack means the fields were not readable -- disabled, or
		   removed by something else on the page -- and posting it would ask the
		   server to store nothing over everything. */
		var sections = Object.keys( settled );
		if ( sections.length < 2 ) {
			e.preventDefault();
			unpark();
			var words = window.VeslaAdminL10n || {};
			window.alert( words.packEmpty ||
				'Nothing was sent, because this page could not read its own fields. Reload the page and try again — your saved settings have not been changed.' );
			return;
		}

		/* The carrier field is made only once there is something worth putting
		   in it, so a refused submit leaves no empty __json behind. */
		var box = form.querySelector( '#vesla-packed' );
		if ( !box ) {
			box = document.createElement( 'input' );
			box.type = 'hidden';
			box.id = 'vesla-packed';
			box.name = OPTION + '[__json]';
			form.appendChild( box );
		}
		box.value = JSON.stringify( settled );

		/* Last, and only once the packed value is safely in place: a throw above
		   this line leaves the form exactly as it was and it posts normally. */
		parked = packed;
		sending = true;
		packed.forEach( function ( el ) { el.disabled = true; } );

		/* And back on again on the next turn of the event loop. The browser
		   serialises the form synchronously as this handler returns, so by the
		   time a zero-delay timer runs the POST body is already built and the
		   controls can be live again. If the navigation happens, the page is
		   gone and this cost nothing; if it does not -- a cancelled upload, an
		   offline browser, a beforeunload the user backed out of -- the form is
		   usable instead of being a screen of dead inputs that saves nothing. */
		window.setTimeout( unpark, 0 );
	} );
})();
