/**
 * VHB Club : administration (créneaux, années, type de lieu, logo).
 * Sans dépendance (pas de jQuery).
 */
( function () {
	'use strict';

	/* Créneaux : ajouter, retirer ---------------------------------------- */
	var rows = document.querySelector( '.vhbc-creneaux-rows' );
	var tpl = document.getElementById( 'tmpl-vhbc-creneau' );
	var add = document.querySelector( '.vhbc-add-creneau' );
	var next = rows ? rows.querySelectorAll( 'tr' ).length : 0;

	function checkRow( tr ) {
		var d = tr.querySelector( 'input[name$="[debut]"]' );
		var f = tr.querySelector( 'input[name$="[fin]"]' );
		var bad = d && f && d.value && f.value && f.value <= d.value;
		tr.classList.toggle( 'is-invalid', !! bad );
	}

	if ( rows && tpl && add ) {
		add.addEventListener( 'click', function () {
			var html = tpl.innerHTML.replace( /__i__/g, 'n' + next++ );
			rows.insertAdjacentHTML( 'beforeend', html );
			var sel = rows.lastElementChild.querySelector( 'select' );
			if ( sel ) {
				sel.focus();
			}
		} );
		rows.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.vhbc-del-creneau' );
			if ( ! btn ) {
				return;
			}
			var tr = btn.closest( 'tr' );
			if ( rows.querySelectorAll( 'tr' ).length > 1 ) {
				tr.remove();
			} else {
				tr.querySelectorAll( 'input' ).forEach( function ( i ) { i.value = ''; } );
				tr.querySelectorAll( 'select' ).forEach( function ( s ) { s.selectedIndex = 0; } );
			}
		} );
		rows.addEventListener( 'change', function ( e ) {
			var tr = e.target.closest( 'tr' );
			if ( tr ) {
				checkRow( tr );
			}
		} );
		rows.querySelectorAll( 'tr' ).forEach( checkRow );
	}

	/* Années : « et avant » désactive la première année ------------------ */
	var avant = document.querySelector( 'input[name="vhbc_et_avant"]' );
	var de = document.querySelector( '.vhbc-annee-de' );
	if ( avant && de ) {
		avant.addEventListener( 'change', function () {
			de.disabled = avant.checked;
		} );
	}

	/* Lieu : club en entente seulement pour un gymnase partenaire --------- */
	var type = document.querySelector( '.vhbc-type' );
	var part = document.querySelector( '.vhbc-if-partenaire' );
	if ( type && part ) {
		type.addEventListener( 'change', function () {
			part.hidden = 'partenaire' !== type.value;
		} );
	}

	/* Lignes répétables (Inscriptions) --------------------------------- */
	var seq = 0;
	document.querySelectorAll( '.vhbc-repeat-add' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var body = document.getElementById( btn.dataset.target );
			var t = document.getElementById( 'tmpl-' + btn.dataset.target );
			if ( body && t ) {
				body.insertAdjacentHTML( 'beforeend', t.innerHTML.replace( /__i__/g, 'n' + seq++ ) );
				var f = body.lastElementChild.querySelector( 'input,select' );
				if ( f ) {
					f.focus();
				}
			}
		} );
	} );
	document.addEventListener( 'click', function ( e ) {
		var del = e.target.closest( '.vhbc-repeat-del' );
		if ( del ) {
			var tr = del.closest( 'tr' );
			var body = tr.parentNode;
			if ( body.children.length > 1 ) {
				tr.remove();
			} else {
				tr.querySelectorAll( 'input, textarea' ).forEach( function ( i ) { i.value = ''; } );
				tr.querySelectorAll( '.vhbc-file-name' ).forEach( function ( n ) { n.textContent = ''; } );
				tr.querySelectorAll( '.vhbc-pill' ).forEach( function ( n ) { n.remove(); } );
			}
			return;
		}
		var mv = e.target.closest( '.vhbc-repeat-up, .vhbc-repeat-down' );
		if ( mv ) {
			var row = mv.closest( 'tr' );
			if ( mv.classList.contains( 'vhbc-repeat-up' ) && row.previousElementSibling ) {
				row.parentNode.insertBefore( row, row.previousElementSibling );
			} else if ( mv.classList.contains( 'vhbc-repeat-down' ) && row.nextElementSibling ) {
				row.parentNode.insertBefore( row.nextElementSibling, row );
			}
			mv.focus();
			return;
		}
		var pick = e.target.closest( '.vhbc-file-pick' );
		if ( pick && window.wp && window.wp.media ) {
			var cell = pick.closest( '.vhbc-file' );
			var img = !! pick.dataset.image;
			var frame = window.wp.media( { title: img ? 'Choisir une image' : 'Document à télécharger', library: img ? { type: 'image' } : {}, multiple: false } );
			frame.on( 'select', function () {
				var a = frame.state().get( 'selection' ).first().toJSON();
				cell.querySelector( '.vhbc-file-id' ).value = a.id; modifie = true;
				var label = cell.querySelector( '.vhbc-file-name' );
				if ( img ) {
					var src = a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url;
					label.innerHTML = '';
					var im = document.createElement( 'img' );
					im.src = src;
					im.alt = '';
					label.appendChild( im );
				} else {
					label.textContent = a.title || a.filename;
				}
			} );
			frame.open();
		}
	} );

	/* Modifications non enregistrées : avertir avant de quitter la page ----- */
	var forms = document.querySelectorAll( '.vhbc-admin form[method="post"]' );
	var modifie = false;
	function marquer( e ) {
		if ( e.target.closest( '.vhbc-admin form[method="post"]' ) ) {
			modifie = true;
		}
	}
	if ( forms.length ) {
		document.addEventListener( 'input', marquer );
		document.addEventListener( 'change', marquer );
		document.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( '.vhbc-repeat-add, .vhbc-repeat-del, .vhbc-repeat-up, .vhbc-repeat-down, .vhbc-media-clear' ) ) {
				marquer( e );
			}
		} );
		forms.forEach( function ( f ) {
			f.addEventListener( 'submit', function () { modifie = false; } );
		} );
		window.addEventListener( 'beforeunload', function ( e ) {
			if ( modifie ) {
				e.preventDefault();
				e.returnValue = '';
			}
		} );
	}

	/* Logo : médiathèque -------------------------------------------------- */
	document.querySelectorAll( '.vhbc-media' ).forEach( function ( box ) {
		var input = document.getElementById( box.dataset.target );
		var preview = box.querySelector( '.vhbc-media-preview' );
		var clear = box.querySelector( '.vhbc-media-clear' );
		var frame;
		box.querySelector( '.vhbc-media-pick' ).addEventListener( 'click', function () {
			if ( ! window.wp || ! window.wp.media ) {
				return;
			}
			frame = frame || window.wp.media( { title: 'Logo du club', library: { type: 'image' }, multiple: false } );
			frame.off( 'select' ).on( 'select', function () {
				var a = frame.state().get( 'selection' ).first().toJSON();
				input.value = a.id; modifie = true;
				var src = a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url;
				preview.innerHTML = '<img src="' + src.replace( /"/g, '' ) + '" alt="">';
				clear.hidden = false;
			} );
			frame.open();
		} );
		clear.addEventListener( 'click', function () {
			input.value = '0';
			preview.innerHTML = '';
			clear.hidden = true;
		} );
	} );
} )();
