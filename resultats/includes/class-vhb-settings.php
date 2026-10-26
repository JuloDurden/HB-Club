<?php
/**
 * Réglages de l'extension : saison, club, équipes, tables de réécriture.
 *
 * Rien n'est codé en dur dans la logique : la saison, l'identifiant club et
 * les URL d'équipe se modifient depuis l'admin. Les valeurs ci-dessous ne
 * servent qu'au premier démarrage (saison 2026-2027).
 */

defined( 'ABSPATH' ) || exit;

class VHB_Settings {

	const OPTION = 'vhb_ffhb_settings';


	/** Réglages par défaut d'une équipe. */
	const EQUIPE_DEFAUT = array(
		'nom'        => '',
		'slug'       => '',
		'url'        => '',
		'entente'    => false,
		'classement' => true,
		'vd'         => false,
		'blason'     => 0,
		'masquee'    => false,
		'fdm'        => 'copie',
	);

	/** Modes d'affichage des feuilles de match. */
	const FDM_MODES = array(
		'copie'    => 'copie sur le site',
		'lien'     => 'lien FFHB',
		'masquees' => 'masquées',
	);

	/** Préfixe commun des pages compétition FFHB. */
	const FFHB_BASE = 'https://www.ffhandball.fr/competitions/';

	public static function defaults() {
		return array(
			'saison'      => 'saison-2026-2027-22',
			'club_id'     => '',
			'alert_email' => '',
			'theme'       => 'sombre',
			'agenda'      => true,
			'nb_resultats' => 0,
			'jsonld'      => true,
			'salles'      => '',
			'libelles'    => '',
			'equipes'     => array(),
		);
	}

	public static function get() {
		$opt = get_option( self::OPTION );
		if ( ! is_array( $opt ) ) {
			return self::defaults();
		}
		return array_merge( self::defaults(), $opt );
	}

	/** Enregistre les réglages par défaut à l'activation, sans écraser l'existant. */
	public static function install() {
		if ( false === get_option( self::OPTION ) ) {
			add_option( self::OPTION, self::defaults(), '', false );
		}
	}

	/**
	 * Équipes déclarées. Une équipe peut être engagée dans plusieurs
	 * compétitions (championnat, coupe de France, etc.) : chacune a sa
	 * propre page équipe FFHB.
	 *
	 * 'competitions' : liste de { url (jeton {saison} remplacé), nom (libellé
	 * court facultatif), cle (identifiant de compétition FFHB), type
	 * ('coupe' ou 'championnat') }. La première est la compétition principale.
	 * 'url' : URL de la compétition principale (compatibilité).
	 */
	public static function equipes() {
		$s   = self::get();
		$out = array();
		foreach ( (array) $s['equipes'] as $e ) {
			if ( empty( $e['slug'] ) ) {
				continue;
			}
			$e                 = array_merge( self::EQUIPE_DEFAUT, $e );
			$e['competitions'] = self::competitions_of( $e, $s['saison'] );
			$e['url']          = $e['competitions'] ? $e['competitions'][0]['url'] : '';
			$e['entente']    = ! empty( $e['entente'] );
			$e['classement'] = ! empty( $e['classement'] );
			$e['vd']         = ! empty( $e['vd'] );
			$e['blason']     = absint( $e['blason'] );
			$e['masquee']    = ! empty( $e['masquee'] );
			$e['fdm']        = isset( self::FDM_MODES[ $e['fdm'] ] ) ? $e['fdm'] : 'copie';
			$out[]           = $e;
		}
		return $out;
	}

	/** Compétitions d'une équipe, à partir des réglages (ancien format « url » accepté). */
	private static function competitions_of( array $e, $saison ) {
		$rows = isset( $e['competitions'] ) && is_array( $e['competitions'] ) ? $e['competitions'] : array( array( 'url' => (string) ( $e['url'] ?? '' ), 'nom' => '' ) );
		$out  = array();
		$seen = array();
		foreach ( $rows as $c ) {
			$url = self::resolve_url( (string) ( $c['url'] ?? '' ), $saison );
			$cle = self::comp_key( $url );
			if ( '' === $url || '' === $cle || isset( $seen[ $cle ] ) ) {
				continue;
			}
			$seen[ $cle ] = true;
			$out[]        = array(
				'url'  => $url,
				'nom'  => trim( (string) ( $c['nom'] ?? '' ) ),
				'cle'  => $cle,
				'type' => self::comp_type( $url ),
			);
		}
		return $out;
	}

	/** Identifiant de compétition FFHB lu dans l'URL de la page équipe (« …-32594/equipe-… »). */
	public static function comp_key( $url ) {
		return preg_match( '#-([0-9]+)/equipe-[0-9]+#', (string) $url, $m ) ? $m[1] : '';
	}

	/** « coupe » pour les coupes de France (rencontres à élimination, sans classement). */
	public static function comp_type( $url ) {
		return false !== strpos( (string) $url, '/coupe-de-france/' ) || false !== stripos( (string) $url, '/coupe-' ) ? 'coupe' : 'championnat';
	}

	public static function equipe( $slug ) {
		foreach ( self::equipes() as $e ) {
			if ( $e['slug'] === $slug ) {
				return $e;
			}
		}
		return null;
	}

	public static function resolve_url( $url, $saison ) {
		return trim( str_replace( '{saison}', $saison, $url ) );
	}

	/** Segment « saison-xxxx-xxxx-nn » présent dans une URL FFHB, ou ''. */
	public static function saison_of_url( $url ) {
		return preg_match( '#/competitions/(saison-[0-9]{4}-[0-9]{4}-[0-9]+)/#', $url, $m ) ? $m[1] : '';
	}

	/**
	 * Table « NOM FFHB = Nom affiché », une correspondance par ligne.
	 *
	 * @return array<string,string> clés en majuscules
	 */
	public static function map( $key ) {
		$s   = self::get();
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) ( $s[ $key ] ?? '' ) ) as $line ) {
			if ( false === strpos( $line, '=' ) ) {
				continue;
			}
			list( $from, $to ) = array_map( 'trim', explode( '=', $line, 2 ) );
			if ( '' !== $from && '' !== $to ) {
				$out[ self::upper( $from ) ] = $to;
			}
		}
		return $out;
	}

	/**
	 * Table des salles, une ligne par salle :
	 * « NOM FFHB = Nom affiché | Adresse | lat, lon » (adresse et GPS facultatifs).
	 *
	 * @return array<string,array{nom:string,adresse:string,lat:?float,lon:?float}> clés en majuscules
	 */
	public static function salles() {
		$out = array();
		foreach ( self::map( 'salles' ) as $k => $right ) {
			$parts = array_map( 'trim', explode( '|', $right ) );
			$lat   = null;
			$lon   = null;
			if ( ! empty( $parts[2] ) && preg_match( '/^(-?[0-9]+(?:\.[0-9]+)?)\s*[,;]\s*(-?[0-9]+(?:\.[0-9]+)?)$/', $parts[2], $m ) ) {
				$lat = (float) $m[1];
				$lon = (float) $m[2];
			}
			$out[ $k ] = array(
				'nom'     => '' !== $parts[0] ? $parts[0] : $k,
				'adresse' => $parts[1] ?? '',
				'lat'     => $lat,
				'lon'     => $lon,
			);
		}
		/**
		 * Filtre « vhb_ffhb_salles » : VHB Club y ajoute les gymnases qu'il gère
		 * (nom FFHB renseigné). Ils priment sur cette table.
		 */
		return apply_filters( 'vhb_ffhb_salles', $out );
	}

	/**
	 * Mise à niveau des réglages enregistrés par une version précédente.
	 * 1.3.0 : une équipe peut avoir plusieurs compétitions.
	 */
	public static function upgrade( $from ) {
		$opt = get_option( self::OPTION );
		if ( ! is_array( $opt ) ) {
			return;
		}
		if ( version_compare( $from, '1.3.0', '<' ) ) {
			$opt = get_option( self::OPTION );
			foreach ( (array) ( $opt['equipes'] ?? array() ) as $i => $e ) {
				if ( ! isset( $e['competitions'] ) ) {
					$url                                  = (string) ( $e['url'] ?? '' );
					$opt['equipes'][ $i ]['competitions'] = '' !== $url ? array( array( 'url' => $url, 'nom' => '' ) ) : array();
				}
				unset( $opt['equipes'][ $i ]['url'] );
			}
			update_option( self::OPTION, $opt );
		}
	}

	public static function upper( $s ) {
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $s, 'UTF-8' ) : strtoupper( $s );
	}

	/** Nettoyage des réglages postés depuis l'admin. */
	public static function sanitize( $in ) {
		$old = self::get();
		$in  = is_array( $in ) ? $in : array();
		$out = array(
			'saison'      => preg_match( '/^saison-[0-9]{4}-[0-9]{4}-[0-9]+$/', trim( (string) ( $in['saison'] ?? '' ) ) ) ? trim( $in['saison'] ) : $old['saison'],
			'club_id'     => preg_match( '/^[0-9]+$/', trim( (string) ( $in['club_id'] ?? '' ) ) ) ? trim( $in['club_id'] ) : $old['club_id'],
			'alert_email' => sanitize_email( (string) ( $in['alert_email'] ?? '' ) ),
			'theme'       => in_array( $in['theme'] ?? '', array( 'sombre', 'clair', 'auto', 'pages' ), true ) ? $in['theme'] : 'sombre',
			'agenda'      => ! empty( $in['agenda'] ),
			'nb_resultats' => min( 50, absint( $in['nb_resultats'] ?? 0 ) ),
			'jsonld'      => ! empty( $in['jsonld'] ),
			'salles'      => sanitize_textarea_field( (string) ( $in['salles'] ?? '' ) ),
			'libelles'    => sanitize_textarea_field( (string) ( $in['libelles'] ?? '' ) ),
			'equipes'     => array(),
		);

		$seen = array();
		foreach ( (array) ( $in['equipes'] ?? array() ) as $row ) {
			$nom = sanitize_text_field( (string) ( $row['nom'] ?? '' ) );
			if ( '' === $nom ) {
				continue;
			}
			$slug = sanitize_title( (string) ( $row['slug'] ?? '' ) );
			if ( '' === $slug ) {
				$slug = sanitize_title( $nom );
			}
			$base = $slug;
			$i    = 2;
			while ( isset( $seen[ $slug ] ) ) {
				$slug = $base . '-' . $i++;
			}
			$seen[ $slug ] = true;

			$rows = isset( $row['competitions'] ) && is_array( $row['competitions'] ) ? $row['competitions'] : array( array( 'url' => $row['url'] ?? '', 'nom' => '' ) );
			$comps = array();
			$cles  = array();
			foreach ( $rows as $c ) {
				$url = trim( (string) ( $c['url'] ?? '' ) );
				if ( '' === $url ) {
					continue;
				}
				// esc_url_raw() supprime les accolades : on protège le jeton {saison}.
				$url = str_replace( '__VHBSAISON__', '{saison}', esc_url_raw( str_replace( '{saison}', '__VHBSAISON__', $url ) ) );
				if ( ! preg_match( '#^https://www\.ffhandball\.fr/competitions/.+-[0-9]+/equipe-[0-9]+/?$#', str_replace( '{saison}', 'saison-0000-0000-0', $url ) ) ) {
					add_settings_error( self::OPTION, 'url-' . $slug . count( $comps ), sprintf( 'URL ignorée pour « %s » : ce n\'est pas une page équipe FFHB (…-<compétition>/equipe-<numéro>/).', $nom ) );
					continue;
				}
				if ( '/' !== substr( $url, -1 ) ) {
					$url .= '/';
				}
				$cle = self::comp_key( $url );
				if ( isset( $cles[ $cle ] ) ) {
					add_settings_error( self::OPTION, 'dup-' . $slug . $cle, sprintf( 'Compétition en double ignorée pour « %s ».', $nom ) );
					continue;
				}
				$cles[ $cle ] = true;
				$comps[]      = array(
					'url' => $url,
					'nom' => sanitize_text_field( (string) ( $c['nom'] ?? '' ) ),
				);
			}
			$out['equipes'][] = array(
				'nom'          => $nom,
				'slug'         => $slug,
				'competitions' => $comps,
				'entente'    => ! empty( $row['entente'] ),
				'classement' => ! empty( $row['classement'] ),
				'vd'         => ! empty( $row['vd'] ),
				'blason'     => absint( $row['blason'] ?? 0 ),
				'masquee'    => ! empty( $row['masquee'] ),
				'fdm'        => isset( self::FDM_MODES[ $row['fdm'] ?? '' ] ) ? $row['fdm'] : 'copie',
			);
		}
		return $out;
	}
}
