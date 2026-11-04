<?php
/**
 * Lecture d'une feuille de match FDME (PDF) : en-tête, score, tiers-temps,
 * statistiques des joueurs de NOTRE club.
 *
 * Données personnelles : on ne garde des joueurs que le prénom et l'initiale
 * du nom, le numéro de maillot et les statistiques. Jamais les numéros de
 * licence, jamais les joueurs adverses, jamais les blessures ni les officiels.
 */

defined( 'ABSPATH' ) || exit;

class VHB_Fdm_Lecture {

	/** Colonnes des tableaux joueurs, telles qu'écrites dans l'en-tête. */
	const COLONNES = array(
		'buts'   => 'Buts',
		'7m'     => '7',
		'tirs'   => 'Tirs',
		'arrets' => 'Arrets',
		'av'     => 'Av.',
		'2min'   => "2'",
		'dis'    => 'Dis',
	);

	/**
	 * @param string $pdf     contenu du fichier
	 * @param string $club_id identifiant FFHB du club (ex. 4078) : fin du code club des licences
	 * @param bool   $double  vrai en loisir : un but féminin compte double sur la feuille
	 * @return array
	 * @throws RuntimeException fichier illisible ou qui n'est pas une feuille de match
	 */
	public static function lire( $pdf, $club_id, $double = true ) {
		$doc   = new VHB_Pdf( $pdf );
		$pages = $doc->text();
		if ( ! $pages || ! $pages[0] ) {
			throw new RuntimeException( 'Aucun texte lisible dans ce PDF.' );
		}
		$p0   = $pages[0];
		$rows = self::rows( $p0 );
		$out  = array(
			'code'        => self::right_of( $p0, 'Code Renc' ),
			'groupe'      => self::right_of( $p0, 'Groupe' ),
			'competition' => self::right_of( $p0, 'Compétition' ),
			'date'        => null,
			'domicile'    => '',
			'exterieur'   => '',
			'score1'      => null,
			'score2'      => null,
			'tt'          => array(),
			'statut'      => '',
			'salle'       => self::right_of( $p0, 'Salle' ),
			'joueurs'     => array(),
			'nous'        => '', // « domicile » ou « exterieur » : tableau où figurent nos joueurs
		);
		if ( ! preg_match( '/^[A-Z0-9]{4,16}$/', $out['code'] ) ) {
			throw new RuntimeException( 'Ce PDF ne ressemble pas à une feuille de match FDME (code rencontre introuvable).' );
		}

		// Date : « mardi 03/11/2026 20:15 ».
		if ( preg_match( '#(\d{2})/(\d{2})/(\d{4})(?:\s+(\d{1,2}):(\d{2}))?#', self::right_of( $p0, 'Date' ), $m ) ) {
			$out['date'] = sprintf( '%04d-%02d-%02d %02d:%02d:00', $m[3], $m[2], $m[1], $m[4] ?? 0, $m[5] ?? 0 );
		}

		// Ligne « RECEVANT / VISITEUR  27  29 », en haut de la première page.
		foreach ( $rows as $r ) {
			$txt = array_values( array_filter( $r, static function ( $it ) {
				return false !== strpos( $it['t'], ' / ' );
			} ) );
			if ( ! $txt ) {
				continue;
			}
			list( $a, $b )      = array_map( 'trim', explode( ' / ', $txt[0]['t'], 2 ) );
			$out['domicile']    = $a;
			$out['exterieur']   = $b;
			$nums               = array_values( array_filter( $r, static function ( $it ) use ( $txt ) {
				return $it['x'] > $txt[0]['x'] && preg_match( '/^\d{1,3}$/', $it['t'] );
			} ) );
			if ( count( $nums ) >= 2 ) {
				$out['score1'] = $nums[0]['t'];
				$out['score2'] = $nums[1]['t'];
			}
			break;
		}

		// Détail du score : « Période 1 / Période 2 / Fin » puis REC VIS…, puis les nombres (cumulés).
		$grille = array();
		foreach ( $rows as $i => $r ) {
			if ( self::find( $r, 'Période 1' ) ) {
				for ( $k = $i + 1; $k <= $i + 3; $k++ ) {
					if ( ! isset( $rows[ $k ] ) ) {
						break;
					}
					$n = array_values( array_filter( $rows[ $k ], static function ( $it ) {
						return preg_match( '/^\d{1,3}$/', $it['t'] );
					} ) );
					if ( count( $n ) >= 4 ) {
						$grille = array_map( 'intval', wp_list_pluck( $n, 't' ) );
						break;
					}
				}
				break;
			}
		}

		// Statut (« Joué ») et tiers-temps saisis dans « Colle et Résine » (« 1r TT : 8 - 7 »).
		$tt_texte = array();
		foreach ( $pages as $items ) {
			foreach ( self::rows( $items ) as $r ) {
				$ligne = implode( ' ', wp_list_pluck( $r, 't' ) );
				if ( preg_match( '/Statut Match\s*:\s*(.+)$/u', $ligne, $m ) ) {
					$out['statut'] = trim( $m[1] );
				}
				if ( preg_match( '/^\s*(\d)?\s*(?:r|er|re|e|ème|eme)?\s*TT\s*:\s*(\d+)\s*-\s*(\d+)/iu', $ligne, $m ) ) {
					$tt_texte[] = array( (int) $m[2], (int) $m[3] );
				}
			}
		}
		if ( $tt_texte ) {
			$out['tt'] = $tt_texte;
		} elseif ( count( $grille ) >= 6 ) {
			// Grille cumulée : fin du 1er tiers, fin du 2e, fin du match.
			$out['tt'] = array(
				array( $grille[0], $grille[1] ),
				array( $grille[2] - $grille[0], $grille[3] - $grille[1] ),
				array( $grille[4] - $grille[2], $grille[5] - $grille[3] ),
			);
		}
		if ( '' === $out['statut'] && null !== $out['score1'] ) {
			$out['statut'] = 'Joué';
		}

		$cote           = null;
		$out['joueurs'] = self::joueurs( $rows, (string) $club_id, (bool) $double, $cote );
		$out['nous']    = 0 === $cote ? 'domicile' : ( 1 === $cote ? 'exterieur' : '' );
		return $out;
	}

	/** Éléments regroupés par ligne (même hauteur à 2 points près), triés de haut en bas puis de gauche à droite. */
	private static function rows( array $items ) {
		usort( $items, static function ( $a, $b ) {
			return array( $a['y'], $a['x'] ) <=> array( $b['y'], $b['x'] );
		} );
		$rows = array();
		$cur  = array();
		$y    = null;
		foreach ( $items as $it ) {
			if ( null !== $y && abs( $it['y'] - $y ) > 2.0 ) {
				$rows[] = $cur;
				$cur    = array();
			}
			if ( ! $cur ) {
				$y = $it['y'];
			}
			$cur[] = $it;
		}
		if ( $cur ) {
			$rows[] = $cur;
		}
		foreach ( $rows as &$r ) {
			usort( $r, static function ( $a, $b ) {
				return $a['x'] <=> $b['x'];
			} );
		}
		unset( $r );
		return $rows;
	}

	private static function find( array $row, $label ) {
		foreach ( $row as $it ) {
			if ( $it['t'] === $label ) {
				return $it;
			}
		}
		return null;
	}

	/** Étiquettes de l'en-tête de la feuille : la valeur d'une étiquette s'arrête à la suivante. */
	const ETIQUETTES = array( 'Organisateur', 'Code Renc', 'Compétition', 'Groupe', 'Date', 'Journée', 'Salle' );

	/** Texte situé à droite d'une étiquette, sur la même ligne (jusqu'à l'étiquette suivante). */
	private static function right_of( array $items, $label ) {
		foreach ( self::rows( $items ) as $r ) {
			foreach ( $r as $k => $it ) {
				if ( $it['t'] !== $label ) {
					continue;
				}
				$val = array();
				for ( $i = $k + 1; isset( $r[ $i ] ); $i++ ) {
					if ( in_array( $r[ $i ]['t'], self::ETIQUETTES, true ) ) {
						break;
					}
					$val[] = trim( $r[ $i ]['t'] );
				}
				return trim( implode( ' ', $val ) );
			}
		}
		return '';
	}

	/** « LE PEMP Justine (Né.e X) » → « Justine L. » */
	public static function prenom_initiale( $nom ) {
		$nom    = trim( preg_replace( '/\(.*?\)/u', '', $nom ) );
		$mots   = preg_split( '/\s+/u', $nom );
		$famille = array();
		$prenom  = array();
		foreach ( $mots as $w ) {
			$lettres = preg_replace( '/[^\p{L}]/u', '', $w );
			if ( ! $prenom && '' !== $lettres && mb_strtoupper( $lettres, 'UTF-8' ) === $lettres ) {
				$famille[] = $w;
			} else {
				$prenom[] = $w;
			}
		}
		if ( ! $prenom ) {
			return $nom;
		}
		$p = implode( ' ', $prenom );
		$i = $famille ? mb_substr( preg_replace( '/[^\p{L}]/u', '', $famille[0] ), 0, 1, 'UTF-8' ) : '';
		return trim( $p . ( '' !== $i ? ' ' . mb_strtoupper( $i, 'UTF-8' ) . '.' : '' ) );
	}

	/**
	 * Joueurs de notre club : un tableau par équipe, en-tête « Capt N° NOM… Buts 7 m Tirs… ».
	 * Une joueuse se reconnaît au 8e chiffre de sa licence (2) ; en loisir, ses buts comptent double
	 * sur la feuille (et dans ses tirs) : le nombre réel est la moitié.
	 */
	private static function joueurs( array $rows, $club_id, $double, &$cote = null ) {
		$out    = array();
		$cols   = null;
		$tableau = -1;
		foreach ( $rows as $r ) {
			if ( self::find( $r, 'Capt' ) && self::find( $r, 'Buts' ) ) {
				++$tableau; // 0 : recevant, 1 : visiteur
				$cols = array();
				foreach ( self::COLONNES as $k => $label ) {
					$h = self::find( $r, $label );
					if ( $h ) {
						$cols[ $k ] = $h['x'];
					}
				}
				continue;
			}
			if ( null === $cols ) {
				continue;
			}
			$lic = null;
			foreach ( $r as $it ) {
				if ( preg_match( '/^\d{13}$/', $it['t'] ) ) {
					$lic = $it;
				}
			}
			if ( ! $lic || self::find( $r, 'Officiel Resp. A' ) ) {
				continue;
			}
			foreach ( $r as $it ) {
				if ( 0 === strpos( $it['t'], 'Officiel' ) ) {
					continue 2;
				}
			}
			$club = substr( $lic['t'], 0, 7 );
			if ( '' === $club_id || substr( $club, -strlen( $club_id ) ) !== $club_id ) {
				continue; // joueur adverse : rien n'est gardé
			}
			$num  = '';
			$nom  = '';
			$capt = false;
			$st   = array_fill_keys( array_keys( self::COLONNES ), 0 );
			foreach ( $r as $it ) {
				if ( $it['x'] < $lic['x'] ) {
					if ( 'X' === $it['t'] && '' === $num ) {
						$capt = true;
					} elseif ( '' === $num && preg_match( '/^\d{1,3}$/', $it['t'] ) ) {
						$num = $it['t'];
					} elseif ( preg_match( '/\p{L}{2}/u', $it['t'] ) ) {
						$nom = trim( $nom . ' ' . $it['t'] );
					}
					continue;
				}
				if ( ! preg_match( '/^\d{1,3}$/', $it['t'] ) ) {
					continue;
				}
				$best = null;
				$dist = 18.0;
				foreach ( $cols as $k => $x ) {
					if ( abs( $it['x'] - $x ) < $dist ) {
						$dist = abs( $it['x'] - $x );
						$best = $k;
					}
				}
				if ( $best ) {
					$st[ $best ] = (int) $it['t'];
				}
			}
			$cote  = $tableau;
			$fille = '2' === substr( $lic['t'], 7, 1 );
			$buts  = ( $fille && $double ) ? intdiv( $st['buts'], 2 ) : $st['buts'];
			$out[] = array(
				'nom'       => self::prenom_initiale( $nom ),
				'numero'    => $num,
				'capitaine' => $capt,
				'fille'     => $fille,
				'buts_fdm'  => $st['buts'],
				'buts'      => $buts,
				'7m'        => $st['7m'],
				// Un but doublé est aussi compté deux fois dans les tirs.
				'tirs'      => max( 0, $st['tirs'] - ( $st['buts'] - $buts ) ),
				'arrets'    => $st['arrets'],
				'av'        => $st['av'],
				'2min'      => $st['2min'],
				'dis'       => $st['dis'],
			);
		}
		return $out;
	}
}
