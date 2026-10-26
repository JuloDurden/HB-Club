<?php
/**
 * Réglages du club (option unique) et saisons.
 *
 * Saisons : option « vhb_club_saisons », liste ordonnée de
 * array( 'id' => '2026-2027', 'statut' => 'en_cours|preparation|archivee' ).
 * Une seule saison est « en cours » : c'est elle que le site affiche.
 */

defined( 'ABSPATH' ) || exit;

class VHB_Club_Reglages {

	const OPTION         = 'vhb_club_reglages';
	const OPTION_SAISONS = 'vhb_club_saisons';

	/** Capacité pour les données du club (catégories, lieux, ententes). */
	const CAP_DONNEES = 'vhb_club_gerer';
	/** Capacité pour les réglages et le changement de saison. */
	const CAP_REGLAGES = 'vhb_club_reglages';
	/** Capacité pour les stages et événements (rôle « Éditeur de stages »). */
	const CAP_STAGES = 'vhb_club_stages';

	const STATUTS_SAISON = array(
		'preparation' => 'En préparation',
		'en_cours'    => 'En cours',
		'archivee'    => 'Archivée',
	);

	const THEMES = array(
		'site'   => 'Site du club (couleurs et polices du CSS général)',
		'sombre' => 'Sombre (comme les Résultats FFHB)',
		'clair'  => 'Clair',
		'auto'   => 'Automatique (suit le visiteur)',
	);

	public static function defaults() {
		$theme = 'site'; // Suit le CSS général du site (variables --vhb, --navy2…).
		return array(
			'nom'            => '',
			'sigle'          => '',
			'affiliation'    => '',
			'siege'          => '',
			'email'          => '',
			'email_licences' => '',
			'telephone'      => '',
			'facebook'       => '',
			'instagram'      => '',
			'logo'           => 0,
			'lieu_principal' => 0,
			'theme'          => $theme,
			// 2.0.1 : chiffres de la page « Le club » (vides = non affichés).
			'annee_creation' => '',
			'licencies'      => '',
			'licenciees_pct' => '',
		);
	}

	public static function get() {
		$opt = get_option( self::OPTION );
		return array_merge( self::defaults(), is_array( $opt ) ? $opt : array() );
	}

	/**
	 * Icône de l'extension (menu, blocs) : SVG d'un seul tracé blanc.
	 * Logo du club si l'édition le fournit (profil/logo.svg), sinon un ballon.
	 */
	public static function fichier_icone() {
		$f = VHB_CLUB_DIR . 'profil/logo.svg';
		return is_readable( $f ) ? $f : VHB_CLUB_DIR . 'assets/icone.svg';
	}

	/** Nom du club (Réglages du club), sinon nom du site. */
	public static function nom_club() {
		$n = trim( (string) self::get()['nom'] );
		return '' !== $n ? $n : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/** Sigle du club, sinon nom du club. */
	public static function sigle_club() {
		$s = trim( (string) self::get()['sigle'] );
		return '' !== $s ? $s : self::nom_club();
	}

	public static function sanitize( $in ) {
		$in  = is_array( $in ) ? $in : array();
		$out = self::defaults();
		foreach ( array( 'nom', 'sigle', 'affiliation', 'telephone' ) as $k ) {
			$out[ $k ] = sanitize_text_field( (string) ( $in[ $k ] ?? '' ) );
		}
		$out['siege'] = sanitize_textarea_field( (string) ( $in['siege'] ?? '' ) );
		foreach ( array( 'email', 'email_licences' ) as $k ) {
			$out[ $k ] = sanitize_email( (string) ( $in[ $k ] ?? '' ) );
		}
		foreach ( array( 'facebook', 'instagram' ) as $k ) {
			$out[ $k ] = esc_url_raw( trim( (string) ( $in[ $k ] ?? '' ) ) );
		}
		$out['logo']           = absint( $in['logo'] ?? 0 );
		$out['lieu_principal'] = absint( $in['lieu_principal'] ?? 0 );
		$out['theme']          = isset( self::THEMES[ $in['theme'] ?? '' ] ) ? $in['theme'] : 'site';
		foreach ( array( 'annee_creation' => 2100, 'licencies' => 100000, 'licenciees_pct' => 100 ) as $k => $max ) {
			$v         = trim( (string) ( $in[ $k ] ?? '' ) );
			$out[ $k ] = ( '' === $v || ! is_numeric( $v ) || (float) $v < 0 ) ? '' : (string) min( $max, absint( $v ) );
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Saisons                                                             */
	/* ------------------------------------------------------------------ */

	/** @return array<int,array{id:string,statut:string}> triées de la plus ancienne à la plus récente */
	public static function saisons() {
		$list = get_option( self::OPTION_SAISONS );
		$list = is_array( $list ) ? array_values( array_filter( $list, array( __CLASS__, 'valid_row' ) ) ) : array();
		usort(
			$list,
			static function ( $a, $b ) {
				return strcmp( $a['id'], $b['id'] );
			}
		);
		return $list;
	}

	private static function valid_row( $s ) {
		return is_array( $s ) && isset( $s['id'], $s['statut'] ) && self::valid_id( $s['id'] );
	}

	/** « 2026-2027 » : deux années consécutives. */
	public static function valid_id( $id ) {
		return is_string( $id ) && preg_match( '/^(\d{4})-(\d{4})$/', $id, $m ) && (int) $m[2] === (int) $m[1] + 1;
	}

	public static function save_saisons( array $list ) {
		update_option( self::OPTION_SAISONS, array_values( $list ), false );
	}

	/** Identifiant de la saison affichée sur le site. */
	public static function saison_en_cours() {
		foreach ( self::saisons() as $s ) {
			if ( 'en_cours' === $s['statut'] ) {
				return $s['id'];
			}
		}
		// Aucune saison réglée : saison sportive du moment (bascule au 1er juillet).
		$y = (int) wp_date( 'Y' );
		$y = (int) wp_date( 'n' ) >= 7 ? $y : $y - 1;
		return $y . '-' . ( $y + 1 );
	}

	/** Première année civile d'une saison : 2026 pour « 2026-2027 ». */
	public static function debut( $saison ) {
		return (int) substr( (string) $saison, 0, 4 );
	}

	public static function saison_suivante( $saison ) {
		$d = self::debut( $saison ) + 1;
		return $d . '-' . ( $d + 1 );
	}

	public static function statut( $saison ) {
		foreach ( self::saisons() as $s ) {
			if ( $s['id'] === $saison ) {
				return $s['statut'];
			}
		}
		return '';
	}
}
