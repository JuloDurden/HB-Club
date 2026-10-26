<?php
/**
 * Copie locale des données FFHB (option « vhb_ffhb_data », autoload désactivé).
 *
 * Règle d'or : un échec n'écrase jamais une donnée existante. La mise à jour
 * se fait compétition par compétition ; une compétition en erreur garde sa
 * dernière version.
 *
 * Structure : equipes[slug]['competitions'][identifiant de compétition FFHB].
 */

defined( 'ABSPATH' ) || exit;

class VHB_Store {

	const OPTION = 'vhb_ffhb_data';

	public static function get() {
		$d = get_option( self::OPTION );
		if ( ! is_array( $d ) ) {
			$d = array();
		}
		return array_merge(
			array(
				'maj'     => '',
				'saison'  => '',
				'equipes' => array(),
			),
			$d
		);
	}

	private static function put( array $d ) {
		// update_option() n'ajoute pas si la valeur est identique : pas grave.
		if ( false === get_option( self::OPTION ) ) {
			add_option( self::OPTION, $d, '', false );
		} else {
			update_option( self::OPTION, $d, false );
		}
	}

	/**
	 * Données d'une équipe : array( 'competitions' => array( cle => données ) ).
	 * Accepte l'ancien format (une seule compétition à plat, avant 1.3.0).
	 */
	public static function team( $slug ) {
		$d = self::get();
		return isset( $d['equipes'][ $slug ] ) ? self::normalize( $d['equipes'][ $slug ] ) : null;
	}

	/** Données brutes d'une compétition d'une équipe (null si jamais récupérée). */
	public static function comp( $slug, $cle ) {
		$t = self::team( $slug );
		return $t['competitions'][ $cle ] ?? null;
	}

	private static function normalize( array $t ) {
		if ( isset( $t['competitions'] ) && is_array( $t['competitions'] ) ) {
			return $t;
		}
		// Ancien format : la compétition est à plat dans l'équipe.
		$cle = VHB_Settings::comp_key( (string) ( $t['page'] ?? '' ) );
		return array( 'competitions' => ( '' !== $cle ) ? array( $cle => $t ) : array() );
	}

	/**
	 * Enregistre une compétition fraîchement récupérée, en préservant ce que
	 * l'exécution n'a pas pu obtenir (classement, lieux).
	 */
	public static function save_comp( $slug, $cle, array $comp ) {
		$d    = self::get();
		$team = isset( $d['equipes'][ $slug ] ) ? self::normalize( $d['equipes'][ $slug ] ) : array( 'competitions' => array() );
		$old  = $team['competitions'][ $cle ] ?? null;

		if ( null === $comp['classement'] ) {
			$comp['classement'] = $old['classement'] ?? array();
		}

		if ( $old && ( empty( $comp['lieux_ok'] ) ) ) {
			$prev = array();
			foreach ( (array) ( $old['rencontres'] ?? array() ) as $r ) {
				$prev[ $r['id'] ] = $r;
			}
			foreach ( $comp['rencontres'] as &$r ) {
				if ( '' === $r['adresse'] && isset( $prev[ $r['id'] ] ) ) {
					foreach ( array( 'lieu', 'adresse', 'lat', 'lon', 'url' ) as $k ) {
						$r[ $k ] = $prev[ $r['id'] ][ $k ] ?? $r[ $k ];
					}
				}
			}
			unset( $r );
		}

		unset( $comp['lieux_ok'] );
		$comp['cle']         = $cle;
		$comp['erreur']      = '';
		$comp['erreur_date'] = '';

		$team['competitions'][ $cle ] = $comp;
		$d['equipes'][ $slug ]        = $team;
		$d['maj']                     = $comp['maj'];
		$d['saison']                  = VHB_Settings::get()['saison'];
		self::put( $d );
	}

	/** Note un échec sans toucher aux données de la compétition. */
	public static function mark_error( $slug, $cle, $message ) {
		$d    = self::get();
		$team = isset( $d['equipes'][ $slug ] ) ? self::normalize( $d['equipes'][ $slug ] ) : array( 'competitions' => array() );
		if ( ! isset( $team['competitions'][ $cle ] ) ) {
			$team['competitions'][ $cle ] = array( 'cle' => $cle );
		}
		$team['competitions'][ $cle ]['erreur']      = $message;
		$team['competitions'][ $cle ]['erreur_date'] = ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'c' );
		$d['equipes'][ $slug ]                       = $team;
		self::put( $d );
	}

	/** Retire les équipes et compétitions qui ne figurent plus dans les réglages. */
	public static function prune() {
		$d    = self::get();
		$keep = array();
		foreach ( VHB_Settings::equipes() as $e ) {
			$keep[ $e['slug'] ] = array_flip( wp_list_pluck( $e['competitions'], 'cle' ) );
		}
		$out = array();
		foreach ( $d['equipes'] as $slug => $t ) {
			if ( ! isset( $keep[ $slug ] ) ) {
				continue;
			}
			$t                    = self::normalize( $t );
			$t['competitions']    = array_intersect_key( $t['competitions'], $keep[ $slug ] );
			$out[ $slug ]         = $t;
		}
		if ( $out !== $d['equipes'] ) {
			$d['equipes'] = $out;
			self::put( $d );
		}
	}

	/** Nom affiché d'une compétition : celui des réglages, sinon un nom par défaut. */
	public static function comp_label( array $c, array $data = array() ) {
		if ( '' !== $c['nom'] ) {
			return $c['nom'];
		}
		if ( 'coupe' === $c['type'] ) {
			return false !== strpos( $c['url'], '/coupe-de-france/' ) ? 'Coupe de France' : 'Coupe';
		}
		return 'Championnat';
	}

	/**
	 * Tour d'une rencontre : « J2 » / « journée 2 » en championnat,
	 * « T1 » / « 1er tour », « 1/8 » / « 1/8 de finale », « F » / « finale » en coupe.
	 *
	 * @return array{0:string,1:string} court, long
	 */
	public static function round_labels( array $r, $type, $poule = '' ) {
		$n = (int) ( $r['journee'] ?? 0 );
		if ( 'coupe' !== $type ) {
			return array( 'J' . $n, 'journée ' . $n );
		}
		$txt = strtoupper( (string) ( $r['phase'] ?? '' ) . ' ' . $poule );
		if ( preg_match( '#1\s*/\s*([0-9]+)#', $txt, $m ) ) {
			return array( '1/' . $m[1], '1/' . $m[1] . ' de finale' );
		}
		if ( preg_match( '#DEMI#', $txt ) ) {
			return array( '1/2', 'demi-finale' );
		}
		if ( preg_match( '#FINALE#', $txt ) ) {
			return array( 'F', 'finale' );
		}
		if ( preg_match( '#([0-9]+)\s*(?:°|ER|E|EME|ÈME)?\s*TOUR#u', $txt, $m ) ) {
			$t = (int) $m[1];
			return array( 'T' . $t, ( 1 === $t ? '1er' : $t . 'e' ) . ' tour' );
		}
		return array( 'T' . max( 1, $n ), 'tour ' . max( 1, $n ) );
	}

	/**
	 * Vue d'affichage d'une équipe : réglages + données de toutes ses
	 * compétitions, prête pour le rendu et la route REST. Toujours un
	 * tableau, même pour une équipe non publiée.
	 *
	 * Les champs à la racine (libelle, competition, poule, classement…)
	 * sont ceux de la compétition principale (la première des réglages).
	 */
	public static function view( $slug ) {
		$cfg = VHB_Settings::equipe( $slug );
		if ( ! $cfg ) {
			return null;
		}
		$team  = self::team( $slug );
		$comps = array();
		$renc  = array();
		$maj   = '';
		foreach ( $cfg['competitions'] as $i => $c ) {
			$data = $team['competitions'][ $c['cle'] ] ?? array();
			$cv   = array(
				'cle'              => $c['cle'],
				'nom'              => self::comp_label( $c, $data ),
				'type'             => $c['type'],
				'principale'       => 0 === $i,
				'recupere'         => ! empty( $data['libelle'] ),
				'libelle'          => (string) ( $data['libelle'] ?? '' ),
				'competition'      => (string) ( $data['competition'] ?? '' ),
				'poule'            => (string) ( $data['poule'] ?? '' ),
				'classement_actif' => 'coupe' !== $c['type'] && ( $data['classement_actif'] ?? true ),
				'classement'       => 'coupe' !== $c['type'] ? (array) ( $data['classement'] ?? array() ) : array(),
				'page'             => $c['url'],
				'poule_url'        => (string) ( $data['poule_url'] ?? '' ),
				'maj'              => (string) ( $data['maj'] ?? '' ),
				'erreur'           => (string) ( $data['erreur'] ?? '' ),
				'erreur_date'      => (string) ( $data['erreur_date'] ?? '' ),
			);
			$comps[] = $cv;
			if ( $cv['maj'] > $maj ) {
				$maj = $cv['maj'];
			}
			foreach ( (array) ( $data['rencontres'] ?? array() ) as $r ) {
				list( $court, $long ) = self::round_labels( $r, $c['type'], $cv['poule'] );
				$r['comp']       = $c['cle'];
				$r['comp_nom']   = $cv['nom'];
				$r['comp_type']  = $c['type'];
				$r['principale'] = $cv['principale'];
				$r['nous']       = $cv['libelle'];
				$r['tour']       = $court;
				$r['tour_long']  = $long;
				$r['ordre']      = array( $i, (int) $r['journee'] );
				$renc[]          = $r;
			}
		}
		// Tri chronologique toutes compétitions confondues ; les matchs sans date à la fin.
		usort(
			$renc,
			static function ( $a, $b ) {
				$ta = VHB_Render::ts( $a['date'] );
				$tb = VHB_Render::ts( $b['date'] );
				if ( $ta && $tb ) {
					return $ta <=> $tb;
				}
				if ( $ta xor $tb ) {
					return $ta ? -1 : 1;
				}
				return $a['ordre'] <=> $b['ordre'];
			}
		);

		$p   = $comps[0] ?? null;
		$out = array(
			'slug'             => $cfg['slug'],
			'equipe'           => $cfg['nom'],
			'entente'          => $cfg['entente'],
			'publie'           => (bool) $comps,
			'recupere'         => (bool) array_filter( wp_list_pluck( $comps, 'recupere' ) ),
			'multi'            => count( $comps ) > 1,
			'competitions'     => $comps,
			'libelle'          => $p ? $p['libelle'] : '',
			'competition'      => $p ? $p['competition'] : '',
			'poule'            => $p ? $p['poule'] : '',
			'classement_actif' => $p ? $p['classement_actif'] : true,
			'classement'       => $p ? $p['classement'] : array(),
			'rencontres'       => $renc,
			'maj'              => $maj,
			'page'             => $p ? $p['page'] : '',
			'poule_url'        => $p ? $p['poule_url'] : '',
		);
		return $out + array(
			'afficher_classement' => $cfg['classement'],
			'masquee'             => $cfg['masquee'],
			'fdm_mode'            => $cfg['fdm'],
			'vd'                  => $cfg['vd'],
			'blason_url'          => $cfg['blason'] ? (string) wp_get_attachment_image_url( $cfg['blason'], 'thumbnail' ) : '',
		);
	}

	/**
	 * Équipes dans l'ordre des réglages. Les équipes masquées sont exclues,
	 * sauf demande explicite (admin).
	 */
	public static function views( $avec_masquees = false ) {
		$out = array();
		foreach ( VHB_Settings::equipes() as $e ) {
			if ( $e['masquee'] && ! $avec_masquees ) {
				continue;
			}
			$out[] = self::view( $e['slug'] );
		}
		return $out;
	}

	/** Empreinte du contenu, sans les horodatages : change seulement si un score, une date, un lieu ou le classement change. */
	public static function fingerprint() {
		$d = self::get();
		unset( $d['maj'] );
		foreach ( $d['equipes'] as &$e ) {
			$e = self::normalize( $e );
			foreach ( $e['competitions'] as &$c ) {
				unset( $c['maj'], $c['erreur'], $c['erreur_date'] );
			}
			unset( $c );
		}
		unset( $e );
		return md5( serialize( $d ) );
	}

	public static function clear() {
		delete_option( self::OPTION );
	}
}
