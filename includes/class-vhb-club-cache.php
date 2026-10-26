<?php
/**
 * Compatibilité avec W3 Total Cache (et WP Rocket, LiteSpeed par précaution).
 *
 * Même règle que VHB Résultats (constat du 27/09/2026) :
 * 1. Nos CSS/JS sont exclus de la minification W3TC et versionnés par date.
 * 2. Changement de version de l'extension : purge complète.
 * 3. Modification d'une donnée du club : purge du cache des pages, une seule
 *    fois en fin de requête, pour que le nouvel horaire s'affiche partout.
 */

defined( 'ABSPATH' ) || exit;

class VHB_Club_Cache {

	private static $pending = false;

	public static function init() {
		add_filter( 'w3tc_minify_css_do_tag_minification', array( __CLASS__, 'skip_minify' ), 10, 3 );
		add_filter( 'w3tc_minify_js_do_tag_minification', array( __CLASS__, 'skip_minify' ), 10, 3 );

		foreach ( array( VHB_Club_Types::CATEGORIE, VHB_Club_Types::LIEU, VHB_Club_Types::ENTENTE, VHB_Club_Types::STAGE ) as $type ) {
			add_action( 'save_post_' . $type, array( __CLASS__, 'changed' ) );
		}
		add_action( 'deleted_post', array( __CLASS__, 'changed_post' ) );
		add_action( 'trashed_post', array( __CLASS__, 'changed_post' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'changed_post' ) );
		add_action( 'update_option_' . VHB_Club_Reglages::OPTION, array( __CLASS__, 'changed' ) );
		add_action( 'update_option_' . VHB_Club_Reglages::OPTION_SAISONS, array( __CLASS__, 'changed' ) );
		add_action( 'update_option_' . VHB_Club_Inscriptions::OPTION, array( __CLASS__, 'changed' ) );
		add_action( 'update_option_' . VHB_Club_Inscriptions::OPTION_SAISONS, array( __CLASS__, 'changed' ) );
		add_action( 'update_option_' . VHB_Club_Vie::OPTION, array( __CLASS__, 'changed' ) );
	}

	public static function skip_minify( $do, $tag = '', $file = '' ) {
		$dossier = '/' . basename( VHB_CLUB_DIR ) . '/assets/'; // vhb-club ou hb-club selon l'édition
		if ( false !== strpos( (string) $tag, $dossier ) || false !== strpos( (string) $file, $dossier ) ) {
			return false;
		}
		return $do;
	}

	public static function ver( $relative ) {
		$t = @filemtime( VHB_CLUB_DIR . $relative ); // phpcs:ignore
		return VHB_CLUB_VERSION . ( $t ? '.' . $t : '' );
	}

	public static function changed_post( $post_id ) {
		$type = get_post_type( $post_id );
		if ( in_array( $type, array( VHB_Club_Types::CATEGORIE, VHB_Club_Types::LIEU, VHB_Club_Types::ENTENTE, VHB_Club_Types::STAGE ), true ) ) {
			self::changed();
		}
	}

	public static function changed() {
		VHB_Club_Donnees::reset();
		if ( ! self::$pending ) {
			self::$pending = true;
			add_action( 'shutdown', array( __CLASS__, 'flush_pages' ) );
		}
	}

	public static function flush_all() {
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		} else {
			if ( function_exists( 'w3tc_minify_flush' ) ) {
				w3tc_minify_flush();
			}
			if ( function_exists( 'w3tc_pgcache_flush' ) ) {
				w3tc_pgcache_flush();
			}
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		do_action( 'litespeed_purge_all' );
	}

	public static function flush_pages() {
		if ( function_exists( 'w3tc_flush_posts' ) ) {
			w3tc_flush_posts();
		} elseif ( function_exists( 'w3tc_pgcache_flush' ) ) {
			w3tc_pgcache_flush();
		} elseif ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		do_action( 'litespeed_purge_all' );
	}
}
