/**
 * Pages de la refonte (VHB 3.4.0) :
 * 1. photos des emplacements choisies dans VHB > Photos (window.VHB_PHOTOS) ;
 * 2. légendes « [Photo : …] » de la maquette masquées ;
 * 3. squelettes de chargement : une zone « vhb-sk-z » reste en squelette jusqu'à ce que
 *    son composant lui ajoute la classe « vhb-pret » (au plus 10 secondes).
 */
( function () {
	var D = window.VHB_PHOTOS || { page: '', photos: {} };
	var P = D.photos || {};
	var reduit = window.matchMedia ? window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches : false;

	function taille( el, p ) {
		var w = el.getBoundingClientRect().width * ( window.devicePixelRatio || 1 );
		if ( w > 1536 ) { return p.u; }
		return w > 1024 ? p.m : p.l;
	}
	function couche( el, p ) {
		var c = document.createElement( 'div' );
		c.className = 'vhb-ph-c';
		c.style.backgroundImage = 'url("' + String( taille( el, p ) ).replace( /"/g, '%22' ) + '")';
		c.style.backgroundPosition = p.x + '% ' + p.y + '%';
		if ( p.a ) { c.setAttribute( 'role', 'img' ); c.setAttribute( 'aria-label', p.a ); }
		return c;
	}
	function poser( el, liste, modale ) {
		if ( ! el || ! liste || ! liste.length || el.classList.contains( 'vhb-ph-ok' ) ) { return; }
		if ( window.getComputedStyle( el ).position === 'static' ) { el.style.position = 'relative'; }
		el.classList.add( 'vhb-ph-ok' );
		var cs = liste.map( function ( p ) { var c = couche( el, p ); el.appendChild( c ); return c; } );
		cs[ 0 ].classList.add( 'on' );
		if ( modale ) {
			modale.setAttribute( 'data-modale', liste[ 0 ].u );
			if ( liste[ 0 ].a ) { modale.setAttribute( 'data-modale-titre', liste[ 0 ].a ); }
		}
		if ( cs.length > 1 ) {
			if ( reduit ) { return; }
			var k = 0;
			setInterval( function () {
				cs[ k ].classList.remove( 'on' );
				k = ( k + 1 ) % cs.length;
				cs[ k ].classList.add( 'on' );
			}, 6000 );
		}
	}
	function tous( sel ) { return Array.prototype.slice.call( document.querySelectorAll( sel ) ); }

	// 1. Photos
	if ( D.page === 'pratiquer' ) {
		tous( '.vhb2-hero .vhb2-kbz' ).forEach( function ( el ) { poser( el, P.hero ); } );
		tous( '.vhb2-sec .vhb2-kbz' ).forEach( function ( el, i ) { poser( el, P[ 'sec' + ( i + 1 ) ] ); } );
	} else if ( D.page !== 'accueil' ) {
		tous( '.vhb3-d .kb, .vhb3-m .kb' ).forEach( function ( el ) { poser( el, P.hero ); } );
		[ '.vhb3-d', '.vhb3-m' ].forEach( function ( v ) {
			tous( v + ' .phw > .ph' ).forEach( function ( el, i ) { poser( el, P[ 'photo' + ( i + 1 ) ], el.parentNode ); } );
		} );
	}

	// 2. Légendes de la maquette
	tous( '.vhb3-d *, .vhb3-m *, .vhb2 *, .vhb2-hero *, .vhb2-sec *' ).forEach( function ( el ) {
		if ( el.children.length ) { return; }
		var t = ( el.textContent || '' ).trim();
		if ( t.indexOf( '[Photo' ) !== 0 ) { return; }
		if ( el.classList.contains( 'ph' ) ) { el.classList.add( 'vhb-ph-muet' ); } else { el.style.visibility = 'hidden'; }
	} );

	// 3. Squelettes : filet de sécurité si une donnée n'arrive jamais
	setTimeout( function () {
		tous( '.vhb-sk-z' ).forEach( function ( el ) { el.classList.add( 'vhb-pret' ); } );
	}, 10000 );
} )();
