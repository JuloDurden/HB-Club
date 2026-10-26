<?php
/**
 * Photos d'équipe (2.0.0).
 *
 * Une photo par équipe des Résultats FFHB (clé : identifiant de l'équipe,
 * ex. « seniors-m »), choisie dans la médiathèque, avec un point de cadrage
 * en pourcentage (x, y) : la carte Panini recadre la photo autour de ce point.
 *
 * Diffusion :
 * - REST /wp-json/vhb/v1/equipes : champ « photo » de chaque équipe ;
 * - données des pages (script « vhb-club-donnees ») : « photos » (par
 *   équipe) et « photo » de chaque catégorie (première équipe liée qui en a une).
 *
 * Option : vhb_club_photos = { slug: { id, x, y } }.
 */

defined( 'ABSPATH' ) || exit;

class VHB_Club_Photos {

	const OPTION = 'vhb_club_photos';
	const PAGE   = 'vhb-club-photos';

	public static function init() {
		add_action( 'update_option_' . self::OPTION, array( 'VHB_Club_Cache', 'changed' ) );
		add_action( 'add_option_' . self::OPTION, array( 'VHB_Club_Cache', 'changed' ) );
		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'menu' ), 10 );
			add_action( 'admin_post_vhb_club_photos', array( __CLASS__, 'save' ) );
		}
	}

	/** @return array<string,array{id:int,x:int,y:int}> */
	public static function get() {
		$o   = get_option( self::OPTION, array() );
		$out = array();
		foreach ( is_array( $o ) ? $o : array() as $slug => $p ) {
			$slug = sanitize_title( (string) $slug );
			$id   = absint( $p['id'] ?? 0 );
			if ( '' === $slug || ! $id ) {
				continue;
			}
			$out[ $slug ] = array(
				'id' => $id,
				'x'  => self::pct( $p['x'] ?? 50 ),
				'y'  => self::pct( $p['y'] ?? 50 ),
			);
		}
		return $out;
	}

	private static function pct( $v ) {
		return max( 0, min( 100, (int) round( (float) $v ) ) );
	}

	/** Photo publique d'une équipe, ou null. */
	public static function pour_equipe( $slug ) {
		$all = self::get();
		$p   = $all[ (string) $slug ] ?? null;
		if ( ! $p ) {
			return null;
		}
		$grande = wp_get_attachment_image_src( $p['id'], 'large' );
		if ( ! $grande ) {
			return null;
		}
		$moyenne = wp_get_attachment_image_src( $p['id'], 'medium_large' );
		$alt     = trim( (string) get_post_meta( $p['id'], '_wp_attachment_image_alt', true ) );
		return array(
			'url'     => $grande[0],
			'largeur' => (int) $grande[1],
			'hauteur' => (int) $grande[2],
			'moyenne' => $moyenne ? $moyenne[0] : $grande[0],
			'x'       => $p['x'],
			'y'       => $p['y'],
			'position' => $p['x'] . '% ' . $p['y'] . '%',
			'alt'     => $alt,
		);
	}

	/** Toutes les photos publiques, par équipe. */
	public static function publiques() {
		$out = array();
		foreach ( array_keys( self::get() ) as $slug ) {
			$p = self::pour_equipe( $slug );
			if ( $p ) {
				$out[ $slug ] = $p;
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Administration                                                      */
	/* ------------------------------------------------------------------ */

	public static function menu() {
		add_submenu_page( VHB_Club_Admin_Slugs::MENU, 'Photos d\'équipe', 'Photos d\'équipe', VHB_Club_Reglages::CAP_DONNEES, self::PAGE, array( __CLASS__, 'page' ) );
	}

	/** Équipes connues : celles des Résultats FFHB, plus les photos dont l'équipe n'existe plus. */
	private static function equipes() {
		$list = array();
		if ( class_exists( 'VHB_Settings' ) ) {
			foreach ( VHB_Settings::equipes() as $e ) {
				$list[ $e['slug'] ] = array(
					'nom'     => $e['nom'],
					'masquee' => ! empty( $e['masquee'] ),
					'comps'   => count( (array) $e['competitions'] ),
				);
			}
		}
		foreach ( self::get() as $slug => $p ) {
			if ( ! isset( $list[ $slug ] ) ) {
				$list[ $slug ] = array( 'nom' => $slug, 'orpheline' => true );
			}
		}
		return $list;
	}

	/** Catégories VHB Club rattachées à chaque équipe (pour information). */
	private static function categories_par_equipe() {
		$out = array();
		if ( ! class_exists( 'VHB_Club_Donnees' ) ) {
			return $out;
		}
		foreach ( VHB_Club_Donnees::categories() as $c ) {
			foreach ( (array) $c['equipes'] as $slug ) {
				$out[ $slug ][] = html_entity_decode( $c['nom'], ENT_QUOTES, 'UTF-8' );
			}
		}
		return $out;
	}

	public static function page() {
		if ( ! current_user_can( VHB_Club_Reglages::CAP_DONNEES ) ) {
			wp_die( 'Accès refusé.' );
		}
		wp_enqueue_media();
		$photos = self::get();
		$teams  = self::equipes();
		$cats   = self::categories_par_equipe();
		$res    = current_user_can( 'manage_options' ) ? admin_url( 'admin.php?page=vhb-ffhb' ) : '';
		?>
		<div class="wrap vhbc-wrap vhbc-photos">
			<h1>Photos d'équipe</h1>
			<?php if ( isset( $_GET['ok'] ) ) : // phpcs:ignore ?>
				<div class="notice notice-success is-dismissible"><p>Photos enregistrées. Le cache des pages a été vidé.</p></div>
			<?php endif; ?>
			<p class="description" style="max-width:760px">Une photo par équipe, affichée sur sa carte Panini (page Nos équipes, page Loisir). Cliquez sur la photo pour placer le <strong>point de cadrage</strong> (le centre de la carte) : la photo est recadrée autour de ce point. Format conseillé : paysage, 1600 px de large au moins. Pensez au texte alternatif dans la médiathèque.</p>
			<?php if ( ! $teams ) : ?>
				<div class="notice notice-warning inline"><p>Aucune équipe déclarée dans les Résultats FFHB.<?php echo $res ? ' <a href="' . esc_url( $res ) . '">Ajouter les équipes</a>.' : ''; ?></p></div>
			<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="vhb_club_photos">
				<?php wp_nonce_field( 'vhb_club_photos' ); ?>
				<div class="vhbc-photos-grille">
				<?php
				foreach ( $teams as $slug => $t ) :
					$p   = $photos[ $slug ] ?? array( 'id' => 0, 'x' => 50, 'y' => 50 );
					$src = $p['id'] ? wp_get_attachment_image_src( $p['id'], 'medium_large' ) : false;
					$n   = 'photos[' . $slug . ']';
					?>
					<div class="vhbc-photo" data-slug="<?php echo esc_attr( $slug ); ?>">
						<div class="vhbc-photo-tete">
							<strong><?php echo esc_html( $t['nom'] ); ?></strong>
							<code><?php echo esc_html( $slug ); ?></code>
							<?php if ( ! empty( $t['orpheline'] ) ) : ?>
								<span class="vhbc-badge vhbc-badge--alerte">équipe supprimée des Résultats</span>
							<?php else : ?>
								<?php if ( $t['masquee'] ) : ?><span class="vhbc-badge">masquée</span><?php endif; ?>
								<?php if ( ! $t['comps'] ) : ?><span class="vhbc-badge">sans compétition</span><?php endif; ?>
							<?php endif; ?>
						</div>
						<?php if ( ! empty( $cats[ $slug ] ) ) : ?>
							<div class="vhbc-photo-cats">Catégorie : <?php echo esc_html( implode( ', ', $cats[ $slug ] ) ); ?></div>
						<?php endif; ?>
						<div class="vhbc-photo-cadre<?php echo $src ? '' : ' vide'; ?>" title="Cliquer pour placer le point de cadrage">
							<?php if ( $src ) : ?>
								<img src="<?php echo esc_url( $src[0] ); ?>" alt="">
							<?php endif; ?>
							<span class="vhbc-photo-point" style="left:<?php echo (int) $p['x']; ?>%;top:<?php echo (int) $p['y']; ?>%"></span>
							<span class="vhbc-photo-vide">Aucune photo</span>
						</div>
						<div class="vhbc-photo-apercus" aria-hidden="true">
							<span class="vhbc-photo-apercu vhbc-photo-apercu--portrait" title="Carte Panini (portrait)"<?php echo $src ? ' style="background-image:url(' . esc_url( $src[0] ) . ');background-position:' . (int) $p['x'] . '% ' . (int) $p['y'] . '%"' : ''; ?>></span>
							<span class="vhbc-photo-apercu vhbc-photo-apercu--paysage" title="Carte de groupe (paysage)"<?php echo $src ? ' style="background-image:url(' . esc_url( $src[0] ) . ');background-position:' . (int) $p['x'] . '% ' . (int) $p['y'] . '%"' : ''; ?>></span>
						</div>
						<input type="hidden" class="vhbc-photo-id" name="<?php echo esc_attr( $n ); ?>[id]" value="<?php echo (int) $p['id']; ?>">
						<input type="hidden" class="vhbc-photo-x" name="<?php echo esc_attr( $n ); ?>[x]" value="<?php echo (int) $p['x']; ?>">
						<input type="hidden" class="vhbc-photo-y" name="<?php echo esc_attr( $n ); ?>[y]" value="<?php echo (int) $p['y']; ?>">
						<p class="vhbc-photo-actions">
							<button type="button" class="button vhbc-photo-choisir"><?php echo $src ? 'Remplacer' : 'Choisir une photo'; ?></button>
							<button type="button" class="button-link vhbc-photo-centrer"<?php echo $src ? '' : ' hidden'; ?>>Recentrer</button>
							<button type="button" class="button-link button-link-delete vhbc-photo-retirer"<?php echo $src ? '' : ' hidden'; ?>>Retirer</button>
						</p>
					</div>
				<?php endforeach; ?>
				</div>
				<?php submit_button( 'Enregistrer les photos' ); ?>
			</form>
			<p class="description">L'équipe Loisirs apparaît ici dès qu'elle est ajoutée aux équipes des Résultats FFHB<?php echo $res ? ' (<a href="' . esc_url( $res ) . '">Résultats FFHB</a>)' : ''; ?>, même sans compétition. Une équipe sans compétition n'est pas publiée dans les résultats : sa photo reste disponible pour les pages.</p>
			<?php endif; ?>
		</div>
		<style>
			.vhbc-photos-grille{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;margin:16px 0}
			.vhbc-photo{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px}
			.vhbc-photo-tete{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:4px}
			.vhbc-photo-tete code{font-size:11px}
			.vhbc-photo-cats{font-size:12px;color:#646970;margin-bottom:6px}
			.vhbc-badge{font-size:11px;background:#f0f0f1;border-radius:10px;padding:1px 8px}
			.vhbc-badge--alerte{background:#fcf0f1;color:#b32d2e}
			.vhbc-photo-cadre{position:relative;cursor:crosshair;background:#f0f0f1;border-radius:6px;overflow:hidden;min-height:120px;line-height:0}
			.vhbc-photo-cadre img{width:100%;height:auto;display:block}
			.vhbc-photo-cadre.vide{cursor:default}
			.vhbc-photo-point{position:absolute;width:22px;height:22px;margin:-11px 0 0 -11px;border:3px solid #fff;border-radius:50%;box-shadow:0 0 0 2px #FF6A3D,0 1px 4px rgba(0,0,0,.5);pointer-events:none}
			.vhbc-photo-cadre.vide .vhbc-photo-point{display:none}
			.vhbc-photo-vide{display:none;position:absolute;inset:0;align-items:center;justify-content:center;color:#646970;line-height:1.4}
			.vhbc-photo-cadre.vide .vhbc-photo-vide{display:flex}
			.vhbc-photo-apercus{display:flex;gap:8px;align-items:flex-end;margin-top:8px}
			.vhbc-photo-apercu{display:block;background:#f0f0f1 center/cover no-repeat;border-radius:4px;border:1px solid #dcdcde}
			.vhbc-photo-apercu--portrait{width:60px;height:80px}
			.vhbc-photo-apercu--paysage{width:132px;height:80px}
			.vhbc-photo-actions{display:flex;gap:12px;align-items:center;margin:10px 0 0}
		</style>
		<script>
		( function () {
			document.querySelectorAll( '.vhbc-photo' ).forEach( function ( box ) {
				var cadre = box.querySelector( '.vhbc-photo-cadre' );
				var point = box.querySelector( '.vhbc-photo-point' );
				var id = box.querySelector( '.vhbc-photo-id' );
				var x = box.querySelector( '.vhbc-photo-x' );
				var y = box.querySelector( '.vhbc-photo-y' );
				var apercus = box.querySelectorAll( '.vhbc-photo-apercu' );
				var choisir = box.querySelector( '.vhbc-photo-choisir' );
				var centrer = box.querySelector( '.vhbc-photo-centrer' );
				var retirer = box.querySelector( '.vhbc-photo-retirer' );
				var frame;
				function maj() {
					point.style.left = x.value + '%';
					point.style.top = y.value + '%';
					var img = cadre.querySelector( 'img' );
					apercus.forEach( function ( a ) {
						a.style.backgroundImage = img ? 'url(' + img.src + ')' : '';
						a.style.backgroundPosition = x.value + '% ' + y.value + '%';
					} );
					var vide = ! img;
					cadre.classList.toggle( 'vide', vide );
					centrer.hidden = vide;
					retirer.hidden = vide;
					choisir.textContent = vide ? 'Choisir une photo' : 'Remplacer';
				}
				cadre.addEventListener( 'click', function ( e ) {
					var img = cadre.querySelector( 'img' );
					if ( ! img ) { return; }
					var r = img.getBoundingClientRect();
					x.value = Math.round( Math.max( 0, Math.min( 100, ( e.clientX - r.left ) / r.width * 100 ) ) );
					y.value = Math.round( Math.max( 0, Math.min( 100, ( e.clientY - r.top ) / r.height * 100 ) ) );
					maj();
				} );
				centrer.addEventListener( 'click', function () { x.value = 50; y.value = 50; maj(); } );
				retirer.addEventListener( 'click', function () {
					id.value = 0;
					var img = cadre.querySelector( 'img' );
					if ( img ) { img.remove(); }
					maj();
				} );
				choisir.addEventListener( 'click', function () {
					if ( ! window.wp || ! window.wp.media ) { return; }
					frame = frame || window.wp.media( { title: 'Photo de l\'équipe', library: { type: 'image' }, multiple: false } );
					frame.off( 'select' ).on( 'select', function () {
						var a = frame.state().get( 'selection' ).first().toJSON();
						var s = a.sizes && ( a.sizes.medium_large || a.sizes.large ) ? ( a.sizes.medium_large || a.sizes.large ).url : a.url;
						id.value = a.id;
						x.value = 50; y.value = 50;
						var img = cadre.querySelector( 'img' );
						if ( ! img ) { img = document.createElement( 'img' ); img.alt = ''; cadre.insertBefore( img, point ); }
						img.src = s;
						maj();
					} );
					frame.open();
				} );
			} );
		} )();
		</script>
		<?php
	}

	public static function save() {
		if ( ! current_user_can( VHB_Club_Reglages::CAP_DONNEES ) ) {
			wp_die( 'Accès refusé.' );
		}
		check_admin_referer( 'vhb_club_photos' );
		$in  = isset( $_POST['photos'] ) && is_array( $_POST['photos'] ) ? wp_unslash( $_POST['photos'] ) : array(); // phpcs:ignore
		$out = array();
		foreach ( $in as $slug => $p ) {
			$slug = sanitize_title( (string) $slug );
			$id   = absint( $p['id'] ?? 0 );
			if ( '' === $slug || ! $id || ! wp_attachment_is_image( $id ) ) {
				continue;
			}
			$out[ $slug ] = array(
				'id' => $id,
				'x'  => self::pct( $p['x'] ?? 50 ),
				'y'  => self::pct( $p['y'] ?? 50 ),
			);
		}
		update_option( self::OPTION, $out, false );
		VHB_Club_Cache::changed();
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&ok=1' ) );
		exit;
	}
}
