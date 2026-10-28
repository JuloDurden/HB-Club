<?php
/**
 * Chargement de l'extension, commun à toutes les éditions.
 *
 * Le fichier principal (hb-club.php, ou celui d'une édition propre à un club)
 * déclare l'en-tête WordPress, définit VHB_CLUB_FILE et éventuellement
 * VHB_MARQUE (nom affiché), puis inclut ce fichier.
 *
 * Préfixes techniques vhb_ / vhbc_ / VHB_ : hérités de l'extension d'origine
 * (VHB, Villeneuve Handball), gardés pour la compatibilité des données.
 *
 * Principe : source unique. Les pages n'embarquent aucune valeur en dur,
 * seulement des blocs ou shortcodes qui lisent la donnée à l'affichage.
 * Les données qui changent chaque année sont rattachées à une saison.
 */

defined( 'ABSPATH' ) || exit;

define( 'VHB_CLUB_VERSION', '3.0.1' );
define( 'VHB_CLUB_DIR', plugin_dir_path( VHB_CLUB_FILE ) );
define( 'VHB_CLUB_URL', plugin_dir_url( VHB_CLUB_FILE ) );
if ( ! defined( 'VHB_MARQUE' ) ) {
	define( 'VHB_MARQUE', 'HB Club' );
}

require_once VHB_CLUB_DIR . 'includes/class-vhb-club-reglages.php';
require_once VHB_CLUB_DIR . 'includes/class-vhb-club-types.php';
require_once VHB_CLUB_DIR . 'includes/class-vhb-club-donnees.php';
require_once VHB_CLUB_DIR . 'includes/class-vhb-club-inscriptions.php';
require_once VHB_CLUB_DIR . 'includes/class-vhb-club-stages.php';
require_once VHB_CLUB_DIR . 'includes/class-vhb-club-vie.php';
require_once VHB_CLUB_DIR . 'includes/class-vhb-club-ffhb.php';
require_once VHB_CLUB_DIR . 'includes/class-vhb-club-cache.php';
require_once VHB_CLUB_DIR . 'includes/class-vhb-club-render.php';
require_once VHB_CLUB_DIR . 'includes/class-vhb-club-pages.php';
require_once VHB_CLUB_DIR . 'includes/class-vhb-club-blocks.php';
require_once VHB_CLUB_DIR . 'includes/class-vhb-club-demarrage.php';
require_once VHB_CLUB_DIR . 'includes/class-vhb-club-photos.php';
require_once VHB_CLUB_DIR . 'includes/class-vhb-club-articles.php';
require_once VHB_CLUB_DIR . 'includes/class-vhb-fusion.php';

/*
 * Module Résultats FFHB (ancienne extension VHB Résultats FFHB). Pas chargé
 * tant que l'ancienne extension est active : elle déclare les mêmes classes.
 * VHB_Fusion la désactive à la première visite de l'administration.
 */
define( 'VHB_RESULTATS_INTEGRE', ! VHB_Fusion::ancienne_active() && ! class_exists( 'VHB_Settings', false ) );
if ( VHB_RESULTATS_INTEGRE ) {
	require_once VHB_CLUB_DIR . 'resultats/resultats.php';
}

if ( is_admin() ) {
	require_once VHB_CLUB_DIR . 'includes/class-vhb-club-admin.php';
	require_once VHB_CLUB_DIR . 'includes/class-vhb-club-metaboxes.php';
	require_once VHB_CLUB_DIR . 'includes/class-vhb-club-saisons.php';
	require_once VHB_CLUB_DIR . 'includes/class-vhb-club-inscriptions-admin.php';
	require_once VHB_CLUB_DIR . 'includes/class-vhb-club-vie-admin.php';
	require_once VHB_CLUB_DIR . 'includes/class-vhb-club-export.php';
	require_once VHB_CLUB_DIR . 'includes/class-vhb-club-etat.php';
}

register_activation_hook(
	VHB_CLUB_FILE,
	static function () {
		VHB_Club_Demarrage::activate();
		if ( VHB_RESULTATS_INTEGRE ) {
			VHB_Cron::activate();
		}
	}
);
register_deactivation_hook(
	VHB_CLUB_FILE,
	static function () {
		if ( VHB_RESULTATS_INTEGRE ) {
			VHB_Cron::deactivate();
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		$installed = get_option( 'vhb_club_version', '' );
		if ( version_compare( (string) $installed, VHB_CLUB_VERSION, '<' ) ) {
			VHB_Club_Types::add_caps();
			add_action(
				'init',
				static function () use ( $installed ) {
					VHB_Club_Demarrage::upgrade( $installed );
				},
				20
			);
			update_option( 'vhb_club_version', VHB_CLUB_VERSION );
			add_action( 'init', array( 'VHB_Club_Cache', 'flush_all' ), 99 );
		}
		if ( VHB_RESULTATS_INTEGRE ) {
			vhb_resultats_demarrer();
		}
		VHB_Fusion::init();
		VHB_Club_Types::init();
		VHB_Club_Stages::init();
		VHB_Club_Photos::init();
		VHB_Club_Articles::init();
		VHB_Club_Ffhb::init();
		VHB_Club_Cache::init();
		VHB_Club_Render::init();
		VHB_Club_Pages::init();
		VHB_Club_Blocks::init();
		if ( is_admin() ) {
			VHB_Club_Admin::init();
			VHB_Club_Metaboxes::init();
			VHB_Club_Saisons::init();
			VHB_Club_Inscriptions_Admin::init();
			VHB_Club_Vie_Admin::init();
			VHB_Club_Export::init();
			VHB_Club_Etat::init();
		}
	}
);
