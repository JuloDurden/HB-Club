<?php
/**
 * Export et import (lot 4) : toutes les données de VHB Club dans un fichier
 * JSON, pour la sauvegarde ou le passage d'un site de test au site en ligne.
 * Depuis 2.0.0 : aussi les photos d'équipe et les réglages des Résultats FFHB
 * (équipes, compétitions, blasons, gymnases, apparence). Les résultats
 * eux-mêmes ne sont pas exportés : ils sont récupérés de nouveau sur la FFHB.
 * Un export plus ancien (sans ces parties) les laisse tels quels à l'import.
 *
 * Import = remplacement complet. Avant d'importer, l'état actuel est gardé
 * dans une sauvegarde automatique (restaurable d'un clic) et les anciennes
 * fiches vont à la corbeille (jamais de suppression définitive).
 * Les images et documents ne sont pas copiés : ils sont retrouvés dans la
 * médiathèque du site par leur adresse, sinon laissés vides (signalé).
 */

defined( 'ABSPATH' ) || exit;

class VHB_Club_Export {

	const SLUG       = 'vhb-club-export';
	const SAUVEGARDE = 'vhb_club_sauvegarde';
	const FORMAT     = 'vhb-club';

	const TYPES = array(
		'lieux'      => VHB_Club_Types::LIEU,
		'ententes'   => VHB_Club_Types::ENTENTE,
		'categories' => VHB_Club_Types::CATEGORIE,
		'stages'     => VHB_Club_Types::STAGE,
	);

	public static function init() {
		add_action( 'admin_post_vhbc_export', array( __CLASS__, 'download' ) );
		add_action( 'admin_post_vhbc_import', array( __CLASS__, 'import_upload' ) );
		add_action( 'admin_post_vhbc_restaurer', array( __CLASS__, 'restaurer' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Export                                                              */
	/* ------------------------------------------------------------------ */

	private static $medias = array();

	private static function media( $id ) {
		$id = (int) $id;
		if ( $id ) {
			$url = wp_get_attachment_url( $id );
			if ( $url ) {
				self::$medias[ $id ] = $url;
			}
		}
		return $id;
	}

	public static function data() {
		self::$medias = array();
		$out          = array(
			'format'  => self::FORMAT,
			'version' => VHB_CLUB_VERSION,
			'date'    => wp_date( 'c' ),
			'site'    => home_url( '/' ),
			'options' => array(
				'reglages'             => VHB_Club_Reglages::get(),
				'saisons'              => VHB_Club_Reglages::saisons(),
				'inscriptions'         => VHB_Club_Inscriptions::get(),
				'inscriptions_saisons' => (array) get_option( VHB_Club_Inscriptions::OPTION_SAISONS, array() ),
				'vie'                  => VHB_Club_Vie::get(),
				'photos'               => VHB_Club_Photos::get(),
			),
			'posts'   => array(),
		);
		self::media( $out['options']['reglages']['logo'] );
		foreach ( $out['options']['inscriptions']['documents'] as $d ) {
			self::media( $d['fichier'] );
		}
		foreach ( $out['options']['vie']['personnes'] as $p ) {
			self::media( $p['photo'] );
		}
		foreach ( $out['options']['vie']['partenaires'] as $p ) {
			self::media( $p['logo'] );
		}
		foreach ( $out['options']['vie']['ag'] as $a ) {
			self::media( $a['fichier'] );
		}
		foreach ( $out['options']['photos'] as $p ) {
			self::media( $p['id'] );
		}
		$res = self::resultats_option();
		if ( $res ) {
			$out['options']['resultats'] = $res;
			foreach ( (array) ( $res['equipes'] ?? array() ) as $e ) {
				self::media( $e['blason'] ?? 0 );
			}
		}
		foreach ( self::TYPES as $key => $type ) {
			$out['posts'][ $key ] = array();
			$ids                  = get_posts(
				array(
					'post_type'        => $type,
					'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
					'posts_per_page'   => -1,
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'fields'           => 'ids',
					'suppress_filters' => true,
				)
			);
			foreach ( $ids as $id ) {
				$p    = get_post( $id );
				$meta = array();
				foreach ( get_post_meta( $id ) as $k => $v ) {
					if ( 0 === strpos( $k, VHB_Club_Types::M ) ) {
						$meta[ substr( $k, strlen( VHB_Club_Types::M ) ) ] = maybe_unserialize( $v[0] );
					}
				}
				$item = array(
					'id'     => (int) $id,
					'titre'  => $p->post_title,
					'statut' => $p->post_status,
					'ordre'  => (int) $p->menu_order,
					'slug'   => $p->post_name,
					'meta'   => $meta,
				);
				if ( VHB_Club_Types::ENTENTE === $type ) {
					$item['logo'] = self::media( get_post_thumbnail_id( $id ) );
				}
				$out['posts'][ $key ][] = $item;
			}
		}
		$out['medias'] = self::$medias;
		return $out;
	}

	/** Réglages des Résultats FFHB tels qu'enregistrés (null si le module n'est pas là). */
	private static function resultats_option() {
		if ( ! class_exists( 'VHB_Settings' ) ) {
			return null;
		}
		$o = get_option( VHB_Settings::OPTION );
		return is_array( $o ) ? $o : null;
	}

	public static function download() {
		if ( ! current_user_can( VHB_Club_Reglages::CAP_REGLAGES ) ) {
			wp_die( 'Accès refusé.', 403 );
		}
		check_admin_referer( 'vhbc_export' );
		$json = wp_json_encode( self::data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="vhb-' . wp_date( 'Y-m-d-His' ) . '.json"' );
		echo $json; // phpcs:ignore
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Import                                                              */
	/* ------------------------------------------------------------------ */

	private static function back( $type, $msg ) {
		VHB_Club_Admin::notice( $type, $msg );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	public static function import_upload() {
		if ( ! current_user_can( VHB_Club_Reglages::CAP_REGLAGES ) ) {
			wp_die( 'Accès refusé.', 403 );
		}
		check_admin_referer( 'vhbc_import' );
		if ( empty( $_POST['confirme'] ) ) { // phpcs:ignore
			self::back( 'error', 'Import annulé : cochez la case de confirmation.' );
		}
		$f = $_FILES['fichier'] ?? null; // phpcs:ignore
		if ( ! $f || ! empty( $f['error'] ) || ! is_uploaded_file( $f['tmp_name'] ) || $f['size'] > 5 * MB_IN_BYTES ) {
			self::back( 'error', 'Import annulé : fichier manquant ou trop gros (5 Mo maximum).' );
		}
		$data = json_decode( (string) file_get_contents( $f['tmp_name'] ), true ); // phpcs:ignore
		if ( ! is_array( $data ) || self::FORMAT !== ( $data['format'] ?? '' ) || empty( $data['options'] ) || ! isset( $data['posts'] ) ) {
			self::back( 'error', 'Import annulé : ce fichier n\'est pas un export de ' . VHB_MARQUE . ' (ni de VHB ou VHB Club).' );
		}
		update_option( self::SAUVEGARDE, array( 'date' => wp_date( 'c' ), 'data' => self::data() ), false );
		$r = self::importer( $data );
		$msg = 'Import terminé : ' . $r['posts'] . ' fiche(s) importée(s), ' . $r['corbeille'] . ' ancienne(s) mise(s) à la corbeille. Sauvegarde de l\'état précédent disponible ci-dessous.';
		if ( $r['medias_manquants'] ) {
			$msg .= ' ' . $r['medias_manquants'] . ' image(s) ou document(s) introuvable(s) dans la médiathèque de ce site : à rechoisir.';
		}
		self::back( $r['medias_manquants'] ? 'warning' : 'success', $msg );
	}

	public static function restaurer() {
		if ( ! current_user_can( VHB_Club_Reglages::CAP_REGLAGES ) ) {
			wp_die( 'Accès refusé.', 403 );
		}
		check_admin_referer( 'vhbc_restaurer' );
		$s = get_option( self::SAUVEGARDE );
		if ( empty( $_POST['confirme'] ) || ! is_array( $s ) || empty( $s['data'] ) ) { // phpcs:ignore
			self::back( 'error', 'Restauration annulée : cochez la case de confirmation.' );
		}
		$r = self::importer( $s['data'] );
		delete_option( self::SAUVEGARDE );
		self::back( 'success', 'Données d\'avant l\'import restaurées (' . $r['posts'] . ' fiche(s)).' );
	}

	/**
	 * Remplace toutes les données par celles de $data.
	 *
	 * @return array{posts:int,corbeille:int,medias_manquants:int}
	 */
	public static function importer( array $data ) {
		$medias    = (array) ( $data['medias'] ?? array() );
		$manquants = 0;
		$media     = static function ( $old ) use ( $medias, &$manquants ) {
			$old = (int) $old;
			if ( ! $old ) {
				return 0;
			}
			$url = (string) ( $medias[ $old ] ?? $medias[ (string) $old ] ?? '' );
			if ( $url && wp_get_attachment_url( $old ) === $url ) {
				return $old; // Même site, même fichier.
			}
			$id = $url ? attachment_url_to_postid( $url ) : 0;
			if ( ! $id ) {
				++$manquants;
			}
			return (int) $id;
		};

		// 1. Anciennes fiches à la corbeille.
		$corbeille = 0;
		foreach ( self::TYPES as $type ) {
			$ids = get_posts( array( 'post_type' => $type, 'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ), 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true ) );
			foreach ( $ids as $id ) {
				if ( wp_trash_post( $id ) ) {
					++$corbeille;
				}
			}
		}

		// 2. Fiches, dans l'ordre des dépendances (lieux et ententes d'abord).
		$map   = array( 'lieux' => array(), 'ententes' => array() );
		$count = 0;
		$posts = (array) $data['posts'];
		foreach ( array( 'ententes', 'lieux', 'categories', 'stages' ) as $key ) {
			foreach ( (array) ( $posts[ $key ] ?? array() ) as $it ) {
				$statut = in_array( $it['statut'] ?? '', array( 'publish', 'draft', 'pending', 'private' ), true ) ? $it['statut'] : 'draft';
				$new    = wp_insert_post(
					array(
						'post_type'   => self::TYPES[ $key ],
						'post_title'  => sanitize_text_field( (string) ( $it['titre'] ?? '' ) ),
						'post_status' => $statut,
						'menu_order'  => (int) ( $it['ordre'] ?? 0 ),
						'post_name'   => sanitize_title( (string) ( $it['slug'] ?? '' ) ),
					),
					true
				);
				if ( is_wp_error( $new ) ) {
					continue;
				}
				++$count;
				$meta = (array) ( $it['meta'] ?? array() );
				switch ( $key ) {
					case 'ententes':
						$map['ententes'][ (int) $it['id'] ] = $new;
						$logo                               = $media( $it['logo'] ?? 0 );
						if ( $logo ) {
							set_post_thumbnail( $new, $logo );
						}
						break;
					case 'lieux':
						$map['lieux'][ (int) $it['id'] ] = $new;
						if ( ! empty( $meta['entente'] ) ) {
							$meta['entente'] = $map['ententes'][ (int) $meta['entente'] ] ?? 0;
						}
						break;
					case 'categories':
						$meta['ententes'] = array_values( array_filter( array_map( static function ( $e ) use ( $map ) { return $map['ententes'][ (int) $e ] ?? 0; }, (array) ( $meta['ententes'] ?? array() ) ) ) );
						$cr               = array();
						foreach ( (array) ( $meta['creneaux'] ?? array() ) as $c ) {
							$c['lieu'] = $map['lieux'][ (int) ( $c['lieu'] ?? 0 ) ] ?? 0;
							$cr[]      = $c;
						}
						$meta['creneaux'] = $cr;
						break;
					case 'stages':
						if ( ! empty( $meta['lieu'] ) ) {
							$meta['lieu'] = $map['lieux'][ (int) $meta['lieu'] ] ?? 0;
						}
						break;
				}
				foreach ( $meta as $k => $v ) {
					VHB_Club_Types::set_meta( $new, sanitize_key( $k ), $v );
				}
			}
		}

		// 3. Réglages, en recalant les liens vers les fiches et la médiathèque.
		$o = (array) $data['options'];
		$r = (array) ( $o['reglages'] ?? array() );
		$r['lieu_principal'] = $map['lieux'][ (int) ( $r['lieu_principal'] ?? 0 ) ] ?? 0;
		$r['logo']           = $media( $r['logo'] ?? 0 );
		update_option( VHB_Club_Reglages::OPTION, VHB_Club_Reglages::sanitize( $r ) );

		$saisons = array_values( array_filter( (array) ( $o['saisons'] ?? array() ), static function ( $s ) { return is_array( $s ) && VHB_Club_Reglages::valid_id( $s['id'] ?? '' ); } ) );
		if ( $saisons ) {
			VHB_Club_Reglages::save_saisons( $saisons );
		}

		$ins = (array) ( $o['inscriptions'] ?? array() );
		foreach ( (array) ( $ins['documents'] ?? array() ) as $i => $d ) {
			$ins['documents'][ $i ]['fichier'] = $media( $d['fichier'] ?? 0 );
		}
		$ins['pieces']   = implode( "\n", (array) ( $ins['pieces'] ?? array() ) );
		$ins['paiement'] = implode( "\n", (array) ( $ins['paiement'] ?? array() ) );
		update_option( VHB_Club_Inscriptions::OPTION, VHB_Club_Inscriptions::sanitize( $ins ), false );
		$par = array();
		foreach ( (array) ( $o['inscriptions_saisons'] ?? array() ) as $sid => $s ) {
			if ( VHB_Club_Reglages::valid_id( (string) $sid ) && is_array( $s ) ) {
				$par[ $sid ] = VHB_Club_Inscriptions::sanitize_saison( $s );
			}
		}
		update_option( VHB_Club_Inscriptions::OPTION_SAISONS, $par, false );

		$v = (array) ( $o['vie'] ?? array() );
		foreach ( array( 'personnes' => 'photo', 'partenaires' => 'logo', 'ag' => 'fichier' ) as $liste => $champ ) {
			foreach ( (array) ( $v[ $liste ] ?? array() ) as $i => $row ) {
				$v[ $liste ][ $i ][ $champ ] = $media( $row[ $champ ] ?? 0 );
			}
		}
		$vie = VHB_Club_Vie::sanitize_organigramme( $v, VHB_Club_Vie::defaults() );
		$vie = VHB_Club_Vie::sanitize_vie( $v, $vie );
		VHB_Club_Vie::save( $vie );

		// 4. Photos d'équipe (exports 2.0.0 et plus).
		if ( isset( $o['photos'] ) && is_array( $o['photos'] ) ) {
			$ph = array();
			foreach ( $o['photos'] as $slug => $p ) {
				$id = $media( $p['id'] ?? 0 );
				if ( $id ) {
					$ph[ sanitize_title( (string) $slug ) ] = array( 'id' => $id, 'x' => (int) ( $p['x'] ?? 50 ), 'y' => (int) ( $p['y'] ?? 50 ) );
				}
			}
			update_option( VHB_Club_Photos::OPTION, $ph, false );
		}

		// 5. Réglages des Résultats FFHB (exports 2.0.0 et plus).
		if ( isset( $o['resultats'] ) && is_array( $o['resultats'] ) && isset( $o['resultats']['equipes'] ) && class_exists( 'VHB_Settings' ) ) {
			$res = $o['resultats'];
			foreach ( (array) $res['equipes'] as $i => $e ) {
				if ( ! is_array( $e ) ) {
					unset( $res['equipes'][ $i ] );
					continue;
				}
				$res['equipes'][ $i ]['blason'] = $media( $e['blason'] ?? 0 );
			}
			$res['equipes'] = array_values( $res['equipes'] );
			// Valeur déjà au format enregistré : pas de passage par la validation du formulaire.
			remove_all_filters( 'sanitize_option_' . VHB_Settings::OPTION );
			update_option( VHB_Settings::OPTION, $res, false );
		}

		VHB_Club_Cache::changed();
		return array( 'posts' => $count, 'corbeille' => $corbeille, 'medias_manquants' => $manquants );
	}

	/* ------------------------------------------------------------------ */
	/* Page                                                                */
	/* ------------------------------------------------------------------ */

	public static function page() {
		if ( ! current_user_can( VHB_Club_Reglages::CAP_REGLAGES ) ) {
			return;
		}
		$s = get_option( self::SAUVEGARDE );
		$d = self::data();
		$n = array_map( 'count', $d['posts'] );
		?>
		<div class="wrap vhbc-admin">
			<h1>Export et import</h1>
			<div class="vhbc-admin-grid">
				<div class="vhbc-admin-card">
					<h2>Exporter</h2>
					<p>Un fichier JSON avec toutes les données de <?php echo esc_html( VHB_MARQUE ); ?> : <?php echo (int) $n['categories']; ?> catégories, <?php echo (int) $n['lieux']; ?> gymnases, <?php echo (int) $n['ententes']; ?> clubs en entente, <?php echo (int) $n['stages']; ?> événements, réglages, saisons, inscriptions, organigramme et vie du club, <?php echo (int) count( $d['options']['photos'] ); ?> photo(s) d'équipe<?php echo isset( $d['options']['resultats'] ) ? ', et les réglages des Résultats FFHB (' . (int) count( (array) ( $d['options']['resultats']['equipes'] ?? array() ) ) . ' équipes, compétitions, blasons, gymnases)' : ''; ?>.</p>
					<p class="description">Les images et documents restent dans la médiathèque : le fichier contient seulement leur adresse. Les résultats, calendriers et classements ne sont pas exportés : ils sont récupérés de nouveau sur le site de la FFHB.</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'vhbc_export' ); ?>
						<input type="hidden" name="action" value="vhbc_export">
						<?php submit_button( 'Télécharger l\'export', 'primary', 'submit', false ); ?>
					</form>
				</div>

				<div class="vhbc-admin-card">
					<h2>Importer</h2>
					<p><strong>Remplace toutes les données de <?php echo esc_html( VHB_MARQUE ); ?></strong> par celles du fichier (un export d'avant la version 2.0 ne touche ni aux photos d'équipe ni aux Résultats FFHB). Les fiches actuelles vont à la corbeille et l'état actuel est sauvegardé automatiquement, restaurable d'un clic.</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
						<?php wp_nonce_field( 'vhbc_import' ); ?>
						<input type="hidden" name="action" value="vhbc_import">
						<p><input type="file" name="fichier" accept=".json,application/json" required></p>
						<p><label><input type="checkbox" name="confirme" value="1" required> Je confirme le remplacement des données</label></p>
						<?php submit_button( 'Importer', 'secondary', 'submit', false ); ?>
					</form>
				</div>

				<?php if ( is_array( $s ) && ! empty( $s['data'] ) ) : ?>
				<div class="vhbc-admin-card vhbc-admin-card--wide">
					<h2>Sauvegarde d'avant import</h2>
					<p>État des données le <?php echo esc_html( wp_date( 'd/m/Y à H:i', strtotime( $s['date'] ) ) ); ?>, juste avant le dernier import.</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vhbc-inline-form">
						<?php wp_nonce_field( 'vhbc_restaurer' ); ?>
						<input type="hidden" name="action" value="vhbc_restaurer">
						<label><input type="checkbox" name="confirme" value="1" required> Je confirme</label>
						<?php submit_button( 'Restaurer cet état', 'secondary', 'submit', false ); ?>
					</form>
				</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
