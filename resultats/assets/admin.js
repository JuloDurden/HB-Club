/* VHB Résultats FFHB : administration. */
( function () {
	'use strict';
	var cfg = window.vhbFfhbAdmin || {};
	var btn = document.getElementById( 'vhb-refresh' );
	var status = document.getElementById( 'vhb-refresh-status' );
	var progress = document.querySelector( '.vhb-progress' );
	var bar = document.querySelector( '.vhb-progress-bar' );

	function call( op ) {
		var body = new URLSearchParams();
		body.append( 'action', 'vhb_ffhb_refresh' );
		body.append( 'nonce', cfg.nonce );
		body.append( 'op', op );
		return fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) {
			if ( ! r.ok ) {
				throw new Error( 'HTTP ' + r.status );
			}
			return r.json();
		} );
	}

	function show( run, text ) {
		if ( run && run.total ) {
			progress.hidden = false;
			bar.style.width = Math.round( ( 100 * run.fait ) / run.total ) + '%';
			status.textContent = run.fait + ' / ' + run.total + ( run.dernier ? ' · ' + run.dernier : '' );
		}
		if ( text ) {
			status.textContent = text;
		}
	}

	function loop() {
		call( 'step' )
			.then( function ( res ) {
				var d = res.data || {};
				if ( d.status === 'running' ) {
					show( d.run );
					loop();
				} else if ( d.status === 'busy' ) {
					show( d.run );
					setTimeout( loop, 3000 );
				} else {
					var run = d.run || {};
					var ko = run.ko ? Object.keys( run.ko ).length : 0;
					show( run, 'Terminé : ' + ( run.ok ? run.ok.length : 0 ) + ' équipe(s) à jour' + ( ko ? ', ' + ko + ' en échec (voir le journal)' : '' ) + '. Rechargement…' );
					setTimeout( function () {
						window.location.reload();
					}, 1500 );
				}
			} )
			.catch( function ( e ) {
				status.textContent = 'Erreur : ' + e.message + '. La mise à jour reprendra au prochain passage du cron.';
				btn.disabled = false;
			} );
	}

	if ( btn ) {
		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			status.textContent = 'Démarrage…';
			call( 'start' )
				.then( function ( res ) {
					var d = res.data || {};
					if ( d.status === 'busy' ) {
						status.textContent = 'Une mise à jour est déjà en cours, suivi…';
					}
					show( d.run );
					loop();
				} )
				.catch( function ( e ) {
					status.textContent = 'Erreur : ' + e.message;
					btn.disabled = false;
				} );
		} );
	}

	// Lignes d'équipes : ajout et retrait.
	var rows = document.getElementById( 'vhb-team-rows' );
	var tmpl = document.getElementById( 'tmpl-vhb-team-row' );
	var add = document.getElementById( 'vhb-add-team' );
	if ( rows && tmpl && add ) {
		add.addEventListener( 'click', function () {
			var i = 'n' + Date.now();
			var t = document.createElement( 'tbody' );
			t.innerHTML = tmpl.innerHTML.split( '__i__' ).join( i );
			rows.appendChild( t.firstElementChild );
		} );
		rows.addEventListener( 'click', function ( e ) {
			var t = e.target;
			if ( t.classList.contains( 'vhb-del' ) ) {
				t.closest( 'tr' ).remove();
				return;
			}
			// Compétitions d'une équipe : ajout et retrait de lignes.
			if ( t.classList.contains( 'vhb-comp-add' ) ) {
				var box = t.closest( '.vhb-comps' );
				var b = box.getAttribute( 'data-base' ) + '[c' + Date.now() + ']';
				var line = document.createElement( 'div' );
				line.className = 'vhb-comp-line';
				line.innerHTML = '<input class="vhb-comp-nom" placeholder="Coupe de France" title="Nom court affiché sur le site (facultatif)">'
					+ '<input class="code vhb-comp-url" placeholder="https://www.ffhandball.fr/competitions/{saison}/.../equipe-123456/">'
					+ '<button type="button" class="button-link vhb-comp-del" aria-label="Retirer cette compétition">×</button>';
				line.children[0].name = b + '[nom]';
				line.children[1].name = b + '[url]';
				box.insertBefore( line, t );
				line.children[1].focus();
				return;
			}
			if ( t.classList.contains( 'vhb-comp-del' ) ) {
				var l = t.closest( '.vhb-comp-line' );
				var all = l.parentNode.querySelectorAll( '.vhb-comp-line' );
				if ( all.length > 1 ) {
					l.remove();
				} else {
					l.querySelectorAll( 'input' ).forEach( function ( i ) { i.value = ''; } );
				}
				return;
			}
			var cell = t.closest( '.vhb-blason' );
			if ( ! cell ) {
				return;
			}
			var input = cell.querySelector( '.vhb-blason-id' );
			var preview = cell.querySelector( '.vhb-blason-preview' );
			var clear = cell.querySelector( '.vhb-blason-clear' );
			if ( t.classList.contains( 'vhb-blason-clear' ) ) {
				input.value = '';
				preview.innerHTML = '<em>FFHB</em>';
				clear.hidden = true;
			} else if ( t.classList.contains( 'vhb-blason-pick' ) && window.wp && wp.media ) {
				var frame = wp.media( { title: 'Blason de l\u2019équipe', library: { type: 'image' }, button: { text: 'Utiliser ce blason' }, multiple: false } );
				frame.on( 'select', function () {
					var a = frame.state().get( 'selection' ).first().toJSON();
					var src = ( a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url );
					input.value = a.id;
					preview.innerHTML = '';
					var img = document.createElement( 'img' );
					img.src = src;
					img.alt = '';
					preview.appendChild( img );
					clear.hidden = false;
				} );
				frame.open();
			}
		} );
	}
} )();
