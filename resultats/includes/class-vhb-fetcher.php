<?php
/**
 * Récupération côté serveur des données FFHB.
 *
 * Portage PHP de l'extracteur de référence vhb_ffhb.py, validé contre les
 * vraies données de la saison 2026-2027.
 *
 * Source A : page « équipe » d'une compétition. Le HTML porte toute la saison
 *            en JSON échappé HTML (blocs {"ext_saison_id"...}).
 * Source B : flux iCal club + compétition. Porte salle, adresse et GPS.
 */

defined( 'ABSPATH' ) || exit;

class VHB_Fetch_Exception extends Exception {}

class VHB_Fetcher {

	/** Identité du robot : nom de l'extension et adresse du site, pour que la FFHB sache qui l'interroge. */
	public static function user_agent() {
		return 'HBClub/' . VHB_CLUB_VERSION . ' (+' . home_url( '/' ) . ')';
	}
	const TIMEOUT    = 30;
	/** Pause entre deux requêtes vers la FFHB, par correction. */
	const PAUSE = 1.5;

	/** @var string */
	private $club_id;

	/** @var float horodatage de la dernière requête */
	private $last = 0.0;

	/** @var string[] avertissements non bloquants de la dernière équipe traitée */
	public $warnings = array();

	public function __construct( $club_id ) {
		$this->club_id = (string) $club_id;
	}

	/* ------------------------------------------------------------------ */
	/* HTTP                                                                */
	/* ------------------------------------------------------------------ */

	/** Respecte la pause de politesse depuis la requête précédente. */
	public function pace( $pause = self::PAUSE ) {
		if ( $this->last > 0 ) {
			$wait = $pause - ( microtime( true ) - $this->last );
			if ( $wait > 0 ) {
				usleep( (int) ( $wait * 1e6 ) );
			}
		}
		$this->last = microtime( true );
	}

	/**
	 * @throws VHB_Fetch_Exception
	 */
	public function get( $url, $pause = self::PAUSE ) {
		$this->pace( $pause );
		$res = wp_remote_get(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'user-agent'  => self::user_agent(),
				'redirection' => 3,
				'headers'     => array( 'Accept-Language' => 'fr-FR,fr;q=0.9' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			throw new VHB_Fetch_Exception( 'Réseau : ' . $res->get_error_message() . ' (' . $url . ')' );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			throw new VHB_Fetch_Exception( 'HTTP ' . $code . ' sur ' . $url, $code );
		}
		return (string) wp_remote_retrieve_body( $res );
	}

	/* ------------------------------------------------------------------ */
	/* Extraction                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Fusionne les blocs JSON échappés d'une page FFHB : pour chaque clé, on
	 * garde la première valeur non vide (tous les blocs ne sont pas complets).
	 */
	public static function extract_json( $html ) {
		$s = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( ! preg_match_all( '/\{"ext_saison_id".*?\}(?=")/s', $s, $m ) ) {
			return array();
		}
		$fusion = array();
		foreach ( $m[0] as $bloc ) {
			$b = json_decode( $bloc, true );
			if ( ! is_array( $b ) ) {
				continue;
			}
			foreach ( $b as $k => $v ) {
				if ( self::is_empty( $v ) || ! self::is_empty( $fusion[ $k ] ?? null ) ) {
					continue;
				}
				$fusion[ $k ] = $v;
			}
		}
		return $fusion;
	}

	private static function is_empty( $v ) {
		return null === $v || '' === $v || array() === $v;
	}

	/**
	 * Lieux par identifiant de rencontre, depuis le flux iCal.
	 *
	 * @return array|null null si le flux est injoignable (non bloquant)
	 */
	public function lieux_ics( $competition_id ) {
		if ( '' === $this->club_id ) {
			$this->warnings[] = 'Identifiant club FFHB non renseigné : lieux des matchs non récupérés.';
			return null;
		}
		$url = $this->ics_url( $competition_id );
		try {
			$brut = $this->get( $url );
		} catch ( VHB_Fetch_Exception $e ) {
			$this->warnings[] = 'Flux iCal indisponible, lieux conservés : ' . $e->getMessage();
			return null;
		}
		return self::parse_ics( $brut );
	}

	public function ics_url( $competition_id ) {
		return 'https://competition-calendar.ffhandball.fr/c-' . rawurlencode( (string) $competition_id ) . '/s-' . rawurlencode( $this->club_id ) . '.ics';
	}

	public static function parse_ics( $brut ) {
		// Dépliage des lignes iCal repliées, AVANT tout parsing.
		$brut  = str_replace( array( "\r\n ", "\n ", "\r\n\t", "\n\t" ), '', $brut );
		$lieux = array();
		foreach ( array_slice( explode( 'BEGIN:VEVENT', $brut ), 1 ) as $bloc ) {
			$champ = static function ( $nom ) use ( $bloc ) {
				return preg_match( '/^' . $nom . '[^:\r\n]*:(.*)$/m', $bloc, $mm ) ? trim( $mm[1] ) : '';
			};
			if ( ! preg_match( '/rencontre-(\d+)/', $champ( 'URL' ), $rid ) ) {
				continue;
			}
			$loc = str_replace( array( '\\,', '\\;', '\\n', '\\N', '\\\\' ), array( ',', ';', ' ', ' ', '\\' ), $champ( 'LOCATION' ) );
			$loc = trim( preg_replace( array( '/\s+/', '/ ,/' ), array( ' ', ',' ), $loc ) );
			$geo = explode( ';', $champ( 'GEO' ) );
			$ok  = 2 === count( $geo ) && is_numeric( $geo[0] ) && is_numeric( $geo[1] );

			$lieux[ $rid[1] ] = array(
				'lieu'    => '' !== $loc ? trim( explode( ',', $loc )[0] ) : '',
				'adresse' => $loc,
				'lat'     => $ok ? (float) $geo[0] : null,
				'lon'     => $ok ? (float) $geo[1] : null,
				'url'     => $champ( 'URL' ),
			);
		}
		return $lieux;
	}

	/** Nom de fichier d'un blason sans extension (le CDN FFHB sert du .webp). */
	private static function logo_stem( $file, $show = '1' ) {
		if ( ! $file || '0' === (string) $show ) {
			return '';
		}
		$stem = pathinfo( basename( (string) $file ), PATHINFO_FILENAME );
		return preg_match( '/^[A-Za-z0-9._-]+$/', $stem ) ? $stem : '';
	}

	private static function score( $v ) {
		if ( null === $v ) {
			return null;
		}
		$v = trim( (string) $v );
		return '' === $v ? null : $v;
	}

	/* ------------------------------------------------------------------ */
	/* Une équipe                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Récupère et normalise une équipe.
	 *
	 * @param array $cfg nom, slug, url, entente
	 * @return array
	 * @throws VHB_Fetch_Exception si la page est injoignable ou méconnaissable
	 */
	public function team( array $cfg ) {
		$this->warnings = array();
		$url            = $cfg['url'];

		if ( ! preg_match( '/equipe-(\d+)/', $url, $m_eid ) || ! preg_match( '/-(\d+)\/equipe-/', $url, $m_comp ) ) {
			throw new VHB_Fetch_Exception( 'URL d\'équipe non reconnue : ' . $url );
		}
		$eid  = $m_eid[1];
		$comp = $m_comp[1];

		$d = self::extract_json( $this->get( $url ) );
		if ( empty( $d ) ) {
			throw new VHB_Fetch_Exception( 'Aucune donnée JSON trouvée dans la page FFHB : la structure du site a peut-être changé.' );
		}

		// Piège 1 : identifier NOTRE équipe par son ext_equipeId, puis par son
		// libellé exact. Jamais par le seul nom de la ville (une entente porte souvent le nom de plusieurs clubs).
		$nous = null;
		foreach ( (array) ( $d['equipes'] ?? array() ) as $e ) {
			if ( (string) ( $e['ext_equipeId'] ?? '' ) === $eid ) {
				$nous = $e;
				break;
			}
		}
		if ( ! $nous ) {
			throw new VHB_Fetch_Exception( 'Équipe ' . $eid . ' introuvable dans la page FFHB (URL de saison précédente ?).' );
		}
		$libelle   = (string) $nous['libelle'];
		$id_nous   = (string) ( $nous['id'] ?? '' );
		$poule_int = (string) ( $nous['pouleId'] ?? '' );

		$poule = array();
		if ( ! empty( $d['poule'] ) && (string) ( $d['poule']['id'] ?? '' ) === $poule_int ) {
			$poule = $d['poule'];
		} else {
			foreach ( (array) ( $d['poules'] ?? array() ) as $p ) {
				if ( (string) ( $p['id'] ?? '' ) === $poule_int ) {
					$poule = $p;
					break;
				}
			}
		}

		$journees = array();
		$raw_j    = $poule['journees'] ?? '[]';
		foreach ( (array) ( is_string( $raw_j ) ? json_decode( $raw_j, true ) : $raw_j ) as $j ) {
			if ( isset( $j['journee_numero'] ) ) {
				$journees[ (int) $j['journee_numero'] ] = array(
					'debut' => (string) ( $j['date_debut'] ?? '' ),
					'fin'   => (string) ( $j['date_fin'] ?? '' ),
				);
			}
		}

		$phase = (array) ( ( $d['phases'] ?? array() )[0] ?? array() );

		// Source B : lieux.
		$lieux = $this->lieux_ics( $comp );

		$rencontres = array();
		foreach ( (array) ( $d['rencontres'] ?? array() ) as $r ) {
			$dom = (string) ( $r['equipe1Libelle'] ?? '' );
			$ext = (string) ( $r['equipe2Libelle'] ?? '' );
			$e1  = (string) ( $r['equipe1Id'] ?? '' );
			$e2  = (string) ( $r['equipe2Id'] ?? '' );
			$est_nous_1 = ( '' !== $id_nous && $e1 === $id_nous ) || $dom === $libelle;
			$est_nous_2 = ( '' !== $id_nous && $e2 === $id_nous ) || $ext === $libelle;
			if ( ! $est_nous_1 && ! $est_nous_2 ) {
				continue; // rencontre d'une autre équipe
			}
			$rid = (string) ( $r['ext_rencontreId'] ?? '' );
			$s1  = self::score( $r['equipe1Score'] ?? null );
			$s2  = self::score( $r['equipe2Score'] ?? null );
			// Piège 5 : joué = deux scores présents. La date ne compte pas.
			$joue = null !== $s1 && null !== $s2;
			$num  = (int) ( $r['journeeNumero'] ?? 0 );
			$lieu = is_array( $lieux ) ? ( $lieux[ $rid ] ?? array() ) : array();

			$rencontres[] = array(
				'id'            => $rid,
				'journee'       => $num,
				'journee_debut' => $journees[ $num ]['debut'] ?? '',
				'journee_fin'   => $journees[ $num ]['fin'] ?? '',
				'date'          => ! empty( $r['date'] ) ? (string) $r['date'] : null,
				'domicile'      => $dom,
				'exterieur'     => $ext,
				'score1'        => $s1,
				'score2'        => $s2,
				'joue'          => $joue,
				// Piège 4 : le forfait est codé « FO » dans le score.
				'forfait'       => $joue && ( false !== strpos( $s1, 'FO' ) || false !== strpos( $s2, 'FO' ) ),
				'forfait_de'    => $joue ? ( false !== strpos( $s1, 'FO' ) ? $dom : ( false !== strpos( $s2, 'FO' ) ? $ext : '' ) ) : '',
				'a_domicile'    => $est_nous_1,
				'logo1'         => self::logo_stem( $r['structure1Logo'] ?? '', $r['equipe1ShowLogo'] ?? '1' ),
				'logo2'         => self::logo_stem( $r['structure2Logo'] ?? '', $r['equipe2ShowLogo'] ?? '1' ),
				'lieu'          => $lieu['lieu'] ?? '',
				'adresse'       => $lieu['adresse'] ?? '',
				'lat'           => $lieu['lat'] ?? null,
				'lon'           => $lieu['lon'] ?? null,
				'url'           => $lieu['url'] ?? '',
				'phase'         => (string) ( $r['phaseLibelle'] ?? '' ),
				'fdm'           => preg_match( '/^[A-Z0-9]{4,16}$/', (string) ( $r['fdmCode'] ?? '' ) ) ? (string) $r['fdmCode'] : '',
			);
		}
		usort(
			$rencontres,
			static function ( $a, $b ) {
				return ( $a['journee'] ?: 99 ) <=> ( $b['journee'] ?: 99 );
			}
		);

		// Pièges 2 et 3 : classement absent par règle, ou absent de la vue équipe.
		$classement_actif = '0' !== (string) ( $phase['classement'] ?? '1' );
		$classement       = array();
		if ( $classement_actif ) {
			$classement = $this->classement( $d, $poule_int, $poule, $url );
		}
		if ( null !== $classement ) {
			$classement = array_map(
				static function ( $c ) use ( $libelle ) {
					return array(
						'place'  => (int) ( $c['place'] ?? 0 ),
						'equipe' => (string) ( $c['equipe_libelle'] ?? '' ),
						'points' => (int) ( $c['point'] ?? 0 ),
						'joue'   => (int) ( $c['joue'] ?? 0 ),
						'gagne'  => (int) ( $c['gagne'] ?? 0 ),
						'nul'    => (int) ( $c['nul'] ?? 0 ),
						'perdu'  => (int) ( $c['perdu'] ?? 0 ),
						'bp'     => (int) ( $c['butPlus'] ?? 0 ),
						'bc'     => (int) ( $c['butMoins'] ?? 0 ),
						'diff'   => (int) ( $c['diff'] ?? 0 ),
						'forme'  => (string) ( $c['dernieresRencontres'] ?? '' ),
						'logo'   => self::logo_stem( $c['structure_logo'] ?? '', $c['equipe_logoActif'] ?? '1' ),
						'vhb'    => (string) ( $c['equipe_libelle'] ?? '' ) === $libelle,
					);
				},
				$classement
			);
			usort(
				$classement,
				static function ( $a, $b ) {
					return $a['place'] <=> $b['place'];
				}
			);
		}

		if ( empty( $rencontres ) ) {
			$this->warnings[] = 'Aucune rencontre dans la page FFHB.';
		}

		return array(
			'slug'             => $cfg['slug'],
			'equipe'           => $cfg['nom'],
			'libelle'          => $libelle,
			'competition'      => (string) ( $phase['libelle'] ?? '' ),
			'poule'            => (string) ( $poule['libelle'] ?? '' ),
			'classement_actif' => $classement_actif,
			'entente'          => ! empty( $cfg['entente'] ),
			'publie'           => true,
			'page'             => $url,
			'ics'              => $this->ics_url( $comp ),
			'poule_url'        => ! empty( $poule['ext_pouleId'] ) ? substr( $url, 0, strpos( $url, '/equipe-' ) ) . '/poule-' . $poule['ext_pouleId'] . '/' : '',
			'lieux_ok'         => is_array( $lieux ),
			'rencontres'       => $rencontres,
			'classement'       => $classement, // null = échec de récupération, à ne pas écraser
			'maj'              => ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'c' ),
		);
	}

	/**
	 * Lignes brutes de classement de notre poule.
	 *
	 * @return array|null null si la vue poule est injoignable
	 */
	private function classement( array $d, $poule_int, array $poule, $url ) {
		$rows = array_values(
			array_filter(
				(array) ( $d['classements'] ?? array() ),
				static function ( $c ) use ( $poule_int ) {
					return ! isset( $c['pouleId'] ) || (string) $c['pouleId'] === $poule_int;
				}
			)
		);
		if ( $rows ) {
			return $rows;
		}
		if ( empty( $poule['ext_pouleId'] ) ) {
			return array();
		}
		$base = substr( $url, 0, strpos( $url, '/equipe-' ) );
		try {
			$p = self::extract_json( $this->get( $base . '/poule-' . $poule['ext_pouleId'] . '/' ) );
		} catch ( VHB_Fetch_Exception $e ) {
			$this->warnings[] = 'Vue poule indisponible, classement conservé : ' . $e->getMessage();
			return null;
		}
		return array_values(
			array_filter(
				(array) ( $p['classements'] ?? array() ),
				static function ( $c ) use ( $poule_int ) {
					return ! isset( $c['pouleId'] ) || (string) $c['pouleId'] === $poule_int;
				}
			)
		);
	}
}
