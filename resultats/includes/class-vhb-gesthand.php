<?php
/**
 * Rencontres Gest'Hand non publiées sur ffhandball.fr (rencontres loisirs).
 *
 * Sources :
 *  - le calendrier : export CSV du module GH Extraction de Gest'Hand
 *    (ou un fichier au même format fait d'après le calendrier provisoire,
 *    dont les codes rencontre commencent par « PROV ») ;
 *  - les résultats : la feuille de match FDME, publique sur le serveur de la
 *    FFHB dès qu'on connaît son code rencontre, téléchargée par le cron après
 *    le match, ou déposée à la main dans l'administration.
 *
 * Les rencontres forment une compétition « gesthand » ajoutée à l'équipe
 * choisie (filtre vhb_ffhb_competitions) : elles s'affichent partout comme
 * celles de la FFHB (accueil, Nos équipes, blocs Résultats, agenda).
 *
 * Données personnelles : statistiques des joueurs du club seulement, prénom et
 * initiale du nom, visibles uniquement dans l'administration. La feuille de
 * match n'est pas proposée au public.
 */

defined( 'ABSPATH' ) || exit;

class VHB_Gesthand {

	const OPTION = 'vhb_gesthand';
	const CLE    = 'gesthand';
	const TYPE   = 'gesthand';

	/** Délai après l'heure du match avant de chercher la feuille (1 h 30). */
	const DELAI_FDM = 5400;
	/** Feuille relue une fois si elle a été lue moins de 48 h après le match (feuille corrigée). */
	const DELAI_FINAL = 2 * DAY_IN_SECONDS;
	/** Feuilles téléchargées au plus par passage du cron. */
	const MAX_TOUR = 6;
	/** Codes voisins essayés au plus par passage (une feuille absente coûte une requête). */
	const MAX_SONDES = 16;

	public static function init() {
		add_filter( 'vhb_ffhb_competitions', array( __CLASS__, 'competitions' ), 10, 2 );
	}

	/* ------------------------------------------------------------------ */
	/* Données                                                             */
	/* ------------------------------------------------------------------ */

	public static function defaults() {
		return array(
			'equipe'     => '',
			'nom'        => 'Rencontres loisirs',
			'libelle'    => '',
			'filtre'     => 'loisir',
			'filles'     => true,
			'blasons'    => array(), // NOM ADVERSAIRE => « stem:… », « media:ID » ou « aucun »
			'rencontres' => array(),
			'resultats'  => array(),
			'stats'      => array(),
			'imports'    => array(),
			'sondes'     => array(), // codes voisins déjà essayés : code => horodatage
		);
	}

	public static function get() {
		$d = get_option( self::OPTION );
		return array_merge( self::defaults(), is_array( $d ) ? $d : array() );
	}

	private static function put( array $d ) {
		if ( false === get_option( self::OPTION ) ) {
			add_option( self::OPTION, $d, '', false );
		} else {
			update_option( self::OPTION, $d, false );
		}
	}

	/** Compétition « Gest'Hand » ajoutée à l'équipe choisie, dès qu'elle a au moins une rencontre. */
	public static function competitions( $comps, $equipe ) {
		$d = self::get();
		if ( '' === $d['equipe'] || ( $equipe['slug'] ?? '' ) !== $d['equipe'] || ! $d['rencontres'] ) {
			return $comps;
		}
		$comps[] = array(
			'url'  => '',
			'nom'  => $d['nom'],
			'cle'  => self::CLE,
			'type' => self::TYPE,
		);
		return $comps;
	}

	private static function club_id() {
		return (string) VHB_Settings::get()['club_id'];
	}

	/** Numéro de club Gest'Hand (6134078) qui se termine par l'identifiant FFHB du club (4078). */
	private static function est_nous( $num ) {
		$id = self::club_id();
		return '' !== $id && '' !== (string) $num && substr( (string) $num, -strlen( $id ) ) === $id;
	}

	private static function upper( $s ) {
		return VHB_Settings::upper( trim( (string) $s ) );
	}

	/** « LA VENE HANDBALL POUSSAN 0 » → « LA VENE HANDBALL POUSSAN » (suffixe d'équipe unique de Gest'Hand). */
	private static function nom_equipe( $s ) {
		return trim( preg_replace( '/\s+0$/', '', trim( (string) $s ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* Import du calendrier (GH Extraction)                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Lit un export CSV de GH Extraction (séparateur « ; », UTF-8 ou Windows-1252).
	 *
	 * @return array<int,array<string,string>> lignes, clés = en-têtes en minuscules
	 * @throws RuntimeException fichier vide ou méconnaissable
	 */
	public static function lire_csv( $raw ) {
		$raw = (string) $raw;
		if ( "\xEF\xBB\xBF" === substr( $raw, 0, 3 ) ) {
			$raw = substr( $raw, 3 );
		}
		if ( ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			$raw = mb_convert_encoding( $raw, 'UTF-8', 'Windows-1252' );
		}
		$h = fopen( 'php://temp', 'r+' ); // phpcs:ignore
		fwrite( $h, $raw ); // phpcs:ignore
		rewind( $h );
		$entete = null;
		$out    = array();
		while ( false !== ( $l = fgetcsv( $h, 0, ';', '"', '' ) ) ) {
			if ( array( null ) === $l ) {
				continue;
			}
			if ( null === $entete ) {
				$entete = array_map(
					static function ( $c ) {
						return strtolower( trim( (string) $c ) );
					},
					$l
				);
				continue;
			}
			$row = array();
			foreach ( $entete as $i => $k ) {
				$row[ $k ] = trim( (string) ( $l[ $i ] ?? '' ) );
			}
			$out[] = $row;
		}
		fclose( $h ); // phpcs:ignore
		if ( ! $entete || ! in_array( 'code renc', $entete, true ) || ! in_array( 'club rec', $entete, true ) ) {
			throw new RuntimeException( 'Ce fichier ne ressemble pas à un export GH Extraction (colonnes « code renc » et « club rec » introuvables).' );
		}
		return $out;
	}

	/** « 01/12/2026 » + « 20:15:00 » → « 2026-12-01 20:15:00 » ; heure inconnue → minuit (heure à venir). */
	private static function date_gh( $jour, $heure ) {
		if ( ! preg_match( '#^(\d{2})/(\d{2})/(\d{4})$#', trim( (string) $jour ), $m ) ) {
			return null;
		}
		$h = preg_match( '#^(\d{1,2}):(\d{2})#', trim( (string) $heure ), $t ) ? sprintf( '%02d:%02d:00', $t[1], $t[2] ) : '00:00:00';
		return sprintf( '%04d-%02d-%02d', $m[3], $m[2], $m[1] ) . ' ' . $h;
	}

	/**
	 * Importe un export GH Extraction : rencontres de la compétition loisirs où joue le club.
	 * Un import officiel remplace les rencontres provisoires de la même période.
	 *
	 * @return array{ajoutes:int,maj:int,retires:int,provisoires_retires:int,ignores:int}
	 * @throws RuntimeException
	 */
	public static function importer( $raw, $fichier = '' ) {
		$rows   = self::lire_csv( $raw );
		$d      = self::get();
		$filtre = self::upper( $d['filtre'] );
		$bilan  = array( 'ajoutes' => 0, 'maj' => 0, 'retires' => 0, 'provisoires_retires' => 0, 'ignores' => 0 );
		$recus  = array();
		foreach ( $rows as $r ) {
			$comp = self::upper( $r['competition'] ?? '' );
			if ( '' !== $filtre && false === strpos( $comp, $filtre ) ) {
				++$bilan['ignores'];
				continue; // autre compétition : déjà récupérée sur ffhandball.fr
			}
			$dom = self::est_nous( $r['num rec'] ?? '' );
			$ext = self::est_nous( $r['num vis'] ?? '' );
			if ( ! $dom && ! $ext ) {
				++$bilan['ignores'];
				continue;
			}
			$code = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $r['code renc'] ?? '' ) ) );
			$date = self::date_gh( $r['le'] ?? '', $r['horaire'] ?? '' );
			$prov = '' === $code || 0 === strpos( $code, 'PROV' );
			if ( '' === $code ) {
				$code = 'PROV' . ( $date ? str_replace( array( '-', ' ', ':' ), '', substr( $date, 0, 10 ) ) : wp_generate_password( 6, false ) );
			}
			$recus[ $code ] = array(
				'code'       => $code,
				'provisoire' => $prov,
				'groupe'     => (string) ( $r['num poule'] ?? '' ),
				'poule'      => (string) ( $r['poule'] ?? '' ),
				'date'       => $date,
				'domicile'   => $dom ? '' : self::nom_equipe( $r['club rec'] ?? '' ),
				'exterieur'  => $ext ? '' : self::nom_equipe( $r['club vis'] ?? '' ),
				'a_domicile' => $dom,
				'nom_gh'     => self::nom_equipe( $dom ? ( $r['club rec'] ?? '' ) : ( $r['club vis'] ?? '' ) ),
				'salle'      => (string) ( $r['nom salle'] ?? '' ),
				'adresse'    => trim( (string) ( $r['adresse salle'] ?? '' ), " ,\t" ),
				'cp'         => (string) ( $r['cp'] ?? '' ),
				'ville'      => (string) ( $r['ville'] ?? '' ),
			);
		}
		if ( ! $recus ) {
			throw new RuntimeException( 'Aucune rencontre loisirs du club dans ce fichier (compétition contenant « ' . $d['filtre'] . ' », numéro de club se terminant par ' . self::club_id() . ').' );
		}

		$auj = wp_date( 'Y-m-d' );
		$jour = static function ( $x ) {
			return $x['date'] ? substr( $x['date'], 0, 10 ) : '';
		};

		// Période couverte par les rencontres officielles du fichier.
		$min = '';
		$max = '';
		foreach ( $recus as $x ) {
			if ( ! $x['provisoire'] && $x['date'] ) {
				$max = max( $max, $jour( $x ) );
				$min = '' === $min ? $jour( $x ) : min( $min, $jour( $x ) );
			}
		}

		// 1. Rencontres du fichier.
		foreach ( $recus as $code => $x ) {
			if ( $x['provisoire'] && self::jour_officiel( array_merge( $d['rencontres'], $recus ), $jour( $x ) ) ) {
				continue; // ce jour a déjà sa rencontre officielle
			}
			if ( isset( $d['rencontres'][ $code ] ) ) {
				// Une date manquante dans Gest'Hand ne doit pas effacer celle déjà connue (feuille, calendrier provisoire).
				if ( ! $x['date'] ) {
					$x['date'] = $d['rencontres'][ $code ]['date'];
				}
				if ( $x !== $d['rencontres'][ $code ] ) {
					++$bilan['maj'];
				}
			} else {
				++$bilan['ajoutes'];
			}
			$d['rencontres'][ $code ] = $x;
		}

		// 2. Rencontre officielle sans date (pas encore jouée, puisqu'elle est dans l'export) : elle prend la date
		// de la prochaine rencontre provisoire contre le même adversaire, au même endroit.
		$prov = array_filter( $d['rencontres'], static function ( $y ) {
			return ! empty( $y['provisoire'] ) && $y['date'];
		} );
		uasort( $prov, static function ( $a, $b ) {
			return strcmp( $a['date'], $b['date'] );
		} );
		foreach ( $d['rencontres'] as $code => $x ) {
			if ( ! empty( $x['provisoire'] ) || $x['date'] ) {
				continue;
			}
			foreach ( $prov as $k => $y ) {
				if ( isset( $d['rencontres'][ $k ] ) && $jour( $y ) >= $auj && (bool) $y['a_domicile'] === (bool) $x['a_domicile'] && self::meme_adversaire( $x, $y ) ) {
					$d['rencontres'][ $code ]['date'] = $jour( $y ) . ' 00:00:00';
					if ( '' === (string) $x['salle'] ) {
						foreach ( array( 'salle', 'adresse', 'cp', 'ville' ) as $k2 ) {
							$d['rencontres'][ $code ][ $k2 ] = $y[ $k2 ];
						}
					}
					unset( $d['rencontres'][ $k ] );
					++$bilan['provisoires_retires'];
					break;
				}
			}
		}

		// 3. Rencontres provisoires remplacées : même jour qu'une officielle, ou à venir sur la période du calendrier officiel.
		foreach ( $d['rencontres'] as $code => $x ) {
			if ( empty( $x['provisoire'] ) ) {
				continue;
			}
			$j = $jour( $x );
			if ( self::jour_officiel( $d['rencontres'], $j ) || ( '' !== $max && '' !== $j && $j >= $auj && $j >= $min && $j <= $max ) ) {
				unset( $d['rencontres'][ $code ] );
				++$bilan['provisoires_retires'];
			}
		}

		// 4. Rencontre officielle à venir disparue de Gest'Hand sur la période du fichier : annulée ou déplacée.
		// (Les rencontres jouées sortent de l'export GH Extraction : elles restent.)
		foreach ( $d['rencontres'] as $code => $x ) {
			$j = $jour( $x );
			if ( empty( $x['provisoire'] ) && ! isset( $recus[ $code ] ) && '' !== $j && $j >= $auj && '' !== $min && $j >= $min && $j <= $max && ! isset( $d['resultats'][ $code ]['score1'] ) ) {
				unset( $d['rencontres'][ $code ] );
				++$bilan['retires'];
			}
		}
		array_unshift(
			$d['imports'],
			array(
				'date'    => ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'c' ),
				'fichier' => sanitize_file_name( (string) $fichier ),
				'bilan'   => $bilan,
			)
		);
		$d['imports'] = array_slice( $d['imports'], 0, 10 );
		self::put( $d );
		self::publier();
		return $bilan;
	}

	/** « SALLE DU BICENTENAIRE » → « Salle du Bicentenaire ». */
	private static function titre( $s ) {
		$s = mb_convert_case( mb_strtolower( trim( (string) $s ), 'UTF-8' ), MB_CASE_TITLE, 'UTF-8' );
		$s = preg_replace_callback(
			'/(?<=\s)(De|Du|Des|La|Le|Les|Et|Sur)(?=\s)/u',
			static function ( $m ) {
				return mb_strtolower( $m[1], 'UTF-8' );
			},
			$s
		);
		return preg_replace_callback(
			"/(?<=\\s)([DL])'(\\p{L})/u",
			static function ( $m ) {
				return mb_strtolower( $m[1], 'UTF-8' ) . "'" . mb_strtoupper( $m[2], 'UTF-8' );
			},
			$s
		);
	}

	/* ------------------------------------------------------------------ */
	/* Blasons                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Clubs déjà rencontrés par les équipes FFHB du club, avec leur blason :
	 * NOM FFHB => identifiant du blason (rencontres et classements).
	 *
	 * @return array<string,string>
	 */
	public static function clubs_connus() {
		$out = array();
		foreach ( (array) VHB_Store::get()['equipes'] as $e ) {
			foreach ( (array) ( $e['competitions'] ?? array() ) as $cle => $c ) {
				if ( self::CLE === (string) $cle ) {
					continue;
				}
				foreach ( (array) ( $c['rencontres'] ?? array() ) as $r ) {
					foreach ( array( 1 => 'domicile', 2 => 'exterieur' ) as $i => $k ) {
						$moi = 1 === $i ? ! empty( $r['a_domicile'] ) : empty( $r['a_domicile'] );
						if ( ! $moi && ! empty( $r[ 'logo' . $i ] ) ) {
							$out[ (string) $r[ $k ] ] = (string) $r[ 'logo' . $i ];
						}
					}
				}
				foreach ( (array) ( $c['classement'] ?? array() ) as $l ) {
					if ( empty( $l['vhb'] ) && ! empty( $l['logo'] ) ) {
						$out[ (string) $l['equipe'] ] = (string) $l['logo'];
					}
				}
			}
		}
		ksort( $out );
		return $out;
	}

	/** Blason du club dans les rencontres FFHB (le plus fréquent de notre côté). */
	public static function notre_blason() {
		$n = array();
		foreach ( (array) VHB_Store::get()['equipes'] as $e ) {
			foreach ( (array) ( $e['competitions'] ?? array() ) as $cle => $c ) {
				if ( self::CLE === (string) $cle ) {
					continue;
				}
				foreach ( (array) ( $c['rencontres'] ?? array() ) as $r ) {
					$s = (string) ( ! empty( $r['a_domicile'] ) ? ( $r['logo1'] ?? '' ) : ( $r['logo2'] ?? '' ) );
					if ( '' !== $s ) {
						$n[ $s ] = ( $n[ $s ] ?? 0 ) + 1;
					}
				}
			}
		}
		arsort( $n );
		return $n ? (string) key( $n ) : '';
	}

	/** Club FFHB qui ressemble le plus à un adversaire loisirs (mot significatif en commun), ou ''. */
	public static function devine( $adv, array $connus ) {
		$best = '';
		$sc   = 0;
		foreach ( array_keys( $connus ) as $nom ) {
			$m = count( array_intersect_key( self::mots( $adv ), self::mots( $nom ) ) );
			if ( $m > $sc || ( $m === $sc && $m && strlen( $nom ) < strlen( $best ) ) ) {
				$sc   = $m;
				$best = $nom;
			}
		}
		return $sc ? $best : '';
	}

	/**
	 * Blason d'un adversaire : choix enregistré dans l'administration, sinon club FFHB ressemblant.
	 *
	 * @return string identifiant pour VHB_Logos::url() (« media-ID » pour la médiathèque), '' si aucun
	 */
	public static function blason_de( $adv, array $connus, array $choix ) {
		$c = (string) ( $choix[ self::upper( $adv ) ] ?? '' );
		if ( 'aucun' === $c ) {
			return '';
		}
		if ( preg_match( '/^media:(\d+)$/', $c, $m ) ) {
			return 'media-' . $m[1];
		}
		if ( 0 === strpos( $c, 'club:' ) ) {
			return (string) ( $connus[ substr( $c, 5 ) ] ?? '' );
		}
		$g = self::devine( $adv, $connus );
		return '' !== $g ? $connus[ $g ] : '';
	}

	/** Adversaires de la saison, dans l'ordre alphabétique. */
	public static function adversaires() {
		$out = array();
		foreach ( self::get()['rencontres'] as $r ) {
			$a = $r['a_domicile'] ? $r['exterieur'] : $r['domicile'];
			if ( '' !== $a ) {
				$out[ self::upper( $a ) ] = $a;
			}
		}
		ksort( $out );
		return $out;
	}

	/** Notre nom le plus fréquent dans Gest'Hand, sans numéro d'équipe (« VILLENEUVE LOISIRS 1 » → « VILLENEUVE LOISIRS »). */
	private static function notre_nom( array $rencontres, $defaut ) {
		$n = array();
		foreach ( $rencontres as $r ) {
			$k = trim( preg_replace( '/\s+\d+$/', '', (string) ( $r['nom_gh'] ?? '' ) ) );
			if ( '' !== $k ) {
				$n[ $k ] = ( $n[ $k ] ?? 0 ) + 1;
			}
		}
		arsort( $n );
		return $n ? (string) key( $n ) : $defaut;
	}

	/** Une rencontre officielle (non provisoire) a-t-elle lieu ce jour-là ? */
	private static function jour_officiel( array $rencontres, $jour ) {
		if ( '' === $jour ) {
			return false;
		}
		foreach ( $rencontres as $y ) {
			if ( empty( $y['provisoire'] ) && $y['date'] && substr( $y['date'], 0, 10 ) === $jour ) {
				return true;
			}
		}
		return false;
	}

	/** Même adversaire : un mot significatif en commun (« CLERMONT LOISIRS » et « CLERMONT L'HERAULT »). */
	private static function meme_adversaire( array $a, array $b ) {
		return (bool) array_intersect_key(
			self::mots( $a['a_domicile'] ? $a['exterieur'] : $a['domicile'] ),
			self::mots( $b['a_domicile'] ? $b['exterieur'] : $b['domicile'] )
		);
	}

	/** Mots significatifs d'un nom de club (6 premières lettres) : « RC MONTAGNACOIS HB 2 » → MONTAG. */
	private static function mots( $nom ) {
		$out = array();
		foreach ( preg_split( '/[^\p{L}]+/u', self::upper( $nom ) ) as $w ) {
			if ( mb_strlen( $w ) >= 4 && ! in_array( $w, array( 'HANDBALL', 'LOISIRS', 'LOISIR', 'CLUB', 'SPORT', 'SPORTS', 'HAND' ), true ) ) {
				$out[ mb_substr( $w, 0, 6 ) ] = true;
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Feuilles de match                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Enregistre le contenu d'une feuille de match lue.
	 *
	 * @param array $f       résultat de VHB_Fdm_Lecture::lire()
	 * @param bool  $creer   créer la rencontre si elle n'est pas au calendrier (dépôt manuel)
	 * @return string code de la rencontre
	 * @throws RuntimeException feuille d'une autre compétition, ou d'un match sans le club
	 */
	public static function enregistrer_fdm( array $f, $creer = false ) {
		$d    = self::get();
		$code = $f['code'];
		if ( ! isset( $d['rencontres'][ $code ] ) ) {
			if ( ! $creer ) {
				throw new RuntimeException( 'Rencontre ' . $code . ' absente du calendrier.' );
			}
			$filtre = self::upper( $d['filtre'] );
			if ( '' !== $filtre && false === strpos( self::upper( $f['competition'] ), $filtre ) ) {
				throw new RuntimeException( 'Cette feuille est celle d\'une autre compétition (' . $f['competition'] . ') : ses résultats viennent déjà de ffhandball.fr.' );
			}
			if ( ! $f['joueurs'] ) {
				throw new RuntimeException( 'Aucun joueur du club sur cette feuille (numéro de club ' . self::club_id() . ').' );
			}
			// Côté du club : le tableau où figurent nos joueurs (recevant ou visiteur).
			$dom = 'domicile' === $f['nous'];
			$d['rencontres'][ $code ] = array(
				'code'       => $code,
				'provisoire' => false,
				'groupe'     => $f['groupe'],
				'poule'      => '',
				'date'       => $f['date'],
				'domicile'   => $dom ? '' : self::nom_equipe( $f['domicile'] ),
				'exterieur'  => $dom ? self::nom_equipe( $f['exterieur'] ) : '',
				'a_domicile' => $dom,
				'nom_gh'     => self::nom_equipe( $dom ? $f['domicile'] : $f['exterieur'] ),
				'salle'      => $f['salle'],
				'adresse'    => '',
				'cp'         => '',
				'ville'      => '',
			);
			// Un provisoire du même jour est remplacé.
			foreach ( $d['rencontres'] as $k => $y ) {
				if ( ! empty( $y['provisoire'] ) && $y['date'] && $f['date'] && substr( $y['date'], 0, 10 ) === substr( $f['date'], 0, 10 ) ) {
					unset( $d['rencontres'][ $k ] );
				}
			}
		}
		$r = &$d['rencontres'][ $code ];
		if ( $f['date'] ) {
			$r['date'] = $f['date']; // la feuille fait foi (heure réelle, match sans date dans Gest'Hand)
		}
		$ts    = VHB_Render::ts( $r['date'] );
		$avait = $d['resultats'][ $code ] ?? array();
		$d['resultats'][ $code ] = array(
			'score1' => $f['score1'],
			'score2' => $f['score2'],
			'tt'     => $f['tt'],
			'statut' => $f['statut'],
			'lu'     => time(),
			'final'  => ! empty( $avait['manuel'] ) || ( $ts ? time() >= $ts + self::DELAI_FINAL : true ),
			'manuel' => ! empty( $avait['manuel'] ),
		);
		$d['stats'][ $code ] = array_map(
			static function ( $j ) {
				return array_intersect_key( $j, array_flip( array( 'nom', 'numero', 'capitaine', 'fille', 'buts', 'buts_fdm', '7m', 'tirs', 'arrets', 'av', '2min', 'dis' ) ) );
			},
			(array) $f['joueurs']
		);
		unset( $r );
		self::put( $d );
		return $code;
	}

	/** Dépôt manuel d'une feuille (PDF) depuis l'administration. */
	public static function deposer( $pdf ) {
		$d = self::get();
		$f = VHB_Fdm_Lecture::lire( $pdf, self::club_id(), ! empty( $d['filles'] ) );
		if ( null === $f['score1'] ) {
			throw new RuntimeException( 'La feuille ' . $f['code'] . ' ne porte pas encore de score.' );
		}
		$code = self::enregistrer_fdm( $f, true );
		$d    = self::get();
		$d['resultats'][ $code ]['manuel'] = true;
		$d['resultats'][ $code ]['final']  = true;
		self::put( $d );
		self::publier();
		return $code;
	}

	/**
	 * Cherche les feuilles des matchs passés sur le serveur de la FFHB.
	 *
	 * @return array{lues:int,absentes:int,erreurs:array<string,string>}
	 */
	public static function recuperer( VHB_Fetcher $fetcher, $max = self::MAX_TOUR ) {
		$d     = self::get();
		$bilan = array( 'lues' => 0, 'absentes' => 0, 'erreurs' => array() );
		$tries = 0;
		foreach ( $d['rencontres'] as $code => $r ) {
			if ( ! empty( $r['provisoire'] ) || ! VHB_Fdm::valid( $code ) ) {
				continue;
			}
			$res = $d['resultats'][ $code ] ?? array();
			$ts  = VHB_Render::ts( $r['date'] );
			if ( ! empty( $res['final'] ) ) {
				continue;
			}
			if ( $ts && time() < $ts + self::DELAI_FDM ) {
				continue; // pas encore joué
			}
			if ( ! empty( $res['lu'] ) && time() - (int) $res['lu'] < 12 * HOUR_IN_SECONDS ) {
				continue; // déjà lue récemment, on la relira une fois après 48 h
			}
			if ( ! empty( $res['absent'] ) ) {
				if ( time() - (int) $res['absent'] < 6 * HOUR_IN_SECONDS ) {
					continue;
				}
				if ( $ts && time() > $ts + 21 * DAY_IN_SECONDS ) {
					continue; // feuille jamais publiée : dépôt manuel
				}
			}
			if ( $tries++ >= $max ) {
				break;
			}
			try {
				$pdf = $fetcher->get( VHB_Fdm::remote_url( $code ), 1.0 );
				$f   = VHB_Fdm_Lecture::lire( $pdf, self::club_id(), ! empty( $d['filles'] ) );
				if ( $f['code'] !== $code || null === $f['score1'] ) {
					throw new RuntimeException( 'feuille sans score' );
				}
				self::enregistrer_fdm( $f );
				++$bilan['lues'];
			} catch ( Throwable $e ) {
				$d2 = self::get();
				if ( empty( $d2['resultats'][ $code ]['score1'] ) ) {
					$d2['resultats'][ $code ] = array_merge( $d2['resultats'][ $code ] ?? array(), array( 'absent' => time() ) );
					self::put( $d2 );
				}
				if ( $e instanceof VHB_Fetch_Exception && 404 === $e->getCode() ) {
					++$bilan['absentes'];
				} else {
					$bilan['erreurs'][ $code ] = $e->getMessage();
				}
			}
			$d = self::get();
		}
		if ( $tries < $max ) {
			$bilan['trouvees'] = self::sonder( $fetcher, self::MAX_SONDES );
			$bilan['lues']    += $bilan['trouvees'];
		}
		return $bilan;
	}

	/**
	 * Ajoute une rencontre jouée à partir de son code (Gest'Hand), absente de l'export
	 * GH Extraction parce qu'elle est déjà jouée : la feuille est lue sur le serveur de la FFHB.
	 *
	 * @throws RuntimeException
	 */
	public static function ajouter_code( $code, VHB_Fetcher $fetcher ) {
		$code = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $code ) );
		if ( ! VHB_Fdm::valid( $code ) || 0 === strpos( $code, 'PROV' ) ) {
			throw new RuntimeException( 'Code rencontre invalide (ex. WAHAPQE).' );
		}
		try {
			$pdf = $fetcher->get( VHB_Fdm::remote_url( $code ), 1.0 );
		} catch ( VHB_Fetch_Exception $e ) {
			throw new RuntimeException( 404 === $e->getCode() ? 'La FFHB n\'a pas (encore) publié la feuille ' . $code . '. Déposez le PDF si vous l\'avez.' : $e->getMessage() );
		}
		$d = self::get();
		$f = VHB_Fdm_Lecture::lire( $pdf, self::club_id(), ! empty( $d['filles'] ) );
		if ( null === $f['score1'] ) {
			throw new RuntimeException( 'La feuille ' . $code . ' ne porte pas encore de score.' );
		}
		self::enregistrer_fdm( $f, true );
		self::publier();
		return $code;
	}

	/** « WAHAPQF » + 1 = « WAHAPQG » (codes FDME en lettres, en base 26). */
	private static function voisin( $code, $pas ) {
		if ( ! preg_match( '/^[A-Z]+$/', $code ) ) {
			return '';
		}
		$c = str_split( $code );
		$i = count( $c ) - 1;
		$r = (int) $pas;
		while ( 0 !== $r && $i >= 0 ) {
			$v      = ord( $c[ $i ] ) - 65 + $r;
			$r      = (int) floor( $v / 26 );
			$c[ $i ] = chr( 65 + ( ( $v % 26 ) + 26 ) % 26 );
			--$i;
		}
		return 0 === $r ? implode( '', $c ) : '';
	}

	/**
	 * Les matchs déjà joués sortent de l'export GH Extraction. Tant qu'une rencontre provisoire
	 * passée n'a pas de résultat, on essaie les codes voisins des rencontres connues (Gest'Hand
	 * numérote les rencontres d'une compétition à la suite) : une feuille de la compétition où
	 * jouent nos joueurs est ajoutée.
	 *
	 * @return int feuilles trouvées
	 */
	private static function sonder( VHB_Fetcher $fetcher, $max ) {
		$d     = self::get();
		$auj   = wp_date( 'Y-m-d' );
		$reste = false;
		foreach ( $d['rencontres'] as $r ) {
			if ( ! empty( $r['provisoire'] ) && $r['date'] && substr( $r['date'], 0, 10 ) < $auj ) {
				$reste = true;
			}
		}
		if ( ! $reste ) {
			return 0;
		}
		$connus = array();
		foreach ( array_keys( $d['rencontres'] ) as $code ) {
			if ( 0 !== strpos( $code, 'PROV' ) ) {
				$connus[ $code ] = true;
			}
		}
		$cand = array();
		// Du plus proche au plus lointain, tous codes confondus.
		foreach ( array( -1, 1, -2, 2, -3, 3, -4, 4 ) as $p ) {
			foreach ( array_keys( $connus ) as $code ) {
				$v = self::voisin( $code, $p );
				if ( '' === $v || isset( $connus[ $v ] ) || isset( $cand[ $v ] ) ) {
					continue;
				}
				if ( isset( $d['sondes'][ $v ] ) && time() - (int) $d['sondes'][ $v ] < 7 * DAY_IN_SECONDS ) {
					continue;
				}
				$cand[ $v ] = true;
			}
		}
		$trouvees = 0;
		$n        = 0;
		foreach ( array_keys( $cand ) as $v ) {
			if ( $n++ >= $max ) {
				break;
			}
			$d                  = self::get();
			$d['sondes'][ $v ] = time();
			self::put( $d );
			try {
				$pdf = $fetcher->get( VHB_Fdm::remote_url( $v ), 0.5 );
				$f   = VHB_Fdm_Lecture::lire( $pdf, self::club_id(), ! empty( $d['filles'] ) );
				if ( null === $f['score1'] || ! $f['joueurs'] || $f['code'] !== $v ) {
					continue;
				}
				self::enregistrer_fdm( $f, true );
				++$trouvees;
			} catch ( Throwable $e ) {
				continue; // pas de feuille, ou une autre compétition
			}
		}
		return $trouvees;
	}

	/* ------------------------------------------------------------------ */
	/* Compétition pour l'affichage                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Données de la compétition, au format de VHB_Fetcher::team().
	 *
	 * @param bool $tout vrai pour l'administration : garde les matchs passés sans résultat
	 *                   (feuille pas encore lue), qui ne doivent pas s'afficher « à venir » sur le site.
	 */
	public static function comp_data( array $cfg, $tout = false ) {
		$d      = self::get();
		$salles = VHB_Settings::salles();
		$lib    = '' !== trim( $d['libelle'] ) ? trim( $d['libelle'] ) : self::notre_nom( $d['rencontres'], $cfg['nom'] );
		$renc   = array();
		$connus = self::clubs_connus();
		$notre  = self::notre_blason();
		foreach ( $d['rencontres'] as $code => $r ) {
			$adv   = $r['a_domicile'] ? $r['exterieur'] : $r['domicile'];
			$bl    = self::blason_de( $adv, $connus, $d['blasons'] );
			$res   = $d['resultats'][ $code ] ?? array();
			$s1    = isset( $res['score1'] ) && '' !== (string) $res['score1'] ? (string) $res['score1'] : null;
			$s2    = isset( $res['score2'] ) && '' !== (string) $res['score2'] ? (string) $res['score2'] : null;
			$joue  = null !== $s1 && null !== $s2;
			$forf  = $joue && false !== stripos( (string) ( $res['statut'] ?? '' ), 'forfait' );
			$cle   = self::upper( $r['salle'] );
			$salle = $salles[ $cle ] ?? null;
			$lieu  = $salle ? $salle['nom'] : ( '' !== $r['salle'] ? self::titre( $r['salle'] ) : '' );
			$adr   = $salle ? $salle['adresse'] : trim( $r['adresse'] . ( '' !== $r['cp'] . $r['ville'] ? ', ' . trim( $r['cp'] . ' ' . self::titre( $r['ville'] ) ) : '' ), ' ,' );
			$renc[] = array(
				'id'            => $code,
				'journee'       => 0,
				'journee_debut' => '',
				'journee_fin'   => '',
				'date'          => $r['date'] && VHB_Render::ts( $r['date'] ) ? wp_date( 'c', VHB_Render::ts( $r['date'] ) ) : '', // ISO 8601 comme la FFHB (Safari refuse « 2026-11-03 20:15 »)
				'domicile'      => $r['a_domicile'] ? $lib : $r['domicile'],
				'exterieur'     => $r['a_domicile'] ? $r['exterieur'] : $lib,
				'score1'        => $s1,
				'score2'        => $s2,
				'joue'          => $joue,
				'forfait'       => $forf,
				'forfait_de'    => '',
				'a_domicile'    => (bool) $r['a_domicile'],
				'logo1'         => $r['a_domicile'] ? $notre : $bl,
				'logo2'         => $r['a_domicile'] ? $bl : $notre,
				'lieu'          => $lieu,
				'adresse'       => $adr,
				'lat'           => $salle ? $salle['lat'] : null,
				'lon'           => $salle ? $salle['lon'] : null,
				'url'           => '',
				'phase'         => '',
				// Feuille publique du serveur de la FFHB (jamais celle déposée à la main), selon le réglage « Feuilles » de l'équipe.
				'fdm'           => $joue && empty( $r['provisoire'] ) && 0 !== strpos( (string) $code, 'PROV' ) && VHB_Fdm::valid( (string) $code ) ? (string) $code : '',
				'tt'            => (array) ( $res['tt'] ?? array() ),
				'provisoire'    => ! empty( $r['provisoire'] ),
			);
		}
		// Journée = rang chronologique du match dans la saison (« notre 3e match »).
		usort(
			$renc,
			static function ( $a, $b ) {
				$ta = VHB_Render::ts( $a['date'] );
				$tb = VHB_Render::ts( $b['date'] );
				if ( $ta && $tb ) {
					return $ta <=> $tb;
				}
				return $ta ? -1 : ( $tb ? 1 : 0 );
			}
		);
		foreach ( $renc as $i => &$r ) {
			$r['journee'] = $i + 1;
		}
		unset( $r );
		if ( ! $tout ) {
			$renc = array_values(
				array_filter(
					$renc,
					static function ( $r ) {
						$ts = VHB_Render::ts( $r['date'] );
						return $r['joue'] || ! $ts || time() < $ts + DAY_IN_SECONDS;
					}
				)
			);
		}
		return array(
			'slug'             => $cfg['slug'],
			'equipe'           => $cfg['nom'],
			'libelle'          => $lib,
			'competition'      => $d['nom'],
			'poule'            => '',
			'classement_actif' => false,
			'entente'          => ! empty( $cfg['entente'] ),
			'publie'           => true,
			'page'             => '',
			'ics'              => '',
			'poule_url'        => '',
			'lieux_ok'         => true,
			'rencontres'       => $renc,
			'classement'       => array(),
			'maj'              => ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'c' ),
		);
	}

	/** Recalcule la compétition dans la copie locale des résultats et vide le cache des pages. */
	public static function publier() {
		$d   = self::get();
		$cfg = '' !== $d['equipe'] ? VHB_Settings::equipe( $d['equipe'] ) : null;
		if ( ! $cfg ) {
			return false;
		}
		VHB_Store::save_comp( $cfg['slug'], self::CLE, self::comp_data( $cfg ) );
		VHB_Cache::flush_pages();
		do_action( 'vhb_gesthand_publie' );
		return true;
	}

	/** Passage du cron pour la compétition Gest'Hand d'une équipe. */
	public static function cron( array $cfg, VHB_Fetcher $fetcher ) {
		$bilan = self::recuperer( $fetcher );
		$data  = self::comp_data( $cfg );
		VHB_Store::save_comp( $cfg['slug'], self::CLE, $data );
		if ( 'copie' === ( $cfg['fdm'] ?? 'copie' ) ) {
			VHB_Fdm::ensure( $data, $fetcher ); // copie hébergée par le site, comme pour les matchs FFHB
		}
		return $bilan;
	}

	/* ------------------------------------------------------------------ */
	/* Statistiques (administration)                                       */
	/* ------------------------------------------------------------------ */

	/** Totaux de la saison par joueur, du plus grand nombre de buts au plus petit. */
	public static function totaux() {
		$d   = self::get();
		$out = array();
		foreach ( $d['stats'] as $code => $joueurs ) {
			foreach ( (array) $joueurs as $j ) {
				$k = $j['nom'];
				if ( ! isset( $out[ $k ] ) ) {
					$out[ $k ] = array( 'nom' => $k, 'fille' => ! empty( $j['fille'] ), 'matchs' => 0, 'buts' => 0, 'tirs' => 0, '7m' => 0, 'arrets' => 0, '2min' => 0, 'av' => 0, 'dis' => 0, 'numeros' => array() );
				}
				$t = &$out[ $k ];
				++$t['matchs'];
				foreach ( array( 'buts', 'tirs', '7m', 'arrets', '2min', 'av', 'dis' ) as $c ) {
					$t[ $c ] += (int) ( $j[ $c ] ?? 0 );
				}
				if ( '' !== (string) ( $j['numero'] ?? '' ) ) {
					$t['numeros'][ (string) $j['numero'] ] = true;
				}
				unset( $t );
			}
		}
		uasort(
			$out,
			static function ( $a, $b ) {
				return array( $b['buts'], $b['arrets'], $a['nom'] ) <=> array( $a['buts'], $a['arrets'], $b['nom'] );
			}
		);
		return array_values( $out );
	}

	/* ------------------------------------------------------------------ */
	/* Export et import de l'extension                                     */
	/* ------------------------------------------------------------------ */

	/** Pour l'export JSON : calendrier, résultats et réglages, sans les statistiques nominatives. */
	public static function export() {
		$d = self::get();
		unset( $d['stats'] );
		return $d;
	}

	public static function import( array $o ) {
		$d = array_merge( self::defaults(), array_intersect_key( $o, self::defaults() ) );
		$d['stats'] = self::get()['stats'];
		self::put( $d );
		self::publier();
	}

	public static function clear() {
		delete_option( self::OPTION );
	}
}
