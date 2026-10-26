/* VHB Résultats FFHB : interactions front (carte du lieu, onglets mobile). */
( function () {
	'use strict';
	var desktop = window.matchMedia( '(min-width: 768px)' );

	// Lieu : la carte n'est chargée qu'à l'ouverture, et seulement en desktop.
	document.addEventListener(
		'toggle',
		function ( e ) {
			var d = e.target;
			if ( ! d.classList || ! d.classList.contains( 'vhb-lieu' ) || ! d.open || ! desktop.matches ) {
				return;
			}
			var box = d.querySelector( '.vhb-map' );
			if ( ! box || box.firstChild ) {
				return;
			}
			var lat = parseFloat( box.getAttribute( 'data-lat' ) );
			var lon = parseFloat( box.getAttribute( 'data-lon' ) );
			if ( isNaN( lat ) || isNaN( lon ) ) {
				return;
			}
			var dx = 0.006, dy = 0.0035;
			var f = document.createElement( 'iframe' );
			f.src = 'https://www.openstreetmap.org/export/embed.html?bbox=' + ( lon - dx ) + '%2C' + ( lat - dy ) + '%2C' + ( lon + dx ) + '%2C' + ( lat + dy ) + '&layer=mapnik&marker=' + lat + '%2C' + lon;
			f.title = 'Carte : ' + ( box.getAttribute( 'data-title' ) || 'lieu du match' );
			f.loading = 'lazy';
			f.referrerPolicy = 'no-referrer';
			box.appendChild( f );
			var a = document.createElement( 'a' );
			a.href = 'https://www.openstreetmap.org/?mlat=' + lat + '&mlon=' + lon + '#map=16/' + lat + '/' + lon;
			a.target = '_blank';
			a.rel = 'noopener';
			a.className = 'vhb-map-link';
			a.textContent = 'Agrandir la carte';
			box.appendChild( a );
		},
		true
	);

	// Panneau d'équipe : onglets en mobile, tout visible en desktop.
	document.querySelectorAll( '[data-vhb-tabs]' ).forEach( function ( root ) {
		var bar = root.querySelector( '.vhb-tabbar' );
		if ( ! bar ) {
			return;
		}
		var tabs = bar.querySelectorAll( '.vhb-tab' );
		var panels = root.querySelectorAll( '.vhb-panel' );
		function select( key ) {
			tabs.forEach( function ( t ) {
				var on = t.getAttribute( 'data-tab' ) === key;
				t.classList.toggle( 'is-active', on );
				t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			} );
			panels.forEach( function ( p ) {
				p.classList.toggle( 'is-active', p.getAttribute( 'data-panel' ) === key );
			} );
		}
		bar.hidden = false;
		root.classList.add( 'vhb-tabs-on' );
		select( root.getAttribute( 'data-vhb-tabs' ) || 'avenir' );
		bar.addEventListener( 'click', function ( e ) {
			var t = e.target.closest( '.vhb-tab' );
			if ( t ) {
				select( t.getAttribute( 'data-tab' ) );
			}
		} );
	} );
} )();
