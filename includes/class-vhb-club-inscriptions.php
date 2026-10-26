<?php
/**
 * Inscriptions (lot 2) : ce qui sert aux pages Tarifs et Inscription.
 *
 * Deux options :
 * - « vhb_club_inscriptions » : ce qui ne change pas d'une saison à l'autre
 *   (pièces à fournir, paiement, séances d'essai, équipement, documents) ;
 * - « vhb_club_inscriptions_saisons » : par saison (période d'inscription,
 *   bandeau, autres licences, réductions, mention sous les tarifs).
 * Le tarif de chaque catégorie est dans la catégorie elle-même.
 */

defined( 'ABSPATH' ) || exit;

class VHB_Club_Inscriptions {

	const OPTION         = 'vhb_club_inscriptions';
	const OPTION_SAISONS = 'vhb_club_inscriptions_saisons';

	const UNITES = array(
		'euro'     => '€',
		'pourcent' => '%',
	);

	const STATUTS_CATEGORIE = array(
		''        => 'Non affiché',
		'ouvert'  => 'Inscriptions ouvertes',
		'attente' => 'Liste d\'attente',
		'complet' => 'Complet',
	);

	/* ------------------------------------------------------------------ */
	/* Lecture                                                             */
	/* ------------------------------------------------------------------ */

	public static function defaults() {
		$eq = array();
		foreach ( array_keys( VHB_Club_Types::PRATIQUES ) as $p ) {
			$eq[ $p ] = '';
		}
		return array(
			'pieces'              => array(),
			'depot'               => '',
			'paiement'            => array(),
			'essai'               => '',
			'equipement'          => '',
			'equipement_pratique' => $eq,
			'documents'           => array(),
		);
	}

	public static function defaults_saison() {
		return array(
			'bandeau'    => false,
			'ouverture'  => '',
			'cloture'    => '',
			'texte'      => 'Inscriptions ouvertes',
			'lien'       => '',
			'autres'     => array(),
			'reductions' => array(),
			'note'       => '',
		);
	}

	public static function get() {
		$o = get_option( self::OPTION );
		$o = array_merge( self::defaults(), is_array( $o ) ? $o : array() );
		$o['equipement_pratique'] = array_merge( self::defaults()['equipement_pratique'], (array) $o['equipement_pratique'] );
		return $o;
	}

	public static function saison( $saison = null ) {
		$saison = $saison ?: VHB_Club_Reglages::saison_en_cours();
		$all    = get_option( self::OPTION_SAISONS );
		$s      = is_array( $all ) && isset( $all[ $saison ] ) && is_array( $all[ $saison ] ) ? $all[ $saison ] : array();
		return array_merge( self::defaults_saison(), $s );
	}

	public static function save_saison( $saison, array $data ) {
		$all            = get_option( self::OPTION_SAISONS );
		$all            = is_array( $all ) ? $all : array();
		$all[ $saison ] = $data;
		update_option( self::OPTION_SAISONS, $all, false );
	}

	/** Copie les réglages d'inscription d'une saison vers la suivante (sans les dates). */
	public static function copier_saison( $from, $to ) {
		$s              = self::saison( $from );
		$s['bandeau']   = false;
		$s['ouverture'] = '';
		$s['cloture']   = '';
		self::save_saison( $to, $s );
	}

	/** Bandeau visible aujourd'hui ? */
	public static function bandeau_actif( $saison = null ) {
		$s = self::saison( $saison );
		if ( empty( $s['bandeau'] ) ) {
			return false;
		}
		$today = wp_date( 'Y-m-d' );
		if ( $s['ouverture'] && $today < $s['ouverture'] ) {
			return false;
		}
		if ( $s['cloture'] && $today > $s['cloture'] ) {
			return false;
		}
		return true;
	}

	/** Équipement d'une pratique (sinon la liste générale). */
	public static function equipement( $pratique ) {
		$o = self::get();
		$p = trim( (string) ( $o['equipement_pratique'][ $pratique ] ?? '' ) );
		return '' !== $p ? $p : trim( (string) $o['equipement'] );
	}

	/** « 114 », « 12.5 » deviennent « 114 € », « 12,50 € ». */
	public static function montant( $v, $unite = 'euro' ) {
		if ( '' === (string) $v || null === $v ) {
			return '';
		}
		$f = (float) $v;
		$n = floor( $f ) == $f ? (string) (int) $f : number_format( $f, 2, ',', '' ); // phpcs:ignore
		return $n . "\u{00A0}" . ( self::UNITES[ $unite ] ?? '€' );
	}

	/* ------------------------------------------------------------------ */
	/* Enregistrement                                                      */
	/* ------------------------------------------------------------------ */

	private static function lignes( $text ) {
		return array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', sanitize_textarea_field( (string) $text ) ) ) ) );
	}

	private static function nombre( $v ) {
		$v = str_replace( array( ' ', "\u{00A0}", '€', ',' ), array( '', '', '', '.' ), trim( (string) $v ) );
		return is_numeric( $v ) && (float) $v >= 0 ? (string) ( 0 + $v ) : '';
	}

	private static function date_ok( $v ) {
		$v = sanitize_text_field( (string) $v );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
	}

	public static function sanitize( array $in ) {
		$out             = self::defaults();
		$out['pieces']   = self::lignes( $in['pieces'] ?? '' );
		$out['paiement'] = self::lignes( $in['paiement'] ?? '' );
		$out['depot']    = sanitize_text_field( (string) ( $in['depot'] ?? '' ) );
		$out['essai']    = sanitize_text_field( (string) ( $in['essai'] ?? '' ) );
		$out['equipement'] = sanitize_text_field( (string) ( $in['equipement'] ?? '' ) );
		foreach ( array_keys( VHB_Club_Types::PRATIQUES ) as $p ) {
			$out['equipement_pratique'][ $p ] = sanitize_text_field( (string) ( $in['equipement_pratique'][ $p ] ?? '' ) );
		}
		foreach ( (array) ( $in['documents'] ?? array() ) as $k => $d ) {
			if ( '__i__' === (string) $k || ! is_array( $d ) ) {
				continue;
			}
			$doc = array(
				'titre'   => sanitize_text_field( (string) ( $d['titre'] ?? '' ) ),
				'fichier' => absint( $d['fichier'] ?? 0 ),
				'url'     => esc_url_raw( trim( (string) ( $d['url'] ?? '' ) ) ),
			);
			if ( '' === $doc['titre'] && ! $doc['fichier'] && '' === $doc['url'] ) {
				continue;
			}
			$out['documents'][] = $doc;
		}
		return $out;
	}

	public static function sanitize_saison( array $in ) {
		$out              = self::defaults_saison();
		$out['bandeau']   = ! empty( $in['bandeau'] );
		$out['ouverture'] = self::date_ok( $in['ouverture'] ?? '' );
		$out['cloture']   = self::date_ok( $in['cloture'] ?? '' );
		$out['texte']     = sanitize_text_field( (string) ( $in['texte'] ?? '' ) ) ?: 'Inscriptions ouvertes';
		$out['lien']      = esc_url_raw( trim( (string) ( $in['lien'] ?? '' ) ) );
		$out['note']      = sanitize_text_field( (string) ( $in['note'] ?? '' ) );
		foreach ( (array) ( $in['autres'] ?? array() ) as $k => $a ) {
			if ( '__i__' === (string) $k || ! is_array( $a ) || '' === trim( (string) ( $a['nom'] ?? '' ) ) ) {
				continue;
			}
			$out['autres'][] = array(
				'nom'     => sanitize_text_field( $a['nom'] ),
				'montant' => self::nombre( $a['montant'] ?? '' ),
				'mention' => sanitize_text_field( (string) ( $a['mention'] ?? '' ) ),
				'annees'  => sanitize_text_field( (string) ( $a['annees'] ?? '' ) ),
			);
		}
		foreach ( (array) ( $in['reductions'] ?? array() ) as $k => $r ) {
			if ( '__i__' === (string) $k || ! is_array( $r ) || '' === trim( (string) ( $r['libelle'] ?? '' ) ) ) {
				continue;
			}
			$u                   = sanitize_key( $r['unite'] ?? 'euro' );
			$out['reductions'][] = array(
				'libelle'   => sanitize_text_field( $r['libelle'] ),
				'montant'   => self::nombre( $r['montant'] ?? '' ),
				'unite'     => isset( self::UNITES[ $u ] ) ? $u : 'euro',
				'precision' => sanitize_text_field( (string) ( $r['precision'] ?? '' ) ),
			);
		}
		return $out;
	}

}
