<?php
/**
 * Premier démarrage : capacités et données de départ.
 *
 * Sur une base vide, à la première activation :
 * - si l'édition fournit un profil (profil/donnees.json, au format de
 *   l'export), ses données sont importées : gymnases, catégories, tarifs,
 *   réglages, équipes des Résultats FFHB… ;
 * - sinon, seule la saison en cours est créée, et le nom du club reprend
 *   le nom du site. Tout le reste se saisit dans l'administration.
 * Une réactivation n'écrase rien.
 */

defined( 'ABSPATH' ) || exit;

class VHB_Club_Demarrage {

	const FLAG   = 'vhb_club_demarre';
	const PROFIL = 'profil/donnees.json';

	/** Mise à niveau d'une installation existante (rien à faire en 3.0.0). */
	public static function upgrade( $from ) {
		unset( $from );
	}

	public static function activate() {
		VHB_Club_Types::add_caps();
		VHB_Club_Types::register();
		if ( ! get_option( self::FLAG ) && ! self::has_data() ) {
			self::seed();
		}
		update_option( self::FLAG, VHB_CLUB_VERSION, false );
	}

	private static function has_data() {
		foreach ( array( VHB_Club_Types::CATEGORIE, VHB_Club_Types::LIEU, VHB_Club_Types::ENTENTE ) as $t ) {
			$found = get_posts(
				array(
					'post_type'        => $t,
					'post_status'      => 'any',
					'posts_per_page'   => 1,
					'fields'           => 'ids',
					'suppress_filters' => true,
				)
			);
			if ( $found ) {
				return true;
			}
		}
		return false;
	}

	/** Saison sportive en cours : de juillet à juin (« 2026-2027 » à partir du 1er juillet 2026). */
	public static function saison_courante() {
		$y = (int) wp_date( 'Y' );
		$m = (int) wp_date( 'n' );
		$d = $m >= 7 ? $y : $y - 1;
		return $d . '-' . ( $d + 1 );
	}

	/** Données du profil de l'édition, ou null. */
	public static function profil() {
		$f = VHB_CLUB_DIR . self::PROFIL;
		if ( ! is_readable( $f ) ) {
			return null;
		}
		$d = json_decode( (string) file_get_contents( $f ), true ); // phpcs:ignore
		return is_array( $d ) && ! empty( $d['options'] ) && isset( $d['posts'] ) ? $d : null;
	}

	public static function seed() {
		$p = self::profil();
		if ( $p ) {
			if ( ! class_exists( 'VHB_Club_Export' ) ) {
				require_once VHB_CLUB_DIR . 'includes/class-vhb-club-export.php';
			}
			VHB_Club_Export::importer( $p );
			return;
		}
		VHB_Club_Reglages::save_saisons( array( array( 'id' => self::saison_courante(), 'statut' => 'en_cours' ) ) );
		$r        = VHB_Club_Reglages::defaults();
		$r['nom'] = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		update_option( VHB_Club_Reglages::OPTION, $r );
	}
}
