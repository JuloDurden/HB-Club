<?php
/**
 * Rendu HTML et shortcodes.
 *
 * [vhb_matchs]                        les matchs du VHB (résultats + à venir), toutes équipes
 * [vhb_equipe slug="seniors-filles"]  le panneau complet d'une équipe
 * [vhb_classement slug="u18-filles"]  le tableau de classement seul
 * [vhb_prochain slug="u11-garcons"]   la prochaine rencontre seule
 *
 * Tout est lu dans la copie locale. Aucune requête vers la FFHB ici.
 *
 * Kadence : une règle du site force la couleur des p, li, td, th. Les textes
 * colorés passent donc par des span / div, jamais par ces balises.
 */

defined( 'ABSPATH' ) || exit;

class VHB_Render {

	const JOURS      = array( 1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche' );
	const JOURS_ABR  = array( 1 => 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.' );
	const MOIS       = array( 1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre' );
	const MOIS_ABR   = array( 1 => 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.' );

	const MSG_SANS_CLASSEMENT = 'Pas de classement dans cette catégorie : la Fédération n\'en publie pas, pour laisser la place au jeu et à la progression.';
	const MSG_NON_PUBLIE      = 'L\'engagement de cette équipe en championnat n\'est pas encore publié par la Fédération.';

	private static $maps = array();

	/** Rencontres affichées sur la page, pour les données structurées. */
	private static $events = array();

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ), 5 );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'register_assets' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );

		add_shortcode( 'vhb_matchs', array( __CLASS__, 'sc_matchs' ) );
		add_shortcode( 'vhb_equipe', array( __CLASS__, 'sc_equipe' ) );
		add_shortcode( 'vhb_classement', array( __CLASS__, 'sc_classement' ) );
		add_shortcode( 'vhb_prochain', array( __CLASS__, 'sc_prochain' ) );
		add_shortcode( 'vhb_agenda', array( __CLASS__, 'sc_agenda' ) );
		add_action( 'wp_footer', array( __CLASS__, 'print_jsonld' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Ressources                                                          */
	/* ------------------------------------------------------------------ */

	public static function register_assets() {
		if ( wp_style_is( 'vhb-ffhb', 'registered' ) ) {
			return;
		}
		wp_register_style( 'vhb-ffhb', VHB_FFHB_URL . 'assets/vhb.css', array(), VHB_Cache::ver( 'assets/vhb.css' ) );
		wp_register_script( 'vhb-ffhb', VHB_FFHB_URL . 'assets/vhb.js', array(), VHB_Cache::ver( 'assets/vhb.js' ), true );
	}

	/** Charge les styles dans le <head> quand la page contient nos shortcodes ou blocs. */
	public static function maybe_enqueue() {
		$post = get_post();
		if ( ! $post ) {
			return;
		}
		$c = (string) $post->post_content;
		foreach ( array( 'vhb_matchs', 'vhb_equipe', 'vhb_classement', 'vhb_prochain', 'vhb_agenda' ) as $sc ) {
			if ( has_shortcode( $c, $sc ) ) {
				self::enqueue();
				return;
			}
		}
		if ( false !== strpos( $c, '<!-- wp:vhb/' ) ) {
			self::enqueue();
		}
	}

	public static function enqueue() {
		self::register_assets();
		wp_enqueue_style( 'vhb-ffhb' );
		wp_enqueue_script( 'vhb-ffhb' );
	}

	/* ------------------------------------------------------------------ */
	/* Shortcodes                                                          */
	/* ------------------------------------------------------------------ */

	public static function sc_equipe( $atts ) {
		$a = shortcode_atts( array( 'slug' => '', 'resultats' => '' ), $atts, 'vhb_equipe' );
		$v = self::view_or_notice( $a['slug'] );
		// resultats="5" : les 5 derniers visibles, les autres repliés. Vide : réglage général.
		$nb = '' === (string) $a['resultats'] ? (int) VHB_Settings::get()['nb_resultats'] : absint( $a['resultats'] );
		return is_string( $v ) ? $v : self::wrap( self::equipe( $v, $nb ), 'vhb-equipe', array( 'data-vhb-tabs' => self::default_tab( $v['rencontres'] ) ) );
	}

	/** [vhb_agenda slug="seniors-filles"] ou [vhb_agenda slug="club"] */
	public static function sc_agenda( $atts ) {
		$a = shortcode_atts( array( 'slug' => 'club' ), $atts, 'vhb_agenda' );
		if ( ! VHB_Settings::get()['agenda'] ) {
			return '';
		}
		$slug = sanitize_title( $a['slug'] );
		if ( 'club' !== $slug ) {
			$v = self::view_or_notice( $slug );
			if ( is_string( $v ) ) {
				return $v;
			}
			$label = 'Les matchs des ' . $v['equipe'] . ' dans mon agenda';
		} else {
			$label = 'Tous les matchs du club dans mon agenda';
		}
		return self::wrap( self::agenda_button( $slug, $label, true ), 'vhb-agenda-seul' );
	}

	public static function sc_classement( $atts ) {
		$a = shortcode_atts( array( 'slug' => '' ), $atts, 'vhb_classement' );
		$v = self::view_or_notice( $a['slug'] );
		return is_string( $v ) ? $v : self::wrap( self::classement( $v, true ), 'vhb-seul' );
	}

	public static function sc_prochain( $atts ) {
		$a = shortcode_atts( array( 'slug' => '' ), $atts, 'vhb_prochain' );
		$v = self::view_or_notice( $a['slug'] );
		if ( is_string( $v ) ) {
			return $v;
		}
		$html = '<div class="vhb-card-head"><span class="vhb-kicker">Prochain match</span><span class="vhb-team">' . esc_html( $v['equipe'] ) . '</span></div>';
		if ( ! $v['publie'] ) {
			$html .= self::msg( self::MSG_NON_PUBLIE );
		} else {
			$next = self::prochain( $v['rencontres'] );
			$html .= $next ? self::upcoming_row( $next, $v ) : self::msg( 'Pas de match à venir pour le moment.' );
			if ( VHB_Settings::get()['agenda'] ) {
				$html .= self::agenda_button( $v['slug'], 'Ajouter à mon agenda' );
			}
		}
		return self::wrap( $html, 'vhb-prochain' );
	}

	public static function sc_matchs( $atts ) {
		$a = shortcode_atts(
			array(
				'titre'          => 'Les matchs du ' . VHB_Club_Reglages::sigle_club(),
				'surtitre'       => 'Sur le terrain',
				'mise_en_avant'  => 'auto',
			),
			$atts,
			'vhb_matchs'
		);
		return self::wrap( self::matchs( $a ), 'vhb-matchs' );
	}

	/**
	 * @return array|string la vue de l'équipe, ou un message à afficher tel quel
	 */
	private static function view_or_notice( $slug ) {
		$slug = sanitize_title( (string) $slug );
		$v    = '' !== $slug ? VHB_Store::view( $slug ) : null;
		if ( $v && empty( $v['masquee'] ) ) {
			return $v;
		}
		if ( $v ) { // équipe masquée : rien pour les visiteurs
			return current_user_can( 'edit_posts' ) ? self::wrap( self::msg( sprintf( 'L\'équipe « %s » est masquée dans VHB > Résultats FFHB : ce bloc n\'est pas visible des visiteurs.', $v['equipe'] ), 'warn' ), 'vhb-seul' ) : '';
		}
		if ( current_user_can( 'edit_posts' ) ) {
			$slugs = implode( ', ', wp_list_pluck( VHB_Settings::equipes(), 'slug' ) );
			return self::wrap( self::msg( sprintf( 'Équipe inconnue « %s ». Slugs disponibles : %s.', $slug, $slugs ), 'warn' ), 'vhb-seul' );
		}
		return '';
	}

	/* ------------------------------------------------------------------ */
	/* Composants                                                          */
	/* ------------------------------------------------------------------ */

	private static function wrap( $inner, $class, array $attrs = array() ) {
		self::enqueue();
		$theme = VHB_Settings::get()['theme'];
		$extra = '';
		foreach ( $attrs as $k => $val ) {
			$extra .= ' ' . esc_attr( $k ) . '="' . esc_attr( $val ) . '"';
		}
		return '<div class="vhb vhb--' . esc_attr( $theme ) . ' ' . esc_attr( $class ) . '"' . $extra . '><div class="vhb-body">' . $inner . '</div></div>';
	}

	private static function msg( $text, $type = 'info' ) {
		return '<div class="vhb-msg vhb-msg--' . esc_attr( $type ) . '">' . esc_html( $text ) . '</div>';
	}

	/** Panneau complet d'une équipe. */
	public static function equipe( array $v, $nb_resultats = 0 ) {
		$h  = '<div class="vhb-head">';
		$h .= '<h3 class="vhb-title">' . esc_html( $v['equipe'] ) . '</h3>';
		if ( $v['entente'] ) {
			$h .= '<span class="vhb-tag">Entente</span>';
		}
		foreach ( $v['competitions'] as $c ) {
			if ( ! $c['competition'] ) {
				continue;
			}
			$h .= '<div class="vhb-sub' . ( $c['principale'] ? '' : ' vhb-sub--autre' ) . '">'
				. ( $v['multi'] ? '<span class="vhb-sub-nom">' . esc_html( $c['nom'] ) . '</span> ' : '' )
				. esc_html( $c['competition'] ) . ( $c['poule'] && 'coupe' !== $c['type'] ? ' · ' . esc_html( $c['poule'] ) : '' ) . '</div>';
		}
		if ( $v['publie'] && $v['recupere'] && VHB_Settings::get()['agenda'] ) {
			$h .= self::agenda_button( $v['slug'], 'Ajouter à mon agenda' );
		}
		$h .= '</div>';

		if ( ! $v['publie'] ) {
			return $h . self::msg( self::MSG_NON_PUBLIE );
		}
		if ( ! $v['recupere'] ) {
			return $h . self::msg( 'Les données de cette équipe seront affichées après la prochaine mise à jour.' );
		}

		$avenir   = array_values( array_filter( $v['rencontres'], static function ( $r ) { return ! $r['joue']; } ) );
		$joues    = array_reverse( array_values( array_filter( $v['rencontres'], static function ( $r ) { return $r['joue']; } ) ) );

		$h .= '<div class="vhb-tabbar" role="tablist" hidden>';
		foreach ( array( 'avenir' => 'À venir', 'resultats' => 'Résultats', 'classement' => 'Classement' ) as $k => $label ) {
			$h .= '<button type="button" role="tab" class="vhb-tab" data-tab="' . esc_attr( $k ) . '">' . esc_html( $label ) . '</button>';
		}
		$h .= '</div>';

		$h .= '<div class="vhb-panel" data-panel="avenir"><h4 class="vhb-h">À venir</h4>';
		$h .= $avenir ? implode( '', array_map( static function ( $r ) use ( $v ) { return self::upcoming_row( $r, $v ); }, $avenir ) ) : self::msg( 'Aucun match à venir pour le moment.' );
		$h .= '</div>';

		$h .= '<div class="vhb-panel" data-panel="resultats"><h4 class="vhb-h">Résultats</h4>';
		if ( ! $joues ) {
			$h .= self::msg( 'Aucun résultat pour l\'instant.' );
		} else {
			$row   = static function ( $r ) use ( $v ) {
				return self::result_row( $r, $v );
			};
			$shown = $nb_resultats > 0 ? array_slice( $joues, 0, $nb_resultats ) : $joues;
			$rest  = $nb_resultats > 0 ? array_slice( $joues, $nb_resultats ) : array();
			$h    .= implode( '', array_map( $row, $shown ) );
			if ( $rest ) {
				$n  = count( $rest );
				$h .= '<details class="vhb-more"><summary>' . esc_html( 1 === $n ? 'Voir le résultat précédent' : 'Voir les ' . $n . ' résultats précédents' ) . '</summary>' . implode( '', array_map( $row, $rest ) ) . '</details>';
			}
		}
		$h .= '</div>';

		$h .= '<div class="vhb-panel" data-panel="classement"><h4 class="vhb-h">Classement</h4>' . self::classement( $v, false ) . '</div>';

		$liens = array();
		foreach ( $v['competitions'] as $c ) {
			$liens[] = '<a href="' . esc_url( $c['page'] ) . '" target="_blank" rel="noopener">' . esc_html( $v['multi'] ? $c['nom'] . ' sur le site de la FFHB' : 'Voir la compétition sur le site de la FFHB' ) . '</a>';
		}
		if ( $liens ) {
			$h .= '<div class="vhb-foot">' . implode( ' · ', $liens ) . '</div>';
		}
		return $h;
	}

	/**
	 * Classement(s) d'une équipe : un tableau par championnat. Les coupes
	 * n'ont pas de classement et ne sont pas listées ici.
	 */
	public static function classement( array $v, $with_title ) {
		$h = $with_title ? '<div class="vhb-head"><h3 class="vhb-title">' . esc_html( $v['equipe'] ) . '</h3><div class="vhb-sub">Classement' . ( $v['poule'] && ! $v['multi'] ? ' · ' . esc_html( $v['poule'] ) : '' ) . '</div></div>' : '';
		if ( ! $v['publie'] ) {
			return $h . self::msg( self::MSG_NON_PUBLIE );
		}
		$champs = array_values(
			array_filter(
				$v['competitions'],
				static function ( $c ) {
					return 'coupe' !== $c['type'];
				}
			)
		);
		if ( ! $champs ) {
			return $h . self::msg( 'Pas de classement : cette équipe ne joue que des matchs de coupe.' );
		}
		foreach ( $champs as $c ) {
			if ( count( $champs ) > 1 ) {
				$h .= '<div class="vhb-comp-title">' . esc_html( $c['nom'] ) . ( $c['poule'] ? ' · ' . esc_html( $c['poule'] ) : '' ) . '</div>';
			}
			$h .= self::classement_comp( array_merge( $v, $c ) );
		}
		return $h;
	}

	/** Tableau de classement d'une compétition, ou le message adapté. */
	private static function classement_comp( array $v ) {
		$h = '';
		if ( ! $v['classement_actif'] ) {
			return $h . self::msg( self::MSG_SANS_CLASSEMENT );
		}
		if ( empty( $v['afficher_classement'] ) ) {
			$url = $v['poule_url'] ? $v['poule_url'] : $v['page'];
			return $h . '<div class="vhb-msg vhb-msg--link">Le classement de cette équipe est consultable sur le site de la Fédération.'
				. ( $url ? ' <a class="vhb-btn vhb-btn--sm" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">Voir le classement sur ffhandball.fr</a>' : '' ) . '</div>';
		}
		if ( empty( $v['classement'] ) ) {
			return $h . self::msg( 'Le classement n\'est pas encore publié par la Fédération.' );
		}
		$h .= '<div class="vhb-table-wrap"><table class="vhb-table"><thead><tr>'
			. '<th class="vhb-c-pos" scope="col"><abbr title="Position">Pos</abbr></th>'
			. '<th class="vhb-c-club" scope="col">Club</th>'
			. '<th scope="col"><abbr title="Points">Pts</abbr></th>'
			. '<th scope="col"><abbr title="Joués">J</abbr></th>'
			. '<th class="vhb-hide-m" scope="col"><abbr title="Gagnés">G</abbr></th>'
			. '<th class="vhb-hide-m" scope="col"><abbr title="Nuls">N</abbr></th>'
			. '<th class="vhb-hide-m" scope="col"><abbr title="Perdus">P</abbr></th>'
			. '<th scope="col"><abbr title="Différence de buts">Diff</abbr></th>'
			. '</tr></thead><tbody>';
		foreach ( $v['classement'] as $c ) {
			$diff = $c['diff'] > 0 ? '+' . $c['diff'] : (string) $c['diff'];
			$h   .= '<tr class="' . ( $c['vhb'] ? 'is-vhb' : '' ) . '"' . ( $c['vhb'] ? ' aria-current="true"' : '' ) . '>'
				. '<td class="vhb-c-pos">' . (int) $c['place'] . '</td>'
				. '<td class="vhb-c-club"><span class="vhb-club">' . self::logo( $c['logo'], $c['equipe'], $c['vhb'] ? $v['blason_url'] : '' ) . '<span class="vhb-club-name">' . esc_html( self::label( $c['equipe'] ) ) . '</span></span></td>'
				. '<td class="vhb-c-pts">' . (int) $c['points'] . '</td>'
				. '<td>' . (int) $c['joue'] . '</td>'
				. '<td class="vhb-hide-m">' . (int) $c['gagne'] . '</td>'
				. '<td class="vhb-hide-m">' . (int) $c['nul'] . '</td>'
				. '<td class="vhb-hide-m">' . (int) $c['perdu'] . '</td>'
				. '<td>' . esc_html( $diff ) . '</td>'
				. '</tr>';
		}
		$h .= '</tbody></table></div>';
		if ( $v['maj'] ) {
			$h .= '<div class="vhb-maj">Source FFHB, mis à jour le ' . esc_html( self::date_maj( $v['maj'] ) ) . '</div>';
		}
		return $h;
	}

	/**
	 * Ligne de résultat : J2 | blason + NOTRE CLUB | 44-24 | blason + ADVERSAIRE HB 3.
	 * Le forfait remplace le score. Pastille V / N / D seulement si l'équipe
	 * l'active dans les réglages.
	 */
	public static function result_row( array $r, array $v, $show_team = false ) {
		$nous = $r['nous'] ?? $v['libelle'];
		$left = '<span class="vhb-j">' . ( $show_team ? '<span class="vhb-cat">' . esc_html( $v['equipe'] ) . '</span>' : '' ) . esc_html( $r['tour'] ?? 'J' . (int) $r['journee'] );
		$ts   = self::ts( $r['date'] );
		if ( $ts ) {
			$left .= '<span class="vhb-j-date">' . esc_html( wp_date( 'd/m', $ts ) ) . '</span>';
		}
		$left .= '</span>';

		if ( $r['forfait'] ) {
			$score = '<span class="vhb-score vhb-score--fo"' . ( $r['forfait_de'] ? ' title="' . esc_attr( 'Forfait : ' . $r['forfait_de'] ) . '"' : '' ) . '>Forfait'
				. ( $r['forfait_de'] ? '<span class="vhb-fo-de">' . esc_html( self::label( $r['forfait_de'] ) ) . '</span>' : '' ) . '</span>';
		} else {
			$score = '<span class="vhb-score"><span>' . esc_html( $r['score1'] ) . '</span><span class="vhb-dash" aria-label="à">-</span><span>' . esc_html( $r['score2'] ) . '</span></span>';
		}

		$vd = '';
		if ( ! empty( $v['vd'] ) ) {
			$o  = self::outcome( $r, $nous );
			$vd = $o ? '<span class="vhb-vd vhb-vd--' . strtolower( $o ) . '" title="' . esc_attr( array( 'V' => 'Victoire', 'N' => 'Match nul', 'D' => 'Défaite' )[ $o ] ) . '">' . $o . '</span>' : '<span class="vhb-vd vhb-vd--empty"></span>';
		}

		$fdm = VHB_Fdm::link( $r, $v['fdm_mode'] ?? 'masquees' );
		$mid = '<span class="vhb-mid">' . $score
			. ( $fdm ? '<a class="vhb-fdm" href="' . esc_url( $fdm ) . '" target="_blank" rel="noopener nofollow" title="Feuille de match (PDF)">' . self::icon_doc() . 'Feuille<span class="vhb-fdm-x"> de match</span></a>' : '' )
			. '</span>';
		self::collect( $r, $v );

		$grid = '<div class="vhb-result' . ( ! empty( $v['vd'] ) ? ' has-vd' : '' ) . '">'
			. $left
			. self::side( $r['domicile'], $r['logo1'], $r['domicile'] === $nous, 'home', $v )
			. $mid
			. self::side( $r['exterieur'], $r['logo2'], $r['exterieur'] === $nous, 'away', $v )
			. $vd
			. '</div>';
		// Rencontre d'une autre compétition que la principale : bandeau au-dessus.
		$tag = self::comp_tag( $r );
		return '<div class="vhb-row' . ( $tag ? ' vhb-row--autre' : '' ) . '">' . $tag . $grid . '</div>';
	}

	/** Bandeau « Coupe de France · 1er tour » pour une rencontre hors compétition principale. */
	private static function comp_tag( array $r ) {
		if ( ! isset( $r['principale'] ) || $r['principale'] ) {
			return '';
		}
		$txt = $r['comp_nom'] . ( 'coupe' === $r['comp_type'] ? ' · ' . $r['tour_long'] : '' );
		return '<span class="vhb-comp-tag vhb-comp-tag--' . esc_attr( $r['comp_type'] ) . '">' . esc_html( $txt ) . '</span>';
	}

	/** « V », « N » ou « D » du point de vue de notre équipe, '' si indéterminé. */
	public static function outcome( array $r, $nous ) {
		if ( ! $r['joue'] ) {
			return '';
		}
		$dom = $r['domicile'] === $nous;
		if ( ! $dom && $r['exterieur'] !== $nous ) {
			return '';
		}
		if ( $r['forfait'] ) {
			return $r['forfait_de'] === $nous ? 'D' : 'V';
		}
		if ( ! is_numeric( $r['score1'] ) || ! is_numeric( $r['score2'] ) ) {
			return '';
		}
		$a = (int) ( $dom ? $r['score1'] : $r['score2'] );
		$b = (int) ( $dom ? $r['score2'] : $r['score1'] );
		return $a > $b ? 'V' : ( $a < $b ? 'D' : 'N' );
	}

	/**
	 * Ligne de match à venir : date et heure à gauche, adversaire au centre,
	 * lieu dépliant dessous, badge Dom / Ext à droite.
	 */
	public static function upcoming_row( array $r, array $v, $show_team = false ) {
		$dom  = $r['a_domicile'];
		$adv  = $dom ? $r['exterieur'] : $r['domicile'];
		$logo = $dom ? $r['logo2'] : $r['logo1'];
		$ts   = self::ts( $r['date'] );

		if ( $ts ) {
			$when = '<span class="vhb-day">' . esc_html( self::JOURS_ABR[ (int) wp_date( 'N', $ts ) ] . ' ' . wp_date( 'j', $ts ) . ' ' . self::MOIS_ABR[ (int) wp_date( 'n', $ts ) ] ) . '</span>'
				. '<span class="vhb-hour">' . esc_html( wp_date( 'G\hi', $ts ) ) . '</span>';
		} else {
			$when = '<span class="vhb-day">' . esc_html( $r['tour'] ?? 'J' . (int) $r['journee'] ) . '</span>'
				. '<span class="vhb-hour vhb-hour--tbc">' . esc_html( self::journee_range( $r ) ?: 'Date à venir' ) . '</span>';
		}

		$opp  = '<div class="vhb-opp">';
		if ( $show_team ) {
			$opp .= '<span class="vhb-cat">' . esc_html( $v['equipe'] ) . '</span>';
		}
		$opp .= self::comp_tag( $r );
		$opp .= '<span class="vhb-club">' . self::logo( $logo, $adv ) . '<span class="vhb-club-name">' . esc_html( self::label( $adv ) ) . '</span></span>';
		$opp .= self::lieu( $r );
		$opp .= '</div>';
		self::collect( $r, $v );

		return '<div class="vhb-row vhb-next">'
			. '<div class="vhb-when">' . $when . '</div>'
			. $opp
			. '<span class="vhb-badge vhb-badge--' . ( $dom ? 'dom' : 'ext' ) . '" title="' . ( $dom ? 'À domicile' : 'À l\'extérieur' ) . '">' . ( $dom ? 'Dom' : 'Ext' ) . '</span>'
			. '</div>';
	}

	/** Lieu dépliant : carte en desktop, adresse + Google Maps + Waze en mobile. */
	private static function lieu( array $r ) {
		if ( empty( $r['adresse'] ) ) {
			return '<span class="vhb-lieu vhb-lieu--tbc">' . self::pin() . 'Salle à confirmer</span>';
		}
		$ffhb  = (string) $r['lieu'];
		$l     = self::lieu_affiche( $r );
		$usage = $l['nom'];
		$has   = null !== $l['lat'] && null !== $l['lon'];
		$q     = $has ? $l['lat'] . ',' . $l['lon'] : $l['adresse'];

		$gmaps = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $q );
		$waze  = $has ? 'https://waze.com/ul?ll=' . rawurlencode( $q ) . '&navigate=yes' : 'https://waze.com/ul?q=' . rawurlencode( $l['adresse'] ) . '&navigate=yes';

		$h  = '<details class="vhb-lieu"><summary>' . self::pin() . '<span>' . esc_html( $usage ) . '</span></summary><div class="vhb-lieu-body">';
		$h .= '<div class="vhb-addr">' . ( $l['corrige'] ? '<span class="vhb-addr-nom">' . esc_html( $usage ) . '</span>' : '' ) . esc_html( $l['adresse'] );
		if ( $usage !== $ffhb ) {
			$h .= '<span class="vhb-addr-ffhb">Nom fédéral : ' . esc_html( $ffhb ) . '</span>';
		}
		$h .= '</div>';
		if ( $has ) {
			$h .= '<div class="vhb-map" data-lat="' . esc_attr( $l['lat'] ) . '" data-lon="' . esc_attr( $l['lon'] ) . '" data-title="' . esc_attr( $usage ) . '"></div>';
		}
		$h .= '<div class="vhb-nav"><a class="vhb-btn" href="' . esc_url( $gmaps ) . '" target="_blank" rel="noopener">Google Maps</a><a class="vhb-btn" href="' . esc_url( $waze ) . '" target="_blank" rel="noopener">Waze</a></div>';
		$h .= '</div></details>';
		return $h;
	}

	private static function icon_doc() {
		return '<svg viewBox="0 0 24 24" width="12" height="12" aria-hidden="true" focusable="false"><path fill="currentColor" d="M6 2h8l6 6v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm7 1.5V9h5.5L13 3.5zM8 13v2h8v-2H8zm0 4v2h6v-2H8z"/></svg>';
	}

	private static function icon_cal() {
		return '<svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true" focusable="false"><path fill="currentColor" d="M7 2h2v2h6V2h2v2h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2V2zM5 10v10h14V10H5zm6 2h2v3h3v2h-3v3h-2v-3H8v-2h3v-3z"/></svg>';
	}

	/** Bouton « Ajouter à mon agenda » : abonnement au flux iCal du site. */
	public static function agenda_button( $slug, $label, $open = false ) {
		$l = VHB_Agenda::links( $slug );
		return '<details class="vhb-agenda"' . ( $open ? ' open' : '' ) . '><summary>' . self::icon_cal() . '<span>' . esc_html( $label ) . '</span></summary><div class="vhb-agenda-menu">'
			. '<a class="vhb-btn vhb-btn--sm" href="' . esc_url( $l['google'] ) . '" target="_blank" rel="noopener nofollow">Google Agenda</a>'
			. '<a class="vhb-btn vhb-btn--sm vhb-btn--ghost" href="' . esc_url( $l['webcal'], array( 'webcal', 'http', 'https' ) ) . '" rel="nofollow">iPhone, Mac, Outlook</a>'
			. '<a class="vhb-btn vhb-btn--sm vhb-btn--ghost" href="' . esc_url( $l['ics'] ) . '" rel="nofollow" download>Fichier .ics</a>'
			. '<span class="vhb-agenda-note">Abonnement : les dates, horaires et salles se mettent à jour tout seuls dans votre agenda.</span>'
			. '</div></details>';
	}

	/* ------------------------------------------------------------------ */
	/* Données structurées (schema.org SportsEvent)                        */
	/* ------------------------------------------------------------------ */

	private static function collect( array $r, array $v ) {
		if ( empty( $r['id'] ) || ! self::ts( $r['date'] ) ) {
			return;
		}
		self::$events[ $r['id'] ] = array( $r, $v );
	}

	public static function print_jsonld() {
		if ( ! self::$events || ! VHB_Settings::get()['jsonld'] ) {
			return;
		}
		$graph = array();
		foreach ( self::$events as $e ) {
			list( $r, $v ) = $e;
			$ev = array(
				'@type'       => 'SportsEvent',
				'name'        => self::label( $r['domicile'] ) . ' - ' . self::label( $r['exterieur'] ),
				'description' => trim( $v['equipe'] . ', ' . ( $r['comp_nom'] ?? $v['competition'] ) . ', ' . ( $r['tour_long'] ?? 'journée ' . (int) $r['journee'] ) ),
				'sport'       => 'Handball',
				'startDate'   => wp_date( 'c', self::ts( $r['date'] ) ),
				'endDate'     => wp_date( 'c', self::ts( $r['date'] ) + VHB_Agenda::DUREE ),
				'eventStatus' => 'https://schema.org/EventScheduled',
				'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
				'homeTeam'    => array( '@type' => 'SportsTeam', 'name' => self::label( $r['domicile'] ) ),
				'awayTeam'    => array( '@type' => 'SportsTeam', 'name' => self::label( $r['exterieur'] ) ),
				'organizer'   => array( '@type' => 'SportsOrganization', 'name' => VHB_Club_Reglages::nom_club(), 'url' => home_url( '/' ) ),
			);
			if ( ! empty( $r['adresse'] ) ) {
				$l           = self::lieu_affiche( $r );
				$ev['location'] = array(
					'@type'   => 'Place',
					'name'    => $l['nom'],
					'address' => $l['adresse'],
				);
				if ( null !== $l['lat'] ) {
					$ev['location']['geo'] = array( '@type' => 'GeoCoordinates', 'latitude' => $l['lat'], 'longitude' => $l['lon'] );
				}
			}
			$graph[] = $ev;
		}
		echo "\n" . '<script type="application/ld+json">' . wp_json_encode( array( '@context' => 'https://schema.org', '@graph' => $graph ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n"; // phpcs:ignore
	}

	private static function pin() {
		return '<svg class="vhb-pin" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z"/></svg>';
	}

	private static function side( $label, $stem, $is_vhb, $pos, array $v ) {
		return '<span class="vhb-side vhb-side--' . $pos . ( $is_vhb ? ' is-vhb' : '' ) . '">' . self::logo( $stem, $label, $is_vhb ? $v['blason_url'] : '' ) . '<span class="vhb-club-name">' . esc_html( self::label( $label ) ) . '</span></span>';
	}

	/**
	 * Blason : celui choisi dans l'admin pour notre équipe, sinon la copie
	 * locale du blason FFHB, sinon une pastille grise à initiales.
	 */
	public static function logo( $stem, $label, $override = '' ) {
		$url = $override ? $override : VHB_Logos::url( (string) $stem );
		if ( $url ) {
			return '<img class="vhb-logo" src="' . esc_url( $url ) . '" alt="" width="32" height="32" loading="lazy" decoding="async">';
		}
		return '<span class="vhb-logo vhb-logo--none" aria-hidden="true">' . esc_html( VHB_Logos::initials( $label ) ) . '</span>';
	}

	/* ------------------------------------------------------------------ */
	/* Les matchs du VHB (toutes équipes)                                  */
	/* ------------------------------------------------------------------ */

	private static function matchs( array $a ) {
		$views  = array_filter( VHB_Store::views(), static function ( $v ) { return $v['publie'] && $v['recupere']; } );
		$played = array();
		$next   = array();
		$today  = new DateTimeImmutable( 'today', wp_timezone() );

		foreach ( $views as $v ) {
			foreach ( $v['rencontres'] as $r ) {
				$ts = self::ts( $r['date'] );
				if ( ! $ts ) {
					continue;
				}
				if ( $r['joue'] ) {
					$played[] = array( $ts, $r, $v );
				} elseif ( $ts >= $today->getTimestamp() ) {
					$next[] = array( $ts, $r, $v );
				}
			}
		}
		$sort = static function ( $x, $y ) {
			return $x[0] <=> $y[0];
		};
		usort( $played, $sort );
		usort( $next, $sort );

		// Résultats : la semaine du dernier match joué. À venir : la semaine du prochain.
		$res = $played ? self::same_week( $played, end( $played )[0] ) : array();
		$av  = $next ? self::same_week( $next, $next[0][0] ) : array();

		$feat = $a['mise_en_avant'];
		if ( ! in_array( $feat, array( 'resultats', 'avenir' ), true ) ) {
			// Le lendemain du dernier match joué : Résultats. À partir du surlendemain : À venir.
			$feat = 'avenir';
			if ( $played ) {
				$last = ( new DateTimeImmutable( '@' . end( $played )[0] ) )->setTimezone( wp_timezone() )->setTime( 0, 0 );
				if ( $today <= $last->modify( '+1 day' ) ) {
					$feat = 'resultats';
				}
			}
			if ( ! $av ) {
				$feat = 'resultats';
			}
		}

		$frame_res = '<section class="vhb-frame' . ( 'resultats' === $feat ? ' is-featured' : '' ) . '"><div class="vhb-frame-head"><h3 class="vhb-h">Résultats</h3>'
			. ( $res ? '<span class="vhb-range">' . esc_html( self::range( $res ) ) . '</span>' : '' ) . '</div>'
			. ( $res ? implode( '', array_map( static function ( $x ) { return self::result_row( $x[1], $x[2], true ); }, array_reverse( $res ) ) ) : self::msg( 'Pas encore de résultat cette saison.' ) )
			. '</section>';

		$frame_av = '<section class="vhb-frame' . ( 'avenir' === $feat ? ' is-featured' : '' ) . '"><div class="vhb-frame-head"><h3 class="vhb-h">À venir</h3>'
			. ( $av ? '<span class="vhb-range">' . esc_html( self::range( $av ) ) . '</span>' : '' ) . '</div>'
			. ( $av ? implode( '', array_map( static function ( $x ) { return self::upcoming_row( $x[1], $x[2], true ); }, $av ) ) : self::msg( 'Pas de match programmé pour le moment.' ) )
			. '</section>';

		$h = '<div class="vhb-head vhb-head--section">';
		if ( '' !== $a['surtitre'] ) {
			$h .= '<span class="vhb-kicker">' . esc_html( $a['surtitre'] ) . '</span>';
		}
		if ( '' !== $a['titre'] ) {
			$h .= '<h2 class="vhb-title vhb-title--xl">' . esc_html( $a['titre'] ) . '</h2>';
		}
		$h .= '</div><div class="vhb-frames">' . ( 'resultats' === $feat ? $frame_res . $frame_av : $frame_av . $frame_res ) . '</div>';

		$maj = VHB_Store::get()['maj'];
		if ( $maj ) {
			$h .= '<div class="vhb-maj">Source FFHB, mis à jour le ' . esc_html( self::date_maj( $maj ) ) . '</div>';
		}
		return $h;
	}

	/** Éléments de la même semaine (lundi à dimanche) que $ref. */
	private static function same_week( array $items, $ref ) {
		$tz  = wp_timezone();
		$d   = ( new DateTimeImmutable( '@' . $ref ) )->setTimezone( $tz );
		$lun = $d->setTime( 0, 0 )->modify( '-' . ( (int) $d->format( 'N' ) - 1 ) . ' days' );
		$dim = $lun->modify( '+7 days' );
		return array_values(
			array_filter(
				$items,
				static function ( $x ) use ( $lun, $dim ) {
					return $x[0] >= $lun->getTimestamp() && $x[0] < $dim->getTimestamp();
				}
			)
		);
	}

	/** « du 19 au 20 septembre », « du 30 septembre au 4 octobre », « le samedi 26 septembre ». */
	private static function range( array $items ) {
		$a = (int) $items[0][0];
		$b = (int) end( $items )[0];
		if ( wp_date( 'Y-m-d', $a ) === wp_date( 'Y-m-d', $b ) ) {
			return 'le ' . self::JOURS[ (int) wp_date( 'N', $a ) ] . ' ' . self::jour( $a ) . ' ' . self::MOIS[ (int) wp_date( 'n', $a ) ];
		}
		if ( wp_date( 'Y-m', $a ) === wp_date( 'Y-m', $b ) ) {
			return 'du ' . self::jour( $a ) . ' au ' . self::jour( $b ) . ' ' . self::MOIS[ (int) wp_date( 'n', $b ) ];
		}
		return 'du ' . self::jour( $a ) . ' ' . self::MOIS[ (int) wp_date( 'n', $a ) ] . ' au ' . self::jour( $b ) . ' ' . self::MOIS[ (int) wp_date( 'n', $b ) ];
	}

	private static function jour( $ts ) {
		$j = wp_date( 'j', $ts );
		return '1' === $j ? '1er' : $j;
	}

	/** « 7-8 nov. » à partir des dates de journée, pour un match sans horaire. */
	private static function journee_range( array $r ) {
		if ( empty( $r['journee_debut'] ) ) {
			return '';
		}
		$tz = wp_timezone();
		$a  = DateTimeImmutable::createFromFormat( '!Y-m-d', $r['journee_debut'], $tz );
		$b  = ! empty( $r['journee_fin'] ) ? DateTimeImmutable::createFromFormat( '!Y-m-d', $r['journee_fin'], $tz ) : $a;
		if ( ! $a || ! $b ) {
			return '';
		}
		$ma = self::MOIS_ABR[ (int) $a->format( 'n' ) ];
		$mb = self::MOIS_ABR[ (int) $b->format( 'n' ) ];
		if ( $a->format( 'Y-m-d' ) === $b->format( 'Y-m-d' ) ) {
			return $a->format( 'j' ) . ' ' . $ma;
		}
		return $ma === $mb ? $a->format( 'j' ) . '-' . $b->format( 'j' ) . ' ' . $mb : $a->format( 'j' ) . ' ' . $ma . ' - ' . $b->format( 'j' ) . ' ' . $mb;
	}

	/* ------------------------------------------------------------------ */
	/* Utilitaires                                                         */
	/* ------------------------------------------------------------------ */

	/** Prochaine rencontre non jouée : la plus proche datée, sinon la première journée sans date. */
	public static function prochain( array $rencontres ) {
		$now = time() - 3 * HOUR_IN_SECONDS; // un match en cours reste « prochain »
		$sans_date = null;
		foreach ( $rencontres as $r ) {
			if ( $r['joue'] ) {
				continue;
			}
			$ts = self::ts( $r['date'] );
			if ( $ts && $ts >= $now ) {
				return $r;
			}
			if ( ! $ts && ! $sans_date ) {
				$sans_date = $r;
			}
		}
		return $sans_date;
	}

	/** Onglet mobile ouvert par défaut : « resultats » le jour et le lendemain du dernier match joué. */
	public static function default_tab( array $rencontres ) {
		$last = 0;
		foreach ( $rencontres as $r ) {
			if ( $r['joue'] ) {
				$last = max( $last, (int) self::ts( $r['date'] ) );
			}
		}
		if ( $last ) {
			$d     = ( new DateTimeImmutable( '@' . $last ) )->setTimezone( wp_timezone() )->setTime( 0, 0 );
			$today = new DateTimeImmutable( 'today', wp_timezone() );
			if ( $today <= $d->modify( '+1 day' ) ) {
				return 'resultats';
			}
		}
		return 'avenir';
	}

	public static function ts( $iso ) {
		if ( empty( $iso ) ) {
			return 0;
		}
		try {
			return ( new DateTimeImmutable( (string) $iso, wp_timezone() ) )->getTimestamp();
		} catch ( Exception $e ) {
			return 0;
		}
	}

	private static function date_maj( $iso ) {
		$ts = self::ts( $iso );
		return $ts ? wp_date( 'd/m/Y \à G\hi', $ts ) : '';
	}

	/** Réécriture manuelle des libellés FFHB (table de l'admin). */
	public static function label( $s ) {
		if ( ! isset( self::$maps['libelles'] ) ) {
			self::$maps['libelles'] = VHB_Settings::map( 'libelles' );
		}
		$k = VHB_Settings::upper( trim( (string) $s ) );
		return self::$maps['libelles'][ $k ] ?? (string) $s;
	}

	/**
	 * Lieu tel qu'affiché : la table « Salles » de l'admin peut corriger le nom,
	 * l'adresse et les coordonnées fournis par la FFHB.
	 *
	 * @return array{nom:string,adresse:string,lat:?float,lon:?float,corrige:bool}
	 */
	public static function lieu_affiche( array $r ) {
		if ( ! isset( self::$maps['salles'] ) ) {
			self::$maps['salles'] = VHB_Settings::salles();
		}
		$out = array(
			'nom'     => (string) $r['lieu'],
			'adresse' => (string) $r['adresse'],
			'lat'     => $r['lat'],
			'lon'     => $r['lon'],
			'corrige' => false,
		);
		$o = self::$maps['salles'][ VHB_Settings::upper( trim( (string) $r['lieu'] ) ) ] ?? null;
		if ( ! $o ) {
			return $out;
		}
		$out['nom'] = $o['nom'];
		if ( '' !== $o['adresse'] ) {
			$out['adresse'] = $o['adresse'];
			$out['corrige'] = true;
			// Adresse corrigée sans GPS : on ne garde pas les coordonnées FFHB,
			// qui correspondent à l'ancienne adresse. Navigation par l'adresse.
			$out['lat'] = null;
			$out['lon'] = null;
		}
		if ( null !== $o['lat'] ) {
			$out['lat'] = $o['lat'];
			$out['lon'] = $o['lon'];
		}
		return $out;
	}
}
