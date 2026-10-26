<?php
/**
 * Vie du club (lot 3) : organigramme, alertes, assemblées générales,
 * boutique, partenaires, mentions légales. Une seule option.
 */

defined( 'ABSPATH' ) || exit;

class VHB_Club_Vie {

	const OPTION = 'vhb_club_vie';

	const INSTANCES = array(
		'bureau'     => 'Bureau',
		'ca'         => 'Conseil d\'administration',
		'commission' => 'Commissions',
		'benevole'   => 'Bénévoles',
	);

	const TYPES_AG = array(
		'ca'       => 'AG du CA',
		'publique' => 'AG grand public',
	);

	const NIVEAUX = array(
		'principal'      => 'Partenaires principaux',
		'partenaire'     => 'Partenaires',
		'institutionnel' => 'Partenaires institutionnels',
	);

	/** Logo affiché en blanc sur le fond sombre du pied de page. */
	const RENDUS_LOGO = array(
		'silhouette' => 'Silhouette blanche',
		'clair'      => 'Logo sur fond clair (inversé)',
		'noir'       => 'Logo noir ou foncé',
		'blanc'      => 'Déjà blanc (sans retouche)',
	);
	const HAUTEURS_LOGO = array(
		'normale' => 'Normale',
		'grande'  => 'Grande',
	);
	const ZOOMS_LOGO = array(
		'100' => 'Sans agrandir',
		'120' => '120 %',
		'133' => '133 %',
	);

	const NIVEAUX_ALERTE = array(
		'info'      => 'Information',
		'important' => 'Important',
	);

	public static function defaults() {
		return array(
			'affichage_noms' => 'complet', // complet | initiale
			'personnes'      => array(),
			'alertes'        => array(),
			'ag'             => array(),
			'boutique'       => array( 'actif' => false, 'titre' => 'Boutique du club', 'texte' => '', 'url' => '', 'debut' => '', 'fin' => '' ),
			'partenaires'    => array(),
			'mentions'       => array(
				'rna'          => '',
				'siret'        => '',
				'directeur'    => '',
				'hebergeur'    => '',
				'hebergeur_adr' => '',
				'contact_rgpd' => '',
				'rgpd'         => '',
			),
		);
	}

	public static function get() {
		$o = get_option( self::OPTION );
		$o = is_array( $o ) ? $o : array();
		$d = self::defaults();
		$r = array_merge( $d, $o );
		$r['boutique'] = array_merge( $d['boutique'], (array) $r['boutique'] );
		$r['mentions'] = array_merge( $d['mentions'], (array) $r['mentions'] );
		foreach ( $r['partenaires'] as $k => $p ) {
			$r['partenaires'][ $k ] = array_merge( array( 'rendu' => 'silhouette', 'hauteur' => 'normale', 'zoom' => '100' ), (array) $p );
		}
		return $r;
	}


	public static function save( array $o ) {
		update_option( self::OPTION, $o, false );
	}

	/* ------------------------------------------------------------------ */
	/* Organigramme                                                        */
	/* ------------------------------------------------------------------ */

	/** Nom affiché selon le réglage : « Camille Martin » ou « Camille M. ». */
	public static function nom( array $p, $mode = null ) {
		$mode   = $mode ?: self::get()['affichage_noms'];
		$prenom = trim( (string) $p['prenom'] );
		$nom    = trim( (string) $p['nom'] );
		if ( 'initiale' === $mode && '' !== $nom ) {
			$nom = mb_strtoupper( mb_substr( $nom, 0, 1 ) ) . '.';
		}
		return trim( $prenom . ' ' . $nom );
	}

	public static function initiales( array $p ) {
		return mb_strtoupper( mb_substr( trim( (string) $p['prenom'] ), 0, 1 ) . mb_substr( trim( (string) $p['nom'] ), 0, 1 ) );
	}

	/** Président·e (première fonction qui commence par « Président »), pour les mentions légales et [vhb_info]. */
	public static function president() {
		foreach ( self::get()['personnes'] as $p ) {
			if ( 0 === stripos( (string) $p['fonction'], 'présiden' ) || 0 === stripos( (string) $p['fonction'], 'presiden' ) ) {
				return $p;
			}
		}
		return null;
	}

	/* ------------------------------------------------------------------ */
	/* Alertes, AG, boutique                                               */
	/* ------------------------------------------------------------------ */

	private static function dans_periode( $debut, $fin ) {
		$t = VHB_Club_Donnees::today();
		return ( '' === (string) $debut || $t >= $debut ) && ( '' === (string) $fin || $t <= $fin );
	}

	public static function alertes_actives() {
		return array_values(
			array_filter(
				self::get()['alertes'],
				static function ( $a ) {
					return '' !== trim( $a['message'] ) && self::dans_periode( $a['debut'], $a['fin'] );
				}
			)
		);
	}

	public static function boutique_active() {
		$b = self::get()['boutique'];
		return ! empty( $b['actif'] ) && '' !== $b['url'] && self::dans_periode( $b['debut'], $b['fin'] ) ? $b : null;
	}

	/** AG filtrées : $quand = a_venir | passees | toutes ; $type = '' | ca | publique. */
	public static function ag( $quand = 'toutes', $type = '' ) {
		$t    = VHB_Club_Donnees::today();
		$list = array_values(
			array_filter(
				self::get()['ag'],
				static function ( $a ) use ( $quand, $type, $t ) {
					if ( $type && $a['type'] !== $type ) {
						return false;
					}
					if ( 'a_venir' === $quand ) {
						return '' === $a['date'] || $a['date'] >= $t;
					}
					if ( 'passees' === $quand ) {
						return '' !== $a['date'] && $a['date'] < $t;
					}
					return true;
				}
			)
		);
		usort(
			$list,
			static function ( $a, $b ) use ( $quand ) {
				return 'a_venir' === $quand ? strcmp( $a['date'], $b['date'] ) : strcmp( $b['date'], $a['date'] );
			}
		);
		return $list;
	}

	/* ------------------------------------------------------------------ */
	/* Enregistrement                                                      */
	/* ------------------------------------------------------------------ */

	private static function date_ok( $v ) {
		$v = sanitize_text_field( (string) $v );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
	}

	/** AG dans l'ordre de l'écran : sans date en tête (à planifier), puis de la plus récente à la plus ancienne. */
	public static function trier_ag( array $list ) {
		usort(
			$list,
			static function ( $a, $b ) {
				$da = (string) ( $a['date'] ?? '' );
				$db = (string) ( $b['date'] ?? '' );
				if ( '' === $da || '' === $db ) {
					return ( '' === $da ? 0 : 1 ) - ( '' === $db ? 0 : 1 );
				}
				return strcmp( $db, $da );
			}
		);
		return array_values( $list );
	}

	private static function rows( $in, callable $one ) {
		$out = array();
		foreach ( (array) $in as $k => $r ) {
			if ( '__i__' === (string) $k || ! is_array( $r ) ) {
				continue;
			}
			$x = $one( $r );
			if ( $x ) {
				$out[] = $x;
			}
		}
		return $out;
	}

	public static function sanitize_organigramme( array $in, array $o ) {
		$o['affichage_noms'] = 'initiale' === ( $in['affichage_noms'] ?? '' ) ? 'initiale' : 'complet';
		$o['personnes']      = self::rows(
			$in['personnes'] ?? array(),
			static function ( $r ) {
				$p = array(
					'prenom'   => sanitize_text_field( (string) ( $r['prenom'] ?? '' ) ),
					'nom'      => sanitize_text_field( (string) ( $r['nom'] ?? '' ) ),
					'fonction' => sanitize_text_field( (string) ( $r['fonction'] ?? '' ) ),
					'instance' => isset( self::INSTANCES[ $r['instance'] ?? '' ] ) ? $r['instance'] : 'ca',
					'email'    => sanitize_email( (string) ( $r['email'] ?? '' ) ),
					'photo'    => absint( $r['photo'] ?? 0 ),
				);
				return ( '' === $p['prenom'] && '' === $p['nom'] && '' === $p['fonction'] ) ? null : $p;
			}
		);
		return $o;
	}

	public static function sanitize_vie( array $in, array $o ) {
		$o['alertes'] = self::rows(
			$in['alertes'] ?? array(),
			static function ( $r ) {
				$a = array(
					'message' => sanitize_text_field( (string) ( $r['message'] ?? '' ) ),
					'lien'    => esc_url_raw( trim( (string) ( $r['lien'] ?? '' ) ) ),
					'debut'   => self::date_ok( $r['debut'] ?? '' ),
					'fin'     => self::date_ok( $r['fin'] ?? '' ),
					'niveau'  => isset( self::NIVEAUX_ALERTE[ $r['niveau'] ?? '' ] ) ? $r['niveau'] : 'info',
				);
				return '' === $a['message'] ? null : $a;
			}
		);
		$o['ag'] = self::rows(
			$in['ag'] ?? array(),
			static function ( $r ) {
				$a = array(
					'type'        => isset( self::TYPES_AG[ $r['type'] ?? '' ] ) ? $r['type'] : 'publique',
					'titre'       => sanitize_text_field( (string) ( $r['titre'] ?? '' ) ),
					'date'        => self::date_ok( $r['date'] ?? '' ),
					'heure'       => sanitize_text_field( (string) ( $r['heure'] ?? '' ) ),
					'lieu'        => sanitize_text_field( (string) ( $r['lieu'] ?? '' ) ),
					'description' => sanitize_text_field( (string) ( $r['description'] ?? '' ) ),
					'fichier'     => absint( $r['fichier'] ?? 0 ),
					'url'         => esc_url_raw( trim( (string) ( $r['url'] ?? '' ) ) ),
				);
				return ( '' === $a['titre'] && '' === $a['date'] ) ? null : $a;
			}
		);
		$o['ag'] = self::trier_ag( $o['ag'] );
		$b             = (array) ( $in['boutique'] ?? array() );
		$o['boutique'] = array(
			'actif' => ! empty( $b['actif'] ),
			'titre' => sanitize_text_field( (string) ( $b['titre'] ?? '' ) ) ?: 'Boutique du club',
			'texte' => sanitize_text_field( (string) ( $b['texte'] ?? '' ) ),
			'url'   => esc_url_raw( trim( (string) ( $b['url'] ?? '' ) ) ),
			'debut' => self::date_ok( $b['debut'] ?? '' ),
			'fin'   => self::date_ok( $b['fin'] ?? '' ),
		);
		$o['partenaires'] = self::rows(
			$in['partenaires'] ?? array(),
			static function ( $r ) {
				$p = array(
					'nom'    => sanitize_text_field( (string) ( $r['nom'] ?? '' ) ),
					'logo'   => absint( $r['logo'] ?? 0 ),
					'url'    => esc_url_raw( trim( (string) ( $r['url'] ?? '' ) ) ),
					'niveau' => isset( self::NIVEAUX[ $r['niveau'] ?? '' ] ) ? $r['niveau'] : 'partenaire',
					'rendu'  => isset( self::RENDUS_LOGO[ $r['rendu'] ?? '' ] ) ? $r['rendu'] : 'silhouette',
					'hauteur' => isset( self::HAUTEURS_LOGO[ $r['hauteur'] ?? '' ] ) ? $r['hauteur'] : 'normale',
					'zoom'   => isset( self::ZOOMS_LOGO[ (string) ( $r['zoom'] ?? '' ) ] ) ? (string) $r['zoom'] : '100',
				);
				return '' === $p['nom'] && ! $p['logo'] ? null : $p;
			}
		);
		$m             = (array) ( $in['mentions'] ?? array() );
		$o['mentions'] = array(
			'rna'           => sanitize_text_field( (string) ( $m['rna'] ?? '' ) ),
			'siret'         => sanitize_text_field( (string) ( $m['siret'] ?? '' ) ),
			'directeur'     => sanitize_text_field( (string) ( $m['directeur'] ?? '' ) ),
			'hebergeur'     => sanitize_text_field( (string) ( $m['hebergeur'] ?? '' ) ),
			'hebergeur_adr' => sanitize_text_field( (string) ( $m['hebergeur_adr'] ?? '' ) ),
			'contact_rgpd'  => sanitize_email( (string) ( $m['contact_rgpd'] ?? '' ) ),
			'rgpd'          => sanitize_textarea_field( (string) ( $m['rgpd'] ?? '' ) ),
		);
		return $o;
	}


}
