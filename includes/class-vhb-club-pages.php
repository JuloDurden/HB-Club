<?php
/**
 * Pages de la refonte : les valeurs du club sont lues dans VHB Club à l'affichage.
 *
 * 1. Valeur dans le texte : <span data-vhbc="u9.tarif">169</span> (<tspan> dans un dessin SVG)
 *    Le contenu de la balise est remplacé côté serveur par la valeur du jour.
 *    Le texte d'origine sert de valeur de secours (donnée absente) et donne
 *    la casse : « SAMEDI » reste en capitales, « Samedi » prend une majuscule.
 * 2. Données pour les scripts de la page :
 *    <script type="application/json" id="vhb-club-donnees"></script>
 *    reçoit toutes les catégories de la saison (années, créneaux, gymnases,
 *    tarifs, équipes, maillots, encadrement) et les infos d'inscription.
 *
 * 3. Attributs et affichage conditionnel (en-tête, pied de page) :
 *    data-vhbc-attr="href:club.facebook;data-lat:club.lat" remplace la valeur de ces attributs ;
 *    data-vhbc-si="club.boutique_url" masque la balise si la valeur est vide,
 *    data-vhbc-sinon="club.boutique_url" la masque si la valeur existe.
 *    Sans valeur, l'attribut d'origine reste (secours).
 *
 * Clé = « sujet.champ ». Sujet : « club », le code d'une catégorie (« u9 »,
 * « u13-f »), plusieurs codes reliés par « + » (« babyhand+u9 »), « equipes »
 * (catégories reliées à une équipe VHB Résultats) ou « pratique-loisir ».
 * Champs : voir valeur_categories() et valeur_club().
 */

defined( 'ABSPATH' ) || exit;

class VHB_Club_Pages {

	const JOURS_COURTS = array( 1 => 'Lun.', 'Mar.', 'Mer.', 'Jeu.', 'Ven.', 'Sam.', 'Dim.' );
	const NOMBRES      = array( 'zéro', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf', 'dix', 'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize', 'dix-sept', 'dix-huit', 'dix-neuf', 'vingt' );

	public static function init() {
		add_filter( 'the_content', array( __CLASS__, 'remplir' ), 20 );
	}

	/* ------------------------------------------------------------------ */
	/* Filtre du contenu                                                   */
	/* ------------------------------------------------------------------ */

	public static function remplir( $html ) {
		if ( false === strpos( (string) $html, 'vhbc' ) && false === strpos( (string) $html, 'vhb-club-donnees' ) ) {
			return $html;
		}
		$html = preg_replace_callback(
			'#<(span|tspan) data-vhbc="([a-z0-9+._-]+)"([^>]*)>([^<]*)</\\1>#',
			static function ( $m ) {
				$v = self::valeur( $m[2] );
				if ( null === $v || '' === $v ) {
					return $m[0];
				}
				return '<' . $m[1] . ' data-vhbc="' . $m[2] . '"' . $m[3] . '>' . esc_html( self::casse( $v, html_entity_decode( $m[4], ENT_QUOTES, 'UTF-8' ) ) ) . '</' . $m[1] . '>';
			},
			$html
		);
		if ( false !== strpos( $html, 'data-vhbc-' ) ) {
			$html = preg_replace_callback( '#<[a-zA-Z][a-zA-Z0-9]*\s[^>]*data-vhbc-(?:attr|si|sinon)="[^"]*"[^>]*>#', array( __CLASS__, 'balise' ), $html );
		}
		if ( false !== strpos( $html, '<!-- vhbc-partenaires' ) ) {
			$html = preg_replace_callback(
				'#<!-- vhbc-partenaires ([a-z0-9 -]+) -->[\s\S]*?<!-- /vhbc-partenaires -->#',
				static function ( $m ) {
					$h = self::partenaires( trim( $m[1] ) );
					return '' === $h ? $m[0] : '<!-- vhbc-partenaires ' . $m[1] . ' -->' . $h . '<!-- /vhbc-partenaires -->';
				},
				$html
			);
		}
		if ( false !== strpos( $html, 'id="vhb-club-donnees"' ) ) {
			$json = wp_json_encode( self::donnees(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
			$html = preg_replace_callback(
				'#(<script type="application/json" id="vhb-club-donnees">)[\s\S]*?(</script>)#',
				static function ( $m ) use ( $json ) {
					return $m[1] . $json . $m[2];
				},
				$html,
				1
			);
		}
		return $html;
	}

	/** Attributs reliés (data-vhbc-attr) et affichage conditionnel (data-vhbc-si, data-vhbc-sinon) d'une balise ouvrante. */
	private static function balise( $m ) {
		$t = $m[0];
		if ( preg_match( '#\sdata-vhbc-attr="([^"]*)"#', $t, $a ) ) {
			foreach ( explode( ';', $a[1] ) as $paire ) {
				$p = explode( ':', trim( $paire ), 2 );
				if ( 2 !== count( $p ) || ! preg_match( '#^[a-z][a-z0-9-]*$#', $p[0] ) ) {
					continue;
				}
				$v = self::valeur( trim( $p[1] ) );
				if ( null === $v || '' === $v ) {
					continue;
				}
				$v = 'href' === $p[0] || 'src' === $p[0] ? esc_url( $v ) : esc_attr( $v );
				$re = '#(\s' . $p[0] . '=")[^"]*(")#';
				if ( preg_match( $re, $t ) ) {
					$t = preg_replace_callback( $re, static function ( $x ) use ( $v ) { return $x[1] . $v . $x[2]; }, $t, 1 );
				} else {
					$t = preg_replace( '#\s*(/?>)$#', ' ' . $p[0] . '="' . $v . '"$1', $t, 1 );
				}
			}
		}
		$cache = false;
		if ( preg_match( '#\sdata-vhbc-si="([^"]*)"#', $t, $a ) ) {
			$v     = self::valeur( $a[1] );
			$cache = null === $v || '' === $v;
		}
		if ( preg_match( '#\sdata-vhbc-sinon="([^"]*)"#', $t, $a ) ) {
			$v     = self::valeur( $a[1] );
			$cache = $cache || ( null !== $v && '' !== $v );
		}
		if ( $cache ) {
			if ( preg_match( '#\sstyle="#', $t ) ) {
				$t = preg_replace( '#(\sstyle=")#', '$1display: none !important; ', $t, 1 );
			} else {
				$t = preg_replace( '#\s*(/?>)$#', ' style="display: none !important;" hidden$1', $t, 1 );
			}
		}
		return $t;
	}

	/** Logos des partenaires (Vie du club), en blanc, pour le pied de page. Vide : la page garde sa liste. */
	private static function partenaires( $classe ) {
		if ( ! class_exists( 'VHB_Club_Vie' ) ) {
			return '';
		}
		$h = '';
		foreach ( VHB_Club_Vie::get()['partenaires'] as $p ) {
			if ( empty( $p['logo'] ) ) {
				continue;
			}
			$img = wp_get_attachment_image_src( (int) $p['logo'], 'medium' );
			if ( ! $img ) {
				continue;
			}
			$cl = array();
			if ( isset( array( 'silhouette' => 1, 'clair' => 1, 'noir' => 1 )[ $p['rendu'] ] ) ) {
				$cl[] = 'silhouette' === $p['rendu'] ? 'lg-sil' : 'lg-' . $p['rendu'];
			}
			if ( 'grande' === $p['hauteur'] ) {
				$cl[] = 'lg-grand';
			}
			if ( '100' !== (string) $p['zoom'] ) {
				$cl[] = '133' === (string) $p['zoom'] ? 'lg-z13' : 'lg-z12';
			}
			$tag = '<img src="' . esc_url( $img[0] ) . '" alt="' . esc_attr( html_entity_decode( $p['nom'], ENT_QUOTES, 'UTF-8' ) ) . '" width="' . (int) $img[1] . '" height="' . (int) $img[2] . '" loading="lazy"' . ( $cl ? ' class="' . esc_attr( implode( ' ', $cl ) ) . '"' : '' ) . '>';
			$h  .= $p['url']
				? '<a class="' . esc_attr( $classe ) . '" href="' . esc_url( $p['url'] ) . '" target="_blank" rel="noopener" aria-label="' . esc_attr( html_entity_decode( $p['nom'], ENT_QUOTES, 'UTF-8' ) ) . '">' . $tag . '</a>'
				: '<div class="' . esc_attr( $classe ) . '">' . $tag . '</div>';
			$h  .= "\n";
		}
		return '' === $h ? '' : "\n" . $h;
	}

	/** Reprend la casse du texte de secours : tout en capitales, majuscule initiale, ou minuscule initiale. */
	public static function casse( $v, $secours ) {
		$s = trim( (string) $secours );
		if ( '' === $s || ! preg_match( '/\p{L}/u', $s ) ) {
			return $v;
		}
		if ( mb_strtoupper( $s, 'UTF-8' ) === $s && mb_strtolower( $s, 'UTF-8' ) !== $s ) {
			return mb_strtoupper( $v, 'UTF-8' );
		}
		// Majuscule ou minuscule initiale : seulement si les deux textes commencent par une lettre.
		$a = mb_substr( $s, 0, 1, 'UTF-8' );
		$b = mb_substr( $v, 0, 1, 'UTF-8' );
		if ( ! preg_match( '/^\p{L}$/u', $a ) || ! preg_match( '/^\p{L}$/u', $b ) ) {
			return $v;
		}
		$b = mb_strtoupper( $a, 'UTF-8' ) === $a ? mb_strtoupper( $b, 'UTF-8' ) : mb_strtolower( $b, 'UTF-8' );
		return $b . mb_substr( $v, 1, null, 'UTF-8' );
	}

	/* ------------------------------------------------------------------ */
	/* Valeurs                                                             */
	/* ------------------------------------------------------------------ */

	/** @return string|null null : clé inconnue ou donnée absente (le texte de la page reste). */
	public static function valeur( $cle ) {
		$p = explode( '.', (string) $cle, 2 );
		if ( 2 !== count( $p ) ) {
			return null;
		}
		list( $sujet, $champ ) = $p;
		if ( 'club' === $sujet ) {
			return self::valeur_club( $champ );
		}
		$cats = self::selection( $sujet );
		if ( ! $cats ) {
			return null;
		}
		return self::valeur_categories( $cats, $champ );
	}

	/** Catégories de la saison en cours désignées par un sujet. */
	public static function selection( $sujet ) {
		$toutes = VHB_Club_Donnees::categories();
		if ( 'equipes' === $sujet ) {
			return array_values( array_filter( $toutes, static function ( $c ) { return ! empty( $c['equipes'] ); } ) );
		}
		if ( 0 === strpos( $sujet, 'pratique-' ) ) {
			$pr = substr( $sujet, 9 );
			return array_values( array_filter( $toutes, static function ( $c ) use ( $pr ) { return $c['pratique'] === $pr; } ) );
		}
		$out = array();
		foreach ( explode( '+', $sujet ) as $code ) {
			$c = VHB_Club_Donnees::categorie_par_code( $code );
			if ( $c ) {
				$out[] = $c;
			}
		}
		return $out;
	}

	private static function valeur_club( $champ ) {
		$r   = VHB_Club_Reglages::get();
		$ins = VHB_Club_Inscriptions::get();
		switch ( $champ ) {
			case 'saison':
				return VHB_Club_Reglages::saison_en_cours();
			case 'nom':
			case 'email':
			case 'email_licences':
			case 'telephone':
				return (string) $r[ $champ ];
			case 'gymnase':
			case 'adresse':
			case 'adresse_rue':
			case 'cp_ville':
			case 'ville':
			case 'lat':
			case 'lon':
			case 'gmaps':
			case 'waze':
				$l = VHB_Club_Donnees::lieu_principal();
				if ( ! $l ) {
					return null;
				}
				$gps = null !== $l['lat'] ? null !== $l['lon'] : false;
				switch ( $champ ) {
					case 'gymnase':
						return html_entity_decode( $l['nom'], ENT_QUOTES, 'UTF-8' );
					case 'adresse':
						return $l['adresse_complete'];
					case 'adresse_rue':
						return $l['adresse'];
					case 'cp_ville':
						return trim( $l['cp'] . ' ' . $l['ville'] );
					case 'ville':
						return $l['ville'];
					case 'lat':
						return $gps ? (string) $l['lat'] : null;
					case 'lon':
						return $gps ? (string) $l['lon'] : null;
					case 'gmaps':
						return $gps ? 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode( $l['lat'] . ',' . $l['lon'] ) : null;
					case 'waze':
						return $gps ? 'https://waze.com/ul?ll=' . rawurlencode( $l['lat'] . ',' . $l['lon'] ) . '&navigate=yes' : null;
				}
				return null;
			case 'facebook':
			case 'instagram':
				return (string) $r[ $champ ];
			case 'boutique_url':
				// lien seulement si la boutique est ouverte (case cochée, dans la période, lien saisi)
				$b = class_exists( 'VHB_Club_Vie' ) ? VHB_Club_Vie::boutique_active() : null;
				return $b ? (string) $b['url'] : '';
			case 'annee':
				return wp_date( 'Y' );
			case 'essai':
				return (string) $ins['essai'];
			case 'essai_nb':
			case 'essai_nb_mot':
				if ( ! preg_match( '/\d+/', (string) $ins['essai'], $m ) ) {
					return null;
				}
				return 'essai_nb' === $champ ? $m[0] : self::mot( (int) $m[0] );
			case 'equipement':
				return (string) $ins['equipement'];
			case 'ag_date':
				// derniere AG grand public passee (Vie du club > Assemblees generales) : « 27 juin 2026 »
				$ag = class_exists( 'VHB_Club_Vie' ) ? VHB_Club_Vie::ag( 'passees', 'publique' ) : array();
				return $ag ? self::date_longue( $ag[0]['date'] ) : null;
			case 'nb_equipes':
			case 'nb_equipes_mot':
				$n = count( self::equipes_reliees() );
				return 'nb_equipes' === $champ ? (string) $n : self::mot( $n );
			case 'nb_pratiques':
			case 'nb_pratiques_mot':
				$n = count( array_unique( wp_list_pluck( VHB_Club_Donnees::categories(), 'pratique' ) ) );
				return 'nb_pratiques' === $champ ? (string) $n : self::mot( $n );
		}
		return null;
	}

	private static function equipes_reliees() {
		$eq = array();
		foreach ( VHB_Club_Donnees::categories() as $c ) {
			foreach ( $c['equipes'] as $s ) {
				$eq[ $s ] = true;
			}
		}
		return array_keys( $eq );
	}

	/** « 2026-06-27 » donne « 27 juin 2026 », « 2026-06-01 » donne « 1er juin 2026 ». */
	private static function date_longue( $d ) {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $d, $m ) ) {
			return null;
		}
		$mois = array( 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre' );
		$j    = (int) $m[3];
		return ( 1 === $j ? '1er' : (string) $j ) . ' ' . $mois[ (int) $m[2] - 1 ] . ' ' . $m[1];
	}

	private static function mot( $n ) {
		return self::NOMBRES[ $n ] ?? (string) $n;
	}

	/** Valeur pour une ou plusieurs catégories. */
	private static function valeur_categories( array $cats, $champ ) {
		$debut = VHB_Club_Reglages::debut( VHB_Club_Reglages::saison_en_cours() );
		$c     = $cats[0];
		// Créneau n : « c1.debut », « c2.jour_court »...
		if ( preg_match( '/^c(\d)\.(\w+)$/', $champ, $m ) ) {
			$cr = self::creneaux( $cats );
			$s  = $cr[ (int) $m[1] - 1 ] ?? null;
			return $s ? self::champ_creneau( $s, $m[2] ) : null;
		}
		switch ( $champ ) {
			case 'nom':
				return $c['nom'];
			case 'tarif':
				$t = array_filter( wp_list_pluck( $cats, 'tarif' ), 'strlen' );
				if ( ! $t ) {
					return null;
				}
				$t = array_map( 'floatval', $t );
				$a = self::nombre( min( $t ) );
				$b = self::nombre( max( $t ) );
				return $a === $b ? $a : $a . ' à ' . $b;
			case 'tarif_min':
			case 'tarif_max':
				$t = array_map( 'floatval', array_filter( wp_list_pluck( $cats, 'tarif' ), 'strlen' ) );
				return $t ? self::nombre( 'tarif_min' === $champ ? min( $t ) : max( $t ) ) : null;
			case 'an_min':
			case 'an_max':
			case 'annees':
			case 'nes':
				list( $min, $max, $ouvert ) = self::bornes_annees( $cats, $debut );
				if ( null === $max ) {
					return null;
				}
				if ( 'an_min' === $champ ) {
					return $ouvert ? null : (string) $min;
				}
				if ( 'an_max' === $champ ) {
					return (string) $max;
				}
				$txt = $ouvert ? $max . ' et avant' : ( $min === $max ? (string) $max : $min . '-' . $max );
				if ( 'annees' === $champ ) {
					return $txt;
				}
				$fem = ! array_filter( $cats, static function ( $x ) { return 'f' !== $x['genre']; } );
				$nes = $fem ? 'Nées' : 'Nés';
				if ( ! $ouvert && $max - $min >= 2 ) {
					return $nes . ' de ' . $min . ' à ' . $max;
				}
				return $nes . ' en ' . $txt;
			case 'ages':
			case 'age_min':
			case 'age_max':
				$mins = array_filter( wp_list_pluck( $cats, 'age_min' ), 'is_int' );
				$maxs = wp_list_pluck( $cats, 'age_max' );
				if ( ! $mins ) {
					return null;
				}
				$amin   = min( $mins );
				$ouvert = in_array( null, $maxs, true );
				$amax   = $ouvert ? null : max( $maxs );
				if ( 'age_min' === $champ ) {
					return (string) $amin;
				}
				if ( 'age_max' === $champ ) {
					return null === $amax ? null : (string) $amax;
				}
				return $ouvert ? $amin . ' ans et plus' : ( $amin === $amax ? $amin . ' ans' : $amin . ' à ' . $amax . ' ans' );
			case 'jours':
			case 'jours_ou':
				$j = array();
				foreach ( self::creneaux( $cats ) as $s ) {
					$j[ $s['jour'] ] = mb_strtolower( VHB_Club_Types::JOURS[ $s['jour'] ], 'UTF-8' );
				}
				ksort( $j );
				return $j ? self::liste( array_values( $j ), 'jours_ou' === $champ ? ' ou ' : ' et ' ) : null;
			case 'plages':
			case 'debuts':
				$out = array();
				foreach ( self::creneaux( $cats ) as $s ) {
					$out[] = 'plages' === $champ ? VHB_Club_Donnees::plage( $s['debut'], $s['fin'] ) : VHB_Club_Donnees::heure( $s['debut'] );
				}
				return $out ? implode( ' · ', $out ) : null;
			case 'phrase':
			case 'tous':
				$cr = self::creneaux( $cats );
				if ( ! $cr ) {
					return null;
				}
				if ( 'tous' === $champ && 1 === count( $cr ) ) {
					$s = $cr[0];
					return 'tous les ' . mb_strtolower( VHB_Club_Types::JOURS[ $s['jour'] ], 'UTF-8' ) . 's ' . self::de_a( $s );
				}
				$out = array();
				foreach ( $cr as $s ) {
					$out[] = 'le ' . mb_strtolower( VHB_Club_Types::JOURS[ $s['jour'] ], 'UTF-8' ) . ' ' . self::de_a( $s );
				}
				return self::liste( $out, ' et ' );
			case 'duree':
				$cr = self::creneaux( $cats );
				return $cr ? self::duree( $cr[0] ) : null;
			case 'nb_seances':
				return (string) count( self::creneaux( $cats ) );
			case 'lieux':
			case 'lieux_courts':
			case 'lieux_partenaires':
			case 'nb_lieux':
				$l = array();
				foreach ( self::creneaux( $cats ) as $s ) {
					$x = VHB_Club_Donnees::lieu( $s['lieu'] );
					if ( $x ) {
						if ( 'lieux_partenaires' === $champ ? 'club' === $x['type'] : false ) {
							continue;
						}
						$l[ $x['id'] ] = array( 'club' === $x['type'] ? 0 : 1, 'lieux' === $champ ? $x['nom'] : $x['nom_court'] );
					}
				}
				uasort( $l, static function ( $a, $b ) { return $a <=> $b; } );
				if ( 'nb_lieux' === $champ ) {
					return (string) count( $l );
				}
				return $l ? self::liste( array_values( array_unique( wp_list_pluck( $l, 1 ) ) ), ' et ' ) : null;
			case 'encadrement':
				$e = (string) VHB_Club_Types::meta( $c['id'], 'encadrement' );
				return '' === $e ? null : $e;
		}
		return null;
	}

	private static function champ_creneau( array $s, $k ) {
		$l = VHB_Club_Donnees::lieu( $s['lieu'] );
		switch ( $k ) {
			case 'jour':
				return VHB_Club_Types::JOURS[ $s['jour'] ];
			case 'jour_court':
				return self::JOURS_COURTS[ $s['jour'] ];
			case 'debut':
				return VHB_Club_Donnees::heure( $s['debut'] );
			case 'fin':
				return '' === $s['fin'] ? null : VHB_Club_Donnees::heure( $s['fin'] );
			case 'milieu':
				if ( '' === $s['fin'] ) {
					return null;
				}
				$m = (int) round( ( self::minutes( $s['debut'] ) + self::minutes( $s['fin'] ) ) / 2 / 5 ) * 5;
				return VHB_Club_Donnees::heure( sprintf( '%02d:%02d', intdiv( $m, 60 ), $m % 60 ) );
			case 'plage':
				return VHB_Club_Donnees::plage( $s['debut'], $s['fin'] );
			case 'duree':
				return self::duree( $s );
			case 'lieu':
				return $l ? $l['nom'] : null;
			case 'lieu_court':
				return $l ? $l['nom_court'] : null;
			case 'ville':
				return $l ? $l['ville'] : null;
		}
		return null;
	}

	/** Créneaux de plusieurs catégories, sans doublon, triés par jour puis heure. */
	private static function creneaux( array $cats ) {
		$out = array();
		foreach ( $cats as $c ) {
			foreach ( $c['creneaux'] as $s ) {
				$out[ $s['jour'] . '|' . $s['debut'] . '|' . $s['fin'] . '|' . $s['lieu'] ] = $s;
			}
		}
		return VHB_Club_Donnees::creneaux_tries( array_values( $out ) );
	}

	private static function bornes_annees( array $cats, $debut ) {
		$min    = null;
		$max    = null;
		$ouvert = false;
		foreach ( $cats as $c ) {
			if ( null === $c['age_min'] ) {
				continue;
			}
			$plus_jeune = $debut - $c['age_min'];
			$max        = null === $max ? $plus_jeune : max( $max, $plus_jeune );
			if ( null === $c['age_max'] ) {
				$ouvert = true;
			} else {
				$plus_age = $debut - $c['age_max'];
				$min      = null === $min ? $plus_age : min( $min, $plus_age );
			}
		}
		return array( $min, $max, $ouvert );
	}

	private static function de_a( array $s ) {
		return '' === $s['fin'] ? 'à ' . VHB_Club_Donnees::heure( $s['debut'] ) : 'de ' . VHB_Club_Donnees::heure( $s['debut'] ) . ' à ' . VHB_Club_Donnees::heure( $s['fin'] );
	}

	private static function minutes( $hm ) {
		$p = explode( ':', (string) $hm );
		return (int) $p[0] * 60 + (int) ( $p[1] ?? 0 );
	}

	private static function duree( array $s ) {
		if ( '' === $s['fin'] ) {
			return null;
		}
		$d = self::minutes( $s['fin'] ) - self::minutes( $s['debut'] );
		if ( $d <= 0 ) {
			return null;
		}
		if ( $d < 60 ) {
			return $d . ' min';
		}
		return intdiv( $d, 60 ) . 'h' . ( $d % 60 ? sprintf( '%02d', $d % 60 ) : '' );
	}

	private static function nombre( $f ) {
		return floor( $f ) == $f ? (string) (int) $f : number_format( $f, 2, ',', '' ); // phpcs:ignore
	}

	private static function liste( array $l, $dernier ) {
		if ( count( $l ) < 2 ) {
			return implode( '', $l );
		}
		$fin = array_pop( $l );
		return implode( ', ', $l ) . $dernier . $fin;
	}

	/* ------------------------------------------------------------------ */
	/* Données pour les scripts des pages                                  */
	/* ------------------------------------------------------------------ */

	public static function donnees() {
		$saison = VHB_Club_Reglages::saison_en_cours();
		$debut  = VHB_Club_Reglages::debut( $saison );
		$lieu   = static function ( $id ) {
			$l = VHB_Club_Donnees::lieu( $id );
			if ( ! $l ) {
				return null;
			}
			return array(
				'id'        => $l['id'],
				'nom'       => $l['nom'],
				'nom_court' => $l['nom_court'],
				'adresse'   => $l['adresse_complete'],
				'lat'       => $l['lat'],
				'lon'       => $l['lon'],
				'club'      => 'club' === $l['type'],
			);
		};
		$cats = array();
		foreach ( VHB_Club_Donnees::categories( $saison ) as $c ) {
			$cr = array();
			foreach ( $c['creneaux'] as $s ) {
				$cr[] = array(
					'jour'       => $s['jour'],
					'jour_nom'   => VHB_Club_Types::JOURS[ $s['jour'] ],
					'jour_court' => self::JOURS_COURTS[ $s['jour'] ],
					'debut'      => VHB_Club_Donnees::heure( $s['debut'] ),
					'fin'        => '' === $s['fin'] ? '' : VHB_Club_Donnees::heure( $s['fin'] ),
					'plage'      => VHB_Club_Donnees::plage( $s['debut'], $s['fin'] ),
					'periode'    => $s['periode'],
					'note'       => $s['note'],
					'lieu'       => $lieu( $s['lieu'] ),
				);
			}
			$ent = array();
			foreach ( $c['ententes'] as $eid ) {
				$e = VHB_Club_Donnees::entente( $eid );
				if ( $e ) {
					$ent[] = array( 'nom' => $e['nom'], 'nom_entente' => $e['nom_entente'] );
				}
			}
			$m = (array) VHB_Club_Types::meta( $c['id'], 'maillots', array() );
			$cats[] = array(
				'code'          => $c['code'],
				'nom'           => html_entity_decode( $c['nom'], ENT_QUOTES, 'UTF-8' ),
				'pratique'      => $c['pratique'],
				'genre'         => $c['genre'],
				'age_min'       => $c['age_min'],
				'age_max'       => $c['age_max'],
				'an_min'        => null === $c['age_max'] ? null : $debut - $c['age_max'],
				'an_max'        => null === $c['age_min'] ? null : $debut - $c['age_min'],
				'annees'        => $c['annees'],
				'nes'           => self::valeur_categories( array( $c ), 'nes' ),
				'tarif'         => '' === $c['tarif'] ? null : self::nombre( (float) $c['tarif'] ),
				'inclus'        => $c['inclus'],
				'statut'        => $c['statut'],
				'creneaux'      => $cr,
				'equipes'       => $c['equipes'],
				'ententes'      => $ent,
				'maillots'      => array_values( array_filter( $m, static function ( $k ) { return is_array( $k ) && ! empty( $k['c'] ); } ) ),
				'maillots_note' => (string) VHB_Club_Types::meta( $c['id'], 'maillots_note' ),
				'encadrement'   => (string) VHB_Club_Types::meta( $c['id'], 'encadrement' ),
			);
		}
		// Photos d'équipe (2.0.0) : par équipe, et pour chaque catégorie la
		// première équipe liée qui a une photo.
		$photos = VHB_Club_Photos::publiques();
		foreach ( $cats as &$k ) {
			$k['photo'] = null;
			foreach ( (array) $k['equipes'] as $slug ) {
				if ( isset( $photos[ $slug ] ) ) {
					$k['photo'] = $photos[ $slug ];
					break;
				}
			}
		}
		unset( $k );
		$ins = VHB_Club_Inscriptions::get();
		$r   = VHB_Club_Reglages::get();
		$sai = VHB_Club_Inscriptions::saison( $saison );
		$docs = array();
		foreach ( $ins['documents'] as $d ) {
			$url = $d['fichier'] ? wp_get_attachment_url( $d['fichier'] ) : '';
			$url = $url ? $url : $d['url'];
			if ( $url ) {
				$docs[] = array( 'titre' => $d['titre'], 'url' => $url );
			}
		}
		$autres = array();
		foreach ( $sai['autres'] as $a ) {
			$autres[] = array(
				'nom'     => $a['nom'],
				'annees'  => $a['annees'],
				'montant' => '' === (string) $a['montant'] ? null : self::nombre( (float) $a['montant'] ),
				'mention' => $a['mention'],
			);
		}
		$red = array();
		foreach ( $sai['reductions'] as $x ) {
			$red[] = array(
				'libelle'   => $x['libelle'],
				'montant'   => VHB_Club_Inscriptions::montant( $x['montant'], $x['unite'] ),
				'precision' => $x['precision'],
			);
		}
		return array(
			'saison'      => $saison,
			'debut'       => $debut,
			'categories'  => $cats,
			'lieu'        => ( $lp = VHB_Club_Donnees::lieu_principal() ) ? $lieu( $lp['id'] ) : null,
			'essai'       => (string) $ins['essai'],
			'equipement'  => (string) $ins['equipement'],
			'email'       => (string) $r['email'],
			'ag_date'     => self::valeur_club( 'ag_date' ),
			'autres'      => $autres,
			'reductions'  => $red,
			'note_tarifs' => (string) $sai['note'],
			'documents'   => $docs,
			'photos'      => (object) $photos,
			// 2.0.1 : page « Le club ».
			'chiffres'     => array(
				'creation'   => '' === $r['annee_creation'] ? null : (int) $r['annee_creation'],
				'licencies'  => '' === $r['licencies'] ? null : (int) $r['licencies'],
				'licenciees' => '' === $r['licenciees_pct'] ? null : (int) $r['licenciees_pct'],
				'equipes'    => count( self::equipes_reliees() ),
			),
			'organigramme' => self::organigramme(),
			// 2.0.2 : page « Stages ».
			'stages'       => self::stages( $debut ),
		);
	}

	/**
	 * Stages et événements publiés de la saison (1er septembre au 31 août), du plus ancien au plus récent.
	 * L'état (à venir, en cours, terminé) est recalculé par la page avec la date du visiteur :
	 * une page gardée en cache reste juste le lendemain d'un stage.
	 */
	private static function stages( $debut ) {
		$de  = $debut . '-09-01';
		$a   = ( $debut + 1 ) . '-08-31';
		$out = array();
		foreach ( VHB_Club_Stages::liste( 'tous' ) as $s ) {
			if ( $s['debut'] < $de || $s['debut'] > $a ) {
				continue;
			}
			$l    = $s['lieu'] ? VHB_Club_Donnees::lieu( $s['lieu'] ) : null;
			$lieu = null;
			if ( $l ) {
				$lieu = array(
					'nom'     => $l['nom'],
					'adresse' => $l['adresse_complete'],
					'lat'     => $l['lat'],
					'lon'     => $l['lon'],
				);
			} elseif ( '' !== $s['lieu_libre'] ) {
				$lieu = array( 'nom' => $s['lieu_libre'], 'adresse' => '', 'lat' => null, 'lon' => null );
			}
			$affiche = null;
			$img     = get_post_thumbnail_id( $s['id'] );
			if ( $img ) {
				$g = wp_get_attachment_image_src( $img, 'large' );
				if ( $g ) {
					$affiche = array(
						'url'     => $g[0],
						'largeur' => (int) $g[1],
						'hauteur' => (int) $g[2],
						'alt'     => trim( (string) get_post_meta( $img, '_wp_attachment_image_alt', true ) ),
					);
				}
			}
			$tarifs = array();
			foreach ( $s['tarifs'] as $t ) {
				if ( ! is_array( $t ) ) {
					continue;
				}
				$tarifs[] = array(
					'libelle'   => (string) ( $t['libelle'] ?? '' ),
					'adherent'  => '' === (string) ( $t['adherent'] ?? '' ) ? null : (float) $t['adherent'],
					'exterieur' => '' === (string) ( $t['exterieur'] ?? '' ) ? null : (float) $t['exterieur'],
				);
			}
			$out[] = array(
				'titre'      => html_entity_decode( $s['titre'], ENT_QUOTES, 'UTF-8' ),
				'type'       => $s['type'],
				'debut'      => $s['debut'],
				'fin'        => $s['fin'],
				'periode'    => VHB_Club_Donnees::periode( $s['debut'], $s['fin'] ),
				'horaires'   => $s['horaires'],
				'public'     => $s['public'],
				'groupes'    => $s['groupes'],
				'lieu'       => $lieu,
				'infos'      => $s['infos'],
				'tarifs'     => $tarifs,
				'conditions' => $s['conditions'],
				'helloasso'  => $s['helloasso'],
				'limite'     => $s['limite'],
				'complet'    => $s['complet'],
				'affiche'    => $affiche,
				// 3.0.1 : agenda de la page Actualités.
				'presentation' => $s['presentation'],
				'article'      => ( $s['article'] && 'publish' === get_post_status( $s['article'] ) )
					? array( 'titre' => html_entity_decode( get_the_title( $s['article'] ), ENT_QUOTES, 'UTF-8' ), 'url' => get_permalink( $s['article'] ) )
					: null,
			);
		}
		return $out;
	}

	/** Organigramme pour les pages : nom affiché selon le réglage, photo facultative. */
	private static function organigramme() {
		$v   = VHB_Club_Vie::get();
		$out = array();
		foreach ( (array) $v['personnes'] as $p ) {
			$photo = null;
			if ( ! empty( $p['photo'] ) ) {
				$g = wp_get_attachment_image_src( (int) $p['photo'], 'medium' );
				if ( $g ) {
					$photo = array(
						'url' => $g[0],
						'alt' => trim( (string) get_post_meta( (int) $p['photo'], '_wp_attachment_image_alt', true ) ),
					);
				}
			}
			$out[] = array(
				'nom'       => VHB_Club_Vie::nom( $p ),
				'prenom'    => trim( (string) $p['prenom'] ),
				'nom_seul'  => trim( substr( VHB_Club_Vie::nom( $p ), strlen( trim( (string) $p['prenom'] ) ) ) ),
				'initiales' => VHB_Club_Vie::initiales( $p ),
				'fonction'  => (string) $p['fonction'],
				'instance'  => (string) $p['instance'],
				'email'     => (string) $p['email'],
				'photo'     => $photo,
			);
		}
		return array( 'affichage' => (string) $v['affichage_noms'], 'personnes' => $out );
	}
}
