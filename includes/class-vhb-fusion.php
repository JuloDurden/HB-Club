<?php
/**
 * Passage de deux extensions (VHB Club 1.5.2 + VHB Résultats FFHB 1.4.0)
 * à une seule (VHB 2.0.0).
 *
 * 1. Tant que l'ancienne extension « VHB Résultats FFHB » est active, le module
 *    Résultats intégré n'est pas chargé (mêmes noms de classes : erreur fatale
 *    sinon). Le site continue de tourner avec l'ancienne. Dès qu'un
 *    administrateur ouvre l'administration, elle est désactivée sans toucher
 *    à ses tâches planifiées, et le module intégré prend le relais.
 * 2. Supprimer l'ancienne extension lancerait son uninstall.php, qui efface
 *    les réglages et les données (options vhb_ffhb_*). Juste avant, les options
 *    sont sauvegardées et ce fichier est neutralisé ; après la suppression,
 *    tout ce qui manque est remis en place.
 */

defined( 'ABSPATH' ) || exit;

class VHB_Fusion {

	const ANCIENNE   = 'vhb-ffhb/vhb-ffhb.php';
	const SAUVEGARDE = 'vhb_fusion_sauvegarde';
	const AVIS       = 'vhb_fusion_avis';

	/** Options de l'ancienne extension effacées par son uninstall.php. */
	const OPTIONS = array( 'vhb_ffhb_settings', 'vhb_ffhb_data', 'vhb_ffhb_log', 'vhb_ffhb_failures', 'vhb_ffhb_last_start', 'vhb_ffhb_logos_absents', 'vhb_ffhb_fdm', 'vhb_ffhb_version' );

	public static function ancienne_active() {
		if ( in_array( self::ANCIENNE, (array) get_option( 'active_plugins', array() ), true ) ) {
			return true;
		}
		if ( is_multisite() ) {
			$reseau = (array) get_site_option( 'active_sitewide_plugins', array() );
			return isset( $reseau[ self::ANCIENNE ] );
		}
		return false;
	}

	public static function ancienne_presente() {
		return file_exists( WP_PLUGIN_DIR . '/' . self::ANCIENNE );
	}

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'desactiver_ancienne' ), 1 );
		add_action( 'admin_init', array( __CLASS__, 'restaurer' ), 2 );
		add_action( 'admin_notices', array( __CLASS__, 'avis' ) );
		add_action( 'pre_uninstall_plugin', array( __CLASS__, 'avant_desinstallation' ), 10, 1 );
		add_action( 'deleted_plugin', array( __CLASS__, 'apres_suppression' ), 10, 1 );
	}

	/** Désactive l'ancienne extension (sans lancer sa désactivation : les tâches planifiées restent). */
	public static function desactiver_ancienne() {
		if ( ! self::ancienne_active() || ! current_user_can( 'activate_plugins' ) || wp_doing_ajax() ) {
			return;
		}
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		deactivate_plugins( self::ANCIENNE, true, is_multisite() && is_network_admin() );
		update_option( self::AVIS, 'desactivee', false );
		if ( 'GET' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) { // phpcs:ignore
			// Recharge la page : le module intégré se charge à la requête suivante.
			wp_safe_redirect( remove_query_arg( array( 'activate', 'deactivate', 'plugin_status', '_wpnonce' ), wp_unslash( $_SERVER['REQUEST_URI'] ?? admin_url() ) ) ); // phpcs:ignore
			exit;
		}
	}

	/** Juste avant la suppression de l'ancienne extension : sauvegarde, puis uninstall.php neutralisé. */
	public static function avant_desinstallation( $plugin ) {
		if ( self::ANCIENNE !== $plugin ) {
			return;
		}
		$copie = array();
		foreach ( self::OPTIONS as $o ) {
			$v = get_option( $o, null );
			if ( null !== $v ) {
				$copie[ $o ] = $v;
			}
		}
		if ( $copie ) {
			update_option( self::SAUVEGARDE, $copie, false );
		}
		$fichier = WP_PLUGIN_DIR . '/' . dirname( self::ANCIENNE ) . '/uninstall.php';
		if ( file_exists( $fichier ) ) {
			@unlink( $fichier ); // phpcs:ignore -- le dossier entier est supprimé juste après
		}
	}

	public static function apres_suppression( $plugin ) {
		if ( self::ANCIENNE === $plugin ) {
			self::restaurer( true );
		}
	}

	/**
	 * Remet les options effacées (si uninstall.php n'a pas pu être neutralisé).
	 * Les blasons et feuilles de match en cache sont retéléchargés par la
	 * mise à jour lancée dans la minute.
	 */
	public static function restaurer( $forcer = false ) {
		$copie = get_option( self::SAUVEGARDE );
		if ( ! is_array( $copie ) ) {
			return;
		}
		if ( ! $forcer && self::ancienne_presente() ) {
			return; // suppression pas encore faite (ou échouée) : on garde la sauvegarde
		}
		$remis = false;
		foreach ( $copie as $o => $v ) {
			if ( false === get_option( $o ) ) {
				add_option( $o, $v, '', 'vhb_ffhb_settings' === $o ? 'yes' : 'no' );
				$remis = true;
			}
		}
		delete_option( self::SAUVEGARDE );
		if ( $remis && class_exists( 'VHB_Cron' ) && ! wp_next_scheduled( VHB_Cron::HOOK_RUN_NOW ) ) {
			wp_schedule_single_event( time() + 30, VHB_Cron::HOOK_RUN_NOW );
		}
		update_option( self::AVIS, 'supprimee', false );
	}

	public static function avis() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$etat = get_option( self::AVIS );
		if ( 'desactivee' === $etat ) {
			delete_option( self::AVIS );
			echo '<div class="notice notice-success is-dismissible"><p><strong>VHB 2.0 :</strong> l\'extension « VHB Résultats FFHB » est désormais incluse dans VHB. Elle a été désactivée ; résultats, réglages, équipes et tâches planifiées continuent sans changement. Vous pouvez la supprimer depuis la liste des extensions : ses données sont conservées.</p></div>';
			return;
		}
		if ( 'supprimee' === $etat ) {
			delete_option( self::AVIS );
			echo '<div class="notice notice-success is-dismissible"><p><strong>VHB :</strong> ancienne extension « VHB Résultats FFHB » supprimée. Réglages, équipes et résultats ont été conservés.</p></div>';
			return;
		}
		$ecran = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $ecran && 'plugins' === $ecran->id && self::ancienne_presente() && ! self::ancienne_active() ) {
			echo '<div class="notice notice-info"><p><strong>VHB :</strong> « VHB Résultats FFHB » est incluse dans VHB 2.0 et peut être supprimée sans risque (ses réglages et données sont conservés).</p></div>';
		}
	}
}
