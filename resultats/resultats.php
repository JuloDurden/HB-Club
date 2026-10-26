<?php
/**
 * Module « Résultats FFHB » (ancienne extension VHB Résultats FFHB 1.4.0,
 * intégrée à VHB en 2.0.0).
 *
 * Rien ne change pour le site : mêmes options (vhb_ffhb_*), mêmes shortcodes,
 * mêmes blocs (vhb-ffhb/...), même route REST (/wp-json/vhb/v1/equipes),
 * même agenda, mêmes tâches planifiées. Seul l'emplacement des fichiers change.
 *
 * Chargé par vhb-club.php, sauf si l'ancienne extension est encore active
 * (ses classes portent les mêmes noms) : voir VHB_Fusion.
 */

defined( 'ABSPATH' ) || exit;

define( 'VHB_FFHB_VERSION', VHB_CLUB_VERSION );
define( 'VHB_FFHB_FILE', VHB_CLUB_FILE );
define( 'VHB_FFHB_DIR', VHB_CLUB_DIR . 'resultats/' );
define( 'VHB_FFHB_URL', VHB_CLUB_URL . 'resultats/' );

require_once VHB_FFHB_DIR . 'includes/class-vhb-settings.php';
require_once VHB_FFHB_DIR . 'includes/class-vhb-fetcher.php';
require_once VHB_FFHB_DIR . 'includes/class-vhb-store.php';
require_once VHB_FFHB_DIR . 'includes/class-vhb-logos.php';
require_once VHB_FFHB_DIR . 'includes/class-vhb-cache.php';
require_once VHB_FFHB_DIR . 'includes/class-vhb-fdm.php';
require_once VHB_FFHB_DIR . 'includes/class-vhb-agenda.php';
require_once VHB_FFHB_DIR . 'includes/class-vhb-cron.php';
require_once VHB_FFHB_DIR . 'includes/class-vhb-rest.php';
require_once VHB_FFHB_DIR . 'includes/class-vhb-render.php';
require_once VHB_FFHB_DIR . 'includes/class-vhb-blocks.php';

if ( is_admin() ) {
	require_once VHB_FFHB_DIR . 'includes/class-vhb-admin.php';
	require_once VHB_FFHB_DIR . 'includes/class-vhb-domicile.php';
}

/** Démarrage du module (appelé sur plugins_loaded par vhb-club.php). */
function vhb_resultats_demarrer() {
	$installed = get_option( 'vhb_ffhb_version', '1.0.0' );
	if ( version_compare( $installed, VHB_FFHB_VERSION, '<' ) ) {
		VHB_Settings::upgrade( $installed );
		VHB_Settings::install();
		update_option( 'vhb_ffhb_version', VHB_FFHB_VERSION );
		add_action( 'init', array( 'VHB_Cache', 'flush_all' ), 99 );
	}
	VHB_Cache::init();
	VHB_Cron::init(); // replanifie la tâche horaire si elle manque (après désactivation de l'ancienne extension)
	VHB_Rest::init();
	VHB_Fdm::init();
	VHB_Agenda::init();
	VHB_Render::init();
	VHB_Blocks::init();
	if ( is_admin() ) {
		VHB_Admin::init();
	}
}
