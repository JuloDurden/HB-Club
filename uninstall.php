<?php
/**
 * Désinstallation de l'extension (toutes éditions).
 *
 * Données du club (catégories, créneaux, gymnases, ententes, stages,
 * réglages, saisons, photos d'équipe) et réglages des Résultats FFHB
 * (équipes, compétitions, blasons) : CONSERVÉS. Une réinstallation les retrouve.
 *
 * Supprimés : capacités et rôles, numéros de version, tâches planifiées, et
 * les caches des Résultats FFHB (résultats récupérés, journal, blasons et
 * feuilles de match téléchargés), qui sont récupérés de nouveau à la
 * réinstallation.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Une autre édition de l'extension (HB Club, VHB…) est active : elle utilise
// les mêmes rôles, capacités et données. On ne touche à rien.
if ( defined( 'VHB_CLUB_VERSION' ) ) {
	return;
}

foreach ( wp_roles()->role_objects as $role ) {
	$role->remove_cap( 'vhb_club_gerer' );
	$role->remove_cap( 'vhb_club_reglages' );
	$role->remove_cap( 'vhb_club_stages' );
}
foreach ( array( 'vhb_gestionnaire', 'vhb_editeur_stages' ) as $role ) {
	remove_role( $role ); // Les comptes concernés n'ont plus de rôle : à réattribuer.
}
delete_option( 'vhb_club_version' );

// Résultats FFHB : caches seulement (si l'ancienne extension est encore installée, elle les utilise : on n'y touche pas).
if ( ! file_exists( WP_PLUGIN_DIR . '/vhb-ffhb/vhb-ffhb.php' ) ) {
	foreach ( array( 'vhb_ffhb_tick', 'vhb_ffhb_continue', 'vhb_ffhb_run_now' ) as $hook ) {
		wp_unschedule_hook( $hook );
	}
	foreach ( array( 'vhb_ffhb_data', 'vhb_ffhb_log', 'vhb_ffhb_run', 'vhb_ffhb_step_mutex', 'vhb_ffhb_failures', 'vhb_ffhb_last_start', 'vhb_ffhb_logos_absents', 'vhb_ffhb_fdm', 'vhb_ffhb_version' ) as $opt ) {
		delete_option( $opt );
	}
	delete_transient( 'vhb_ffhb_lock' );
	$u = wp_upload_dir( null, false );
	foreach ( array( 'vhb-ffhb-logos', 'vhb-ffhb-fdm' ) as $sub ) {
		$dir = trailingslashit( $u['basedir'] ) . $sub;
		if ( ! is_dir( $dir ) ) {
			continue;
		}
		foreach ( array_merge( (array) glob( $dir . '/*' ), array( $dir . '/.htaccess' ) ) as $f ) {
			if ( $f && is_file( $f ) ) {
				wp_delete_file( $f );
			}
		}
		@rmdir( $dir ); // phpcs:ignore
	}
}
