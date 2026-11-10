<?php
/**
 * Photos du site (3.4.0).
 *
 * Les pages de la refonte réservent des emplacements photo (héros, vignettes,
 * sections de la page Pratiquer), dessinés avec un fond rayé et une légende
 * « [Photo : …] ». Les photos se choisissent ici, dans la médiathèque : les
 * pages n'ont plus à être modifiées pour changer une photo.
 *
 * Un emplacement « héros » prend plusieurs photos, qui défilent en fondu
 * (comme l'accueil). Chaque photo a un point de cadrage (x, y en %).
 *
 * Diffusion : script « vhb-photos » des pages de la refonte, avec les photos
 * de la page affichée (window.VHB_PHOTOS). Le même script pose les squelettes
 * de chargement (classe vhb-sk-z tant que la zone n'est pas « vhb-pret »).
 *
 * Option : vhb_club_photos_site = { page: { emplacement: [ { id, x, y } ] } }.
 */

defined( 'ABSPATH' ) || exit;

class VHB_Club_Photos_Site {

	const OPTION = 'vhb_club_photos_site';
	const PAGE   = 'vhb-club-photos-site';

	/**
	 * Emplacements des pages de la refonte.
	 * page (dernier segment de l'adresse) => [ nom de la page, [ emplacement => [ libellé, plusieurs ] ] ]
	 */
	public static function emplacements() {
		$e = array(
			'accueil'     => array( 'Accueil', array( 'hero' => array( 'Diaporama de l\'en-tête', true ) ) ),
			'pratiquer'   => array(
				'Pratiquer',
				array(
					'hero' => array( 'En-tête : les quatre pratiques réunies à la Halle', true ),
					'sec1' => array( 'BabyHand : une séance le samedi matin', false ),
					'sec2' => array( 'Compétition : un match à la Halle des Sports', false ),
					'sec3' => array( 'Loisir : le groupe un mardi soir', false ),
					'sec4' => array( 'HandFit : une séance', false ),
				),
			),
			'babyhand'    => array(
				'BabyHand',
				array(
					'hero'   => array( 'En-tête : une séance BabyHand', true ),
					'photo1' => array( 'Jeux d\'éveil parent-enfant', false ),
					'photo2' => array( 'Jeux collectifs', false ),
				),
			),
			'u9'          => array(
				'U9 Mixte',
				array(
					'hero'   => array( 'En-tête : une séance U9', true ),
					'photo1' => array( 'Atelier d\'adresse', false ),
					'photo2' => array( 'Premier but de la saison', false ),
					'photo3' => array( 'Mini-match du samedi', false ),
				),
			),
			'competition' => array(
				'Compétition',
				array(
					'hero'   => array( 'En-tête : un match à la Halle', true ),
					'photo1' => array( 'Match à la Halle', false ),
					'photo2' => array( 'La joie après un but', false ),
					'photo3' => array( 'L\'entraînement du lundi', false ),
				),
			),
			'loisir'      => array(
				'Loisir',
				array(
					'hero'   => array( 'En-tête : un match loisir', true ),
					'photo1' => array( 'Match amical à la Halle', false ),
					'photo2' => array( 'Troisième mi-temps', false ),
					'photo3' => array( 'L\'entraînement du mardi', false ),
				),
			),
			'handfit'     => array(
				'HandFit',
				array(
					'hero'   => array( 'En-tête : une séance HandFit', true ),
					'photo1' => array( 'Échauffement HandRoll', false ),
					'photo2' => array( 'Handjoy en groupe', false ),
					'photo3' => array( 'Retour au calme', false ),
				),
			),
		);
		/** Filtre « vhb_club_photos_emplacements » : emplacements d'autres pages. */
		return apply_filters( 'vhb_club_photos_emplacements', $e );
	}

	public static function init() {
		add_action( 'update_option_' . self::OPTION, array( 'VHB_Club_Cache', 'changed' ) );
		add_action( 'add_option_' . self::OPTION, array( 'VHB_Club_Cache', 'changed' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'front' ), 20 );
		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'menu' ), 10 );
			add_action( 'admin_post_vhb_club_photos_site', array( __CLASS__, 'save' ) );
		}
	}

	private static function pct( $v ) {
		return max( 0, min( 100, (int) round( (float) $v ) ) );
	}

	/** @return array<string,array<string,array<int,array{id:int,x:int,y:int}>>> */
	public static function get() {
		$o   = get_option( self::OPTION, array() );
		$out = array();
		foreach ( is_array( $o ) ? $o : array() as $page => $slots ) {
			foreach ( is_array( $slots ) ? $slots : array() as $slot => $list ) {
				foreach ( is_array( $list ) ? $list : array() as $p ) {
					$id = absint( $p['id'] ?? 0 );
					if ( $id ) {
						$out[ sanitize_key( $page ) ][ sanitize_key( $slot ) ][] = array(
							'id' => $id,
							'x'  => self::pct( $p['x'] ?? 50 ),
							'y'  => self::pct( $p['y'] ?? 50 ),
						);
					}
				}
			}
		}
		return $out;
	}

	/** Photos publiques d'une page : emplacement => [ { u, m, a, x, y } ]. */
	public static function pour_page( $page ) {
		$all = self::get();
		$out = array();
		foreach ( (array) ( $all[ $page ] ?? array() ) as $slot => $list ) {
			foreach ( $list as $p ) {
				$g = wp_get_attachment_image_src( $p['id'], '2048x2048' );
				if ( ! $g ) {
					$g = wp_get_attachment_image_src( $p['id'], 'full' );
				}
				if ( ! $g ) {
					continue;
				}
				$m = wp_get_attachment_image_src( $p['id'], '1536x1536' );
				$l = wp_get_attachment_image_src( $p['id'], 'large' );
				$out[ $slot ][] = array(
					'u' => $g[0],
					'm' => $m ? $m[0] : $g[0],
					'l' => $l ? $l[0] : $g[0],
					'a' => trim( (string) get_post_meta( $p['id'], '_wp_attachment_image_alt', true ) ),
					'x' => $p['x'],
					'y' => $p['y'],
				);
			}
		}
		return $out;
	}

	/** Clé de la page affichée : dernier segment de l'adresse, « accueil » pour l'accueil (et /v2/). */
	/**
	 * Première photo de l'en-tête de chaque page (cartes « Pratiquer au VHB » de l'accueil).
	 *
	 * @return array<string,array{m:string,a:string,x:int,y:int}>
	 */
	public static function heros() {
		$out = array();
		foreach ( array_keys( self::emplacements() ) as $page ) {
			if ( 'accueil' === $page ) {
				continue;
			}
			$h = self::pour_page( $page )['hero'][0] ?? null;
			if ( $h ) {
				$out[ $page ] = array( 'm' => $h['l'], 'a' => $h['a'], 'x' => $h['x'], 'y' => $h['y'] );
			}
		}
		return $out;
	}

	private static function page_courante() {
		if ( is_front_page() ) {
			return 'accueil';
		}
		$id = get_queried_object_id();
		if ( ! $id || ! is_page() ) {
			return '';
		}
		$slug = (string) get_post_field( 'post_name', $id );
		return 'v2' === $slug ? 'accueil' : sanitize_key( $slug );
	}

	/** Script et styles des pages de la refonte (repérées à leurs classes vhb2 / vhb3). */
	public static function front() {
		if ( ! is_singular() ) {
			return;
		}
		$c = (string) get_post_field( 'post_content', get_queried_object_id() );
		if ( false === strpos( $c, 'vhb3' ) && false === strpos( $c, 'vhb2' ) ) {
			return;
		}
		$page = self::page_courante();
		wp_enqueue_style( 'vhb-photos', VHB_CLUB_URL . 'assets/photos.css', array(), VHB_CLUB_VERSION );
		wp_enqueue_script( 'vhb-photos', VHB_CLUB_URL . 'assets/photos.js', array(), VHB_CLUB_VERSION, true );
		// Données dans l'en-tête : les composants des pages (dans le contenu) les lisent avant le pied de page.
		wp_register_script( 'vhb-photos-donnees', false, array(), VHB_CLUB_VERSION, false );
		wp_enqueue_script( 'vhb-photos-donnees' );
		wp_add_inline_script(
			'vhb-photos-donnees',
			'window.VHB_PHOTOS = ' . wp_json_encode(
				array(
					'page'   => $page,
					'photos' => (object) ( '' !== $page ? self::pour_page( $page ) : array() ),
					'heros'  => (object) ( 'accueil' === $page ? self::heros() : array() ),
				)
			) . ';'
		);
	}

	/* ------------------------------------------------------------------ */
	/* Administration                                                      */
	/* ------------------------------------------------------------------ */

	public static function menu() {
		add_submenu_page( VHB_Club_Admin_Slugs::MENU, 'Photos du site', 'Photos', VHB_Club_Reglages::CAP_DONNEES, self::PAGE, array( __CLASS__, 'page' ) );
	}

	/** Onglets communs aux deux pages photos. */
	public static function onglets( $actif ) {
		$o = array(
			self::PAGE               => 'Pages du site',
			VHB_Club_Photos::PAGE    => 'Équipes (cartes Panini)',
		);
		echo '<nav class="nav-tab-wrapper" style="margin-bottom:12px">';
		foreach ( $o as $slug => $t ) {
			echo '<a class="nav-tab' . ( $slug === $actif ? ' nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '">' . esc_html( $t ) . '</a>';
		}
		echo '</nav>';
	}

	public static function page() {
		if ( ! current_user_can( VHB_Club_Reglages::CAP_DONNEES ) ) {
			wp_die( 'Accès refusé.' );
		}
		wp_enqueue_media();
		$all = self::get();
		?>
		<div class="wrap vhbc-wrap vhbps">
			<h1>Photos</h1>
			<?php self::onglets( self::PAGE ); ?>
			<?php if ( isset( $_GET['ok'] ) ) : // phpcs:ignore ?>
				<div class="notice notice-success is-dismissible"><p>Photos enregistrées. Le cache des pages a été vidé.</p></div>
			<?php endif; ?>
			<p class="description" style="max-width:820px">Les photos des pages de la refonte, choisies dans la médiathèque. Un <strong>en-tête</strong> peut avoir plusieurs photos : elles défilent en fondu. Cliquez sur une photo pour placer son <strong>point de cadrage</strong> (ce qui doit rester visible quand la photo est recadrée). Format conseillé : paysage, 2000 px de large pour un en-tête, 1200 px pour une vignette. Pensez au texte alternatif dans la médiathèque. Un emplacement vide garde le fond de la maquette.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="vhb_club_photos_site">
				<?php wp_nonce_field( 'vhb_club_photos_site' ); ?>
				<?php foreach ( self::emplacements() as $page => $def ) : ?>
					<h2 class="vhbps-page"><?php echo esc_html( $def[0] ); ?></h2>
					<div class="vhbps-grille">
					<?php
					foreach ( $def[1] as $slot => $s ) :
						$list = array();
						foreach ( (array) ( $all[ $page ][ $slot ] ?? array() ) as $p ) {
							$src = wp_get_attachment_image_src( $p['id'], 'medium_large' );
							if ( $src ) {
								$list[] = array( 'id' => $p['id'], 'x' => $p['x'], 'y' => $p['y'], 'src' => $src[0] );
							}
						}
						?>
						<div class="vhbps-slot<?php echo $s[1] ? ' vhbps-multi' : ''; ?>" data-multi="<?php echo $s[1] ? '1' : '0'; ?>">
							<div class="vhbps-tete"><strong><?php echo esc_html( $s[0] ); ?></strong><?php echo $s[1] ? ' <span class="vhbc-badge">plusieurs photos</span>' : ''; ?></div>
							<div class="vhbps-photos"></div>
							<input type="hidden" class="vhbps-val" name="photos[<?php echo esc_attr( $page ); ?>][<?php echo esc_attr( $slot ); ?>]" value="<?php echo esc_attr( wp_json_encode( $list ) ); ?>">
							<p class="vhbps-actions"><button type="button" class="button vhbps-ajouter"><?php echo $s[1] ? 'Ajouter des photos' : 'Choisir une photo'; ?></button></p>
						</div>
					<?php endforeach; ?>
					</div>
				<?php endforeach; ?>
				<?php submit_button( 'Enregistrer les photos' ); ?>
			</form>
		</div>
		<style>
			.vhbps .vhbps-page{margin-top:28px}
			.vhbps-grille{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;margin:10px 0}
			.vhbps-slot{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px}
			.vhbps-multi{grid-column:span 2}
			@media (max-width:782px){.vhbps-multi{grid-column:auto}}
			.vhbps-tete{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-bottom:8px}
			.vhbps .vhbc-badge{font-size:11px;background:#f0f0f1;border-radius:10px;padding:1px 8px}
			.vhbps-photos{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px}
			.vhbps-vide{color:#646970;font-size:12px;padding:18px 0;grid-column:1/-1}
			.vhbps-ph{position:relative;border-radius:6px;overflow:hidden;background:#f0f0f1;line-height:0;cursor:crosshair}
			.vhbps-ph img{width:100%;height:110px;object-fit:cover;display:block}
			.vhbps-pt{position:absolute;width:18px;height:18px;margin:-9px 0 0 -9px;border:3px solid #fff;border-radius:50%;box-shadow:0 0 0 2px #FF6A3D,0 1px 4px rgba(0,0,0,.5);pointer-events:none}
			.vhbps-ph img{object-position:var(--x,50%) var(--y,50%)}
			.vhbps-outils{position:absolute;top:4px;right:4px;display:flex;gap:4px}
			.vhbps-outils button{border:0;border-radius:4px;background:rgba(0,0,0,.65);color:#fff;font-size:12px;line-height:1;padding:5px 7px;cursor:pointer}
			.vhbps-actions{margin:10px 0 0}
		</style>
		<script>
		( function () {
			document.querySelectorAll( '.vhbps-slot' ).forEach( function ( slot ) {
				var multi = slot.getAttribute( 'data-multi' ) === '1';
				var val = slot.querySelector( '.vhbps-val' );
				var zone = slot.querySelector( '.vhbps-photos' );
				var liste = [];
				try { liste = JSON.parse( val.value ) || []; } catch ( e ) { liste = []; }
				var frame = null;
				function sauver() {
					val.value = JSON.stringify( liste.map( function ( p ) { return { id: p.id, x: p.x, y: p.y, src: p.src }; } ) );
				}
				function rendre() {
					zone.innerHTML = '';
					if ( ! liste.length ) {
						var v = document.createElement( 'div' );
						v.className = 'vhbps-vide';
						v.textContent = 'Aucune photo : le fond de la maquette reste affiché.';
						zone.appendChild( v );
					}
					liste.forEach( function ( p, i ) {
						var b = document.createElement( 'div' );
						b.className = 'vhbps-ph';
						b.title = 'Cliquer pour placer le point de cadrage';
						b.style.setProperty( '--x', p.x + '%' );
						b.style.setProperty( '--y', p.y + '%' );
						var img = document.createElement( 'img' );
						img.src = p.src; img.alt = '';
						var pt = document.createElement( 'span' );
						pt.className = 'vhbps-pt';
						pt.style.left = p.x + '%'; pt.style.top = p.y + '%';
						var o = document.createElement( 'div' );
						o.className = 'vhbps-outils';
						if ( multi ) {
							if ( i > 0 ) { o.appendChild( bouton( '←', 'Avancer', function () { liste.splice( i - 1, 0, liste.splice( i, 1 )[ 0 ] ); sauver(); rendre(); } ) ); }
							if ( i < liste.length - 1 ) { o.appendChild( bouton( '→', 'Reculer', function () { liste.splice( i + 1, 0, liste.splice( i, 1 )[ 0 ] ); sauver(); rendre(); } ) ); }
						}
						o.appendChild( bouton( '×', 'Retirer', function () { liste.splice( i, 1 ); sauver(); rendre(); } ) );
						b.appendChild( img ); b.appendChild( pt ); b.appendChild( o );
						b.addEventListener( 'click', function ( e ) {
							if ( e.target.tagName === 'BUTTON' ) { return; }
							var r = img.getBoundingClientRect();
							p.x = Math.round( Math.max( 0, Math.min( 100, ( e.clientX - r.left ) / r.width * 100 ) ) );
							p.y = Math.round( Math.max( 0, Math.min( 100, ( e.clientY - r.top ) / r.height * 100 ) ) );
							sauver(); rendre();
						} );
						zone.appendChild( b );
					} );
					slot.querySelector( '.vhbps-ajouter' ).textContent = multi ? 'Ajouter des photos' : ( liste.length ? 'Remplacer' : 'Choisir une photo' );
				}
				function bouton( t, titre, f ) {
					var x = document.createElement( 'button' );
					x.type = 'button'; x.textContent = t; x.title = titre;
					x.addEventListener( 'click', function ( e ) { e.preventDefault(); e.stopPropagation(); f(); } );
					return x;
				}
				slot.querySelector( '.vhbps-ajouter' ).addEventListener( 'click', function () {
					if ( ! window.wp || ! window.wp.media ) { return; }
					frame = frame || window.wp.media( { title: 'Photos', library: { type: 'image' }, multiple: multi ? 'add' : false } );
					frame.off( 'select' ).on( 'select', function () {
						var sel = frame.state().get( 'selection' ).toJSON();
						if ( ! multi ) { liste = []; sel = sel.slice( 0, 1 ); }
						sel.forEach( function ( a ) {
							var s = a.sizes ? ( a.sizes.medium_large || a.sizes.large || a.sizes.medium ) : null;
							liste.push( { id: a.id, x: 50, y: 50, src: s ? s.url : a.url } );
						} );
						sauver(); rendre();
					} );
					frame.open();
				} );
				rendre();
			} );
		} )();
		</script>
		<?php
	}

	public static function save() {
		if ( ! current_user_can( VHB_Club_Reglages::CAP_DONNEES ) ) {
			wp_die( 'Accès refusé.' );
		}
		check_admin_referer( 'vhb_club_photos_site' );
		$in  = isset( $_POST['photos'] ) && is_array( $_POST['photos'] ) ? wp_unslash( $_POST['photos'] ) : array(); // phpcs:ignore
		$def = self::emplacements();
		$out = array();
		foreach ( $def as $page => $d ) {
			foreach ( $d[1] as $slot => $s ) {
				$list = json_decode( (string) ( $in[ $page ][ $slot ] ?? '[]' ), true );
				foreach ( is_array( $list ) ? $list : array() as $p ) {
					$id = absint( $p['id'] ?? 0 );
					if ( ! $id || ! wp_attachment_is_image( $id ) ) {
						continue;
					}
					$out[ $page ][ $slot ][] = array(
						'id' => $id,
						'x'  => self::pct( $p['x'] ?? 50 ),
						'y'  => self::pct( $p['y'] ?? 50 ),
					);
					if ( ! $s[1] ) {
						break; // une seule photo
					}
				}
			}
		}
		update_option( self::OPTION, $out, false );
		VHB_Club_Cache::changed();
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&ok=1' ) );
		exit;
	}
}
