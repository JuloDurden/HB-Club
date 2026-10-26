<?php
/**
 * Lecture des données du club, mise en forme commune au site et à l'admin.
 *
 * Années de naissance : une catégorie ne stocke pas des années mais des âges
 * (âge atteint au cours de la première année civile de la saison). Pour la
 * saison 2026-2027, U13 = âges 11 à 12 = nés en 2014-2015. À la saison
 * suivante, les mêmes âges donnent 2015-2016 sans rien ressaisir.
 */

defined( 'ABSPATH' ) || exit;

class VHB_Club_Donnees {

	/** Cache par requête. */
	private static $cache = array();

	public static function reset() {
		self::$cache = array();
	}

	/* ------------------------------------------------------------------ */
	/* Catégories                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Catégories publiées d'une saison, dans l'ordre choisi.
	 *
	 * @return array<int,array> voir categorie()
	 */
	public static function categories( $saison = null ) {
		$saison = $saison ?: VHB_Club_Reglages::saison_en_cours();
		$key    = 'cat:' . $saison;
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}
		$ids = get_posts(
			array(
				'post_type'        => VHB_Club_Types::CATEGORIE,
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'orderby'          => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
				'meta_key'         => VHB_Club_Types::M . 'saison', // phpcs:ignore
				'meta_value'       => $saison, // phpcs:ignore
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		self::$cache[ $key ] = array_map( array( __CLASS__, 'categorie' ), $ids );
		return self::$cache[ $key ];
	}

	/** Catégorie de la saison en cours par son code (« u13-f »), ou null. */
	public static function categorie_par_code( $code, $saison = null ) {
		$code = sanitize_title( (string) $code );
		foreach ( self::categories( $saison ) as $c ) {
			if ( $c['code'] === $code ) {
				return $c;
			}
		}
		return null;
	}

	public static function categorie( $id ) {
		$id      = (int) $id;
		$m       = static function ( $k, $d = '' ) use ( $id ) {
			return VHB_Club_Types::meta( $id, $k, $d );
		};
		$saison  = (string) $m( 'saison', VHB_Club_Reglages::saison_en_cours() );
		$age_min = $m( 'age_min' );
		$age_max = $m( 'age_max' );
		$c       = array(
			'id'          => $id,
			'nom'         => get_the_title( $id ),
			'code'        => (string) $m( 'code' ),
			'saison'      => $saison,
			'pratique'    => (string) $m( 'pratique', 'competition' ),
			'genre'       => (string) $m( 'genre' ),
			'age_min'     => '' === $age_min ? null : (int) $age_min,
			'age_max'     => '' === $age_max ? null : (int) $age_max,
			'description' => (string) $m( 'description' ),
			'ententes'    => array_values( array_filter( array_map( 'intval', (array) $m( 'ententes', array() ) ) ) ),
			'equipes'     => array_values( array_filter( (array) $m( 'equipes_ffhb', array() ) ) ),
			'creneaux'    => self::creneaux_tries( (array) $m( 'creneaux', array() ) ),
			'tarif'       => (string) $m( 'tarif' ),
			'inclus'      => (string) $m( 'inclus' ),
			'statut'      => (string) $m( 'statut_inscription' ),
		);
		$c['annees']  = self::annees( $c['age_min'], $c['age_max'], $saison );
		return $c;
	}

	/**
	 * Années de naissance en clair.
	 *
	 * @return string « 2014-2015 », « 2018 », « 2009 et avant », « 2021 et après » ou ''
	 */
	public static function annees( $age_min, $age_max, $saison ) {
		$d = VHB_Club_Reglages::debut( $saison );
		if ( null === $age_min && null === $age_max ) {
			return '';
		}
		if ( null === $age_max ) {
			return ( $d - $age_min ) . ' et avant';
		}
		if ( null === $age_min ) {
			return ( $d - $age_max ) . ' et après';
		}
		$y1 = $d - max( $age_min, $age_max );
		$y2 = $d - min( $age_min, $age_max );
		return $y1 === $y2 ? (string) $y1 : $y1 . '-' . $y2;
	}

	/** Créneaux complets, triés par jour puis heure. */
	public static function creneaux_tries( array $list ) {
		$out = array();
		foreach ( $list as $c ) {
			if ( ! is_array( $c ) || empty( $c['jour'] ) || empty( $c['debut'] ) ) {
				continue;
			}
			$out[] = array(
				'jour'    => (int) $c['jour'],
				'debut'   => (string) $c['debut'],
				'fin'     => (string) ( $c['fin'] ?? '' ),
				'lieu'    => (int) ( $c['lieu'] ?? 0 ),
				'periode' => (string) ( $c['periode'] ?? 'saison' ),
				'note'    => (string) ( $c['note'] ?? '' ),
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return array( $a['jour'], $a['debut'] ) <=> array( $b['jour'], $b['debut'] );
			}
		);
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Lieux et ententes                                                   */
	/* ------------------------------------------------------------------ */

	/** @return array<int,array> lieux publiés, gymnases du club d'abord */
	public static function lieux() {
		if ( isset( self::$cache['lieux'] ) ) {
			return self::$cache['lieux'];
		}
		$ids  = get_posts(
			array(
				'post_type'        => VHB_Club_Types::LIEU,
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		$list = array_map( array( __CLASS__, 'lieu' ), $ids );
		usort(
			$list,
			static function ( $a, $b ) {
				return array( 'club' === $a['type'] ? 0 : 1, $a['nom'] ) <=> array( 'club' === $b['type'] ? 0 : 1, $b['nom'] );
			}
		);
		self::$cache['lieux'] = $list;
		return $list;
	}

	public static function lieu( $id ) {
		$id = (int) $id;
		if ( ! $id || VHB_Club_Types::LIEU !== get_post_type( $id ) ) {
			return null;
		}
		$k = 'lieu:' . $id;
		if ( isset( self::$cache[ $k ] ) ) {
			return self::$cache[ $k ];
		}
		$m   = static function ( $key, $d = '' ) use ( $id ) {
			return VHB_Club_Types::meta( $id, $key, $d );
		};
		$lat = $m( 'lat' );
		$lon = $m( 'lon' );
		$l   = array(
			'id'          => $id,
			'slug'        => get_post_field( 'post_name', $id ),
			'nom'         => get_the_title( $id ),
			'nom_court'   => (string) $m( 'nom_court' ),
			'nom_federal' => (string) $m( 'nom_federal' ),
			'adresse'     => (string) $m( 'adresse' ),
			'cp'          => (string) $m( 'cp' ),
			'ville'       => (string) $m( 'ville' ),
			'lat'         => '' === $lat ? null : (float) $lat,
			'lon'         => '' === $lon ? null : (float) $lon,
			'type'        => (string) $m( 'type', 'club' ),
			'entente'     => (int) $m( 'entente', 0 ),
			'acces'       => (string) $m( 'acces' ),
			'pmr'         => (bool) $m( 'pmr', false ),
			'publie'      => 'publish' === get_post_status( $id ),
		);
		if ( '' === $l['nom_court'] ) {
			$l['nom_court'] = '' !== $l['ville'] ? $l['ville'] : $l['nom'];
		}
		$l['adresse_complete'] = trim( $l['adresse'] . ( $l['adresse'] && ( $l['cp'] || $l['ville'] ) ? ', ' : '' ) . trim( $l['cp'] . ' ' . $l['ville'] ) );
		self::$cache[ $k ]     = $l;
		return $l;
	}

	/** Lieu par slug ou identifiant. */
	public static function lieu_par_ref( $ref ) {
		$ref = trim( (string) $ref );
		if ( '' === $ref ) {
			return null;
		}
		if ( ctype_digit( $ref ) ) {
			return self::lieu( (int) $ref );
		}
		foreach ( self::lieux() as $l ) {
			if ( $l['slug'] === sanitize_title( $ref ) ) {
				return $l;
			}
		}
		return null;
	}

	/** Lieu principal : celui des réglages, sinon le premier gymnase du club. */
	public static function lieu_principal() {
		$id = (int) VHB_Club_Reglages::get()['lieu_principal'];
		$l  = $id ? self::lieu( $id ) : null;
		if ( $l && $l['publie'] ) {
			return $l;
		}
		foreach ( self::lieux() as $l ) {
			if ( 'club' === $l['type'] ) {
				return $l;
			}
		}
		return null;
	}

	public static function entente( $id ) {
		$id = (int) $id;
		if ( ! $id || VHB_Club_Types::ENTENTE !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
			return null;
		}
		return array(
			'id'          => $id,
			'nom'         => get_the_title( $id ),
			'nom_entente' => (string) VHB_Club_Types::meta( $id, 'nom_entente' ),
			'site'        => (string) VHB_Club_Types::meta( $id, 'site' ),
			'logo'        => (int) get_post_thumbnail_id( $id ),
		);
	}

	/** @return array<int,array> clubs en entente publiés */
	public static function ententes() {
		$ids = get_posts(
			array(
				'post_type'        => VHB_Club_Types::ENTENTE,
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		return array_values( array_filter( array_map( array( __CLASS__, 'entente' ), $ids ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* Mise en forme                                                       */
	/* ------------------------------------------------------------------ */

	/** « 18:15 » devient « 18h15 », « 18:00 » devient « 18h ». */
	public static function heure( $hm ) {
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', (string) $hm, $m ) ) {
			return (string) $hm;
		}
		return (int) $m[1] . 'h' . ( '00' === $m[2] ? '' : $m[2] );
	}

	public static function plage( $debut, $fin ) {
		return self::heure( $debut ) . ( '' !== (string) $fin ? '-' . self::heure( $fin ) : '' );
	}

	/** Liens Google Maps et Waze : GPS s'il est connu, sinon l'adresse. */
	public static function liens_carte( array $l ) {
		if ( null !== $l['lat'] && null !== $l['lon'] ) {
			$ll = $l['lat'] . ',' . $l['lon'];
			return array(
				'google' => 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $ll ),
				'waze'   => 'https://waze.com/ul?ll=' . rawurlencode( $ll ) . '&navigate=yes',
			);
		}
		$q = trim( $l['nom'] . ', ' . $l['adresse_complete'], ', ' );
		return array(
			'google' => 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $q ),
			'waze'   => 'https://waze.com/ul?q=' . rawurlencode( $q ) . '&navigate=yes',
		);
	}

	/** Équipes de VHB Résultats (si l'extension est active) : slug => nom. */
	public static function equipes_ffhb() {
		if ( ! class_exists( 'VHB_Settings' ) || ! method_exists( 'VHB_Settings', 'equipes' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) VHB_Settings::equipes() as $e ) {
			if ( ! empty( $e['slug'] ) ) {
				$out[ $e['slug'] ] = $e['nom'] ?? $e['slug'];
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Dates en français                                                   */
	/* ------------------------------------------------------------------ */

	const MOIS = array( 1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre' );

	private static function d( $ymd ) {
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $ymd ) ? DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, wp_timezone() ) : null;
	}

	private static function jour( DateTimeImmutable $d ) {
		$j = (int) $d->format( 'j' );
		return 1 === $j ? '1er' : (string) $j;
	}

	/** « samedi 12 décembre 2026 » (avec_jour) ou « 12 décembre 2026 ». */
	public static function date_longue( $ymd, $avec_jour = false ) {
		$d = self::d( $ymd );
		if ( ! $d ) {
			return '';
		}
		$jours = array( 1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche' );
		return ( $avec_jour ? $jours[ (int) $d->format( 'N' ) ] . ' ' : '' ) . self::jour( $d ) . ' ' . self::MOIS[ (int) $d->format( 'n' ) ] . ' ' . $d->format( 'Y' );
	}

	/** « du 19 au 23 octobre 2026 », « du 31 octobre au 4 novembre 2026 », « samedi 12 décembre 2026 ». */
	public static function periode( $debut, $fin, $court = false ) {
		$a = self::d( $debut );
		$b = self::d( $fin );
		if ( ! $a ) {
			return '';
		}
		if ( ! $b || $debut === $fin ) {
			return self::date_longue( $debut, ! $court );
		}
		$ma = (int) $a->format( 'n' );
		$mb = (int) $b->format( 'n' );
		if ( $court ) {
			// « 19-23 octobre 2026 », « 31 octobre-4 novembre 2026 »
			return $ma === $mb && $a->format( 'Y' ) === $b->format( 'Y' )
				? self::jour( $a ) . '-' . self::jour( $b ) . ' ' . self::MOIS[ $mb ] . ' ' . $b->format( 'Y' )
				: self::jour( $a ) . ' ' . self::MOIS[ $ma ] . '-' . self::jour( $b ) . ' ' . self::MOIS[ $mb ] . ' ' . $b->format( 'Y' );
		}
		if ( $ma === $mb && $a->format( 'Y' ) === $b->format( 'Y' ) ) {
			return 'du ' . self::jour( $a ) . ' au ' . self::jour( $b ) . ' ' . self::MOIS[ $mb ] . ' ' . $b->format( 'Y' );
		}
		return 'du ' . self::jour( $a ) . ' ' . self::MOIS[ $ma ] . ( $a->format( 'Y' ) !== $b->format( 'Y' ) ? ' ' . $a->format( 'Y' ) : '' ) . ' au ' . self::jour( $b ) . ' ' . self::MOIS[ $mb ] . ' ' . $b->format( 'Y' );
	}

	/** Aujourd'hui, au fuseau du site. */
	public static function today() {
		return wp_date( 'Y-m-d' );
	}
}
