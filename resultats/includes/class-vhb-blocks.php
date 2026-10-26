<?php
/**
 * Blocs Gutenberg : un bloc par shortcode, rendu côté serveur.
 * L'éditeur affiche un aperçu réel (ServerSideRender) et un sélecteur d'équipe.
 */

defined( 'ABSPATH' ) || exit;

class VHB_Blocks {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		VHB_Render::register_assets();

		wp_register_script(
			'vhb-ffhb-blocks',
			VHB_FFHB_URL . 'blocks/editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ),
			VHB_FFHB_VERSION,
			true
		);
		$equipes = array_map(
			static function ( $e ) {
				return array(
					'value' => $e['slug'],
					'label' => $e['nom'] . ( $e['masquee'] ? ' (masquée)' : '' ),
				);
			},
			VHB_Settings::equipes()
		);
		wp_add_inline_script( 'vhb-ffhb-blocks', 'window.vhbMarque = ' . wp_json_encode( VHB_MARQUE ) . '; window.vhbSigle = ' . wp_json_encode( VHB_Club_Reglages::sigle_club() ) . ';', 'before' );
		wp_add_inline_script( 'vhb-ffhb-blocks', 'window.vhbFfhbEquipes = ' . wp_json_encode( $equipes ) . ';', 'before' );

		$slug_attr = array(
			'slug' => array(
				'type'    => 'string',
				'default' => '',
			),
		);
		$common = array(
			'editor_script' => 'vhb-ffhb-blocks',
			'style'         => 'vhb-ffhb',
			'script'        => 'vhb-ffhb',
			'category'      => 'widgets',
		);

		register_block_type(
			'vhb/matchs',
			$common + array(
				'attributes'      => array(
					'titre'        => array( 'type' => 'string', 'default' => 'Les matchs du ' . VHB_Club_Reglages::sigle_club() ),
					'surtitre'     => array( 'type' => 'string', 'default' => 'Sur le terrain' ),
					'miseEnAvant'  => array( 'type' => 'string', 'default' => 'auto' ),
				),
				'render_callback' => static function ( $a ) {
					return VHB_Render::sc_matchs(
						array(
							'titre'         => $a['titre'] ?? '',
							'surtitre'      => $a['surtitre'] ?? '',
							'mise_en_avant' => $a['miseEnAvant'] ?? 'auto',
						)
					);
				},
			)
		);
		register_block_type(
			'vhb/equipe',
			$common + array(
				'attributes'      => $slug_attr + array(
					'resultats' => array( 'type' => 'string', 'default' => '' ),
				),
				'render_callback' => static function ( $a ) {
					return self::need_slug( $a ) ?? VHB_Render::sc_equipe( array( 'slug' => $a['slug'], 'resultats' => $a['resultats'] ?? '' ) );
				},
			)
		);
		register_block_type(
			'vhb/agenda',
			$common + array(
				'attributes'      => array( 'slug' => array( 'type' => 'string', 'default' => 'club' ) ),
				'render_callback' => static function ( $a ) {
					$html = VHB_Render::sc_agenda( array( 'slug' => $a['slug'] ?? 'club' ) );
					if ( '' === $html && defined( 'REST_REQUEST' ) && REST_REQUEST ) {
						return '<div class="vhb"><div class="vhb-msg">Le bouton agenda est désactivé dans VHB > Résultats FFHB.</div></div>';
					}
					return $html;
				},
			)
		);
		register_block_type(
			'vhb/classement',
			$common + array(
				'attributes'      => $slug_attr,
				'render_callback' => static function ( $a ) {
					return self::need_slug( $a ) ?? VHB_Render::sc_classement( array( 'slug' => $a['slug'] ) );
				},
			)
		);
		register_block_type(
			'vhb/prochain',
			$common + array(
				'attributes'      => $slug_attr,
				'render_callback' => static function ( $a ) {
					return self::need_slug( $a ) ?? VHB_Render::sc_prochain( array( 'slug' => $a['slug'] ) );
				},
			)
		);
	}

	/** Bloc sans équipe choisie : consigne dans l'éditeur, rien en front. */
	private static function need_slug( $a ) {
		if ( ! empty( $a['slug'] ) ) {
			return null;
		}
		$is_editor = defined( 'REST_REQUEST' ) && REST_REQUEST;
		return $is_editor ? '<div class="vhb vhb--' . esc_attr( VHB_Settings::get()['theme'] ) . '"><div class="vhb-msg">Choisissez une équipe dans le panneau de droite.</div></div>' : '';
	}
}
