<?php
/**
 * Blocs Gutenberg : un bloc par shortcode, rendu côté serveur (aperçu réel
 * dans l'éditeur). Espace de noms « vhb-club/ », distinct de VHB Résultats.
 */

defined( 'ABSPATH' ) || exit;

class VHB_Club_Blocks {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	private static function s( $default = '' ) {
		return array( 'type' => 'string', 'default' => $default );
	}

	public static function register() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		VHB_Club_Render::register_assets();
		wp_register_script(
			'vhb-club-blocks',
			VHB_CLUB_URL . 'blocks/editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render' ),
			VHB_Club_Cache::ver( 'blocks/editor.js' ),
			true
		);

		$common = array(
			'editor_script' => 'vhb-club-blocks',
			'style'         => 'vhb-club',
			'category'      => 'widgets',
		);

		register_block_type(
			'vhb-club/planning',
			$common + array(
				'attributes'      => array(
					'pratique'  => self::s(),
					'categorie' => self::s(),
					'lieu'      => self::s(),
					'vue'       => self::s( 'tableau' ),
					'groupe'    => self::s( 'aucun' ),
					'lieux'     => self::s( 'oui' ),
					'titre'     => self::s(),
					'surtitre'  => self::s(),
				),
				'render_callback' => static function ( $a ) {
					return VHB_Club_Render::sc_planning( $a );
				},
			)
		);
		register_block_type(
			'vhb-club/categorie',
			$common + array(
				'attributes'      => array(
					'categorie'      => self::s(),
					'description'    => self::s( 'oui' ),
					'creneaux'       => self::s( 'oui' ),
					'tarif'          => self::s( 'oui' ),
					'pratique_infos' => self::s( 'oui' ),
					'matchs'         => self::s( 'oui' ),
				),
				'render_callback' => static function ( $a ) {
					return VHB_Club_Render::sc_categorie( $a );
				},
			)
		);
		register_block_type(
			'vhb-club/lieu',
			$common + array(
				'attributes'      => array( 'lieu' => self::s( 'principal' ) ),
				'render_callback' => static function ( $a ) {
					return VHB_Club_Render::sc_lieu( $a );
				},
			)
		);
		register_block_type(
			'vhb-club/tarifs',
			$common + array(
				'attributes'      => array(
					'pratique'  => self::s(),
					'regrouper' => self::s( 'oui' ),
					'inclus'    => self::s( 'oui' ),
					'autres'    => self::s( 'oui' ),
					'mention'   => self::s( 'oui' ),
					'titre'     => self::s(),
					'surtitre'  => self::s(),
				),
				'render_callback' => static function ( $a ) {
					return VHB_Club_Render::sc_tarifs( $a );
				},
			)
		);
		register_block_type(
			'vhb-club/reductions',
			$common + array(
				'attributes'      => array(
					'reductions' => self::s( 'oui' ),
					'paiement'   => self::s( 'oui' ),
					'essai'      => self::s( 'non' ),
					'titre'      => self::s(),
					'surtitre'   => self::s(),
				),
				'render_callback' => static function ( $a ) {
					return VHB_Club_Render::sc_reductions( $a );
				},
			)
		);
		register_block_type(
			'vhb-club/pieces',
			$common + array(
				'attributes'      => array(
					'pieces'     => self::s( 'oui' ),
					'documents'  => self::s( 'oui' ),
					'equipement' => self::s( 'non' ),
					'titre'      => self::s(),
					'surtitre'   => self::s(),
				),
				'render_callback' => static function ( $a ) {
					return VHB_Club_Render::sc_pieces( $a );
				},
			)
		);
		register_block_type(
			'vhb-club/bandeau-inscriptions',
			$common + array(
				'attributes'      => array(),
				'render_callback' => static function () {
					return VHB_Club_Render::sc_bandeau( array() );
				},
			)
		);
		register_block_type(
			'vhb-club/stages',
			$common + array(
				'attributes'      => array(
					'quand' => self::s( 'a_venir' ),
					'nombre' => self::s( '0' ),
					'type' => self::s( '' ),
					'vue' => self::s( 'cartes' ),
					'tarifs' => self::s( 'oui' ),
					'widget' => self::s( 'non' ),
					'lien' => self::s( '' ),
					'vide' => self::s( 'Aucun stage programmé pour le moment. Les prochains stages seront annoncés ici.' ),
					'titre' => self::s( '' ),
					'surtitre' => self::s( '' )
				),
				'render_callback' => static function ( $a ) {
					return VHB_Club_Render::sc_stages( (array) $a );
				},
			)
		);
		register_block_type(
			'vhb-club/alertes',
			$common + array(
				'attributes'      => array(

				),
				'render_callback' => static function ( $a ) {
					return VHB_Club_Render::sc_alertes( (array) $a );
				},
			)
		);
		register_block_type(
			'vhb-club/organigramme',
			$common + array(
				'attributes'      => array(
					'groupe' => self::s( '' ),
					'groupes' => self::s( 'oui' ),
					'emails' => self::s( 'oui' ),
					'titre' => self::s( '' ),
					'surtitre' => self::s( '' )
				),
				'render_callback' => static function ( $a ) {
					return VHB_Club_Render::sc_organigramme( (array) $a );
				},
			)
		);
		register_block_type(
			'vhb-club/ag',
			$common + array(
				'attributes'      => array(
					'quand' => self::s( 'toutes' ),
					'type' => self::s( '' ),
					'vide' => self::s( '' ),
					'titre' => self::s( '' ),
					'surtitre' => self::s( '' )
				),
				'render_callback' => static function ( $a ) {
					return VHB_Club_Render::sc_ag( (array) $a );
				},
			)
		);
		register_block_type(
			'vhb-club/boutique',
			$common + array(
				'attributes'      => array(

				),
				'render_callback' => static function ( $a ) {
					return VHB_Club_Render::sc_boutique( (array) $a );
				},
			)
		);
		register_block_type(
			'vhb-club/partenaires',
			$common + array(
				'attributes'      => array(
					'niveau' => self::s( '' ),
					'groupes' => self::s( 'oui' ),
					'titre' => self::s( '' ),
					'surtitre' => self::s( '' )
				),
				'render_callback' => static function ( $a ) {
					return VHB_Club_Render::sc_partenaires( (array) $a );
				},
			)
		);
		register_block_type(
			'vhb-club/mentions',
			$common + array(
				'attributes'      => array(

				),
				'render_callback' => static function ( $a ) {
					return VHB_Club_Render::sc_mentions( (array) $a );
				},
			)
		);
		register_block_type(
			'vhb-club/info',
			$common + array(
				'attributes'      => array(
					'cle'       => self::s(),
					'lien'      => self::s( 'non' ),
					'categorie' => self::s(),
				),
				'render_callback' => static function ( $a ) {
					$h = VHB_Club_Render::sc_info( $a );
					return '' === $h ? '' : '<p class="vhbc-info-bloc">' . $h . '</p>';
				},
			)
		);
	}

	/** Listes pour les sélecteurs de l'éditeur (chargées seulement dans l'éditeur). */
	public static function editor_data() {
		$cats = array();
		foreach ( VHB_Club_Donnees::categories() as $c ) {
			$cats[] = array(
				'value' => $c['code'],
				'label' => $c['nom'] . ( $c['annees'] ? ' (' . $c['annees'] . ')' : '' ),
			);
		}
		$lieux = array();
		foreach ( VHB_Club_Donnees::lieux() as $l ) {
			$lieux[] = array( 'value' => $l['slug'], 'label' => $l['nom'] );
		}
		$pratiques = array();
		foreach ( VHB_Club_Types::PRATIQUES as $k => $v ) {
			$pratiques[] = array( 'value' => $k, 'label' => $v );
		}
		$cles = array();
		foreach ( VHB_Club_Render::cles_info() as $k => $v ) {
			$cles[] = array( 'value' => $k, 'label' => $v );
		}
		$icone = array();
		$svg   = @file_get_contents( VHB_Club_Reglages::fichier_icone() ); // phpcs:ignore
		if ( $svg && preg_match( '/viewBox="([^"]+)"/', $svg, $vb ) && preg_match( '/ d="([^"]+)"/', $svg, $d ) ) {
			$icone = array( 'viewBox' => $vb[1], 'd' => $d[1] );
		}
		$types = array();
		foreach ( VHB_Club_Types::TYPES_STAGE as $k => $v ) {
			$types[] = array( 'value' => $k, 'label' => $v );
		}
		$inst = array();
		foreach ( VHB_Club_Vie::INSTANCES as $k => $v ) {
			$inst[] = array( 'value' => $k, 'label' => $v );
		}
		$niv = array();
		foreach ( VHB_Club_Vie::NIVEAUX as $k => $v ) {
			$niv[] = array( 'value' => $k, 'label' => $v );
		}
		return array(
			'typesStage' => $types,
			'instances'  => $inst,
			'niveaux'    => $niv,
			'icone'      => $icone,
			'saison'     => VHB_Club_Reglages::saison_en_cours(),
			'categories' => $cats,
			'lieux'      => $lieux,
			'pratiques'  => $pratiques,
			'cles'       => $cles,
		);
	}
}

add_action(
	'enqueue_block_editor_assets',
	static function () {
		if ( wp_script_is( 'vhb-club-blocks', 'registered' ) ) {
			wp_add_inline_script( 'vhb-club-blocks', 'window.vhbMarque = ' . wp_json_encode( VHB_MARQUE ) . ';', 'before' );
			wp_add_inline_script( 'vhb-club-blocks', 'window.vhbClub = ' . wp_json_encode( VHB_Club_Blocks::editor_data() ) . ';', 'before' );
		}
	}
);
