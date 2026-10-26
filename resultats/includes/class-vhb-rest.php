<?php
/**
 * Route REST interne : renvoie la copie locale, jamais la FFHB.
 *
 * GET /wp-json/vhb/v1/equipes
 * GET /wp-json/vhb/v1/equipes/<slug>
 */

defined( 'ABSPATH' ) || exit;

class VHB_Rest {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			'vhb/v1',
			'/equipes',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					$d = VHB_Store::get();
					return rest_ensure_response(
						array(
							'maj'     => $d['maj'],
							'saison'  => VHB_Settings::get()['saison'],
							'equipes' => array_map( array( __CLASS__, 'publicize' ), VHB_Store::views() ),
						)
					);
				},
			)
		);
		register_rest_route(
			'vhb/v1',
			'/equipes/(?P<slug>[a-z0-9-]+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $req ) {
					$v = VHB_Store::view( $req['slug'] );
					if ( ! $v || ! empty( $v['masquee'] ) ) {
						return new WP_Error( 'vhb_introuvable', 'Équipe inconnue.', array( 'status' => 404 ) );
					}
					return rest_ensure_response( self::publicize( $v ) );
				},
			)
		);
	}

	/** Ajoute les URL locales des blasons. */
	public static function publicize( array $v ) {
		$b = $v['blason_url'] ?? '';
		foreach ( $v['rencontres'] as &$r ) {
			$r['logo1_url'] = $b && $r['domicile'] === ( $r['nous'] ?? $v['libelle'] ) ? $b : VHB_Logos::url( $r['logo1'] ?? '' );
			$r['logo2_url'] = $b && $r['exterieur'] === ( $r['nous'] ?? $v['libelle'] ) ? $b : VHB_Logos::url( $r['logo2'] ?? '' );
			$r['resultat']  = VHB_Render::outcome( $r, $r['nous'] ?? $v['libelle'] );
			unset( $r['ordre'] );
			$r['salle']     = $r['lieu'] ? VHB_Render::lieu_affiche( $r ) : null;
			$r['feuille']   = VHB_Fdm::link( $r, $v['fdm_mode'] ?? 'masquees' );
			unset( $r['fdm'] );
		}
		unset( $r );
		foreach ( $v['classement'] as &$c ) {
			$c['logo_url'] = $b && $c['vhb'] ? $b : VHB_Logos::url( $c['logo'] ?? '' );
		}
		unset( $c );
		foreach ( $v['competitions'] as &$c ) {
			foreach ( $c['classement'] as &$l ) {
				$l['logo_url'] = $b && $l['vhb'] ? $b : VHB_Logos::url( $l['logo'] ?? '' );
			}
			unset( $l );
			if ( empty( $v['afficher_classement'] ) ) {
				$c['classement'] = array();
			}
			unset( $c['erreur'], $c['erreur_date'] );
		}
		unset( $c );
		if ( empty( $v['afficher_classement'] ) ) {
			$v['classement'] = array(); // masqué par réglage : cohérent avec l'affichage
		}
		// Photo d'équipe (VHB > Photos d'équipe), pour la carte Panini de la page Nos équipes.
		$v['photo'] = class_exists( 'VHB_Club_Photos' ) ? VHB_Club_Photos::pour_equipe( $v['slug'] ?? '' ) : null;
		return $v;
	}
}
