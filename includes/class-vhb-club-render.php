<?php
/**
 * Affichage sur le site : shortcodes (les blocs Gutenberg appellent les mêmes fonctions).
 *
 * [vhb_planning]  planning des entraînements (tableau ou par jour)
 * [vhb_categorie] fiche d'une catégorie
 * [vhb_lieu]      carte d'un lieu (ou de tous)
 * [vhb_info]      une donnée isolée, à glisser dans un texte
 *
 * Typographie : tout tiret affiché est écrit « &#45; ». WordPress (wptexturize)
 * transforme sinon certains tirets en tirets longs dans le contenu des blocs,
 * ce que la règle du site interdit.
 */

defined( 'ABSPATH' ) || exit;

class VHB_Club_Render {

	const SHORTCODES = array( 'vhb_planning', 'vhb_categorie', 'vhb_lieu', 'vhb_info', 'vhb_tarifs', 'vhb_reductions', 'vhb_pieces', 'vhb_bandeau_inscriptions', 'vhb_stages', 'vhb_alertes', 'vhb_organigramme', 'vhb_ag', 'vhb_boutique', 'vhb_partenaires', 'vhb_mentions' );

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ), 5 );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'register_assets' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );

		add_shortcode( 'vhb_planning', array( __CLASS__, 'sc_planning' ) );
		add_shortcode( 'vhb_categorie', array( __CLASS__, 'sc_categorie' ) );
		add_shortcode( 'vhb_lieu', array( __CLASS__, 'sc_lieu' ) );
		add_shortcode( 'vhb_info', array( __CLASS__, 'sc_info' ) );
		add_shortcode( 'vhb_tarifs', array( __CLASS__, 'sc_tarifs' ) );
		add_shortcode( 'vhb_reductions', array( __CLASS__, 'sc_reductions' ) );
		add_shortcode( 'vhb_pieces', array( __CLASS__, 'sc_pieces' ) );
		add_shortcode( 'vhb_bandeau_inscriptions', array( __CLASS__, 'sc_bandeau' ) );
		add_shortcode( 'vhb_stages', array( __CLASS__, 'sc_stages' ) );
		add_shortcode( 'vhb_alertes', array( __CLASS__, 'sc_alertes' ) );
		add_shortcode( 'vhb_organigramme', array( __CLASS__, 'sc_organigramme' ) );
		add_shortcode( 'vhb_ag', array( __CLASS__, 'sc_ag' ) );
		add_shortcode( 'vhb_boutique', array( __CLASS__, 'sc_boutique' ) );
		add_shortcode( 'vhb_partenaires', array( __CLASS__, 'sc_partenaires' ) );
		add_shortcode( 'vhb_mentions', array( __CLASS__, 'sc_mentions' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Ressources                                                          */
	/* ------------------------------------------------------------------ */

	public static function register_assets() {
		if ( wp_style_is( 'vhb-club', 'registered' ) ) {
			return;
		}
		wp_register_style( 'vhb-club', VHB_CLUB_URL . 'assets/vhb-club.css', array(), VHB_Club_Cache::ver( 'assets/vhb-club.css' ) );
	}

	/** Styles dans le <head> quand la page contient nos shortcodes ou blocs. */
	public static function maybe_enqueue() {
		$post = get_post();
		if ( ! $post ) {
			return;
		}
		$c = (string) $post->post_content;
		foreach ( array( 'vhb_planning', 'vhb_categorie', 'vhb_lieu', 'vhb_tarifs', 'vhb_reductions', 'vhb_pieces', 'vhb_bandeau_inscriptions', 'vhb_stages', 'vhb_alertes', 'vhb_organigramme', 'vhb_ag', 'vhb_boutique', 'vhb_partenaires', 'vhb_mentions' ) as $sc ) {
			if ( has_shortcode( $c, $sc ) ) {
				self::enqueue();
				return;
			}
		}
		if ( false !== strpos( $c, '<!-- wp:vhb-club/' ) ) {
			self::enqueue();
		}
	}

	public static function enqueue() {
		self::register_assets();
		wp_enqueue_style( 'vhb-club' );
	}

	/* ------------------------------------------------------------------ */
	/* Outils                                                              */
	/* ------------------------------------------------------------------ */

	/** Texte échappé, tirets protégés de wptexturize. */
	public static function t( $s ) {
		return str_replace( '-', '&#45;', esc_html( (string) $s ) );
	}

	private static function is_editor() {
		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}

	private static function wrap( $inner, $extra = '' ) {
		$theme = VHB_Club_Reglages::get()['theme'];
		return '<div class="vhbc vhbc--' . esc_attr( $theme ) . ( $extra ? ' ' . esc_attr( $extra ) : '' ) . '">' . $inner . '</div>';
	}

	/** Message dans l'éditeur, rien (ou un texte neutre) sur le site. */
	private static function msg( $editor, $front = '' ) {
		$text = self::is_editor() ? $editor : $front;
		return '' === $text ? '' : self::wrap( '<div class="vhbc-msg">' . self::t( $text ) . '</div>' );
	}

	private static function head( $surtitre, $titre ) {
		if ( '' === $titre && '' === $surtitre ) {
			return '';
		}
		$h = '<div class="vhbc-head">';
		if ( '' !== $surtitre ) {
			$h .= '<span class="vhbc-kicker">' . self::t( $surtitre ) . '</span>';
		}
		if ( '' !== $titre ) {
			$h .= '<h2 class="vhbc-title">' . self::t( $titre ) . '</h2>';
		}
		return $h . '</div>';
	}

	private static function liste( $s ) {
		return array_values( array_filter( array_map( 'trim', explode( ',', (string) $s ) ) ) );
	}

	private static function oui( $v ) {
		return in_array( strtolower( trim( (string) $v ) ), array( 'oui', '1', 'true', 'yes' ), true );
	}

	private static function nes( array $c ) {
		if ( '' === $c['annees'] ) {
			return '';
		}
		return ( 'f' === $c['genre'] ? 'Nées en ' : 'Nés en ' ) . $c['annees'];
	}

	private static function kicker_categorie( array $c ) {
		$parts = array( VHB_Club_Types::PRATIQUES[ $c['pratique'] ] ?? '' );
		if ( $c['genre'] && isset( VHB_Club_Types::GENRES[ $c['genre'] ] ) ) {
			$parts[] = VHB_Club_Types::GENRES[ $c['genre'] ];
		}
		return implode( ' · ', array_filter( $parts ) );
	}

	/** Noms des clubs en entente d'une catégorie. */
	private static function ententes_txt( array $c ) {
		$noms = array();
		foreach ( $c['ententes'] as $id ) {
			$e = VHB_Club_Donnees::entente( $id );
			if ( $e ) {
				$noms[] = $e['nom'];
			}
		}
		if ( ! $noms ) {
			return '';
		}
		$last = array_pop( $noms );
		return 'En entente avec ' . ( $noms ? implode( ', ', $noms ) . ' et ' : '' ) . $last;
	}

	/** Étiquette « Complet » ou « Liste d'attente » (rien pour « ouvert » ou vide dans les listes). */
	private static function statut_tag( array $c, $avec_ouvert = false ) {
		if ( 'complet' === $c['statut'] ) {
			return ' <span class="vhbc-tag vhbc-tag--complet">Complet</span>';
		}
		if ( 'attente' === $c['statut'] ) {
			return ' <span class="vhbc-tag vhbc-tag--attente">Liste d\'attente</span>';
		}
		if ( $avec_ouvert && 'ouvert' === $c['statut'] ) {
			return ' <span class="vhbc-tag vhbc-tag--ok">Inscriptions ouvertes</span>';
		}
		return '';
	}

	/** Un créneau en ligne : jour, heures, lieu (si demandé), précisions. */
	private static function creneau_html( array $s, $avec_lieu, $avec_jour = true ) {
		$h = '<li class="vhbc-slot">';
		if ( $avec_jour ) {
			$h .= '<span class="vhbc-day">' . self::t( VHB_Club_Types::JOURS[ $s['jour'] ] ?? '' ) . '</span> ';
		}
		$h .= '<span class="vhbc-time">' . self::t( VHB_Club_Donnees::plage( $s['debut'], $s['fin'] ) ) . '</span>';
		if ( $avec_lieu ) {
			$l = VHB_Club_Donnees::lieu( $s['lieu'] );
			if ( $l && $l['publie'] ) {
				$h .= ' <span class="vhbc-place">' . self::t( $l['nom_court'] ) . '</span>';
			}
		}
		$extra = array();
		if ( 'hors_vacances' === $s['periode'] ) {
			$extra[] = 'hors vacances scolaires';
		}
		if ( '' !== $s['note'] ) {
			$extra[] = $s['note'];
		}
		if ( $extra ) {
			$h .= ' <span class="vhbc-slot-note">' . self::t( implode( ', ', $extra ) ) . '</span>';
		}
		return $h . '</li>';
	}

	/** Boutons Google Maps et Waze. */
	private static function nav( array $l ) {
		$u = VHB_Club_Donnees::liens_carte( $l );
		return '<div class="vhbc-nav">'
			. '<a class="vhbc-btn" href="' . esc_url( $u['google'] ) . '" target="_blank" rel="noopener">Google Maps</a>'
			. '<a class="vhbc-btn vhbc-btn--ghost" href="' . esc_url( $u['waze'] ) . '" target="_blank" rel="noopener">Waze</a>'
			. '</div>';
	}

	/* ------------------------------------------------------------------ */
	/* [vhb_planning]                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Options : pratique="competition,loisir", categorie="u13-f,u13-g",
	 * lieu="<slug du lieu>", vue="tableau|jours", groupe="aucun|pratique",
	 * lieux="oui|non" (adresses sous le planning), titre="…", surtitre="…".
	 */
	public static function sc_planning( $atts ) {
		$a = shortcode_atts(
			array(
				'pratique'  => '',
				'categorie' => '',
				'lieu'      => '',
				'vue'       => 'tableau',
				'groupe'    => 'aucun',
				'lieux'     => 'oui',
				'titre'     => '',
				'surtitre'  => '',
			),
			$atts,
			'vhb_planning'
		);
		self::enqueue();

		$pratiques = self::liste( $a['pratique'] );
		$codes     = array_map( 'sanitize_title', self::liste( $a['categorie'] ) );
		$lieu      = '' !== $a['lieu'] ? VHB_Club_Donnees::lieu_par_ref( $a['lieu'] ) : null;

		$cats = array();
		foreach ( VHB_Club_Donnees::categories() as $c ) {
			if ( $pratiques && ! in_array( $c['pratique'], $pratiques, true ) ) {
				continue;
			}
			if ( $codes && ! in_array( $c['code'], $codes, true ) ) {
				continue;
			}
			if ( $lieu ) {
				$c['creneaux'] = array_values(
					array_filter(
						$c['creneaux'],
						static function ( $s ) use ( $lieu ) {
							return $s['lieu'] === $lieu['id'];
						}
					)
				);
			}
			if ( ! $c['creneaux'] ) {
				continue;
			}
			$cats[] = $c;
		}
		if ( $codes ) {
			// Ordre demandé dans le shortcode.
			usort(
				$cats,
				static function ( $x, $y ) use ( $codes ) {
					return array_search( $x['code'], $codes, true ) <=> array_search( $y['code'], $codes, true );
				}
			);
		}
		if ( ! $cats ) {
			return self::msg( 'Aucun créneau à afficher pour ces critères (saison ' . VHB_Club_Reglages::saison_en_cours() . ').', 'Planning en cours de mise à jour.' );
		}

		// Lieux utilisés : nom affiché seulement s'il y en a plusieurs.
		$lieux_ids = array();
		foreach ( $cats as $c ) {
			foreach ( $c['creneaux'] as $s ) {
				$l = VHB_Club_Donnees::lieu( $s['lieu'] );
				if ( $l && $l['publie'] ) {
					$lieux_ids[ $l['id'] ] = true;
				}
			}
		}
		$multi = count( $lieux_ids ) > 1;

		$body = 'jours' === $a['vue'] ? self::planning_jours( $cats, $multi ) : self::planning_tableau( $cats, $multi, 'pratique' === $a['groupe'] );

		if ( self::oui( $a['lieux'] ) && $lieux_ids ) {
			$body .= self::legende( array_keys( $lieux_ids ) );
		}
		return self::wrap( self::head( $a['surtitre'], $a['titre'] ) . $body, 'vhbc-planning' );
	}

	private static function planning_tableau( array $cats, $multi, $grouper ) {
		$h   = '<div class="vhbc-table-wrap"><table class="vhbc-table">';
		$h  .= '<thead><tr><th scope="col">Catégorie</th><th scope="col">Années</th><th scope="col">Entraînements</th></tr></thead><tbody>';
		$cur = null;
		foreach ( $cats as $c ) {
			if ( $grouper && $c['pratique'] !== $cur ) {
				$cur = $c['pratique'];
				$h  .= '<tr class="vhbc-group"><th colspan="3" scope="colgroup">' . self::t( VHB_Club_Types::PRATIQUES[ $cur ] ?? $cur ) . '</th></tr>';
			}
			$h .= '<tr><th scope="row" class="vhbc-c-cat"><span class="vhbc-cat">' . self::t( $c['nom'] ) . '</span>';
			if ( $c['ententes'] ) {
				$h .= ' <span class="vhbc-tag">Entente</span>';
			}
			$h .= self::statut_tag( $c ) . '</th>';
			$h .= '<td class="vhbc-c-annees" data-label="Années">' . self::t( $c['annees'] ) . '</td>';
			$h .= '<td class="vhbc-c-slots"><ul class="vhbc-slots">';
			foreach ( $c['creneaux'] as $s ) {
				$h .= self::creneau_html( $s, $multi );
			}
			$h .= '</ul></td></tr>';
		}
		return $h . '</tbody></table></div>';
	}

	private static function planning_jours( array $cats, $multi ) {
		$jours = array();
		foreach ( $cats as $c ) {
			foreach ( $c['creneaux'] as $s ) {
				$jours[ $s['jour'] ][] = array( 'c' => $c, 's' => $s );
			}
		}
		ksort( $jours );
		$h = '<div class="vhbc-days">';
		foreach ( $jours as $j => $list ) {
			usort(
				$list,
				static function ( $x, $y ) {
					return array( $x['s']['debut'], $x['c']['nom'] ) <=> array( $y['s']['debut'], $y['c']['nom'] );
				}
			);
			$h .= '<section class="vhbc-day-col"><h3 class="vhbc-h">' . self::t( VHB_Club_Types::JOURS[ $j ] ) . '</h3><ul class="vhbc-day-list">';
			foreach ( $list as $it ) {
				$s  = $it['s'];
				$l  = $multi ? VHB_Club_Donnees::lieu( $s['lieu'] ) : null;
				$h .= '<li class="vhbc-day-item"><span class="vhbc-time">' . self::t( VHB_Club_Donnees::plage( $s['debut'], $s['fin'] ) ) . '</span>'
					. '<span class="vhbc-cat">' . self::t( $it['c']['nom'] ) . '</span>';
				if ( $l && $l['publie'] ) {
					$h .= '<span class="vhbc-place">' . self::t( $l['nom_court'] ) . '</span>';
				}
				if ( 'hors_vacances' === $s['periode'] || '' !== $s['note'] ) {
					$h .= '<span class="vhbc-slot-note">' . self::t( implode( ', ', array_filter( array( 'hors_vacances' === $s['periode'] ? 'hors vacances scolaires' : '', $s['note'] ) ) ) ) . '</span>';
				}
				$h .= '</li>';
			}
			$h .= '</ul></section>';
		}
		return $h . '</div>';
	}

	/** Adresses des lieux utilisés, sous le planning. */
	private static function legende( array $ids ) {
		$lieux = array_filter( array_map( array( 'VHB_Club_Donnees', 'lieu' ), $ids ) );
		usort(
			$lieux,
			static function ( $a, $b ) {
				return array( 'club' === $a['type'] ? 0 : 1, $a['nom'] ) <=> array( 'club' === $b['type'] ? 0 : 1, $b['nom'] );
			}
		);
		$h = '<div class="vhbc-legend"><h3 class="vhbc-h">' . ( count( $lieux ) > 1 ? 'Lieux d\'entraînement' : 'Lieu d\'entraînement' ) . '</h3><ul class="vhbc-legend-list">';
		foreach ( $lieux as $l ) {
			$u  = VHB_Club_Donnees::liens_carte( $l );
			$h .= '<li class="vhbc-legend-item">';
			if ( count( $lieux ) > 1 ) {
				$h .= '<span class="vhbc-place">' . self::t( $l['nom_court'] ) . '</span> ';
			}
			$h .= '<span class="vhbc-legend-nom">' . self::t( $l['nom'] ) . '</span>';
			if ( '' !== $l['adresse_complete'] ) {
				$h .= ', <span class="vhbc-legend-addr">' . self::t( $l['adresse_complete'] ) . '</span>';
			}
			$h .= ' <a class="vhbc-link" href="' . esc_url( $u['google'] ) . '" target="_blank" rel="noopener">Itinéraire</a></li>';
		}
		return $h . '</ul></div>';
	}

	/* ------------------------------------------------------------------ */
	/* [vhb_categorie]                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Options : categorie="u13-f", description, creneaux, tarif, pratique_infos
	 * (équipement et séances d'essai) : "oui|non".
	 */
	public static function sc_categorie( $atts ) {
		$a = shortcode_atts(
			array(
				'categorie'      => '',
				'description'    => 'oui',
				'creneaux'       => 'oui',
				'tarif'          => 'oui',
				'pratique_infos' => 'oui',
				'matchs'         => 'oui',
			),
			$atts,
			'vhb_categorie'
		);
		if ( '' === trim( $a['categorie'] ) ) {
			return self::msg( 'Choisissez une catégorie dans le panneau de droite.' );
		}
		$c = VHB_Club_Donnees::categorie_par_code( $a['categorie'] );
		if ( ! $c ) {
			return self::msg( 'Catégorie « ' . $a['categorie'] . ' » introuvable pour la saison ' . VHB_Club_Reglages::saison_en_cours() . '.' );
		}
		self::enqueue();

		$h = '<article class="vhbc-card vhbc-categorie"><div class="vhbc-head">';
		$k = self::kicker_categorie( $c );
		if ( '' !== $k ) {
			$h .= '<span class="vhbc-kicker">' . self::t( $k ) . '</span>';
		}
		$h .= '<h3 class="vhbc-title">' . self::t( $c['nom'] ) . self::statut_tag( $c, true ) . '</h3>';
		$n  = self::nes( $c );
		if ( '' !== $n ) {
			$h .= '<span class="vhbc-sub">' . self::t( $n ) . '</span>';
		}
		$h .= '</div>';

		if ( self::oui( $a['description'] ) && '' !== $c['description'] ) {
			$h .= '<div class="vhbc-desc">' . self::t( $c['description'] ) . '</div>';
		}
		$e = self::ententes_txt( $c );
		if ( '' !== $e ) {
			$h .= '<div class="vhbc-entente"><span class="vhbc-tag">Entente</span> ' . self::t( $e ) . '</div>';
		}
		if ( self::oui( $a['creneaux'] ) && $c['creneaux'] ) {
			$h .= '<h4 class="vhbc-h">Entraînements</h4><ul class="vhbc-slots vhbc-slots--card">';
			foreach ( $c['creneaux'] as $s ) {
				$h .= self::creneau_carte( $s );
			}
			$h .= '</ul>';
		}
		if ( self::oui( $a['matchs'] ) && $c['equipes'] ) {
			$h .= self::prochains_matchs( $c );
		}
		$facts = array();
		if ( self::oui( $a['tarif'] ) && '' !== $c['tarif'] ) {
			$facts[] = array( 'Licence', VHB_Club_Inscriptions::montant( $c['tarif'] ) . ( '' !== $c['inclus'] ? ', ' . $c['inclus'] . ' inclus' : '' ) );
		}
		if ( self::oui( $a['pratique_infos'] ) ) {
			$eq = VHB_Club_Inscriptions::equipement( $c['pratique'] );
			if ( '' !== $eq ) {
				$facts[] = array( 'Équipement', $eq );
			}
			$es = trim( (string) VHB_Club_Inscriptions::get()['essai'] );
			if ( '' !== $es ) {
				$facts[] = array( 'Essai', $es );
			}
		}
		if ( $facts ) {
			$h .= '<dl class="vhbc-facts">';
			foreach ( $facts as $f ) {
				$h .= '<div class="vhbc-fact"><dt>' . self::t( $f[0] ) . '</dt><dd>' . self::t( $f[1] ) . '</dd></div>';
			}
			$h .= '</dl>';
		}
		return self::wrap( $h . '</article>', 'vhbc-categorie-wrap' );
	}

	/** Prochain match de chaque équipe VHB Résultats liée à la catégorie. */
	private static function prochains_matchs( array $c ) {
		$items = array();
		foreach ( $c['equipes'] as $slug ) {
			$m = VHB_Club_Ffhb::prochain( $slug );
			if ( ! $m ) {
				continue;
			}
			if ( $m['ts'] ) {
				$jours = array( 1 => 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.' );
				$quand = ucfirst( $jours[ (int) wp_date( 'N', $m['ts'] ) ] ) . ' ' . VHB_Club_Donnees::date_longue( wp_date( 'Y-m-d', $m['ts'] ) ) . ', ' . VHB_Club_Donnees::heure( wp_date( 'H:i', $m['ts'] ) );
			} else {
				$quand = 'Week-end ' . VHB_Club_Donnees::periode( $m['journee'][0], $m['journee'][1] ?: $m['journee'][0], true ) . ', horaire à venir';
			}
			$t  = count( $c['equipes'] ) > 1 ? '<span class="vhbc-match-eq">' . self::t( $m['equipe'] ) . '</span>' : '';
			$items[] = '<li class="vhbc-match">' . $t
				. '<span class="vhbc-match-when">' . self::t( $quand ) . '</span>'
				. '<span class="vhbc-match-vs"><span class="vhbc-tag' . ( $m['domicile'] ? ' vhbc-tag--ok' : '' ) . '">' . ( $m['domicile'] ? 'Domicile' : 'Extérieur' ) . '</span> ' . self::t( ( $m['domicile'] ? 'contre ' : 'chez ' ) . $m['adversaire'] ) . '</span>'
				. ( '' !== $m['lieu'] || '' !== $m['competition'] ? '<span class="vhbc-match-lieu">' . self::t( implode( ' · ', array_filter( array( $m['competition'], $m['lieu'] ) ) ) ) . '</span>' : '' )
				. '</li>';
		}
		return $items ? '<h4 class="vhbc-h">' . ( count( $items ) > 1 ? 'Prochains matchs' : 'Prochain match' ) . '</h4><ul class="vhbc-matches">' . implode( '', $items ) . '</ul>' : '';
	}

	/** Créneau d'une fiche : jour, heures, lieu complet et lien d'itinéraire. */
	private static function creneau_carte( array $s ) {
		$h = '<li class="vhbc-slot"><span class="vhbc-day">' . self::t( VHB_Club_Types::JOURS[ $s['jour'] ] ?? '' ) . '</span> '
			. '<span class="vhbc-time">' . self::t( VHB_Club_Donnees::plage( $s['debut'], $s['fin'] ) ) . '</span>';
		$l = VHB_Club_Donnees::lieu( $s['lieu'] );
		if ( $l && $l['publie'] ) {
			$u  = VHB_Club_Donnees::liens_carte( $l );
			$h .= '<span class="vhbc-slot-place"><a class="vhbc-link" href="' . esc_url( $u['google'] ) . '" target="_blank" rel="noopener">' . self::t( $l['nom'] ) . '</a>';
			if ( '' !== $l['ville'] ) {
				$h .= ', ' . self::t( $l['ville'] );
			}
			$h .= '</span>';
		}
		$extra = array_filter( array( 'hors_vacances' === $s['periode'] ? 'hors vacances scolaires' : '', $s['note'] ) );
		if ( $extra ) {
			$h .= '<span class="vhbc-slot-note">' . self::t( implode( ', ', $extra ) ) . '</span>';
		}
		return $h . '</li>';
	}

	/* ------------------------------------------------------------------ */
	/* [vhb_lieu]                                                          */
	/* ------------------------------------------------------------------ */

	/** Options : lieu="<slug>|principal|tous|club|partenaire". */
	public static function sc_lieu( $atts ) {
		$a   = shortcode_atts( array( 'lieu' => 'principal' ), $atts, 'vhb_lieu' );
		$ref = trim( (string) $a['lieu'] );
		if ( in_array( $ref, array( 'tous', 'club', 'partenaire' ), true ) ) {
			$list = array_values(
				array_filter(
					VHB_Club_Donnees::lieux(),
					static function ( $l ) use ( $ref ) {
						return 'tous' === $ref || $l['type'] === $ref;
					}
				)
			);
		} else {
			$l    = '' === $ref || 'principal' === $ref ? VHB_Club_Donnees::lieu_principal() : VHB_Club_Donnees::lieu_par_ref( $ref );
			$list = $l && $l['publie'] ? array( $l ) : array();
		}
		if ( ! $list ) {
			return self::msg( 'Aucun lieu publié ne correspond à « ' . $ref . ' ».' );
		}
		self::enqueue();
		$h = '<div class="vhbc-lieux' . ( count( $list ) > 1 ? ' vhbc-lieux--grid' : '' ) . '">';
		foreach ( $list as $l ) {
			$h .= '<article class="vhbc-card vhbc-lieu"><div class="vhbc-head">';
			$h .= '<span class="vhbc-kicker">' . self::t( 'club' === $l['type'] ? 'Notre gymnase' : 'Gymnase partenaire' ) . '</span>';
			$h .= '<h3 class="vhbc-title">' . self::t( $l['nom'] ) . '</h3></div>';
			if ( '' !== $l['adresse_complete'] ) {
				$h .= '<div class="vhbc-addr">' . self::t( $l['adresse_complete'] ) . '</div>';
			}
			if ( 'partenaire' === $l['type'] && $l['entente'] ) {
				$e = VHB_Club_Donnees::entente( $l['entente'] );
				if ( $e ) {
					$h .= '<div class="vhbc-muted">' . self::t( 'Gymnase de ' . $e['nom'] ) . '</div>';
				}
			}
			if ( '' !== $l['acces'] ) {
				$h .= '<div class="vhbc-desc">' . self::t( $l['acces'] ) . '</div>';
			}
			if ( $l['pmr'] ) {
				$h .= '<div><span class="vhbc-tag vhbc-tag--ok">Accessible aux personnes à mobilité réduite</span></div>';
			}
			$h .= self::nav( $l ) . '</article>';
		}
		return self::wrap( $h . '</div>', 'vhbc-lieu-wrap' );
	}

	/* ------------------------------------------------------------------ */
	/* [vhb_info]                                                          */
	/* ------------------------------------------------------------------ */

	/** Clés disponibles, pour l'éditeur et l'aide. */
	public static function cles_info() {
		return array(
			'nom'            => 'Nom du club',
			'sigle'          => 'Sigle',
			'saison'         => 'Saison en cours (2026-2027)',
			'email'          => 'Email du secrétariat',
			'email_licences' => 'Email des licences',
			'telephone'      => 'Téléphone',
			'siege'          => 'Adresse du siège',
			'affiliation'    => 'Numéro d\'affiliation FFHB',
			'facebook'       => 'Page Facebook',
			'instagram'      => 'Compte Instagram',
			'gymnase'        => 'Nom du gymnase principal',
			'adresse'        => 'Adresse du gymnase principal',
			'annees'         => 'Années de naissance d\'une catégorie',
			'tarif'          => 'Tarif d\'une catégorie',
			'essai'          => 'Séances d\'essai',
			'equipement'     => 'Équipement (liste générale)',
			'president'      => 'Président(e) du club',
			'prochain_stage' => 'Prochain stage (titre, dates, horaires, public)',
		);
	}

	/** Options : cle="email", lien="oui|non", categorie="u13-f" (pour cle="annees"). */
	public static function sc_info( $atts ) {
		$a   = shortcode_atts( array( 'cle' => '', 'lien' => 'non', 'categorie' => '' ), $atts, 'vhb_info' );
		$cle = sanitize_key( $a['cle'] );
		$r   = VHB_Club_Reglages::get();
		$val = '';
		$url = '';
		switch ( $cle ) {
			case 'saison':
				$val = VHB_Club_Reglages::saison_en_cours();
				break;
			case 'email':
			case 'email_licences':
				$val = $r[ $cle ];
				$url = $val ? 'mailto:' . $val : '';
				break;
			case 'telephone':
				$val = $r['telephone'];
				$url = $val ? 'tel:' . preg_replace( '/[^0-9+]/', '', $val ) : '';
				break;
			case 'facebook':
			case 'instagram':
				$val = $r[ $cle ];
				$url = $val;
				break;
			case 'nom':
			case 'sigle':
			case 'siege':
			case 'affiliation':
				$val = $r[ $cle ];
				break;
			case 'gymnase':
			case 'adresse':
				$l = VHB_Club_Donnees::lieu_principal();
				if ( $l ) {
					$val = 'gymnase' === $cle ? $l['nom'] : $l['adresse_complete'];
					$url = VHB_Club_Donnees::liens_carte( $l )['google'];
				}
				break;
			case 'annees':
				$c   = VHB_Club_Donnees::categorie_par_code( $a['categorie'] );
				$val = $c ? $c['annees'] : '';
				break;
			case 'tarif':
				$c   = VHB_Club_Donnees::categorie_par_code( $a['categorie'] );
				$val = $c ? VHB_Club_Inscriptions::montant( $c['tarif'] ) : '';
				break;
			case 'essai':
				$val = VHB_Club_Inscriptions::get()['essai'];
				break;
			case 'equipement':
				$val = VHB_Club_Inscriptions::get()['equipement'];
				break;
			case 'president':
				$p   = VHB_Club_Vie::president();
				$val = $p ? VHB_Club_Vie::nom( $p ) : '';
				break;
			case 'prochain_stage':
				$st = VHB_Club_Stages::liste( 'a_venir', array( 'stage' ), 1 );
				if ( $st ) {
					$val = $st[0]['titre'] . ' · ' . VHB_Club_Stages::resume( $st[0] );
					$url = $st[0]['helloasso'];
				}
				break;
		}
		if ( '' === (string) $val ) {
			return self::is_editor() ? '<span class="vhbc-info vhbc-info--vide">[' . esc_html( $cle ? $cle . ' : non renseigné' : 'choisir une donnée' ) . ']</span>' : '';
		}
		$txt = str_replace( "\n", '<br>', self::t( $val ) );
		if ( $url && self::oui( $a['lien'] ) ) {
			$ext = 0 === strpos( $url, 'http' ) ? ' target="_blank" rel="noopener"' : '';
			return '<a class="vhbc-info" href="' . esc_url( $url, array( 'http', 'https', 'mailto', 'tel' ) ) . '"' . $ext . '>' . $txt . '</a>';
		}
		return '<span class="vhbc-info">' . $txt . '</span>';
	}

	/* ------------------------------------------------------------------ */
	/* [vhb_tarifs]                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Options : pratique="competition,loisir", regrouper="oui|non" (catégories
	 * de même tarif et mêmes années sur une ligne), inclus="oui|non",
	 * autres="oui|non" (autres licences), mention="oui|non", titre, surtitre.
	 */
	public static function sc_tarifs( $atts ) {
		$a = shortcode_atts(
			array(
				'pratique'  => '',
				'regrouper' => 'oui',
				'inclus'    => 'oui',
				'autres'    => 'oui',
				'mention'   => 'oui',
				'titre'     => '',
				'surtitre'  => '',
			),
			$atts,
			'vhb_tarifs'
		);
		$pratiques = self::liste( $a['pratique'] );
		$s         = VHB_Club_Inscriptions::saison();
		$lignes    = array();
		foreach ( VHB_Club_Donnees::categories() as $c ) {
			if ( '' === $c['tarif'] || ( $pratiques && ! in_array( $c['pratique'], $pratiques, true ) ) ) {
				continue;
			}
			$cle  = $c['annees'] . '|' . $c['tarif'] . '|' . $c['inclus'] . '|' . $c['statut'];
			$last = end( $lignes );
			if ( self::oui( $a['regrouper'] ) && $last && $last['cle'] === $cle ) {
				$lignes[ key( $lignes ) ]['noms'][] = $c['nom'];
				continue;
			}
			$lignes[] = array(
				'cle'      => $cle,
				'noms'     => array( $c['nom'] ),
				'annees'   => $c['annees'],
				'montant'  => VHB_Club_Inscriptions::montant( $c['tarif'] ),
				'inclus'   => $c['inclus'],
				'statut'   => self::statut_tag( $c ),
				'entente'  => (bool) $c['ententes'],
			);
		}
		if ( self::oui( $a['autres'] ) && ! $pratiques ) {
			foreach ( $s['autres'] as $o ) {
				$lignes[] = array(
					'cle'     => '',
					'noms'    => array( $o['nom'] ),
					'annees'  => $o['annees'],
					'montant' => '' !== $o['mention'] ? $o['mention'] : VHB_Club_Inscriptions::montant( $o['montant'] ),
					'inclus'  => '',
					'statut'  => '',
					'autre'   => true,
				);
			}
		}
		if ( ! $lignes ) {
			return self::msg( 'Aucun tarif renseigné pour la saison ' . VHB_Club_Reglages::saison_en_cours() . ' (champ « Tarif » des catégories).', 'Tarifs en cours de mise à jour.' );
		}
		self::enqueue();
		$avec_inclus = self::oui( $a['inclus'] ) && array_filter( wp_list_pluck( $lignes, 'inclus' ) );

		$h  = '<div class="vhbc-table-wrap"><table class="vhbc-table vhbc-tarifs"><thead><tr><th scope="col">Licence</th><th scope="col">Années</th>';
		$h .= ( $avec_inclus ? '<th scope="col">Inclus</th>' : '' ) . '<th scope="col" class="vhbc-c-prix">Tarif</th></tr></thead><tbody>';
		foreach ( $lignes as $l ) {
			$h .= '<tr' . ( ! empty( $l['autre'] ) ? ' class="vhbc-autre"' : '' ) . '><th scope="row" class="vhbc-c-cat"><span class="vhbc-cat">' . self::t( implode( ' · ', $l['noms'] ) ) . '</span>' . $l['statut'] . '</th>';
			$h .= '<td class="vhbc-c-annees">' . self::t( $l['annees'] ) . '</td>';
			if ( $avec_inclus ) {
				$h .= '<td class="vhbc-c-inclus">' . self::t( $l['inclus'] ) . '</td>';
			}
			$h .= '<td class="vhbc-c-prix">' . self::t( $l['montant'] ) . '</td></tr>';
		}
		$h .= '</tbody></table></div>';
		if ( self::oui( $a['mention'] ) && '' !== $s['note'] ) {
			$h .= '<div class="vhbc-mention">' . self::t( $s['note'] ) . '</div>';
		}
		return self::wrap( self::head( $a['surtitre'], $a['titre'] ) . $h, 'vhbc-tarifs-wrap' );
	}

	/* ------------------------------------------------------------------ */
	/* [vhb_reductions]                                                    */
	/* ------------------------------------------------------------------ */

	/** Options : reductions, paiement, essai : "oui|non" ; titre, surtitre. */
	public static function sc_reductions( $atts ) {
		$a = shortcode_atts(
			array(
				'reductions' => 'oui',
				'paiement'   => 'oui',
				'essai'      => 'non',
				'titre'      => '',
				'surtitre'   => '',
			),
			$atts,
			'vhb_reductions'
		);
		$s     = VHB_Club_Inscriptions::saison();
		$c     = VHB_Club_Inscriptions::get();
		$cards = '';
		if ( self::oui( $a['reductions'] ) && $s['reductions'] ) {
			$cards .= '<section class="vhbc-card vhbc-panel"><h3 class="vhbc-h">Réductions et aides</h3><ul class="vhbc-list vhbc-list--reduc">';
			foreach ( $s['reductions'] as $r ) {
				$cards .= '<li><span class="vhbc-list-label">' . self::t( $r['libelle'] ) . '</span> ';
				if ( '' !== $r['montant'] ) {
					$cards .= '<span class="vhbc-amount">' . self::t( VHB_Club_Inscriptions::montant( $r['montant'], $r['unite'] ) ) . '</span> ';
				}
				$cards .= '<span class="vhbc-list-text">' . self::t( $r['precision'] ) . '</span></li>';
			}
			$cards .= '</ul></section>';
		}
		if ( self::oui( $a['paiement'] ) && $c['paiement'] ) {
			$cards .= '<section class="vhbc-card vhbc-panel"><h3 class="vhbc-h">Paiement</h3><ul class="vhbc-list vhbc-list--check">';
			foreach ( $c['paiement'] as $l ) {
				$cards .= '<li>' . self::t( $l ) . '</li>';
			}
			$cards .= '</ul></section>';
		}
		if ( self::oui( $a['essai'] ) && '' !== trim( $c['essai'] ) ) {
			$cards .= '<section class="vhbc-card vhbc-panel"><h3 class="vhbc-h">Séances d\'essai</h3><p class="vhbc-p">' . self::t( $c['essai'] ) . '</p></section>';
		}
		if ( '' === $cards ) {
			return self::msg( 'Rien à afficher : réductions et paiement se règlent dans ' . VHB_MARQUE . ' > Inscriptions.' );
		}
		self::enqueue();
		return self::wrap( self::head( $a['surtitre'], $a['titre'] ) . '<div class="vhbc-panels">' . $cards . '</div>', 'vhbc-reductions-wrap' );
	}

	/* ------------------------------------------------------------------ */
	/* [vhb_pieces]                                                        */
	/* ------------------------------------------------------------------ */

	/** Options : pieces, documents, equipement : "oui|non" ; titre, surtitre. */
	public static function sc_pieces( $atts ) {
		$a = shortcode_atts(
			array(
				'pieces'     => 'oui',
				'documents'  => 'oui',
				'equipement' => 'non',
				'titre'      => '',
				'surtitre'   => '',
			),
			$atts,
			'vhb_pieces'
		);
		$c     = VHB_Club_Inscriptions::get();
		$cards = '';
		if ( self::oui( $a['pieces'] ) && $c['pieces'] ) {
			$cards .= '<section class="vhbc-card vhbc-panel"><h3 class="vhbc-h">Pièces à fournir</h3><ul class="vhbc-list vhbc-list--check">';
			foreach ( $c['pieces'] as $l ) {
				$cards .= '<li>' . self::t( $l ) . '</li>';
			}
			$cards .= '</ul>' . ( '' !== $c['depot'] ? '<p class="vhbc-p vhbc-muted">' . self::t( $c['depot'] ) . '</p>' : '' ) . '</section>';
		}
		$docs = array();
		foreach ( $c['documents'] as $d ) {
			$url = $d['fichier'] ? wp_get_attachment_url( $d['fichier'] ) : '';
			$url = $url ? $url : $d['url'];
			if ( ! $url ) {
				continue;
			}
			$titre  = '' !== $d['titre'] ? $d['titre'] : ( $d['fichier'] ? get_the_title( $d['fichier'] ) : $url );
			$type   = strtoupper( (string) pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
			$docs[] = '<li><a class="vhbc-doc" href="' . esc_url( $url ) . '" target="_blank" rel="noopener"><span class="vhbc-doc-ico" aria-hidden="true">' . self::t( $type && strlen( $type ) <= 4 ? $type : 'LIEN' ) . '</span><span class="vhbc-doc-t">' . self::t( $titre ) . '</span></a></li>';
		}
		if ( self::oui( $a['documents'] ) && $docs ) {
			$cards .= '<section class="vhbc-card vhbc-panel"><h3 class="vhbc-h">Documents</h3><ul class="vhbc-list vhbc-docs">' . implode( '', $docs ) . '</ul></section>';
		}
		if ( self::oui( $a['equipement'] ) && '' !== trim( $c['equipement'] ) ) {
			$cards .= '<section class="vhbc-card vhbc-panel"><h3 class="vhbc-h">Équipement</h3><p class="vhbc-p">' . self::t( $c['equipement'] ) . '</p></section>';
		}
		if ( '' === $cards ) {
			return self::msg( 'Rien à afficher : pièces et documents se règlent dans ' . VHB_MARQUE . ' > Inscriptions.' );
		}
		self::enqueue();
		return self::wrap( self::head( $a['surtitre'], $a['titre'] ) . '<div class="vhbc-panels">' . $cards . '</div>', 'vhbc-pieces-wrap' );
	}

	/* ------------------------------------------------------------------ */
	/* [vhb_bandeau_inscriptions]                                          */
	/* ------------------------------------------------------------------ */

	/** Bandeau visible seulement si activé (et dans la période, si elle est réglée). */
	public static function sc_bandeau( $atts ) {
		$s = VHB_Club_Inscriptions::saison();
		if ( ! VHB_Club_Inscriptions::bandeau_actif() ) {
			return self::msg( 'Bandeau masqué : à activer dans ' . VHB_MARQUE . ' > Inscriptions (case « Afficher le bandeau », dates facultatives).' );
		}
		self::enqueue();
		$sous = 'Saison ' . VHB_Club_Reglages::saison_en_cours();
		if ( $s['cloture'] ) {
			$d    = DateTimeImmutable::createFromFormat( '!Y-m-d', $s['cloture'], wp_timezone() );
			$sous .= $d ? ' · jusqu\'au ' . wp_date( 'j F Y', $d->getTimestamp() ) : '';
		}
		$h  = '<div class="vhbc-promo"><div class="vhbc-promo-txt"><span class="vhbc-promo-t">' . self::t( $s['texte'] ) . '</span>';
		$h .= '<span class="vhbc-promo-s">' . self::t( $sous ) . '</span></div>';
		if ( $s['lien'] ) {
			$h .= '<a class="vhbc-promo-btn" href="' . esc_url( $s['lien'] ) . '">S\'inscrire</a>';
		}
		return self::wrap( $h . '</div>', 'vhbc-bandeau-wrap' );
	}

	/* ------------------------------------------------------------------ */
	/* [vhb_stages]                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Options : quand="a_venir|passes|tous", nombre="0", type="stage,tournoi",
	 * vue="cartes|compact", tarifs="oui|non", widget="non|oui" (formulaire
	 * HelloAsso intégré), lien="<url>" (vue compacte : lien de chaque ligne,
	 * sinon la billetterie), vide="<texte>", titre, surtitre.
	 */
	public static function sc_stages( $atts ) {
		$a = shortcode_atts(
			array(
				'quand'    => 'a_venir',
				'nombre'   => '0',
				'type'     => '',
				'vue'      => 'cartes',
				'tarifs'   => 'oui',
				'widget'   => 'non',
				'lien'     => '',
				'vide'     => 'Aucun stage programmé pour le moment. Les prochains stages seront annoncés ici.',
				'titre'    => '',
				'surtitre' => '',
			),
			$atts,
			'vhb_stages'
		);
		$list = VHB_Club_Stages::liste( $a['quand'], self::liste( $a['type'] ), (int) $a['nombre'] );
		self::enqueue();
		$head = self::head( $a['surtitre'], $a['titre'] );
		if ( ! $list ) {
			return '' === trim( $a['vide'] ) ? self::msg( 'Aucun stage à afficher.' ) : self::wrap( $head . '<div class="vhbc-msg">' . self::t( $a['vide'] ) . '</div>' );
		}
		if ( 'compact' === $a['vue'] ) {
			$h = '<ul class="vhbc-stage-list">';
			foreach ( $list as $s ) {
				$url  = '' !== $a['lien'] ? $a['lien'] : $s['helloasso'];
				$in   = '<span class="vhbc-star" aria-hidden="true">&#9733;</span><span class="vhbc-stage-txt"><span class="vhbc-stage-name">' . self::t( $s['titre'] )
					. ( 'en_cours' === $s['etat'] ? ' <span class="vhbc-tag vhbc-tag--ok">En cours</span>' : '' ) . '</span><span class="vhbc-stage-info">' . self::t( VHB_Club_Stages::resume( $s ) ) . '</span></span>';
				$h   .= '<li>' . ( $url ? '<a class="vhbc-stage-line" href="' . esc_url( $url ) . '">' . $in . '<span class="vhbc-chev" aria-hidden="true">&#8250;</span></a>' : '<div class="vhbc-stage-line">' . $in . '</div>' ) . '</li>';
			}
			return self::wrap( $head . $h . '</ul>', 'vhbc-stages-wrap' );
		}
		$h = '<div class="vhbc-stages">';
		foreach ( $list as $s ) {
			$h .= self::stage_carte( $s, self::oui( $a['tarifs'] ), self::oui( $a['widget'] ) );
		}
		return self::wrap( $head . $h . '</div>', 'vhbc-stages-wrap' );
	}

	private static function stage_carte( array $s, $tarifs, $widget ) {
		$kick = VHB_Club_Types::TYPES_STAGE[ $s['type'] ] ?? 'Stage';
		$h    = '<article class="vhbc-card vhbc-stage"><div class="vhbc-head"><span class="vhbc-kicker">' . self::t( $kick ) . '</span>';
		$h   .= '<h3 class="vhbc-title">' . self::t( $s['titre'] ) . ( 'en_cours' === $s['etat'] ? ' <span class="vhbc-tag vhbc-tag--ok">En cours</span>' : '' ) . ( $s['complet'] ? ' <span class="vhbc-tag vhbc-tag--complet">Complet</span>' : '' ) . '</h3>';
		$h   .= '<span class="vhbc-sub">' . self::t( ucfirst( VHB_Club_Donnees::periode( $s['debut'], $s['fin'] ) ) ) . '</span></div>';
		$facts = array();
		if ( '' !== $s['horaires'] ) {
			$facts[] = array( 'Horaires', self::t( $s['horaires'] ) );
		}
		if ( '' !== $s['groupes'] ) {
			$facts[] = array( 'Groupes', self::t( $s['groupes'] ) );
		} elseif ( '' !== $s['public'] ) {
			$facts[] = array( 'Public', self::t( $s['public'] ) );
		}
		list( $lieu, $map ) = VHB_Club_Stages::lieu_txt( $s );
		if ( '' !== $lieu ) {
			$facts[] = array( 'Lieu', $map ? '<a class="vhbc-link" href="' . esc_url( $map ) . '" target="_blank" rel="noopener">' . self::t( $lieu ) . '</a>' : self::t( $lieu ) );
		}
		foreach ( $s['infos'] as $i ) {
			$facts[] = array( 'Pratique', self::t( $i ) );
		}
		if ( $facts ) {
			$h .= '<dl class="vhbc-facts vhbc-facts--top">';
			foreach ( $facts as $f ) {
				$h .= '<div class="vhbc-fact"><dt>' . self::t( $f[0] ) . '</dt><dd>' . $f[1] . '</dd></div>';
			}
			$h .= '</dl>';
		}
		if ( $tarifs && $s['tarifs'] ) {
			$ext = array_filter( wp_list_pluck( $s['tarifs'], 'exterieur' ), 'strlen' );
			$h  .= '<table class="vhbc-mini"><thead><tr><th scope="col">Formule</th><th scope="col">' . ( $ext ? 'Adhérent' : 'Tarif' ) . '</th>' . ( $ext ? '<th scope="col">Extérieur</th>' : '' ) . '</tr></thead><tbody>';
			foreach ( $s['tarifs'] as $t ) {
				$h .= '<tr><th scope="row">' . self::t( $t['libelle'] ) . '</th><td>' . self::t( VHB_Club_Inscriptions::montant( $t['adherent'] ) ) . '</td>' . ( $ext ? '<td>' . self::t( VHB_Club_Inscriptions::montant( $t['exterieur'] ) ) . '</td>' : '' ) . '</tr>';
			}
			$h .= '</tbody></table>';
		}
		if ( $s['conditions'] ) {
			$h .= '<ul class="vhbc-conditions">';
			foreach ( $s['conditions'] as $c ) {
				$h .= '<li>' . self::t( $c ) . '</li>';
			}
			$h .= '</ul>';
		}
		if ( $s['ouvert'] ) {
			$h .= '<div class="vhbc-actions"><a class="vhbc-promo-btn" href="' . esc_url( $s['helloasso'] ) . '" target="_blank" rel="noopener">Réserver ' . ( 'stage' === $s['type'] ? 'le stage' : 'sa place' ) . '</a></div>';
			$w  = $widget ? VHB_Club_Stages::widget_url( $s['helloasso'] ) : '';
			if ( $w ) {
				$h .= '<iframe class="vhbc-widget" src="' . esc_url( $w ) . '" title="' . esc_attr( 'Réservation : ' . $s['titre'] ) . '" loading="lazy" allowtransparency="true"></iframe>';
			}
		} elseif ( 'termine' !== $s['etat'] && '' !== $s['helloasso'] ) {
			$h .= '<div class="vhbc-actions"><span class="vhbc-muted">' . self::t( $s['complet'] ? 'Stage complet.' : 'Réservations closes.' ) . '</span></div>';
		}
		return $h . '</article>';
	}

	/* ------------------------------------------------------------------ */
	/* [vhb_alertes]                                                       */
	/* ------------------------------------------------------------------ */

	public static function sc_alertes( $atts ) {
		$list = VHB_Club_Vie::alertes_actives();
		if ( ! $list ) {
			return self::msg( 'Aucune alerte en cours : rien ne s\'affiche sur le site (' . VHB_MARQUE . ' > Vie du club).' );
		}
		self::enqueue();
		$h = '<div class="vhbc-alerts">';
		foreach ( $list as $a ) {
			$in = '<span class="vhbc-alert-ico" aria-hidden="true">' . ( 'important' === $a['niveau'] ? '!' : 'i' ) . '</span><span class="vhbc-alert-t">' . self::t( $a['message'] ) . '</span>';
			$h .= '<div class="vhbc-alert vhbc-alert--' . esc_attr( $a['niveau'] ) . '" role="' . ( 'important' === $a['niveau'] ? 'alert' : 'status' ) . '">'
				. ( $a['lien'] ? '<a href="' . esc_url( $a['lien'] ) . '">' . $in . '<span class="vhbc-chev" aria-hidden="true">&#8250;</span></a>' : $in ) . '</div>';
		}
		return self::wrap( $h . '</div>', 'vhbc-alertes-wrap' );
	}

	/* ------------------------------------------------------------------ */
	/* [vhb_organigramme]                                                  */
	/* ------------------------------------------------------------------ */

	/** Options : groupe="bureau,ca" (filtre), groupes="oui|non" (titres), emails="oui|non", titre, surtitre. */
	public static function sc_organigramme( $atts ) {
		$a     = shortcode_atts( array( 'groupe' => '', 'groupes' => 'oui', 'emails' => 'oui', 'titre' => '', 'surtitre' => '' ), $atts, 'vhb_organigramme' );
		$only  = self::liste( $a['groupe'] );
		$o     = VHB_Club_Vie::get();
		$par   = array();
		foreach ( $o['personnes'] as $p ) {
			if ( $only && ! in_array( $p['instance'], $only, true ) ) {
				continue;
			}
			$par[ $p['instance'] ][] = $p;
		}
		if ( ! $par ) {
			return self::msg( 'Organigramme vide : ' . VHB_MARQUE . ' > Organigramme.' );
		}
		self::enqueue();
		$h = self::head( $a['surtitre'], $a['titre'] );
		foreach ( VHB_Club_Vie::INSTANCES as $k => $label ) {
			if ( empty( $par[ $k ] ) ) {
				continue;
			}
			$h .= '<section class="vhbc-orga-group">';
			if ( self::oui( $a['groupes'] ) ) {
				$h .= '<h3 class="vhbc-h">' . self::t( $label ) . '</h3>';
			}
			$h .= '<ul class="vhbc-people">';
			foreach ( $par[ $k ] as $p ) {
				$img = $p['photo'] ? wp_get_attachment_image_url( $p['photo'], 'thumbnail' ) : '';
				$h  .= '<li class="vhbc-person">' . ( $img ? '<img class="vhbc-avatar" src="' . esc_url( $img ) . '" alt="" loading="lazy">' : '<span class="vhbc-avatar" aria-hidden="true">' . self::t( VHB_Club_Vie::initiales( $p ) ) . '</span>' );
				$h  .= '<span class="vhbc-person-txt"><span class="vhbc-person-nom">' . self::t( VHB_Club_Vie::nom( $p ) ) . '</span><span class="vhbc-person-fn">' . self::t( $p['fonction'] ) . '</span>';
				if ( self::oui( $a['emails'] ) && '' !== $p['email'] ) {
					$h .= '<a class="vhbc-link vhbc-person-mail" href="' . esc_url( 'mailto:' . $p['email'] ) . '">' . self::t( $p['email'] ) . '</a>';
				}
				$h .= '</span></li>';
			}
			$h .= '</ul></section>';
		}
		return self::wrap( $h, 'vhbc-orga-wrap' );
	}

	/* ------------------------------------------------------------------ */
	/* [vhb_ag]                                                            */
	/* ------------------------------------------------------------------ */

	/** Options : quand="a_venir|passees|toutes", type="ca|publique", vide="<texte>", titre, surtitre. */
	public static function sc_ag( $atts ) {
		$a    = shortcode_atts( array( 'quand' => 'toutes', 'type' => '', 'vide' => '', 'titre' => '', 'surtitre' => '' ), $atts, 'vhb_ag' );
		$type = isset( VHB_Club_Vie::TYPES_AG[ $a['type'] ] ) ? $a['type'] : '';
		$list = VHB_Club_Vie::ag( $a['quand'], $type );
		if ( ! $list ) {
			return '' !== $a['vide'] ? self::wrap( '<div class="vhbc-msg">' . self::t( $a['vide'] ) . '</div>' ) : self::msg( 'Aucune assemblée générale à afficher (' . VHB_MARQUE . ' > Vie du club).' );
		}
		self::enqueue();
		$t = VHB_Club_Donnees::today();
		$h = '<ul class="vhbc-ag">';
		foreach ( $list as $g ) {
			$passee = '' !== $g['date'] && $g['date'] < $t;
			$url    = $g['fichier'] ? wp_get_attachment_url( $g['fichier'] ) : '';
			$url    = $url ? $url : $g['url'];
			$h     .= '<li class="vhbc-card vhbc-ag-item' . ( $passee ? ' is-past' : '' ) . '"><div class="vhbc-ag-date">';
			if ( '' !== $g['date'] ) {
				$d  = DateTimeImmutable::createFromFormat( '!Y-m-d', $g['date'], wp_timezone() );
				$h .= '<span class="vhbc-ag-d">' . (int) $d->format( 'j' ) . '</span><span class="vhbc-ag-m">' . self::t( array( 1 => 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.' )[ (int) $d->format( 'n' ) ] ) . '</span><span class="vhbc-ag-y">' . $d->format( 'Y' ) . '</span>';
			} else {
				$h .= '<span class="vhbc-ag-m">Date à venir</span>';
			}
			$h .= '</div><div class="vhbc-ag-body"><span class="vhbc-kicker">' . self::t( VHB_Club_Vie::TYPES_AG[ $g['type'] ] ) . '</span><span class="vhbc-ag-t">' . self::t( '' !== $g['titre'] ? $g['titre'] : 'Assemblée générale' ) . '</span>';
			$meta = array_filter( array( '' !== $g['date'] ? ucfirst( VHB_Club_Donnees::date_longue( $g['date'], true ) ) . ( '' !== $g['heure'] ? ', ' . $g['heure'] : '' ) : '', $g['lieu'] ) );
			if ( $meta ) {
				$h .= '<span class="vhbc-ag-meta">' . self::t( implode( ' · ', $meta ) ) . '</span>';
			}
			if ( '' !== $g['description'] ) {
				$h .= '<span class="vhbc-ag-meta">' . self::t( $g['description'] ) . '</span>';
			}
			if ( $url ) {
				$h .= '<a class="vhbc-btn" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . self::t( $passee ? 'Compte rendu' : 'Document' ) . '</a>';
			}
			$h .= '</div></li>';
		}
		return self::wrap( self::head( $a['surtitre'], $a['titre'] ) . $h . '</ul>', 'vhbc-ag-wrap' );
	}

	/* ------------------------------------------------------------------ */
	/* [vhb_boutique]                                                      */
	/* ------------------------------------------------------------------ */

	public static function sc_boutique( $atts ) {
		$b = VHB_Club_Vie::boutique_active();
		if ( ! $b ) {
			return self::msg( 'Bandeau boutique masqué : à activer dans ' . VHB_MARQUE . ' > Vie du club (case, lien et dates facultatives).' );
		}
		self::enqueue();
		$sous = $b['texte'];
		if ( $b['fin'] ) {
			$sous .= ( '' !== $sous ? ' · ' : '' ) . 'Commandes jusqu\'au ' . VHB_Club_Donnees::date_longue( $b['fin'] );
		}
		$h = '<div class="vhbc-promo"><div class="vhbc-promo-txt"><span class="vhbc-promo-t">' . self::t( $b['titre'] ) . '</span>' . ( '' !== $sous ? '<span class="vhbc-promo-s">' . self::t( $sous ) . '</span>' : '' ) . '</div>';
		$h .= '<a class="vhbc-promo-btn" href="' . esc_url( $b['url'] ) . '" target="_blank" rel="noopener">Commander</a></div>';
		return self::wrap( $h, 'vhbc-boutique-wrap' );
	}

	/* ------------------------------------------------------------------ */
	/* [vhb_partenaires]                                                   */
	/* ------------------------------------------------------------------ */

	/** Options : niveau="principal,partenaire,institutionnel", groupes="oui|non", titre, surtitre. */
	public static function sc_partenaires( $atts ) {
		$a    = shortcode_atts( array( 'niveau' => '', 'groupes' => 'oui', 'titre' => '', 'surtitre' => '' ), $atts, 'vhb_partenaires' );
		$only = self::liste( $a['niveau'] );
		$par  = array();
		foreach ( VHB_Club_Vie::get()['partenaires'] as $p ) {
			if ( ! $only || in_array( $p['niveau'], $only, true ) ) {
				$par[ $p['niveau'] ][] = $p;
			}
		}
		if ( ! $par ) {
			return self::msg( 'Aucun partenaire enregistré (' . VHB_MARQUE . ' > Vie du club).' );
		}
		self::enqueue();
		$h = self::head( $a['surtitre'], $a['titre'] );
		foreach ( VHB_Club_Vie::NIVEAUX as $k => $label ) {
			if ( empty( $par[ $k ] ) ) {
				continue;
			}
			$h .= '<section class="vhbc-partners-group">' . ( self::oui( $a['groupes'] ) && count( $par ) > 1 ? '<h3 class="vhbc-h">' . self::t( $label ) . '</h3>' : '' ) . '<ul class="vhbc-partners vhbc-partners--' . esc_attr( $k ) . '">';
			foreach ( $par[ $k ] as $p ) {
				$img  = $p['logo'] ? wp_get_attachment_image( $p['logo'], 'medium', false, array( 'alt' => $p['nom'], 'loading' => 'lazy' ) ) : '';
				$in   = $img ? $img : '<span class="vhbc-partner-nom">' . self::t( $p['nom'] ) . '</span>';
				$h   .= '<li class="vhbc-partner">' . ( $p['url'] ? '<a href="' . esc_url( $p['url'] ) . '" target="_blank" rel="noopener" title="' . esc_attr( $p['nom'] ) . '">' . $in . '</a>' : $in ) . '</li>';
			}
			$h .= '</ul></section>';
		}
		return self::wrap( $h, 'vhbc-partenaires-wrap' );
	}

	/* ------------------------------------------------------------------ */
	/* [vhb_mentions]                                                      */
	/* ------------------------------------------------------------------ */

	public static function sc_mentions( $atts ) {
		self::enqueue();
		$r   = VHB_Club_Reglages::get();
		$m   = VHB_Club_Vie::get()['mentions'];
		$nom = '' !== $r['nom'] ? $r['nom'] : get_bloginfo( 'name' );
		$p   = VHB_Club_Vie::president();
		$dir = '' !== $m['directeur'] ? $m['directeur'] : ( $p ? VHB_Club_Vie::nom( $p, 'complet' ) . ', ' . mb_strtolower( $p['fonction'] ) : '' );
		$mel = '' !== $m['contact_rgpd'] ? $m['contact_rgpd'] : $r['email'];
		$siege = trim( $r['siege'] ); // Pas de repli sur le gymnase : le siège légal peut être ailleurs.

		$sec = static function ( $titre, array $lignes ) {
			$lignes = array_filter( $lignes );
			if ( ! $lignes ) {
				return '';
			}
			return '<section class="vhbc-legal-sec"><h3 class="vhbc-h">' . self::t( $titre ) . '</h3>' . implode( '', array_map( static function ( $l ) { return '<p class="vhbc-p">' . $l . '</p>'; }, $lignes ) ) . '</section>';
		};
		$mail = static function ( $e ) {
			return $e ? '<a class="vhbc-link" href="' . esc_url( 'mailto:' . $e ) . '">' . self::t( $e ) . '</a>' : '';
		};
		$rgpd = '' !== $m['rgpd'] ? $m['rgpd']
			: 'Les informations transmises par les formulaires du site (nom, adresse email, message) sont utilisées uniquement par ' . $nom . ' pour répondre aux demandes et gérer les inscriptions. Elles ne sont ni vendues ni cédées à des tiers, en dehors des obligations liées à la Fédération française de handball pour la délivrance des licences.'
			. "\n" . 'Conformément au règlement général sur la protection des données (RGPD) et à la loi Informatique et libertés, vous disposez d\'un droit d\'accès, de rectification, d\'effacement et d\'opposition sur vos données. Pour l\'exercer, écrivez à l\'adresse ci-dessous. Vous pouvez aussi adresser une réclamation à la CNIL (cnil.fr).';

		$h  = $sec(
			'Éditeur du site',
			array(
				self::t( $nom ) . ', association loi 1901',
				'' !== $siege ? 'Siège : ' . str_replace( "\n", ', ', self::t( $siege ) ) : '',
				'' !== $m['rna'] ? 'N° RNA : ' . self::t( $m['rna'] ) : '',
				'' !== $m['siret'] ? 'SIRET : ' . self::t( $m['siret'] ) : '',
				$r['email'] ? 'Contact : ' . $mail( $r['email'] ) : '',
				'' !== $r['telephone'] ? 'Téléphone : ' . self::t( $r['telephone'] ) : '',
			)
		);
		$h .= $sec( 'Directeur de la publication', array( self::t( $dir ) ) );
		$h .= $sec( 'Hébergement', array( self::t( $m['hebergeur'] ), self::t( $m['hebergeur_adr'] ) ) );
		$h .= $sec(
			'Données personnelles',
			array_merge(
				array_map( array( __CLASS__, 't' ), array_filter( array_map( 'trim', explode( "\n", $rgpd ) ) ) ),
				array( $mel ? 'Contact : ' . $mail( $mel ) : '' )
			)
		);
		$h .= $sec( 'Propriété des contenus', array( self::t( 'Textes, photos et logos de ce site appartiennent à ' . $nom . ' ou à leurs auteurs. Toute reproduction demande un accord préalable.' ) ) );
		return self::wrap( '<div class="vhbc-legal">' . $h . '</div>', 'vhbc-mentions-wrap' );
	}
}
