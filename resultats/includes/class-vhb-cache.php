<?php
/**
 * Compatibilité avec les extensions de cache (W3 Total Cache sur le site du club).
 *
 * Constat du 27/09/2026 : après la mise à jour 1.2.0, la minification de
 * W3 Total Cache continuait de servir l'ancienne feuille de style de
 * l'extension (fichier combiné wp-content/cache/minify/xxxx.css). Le HTML
 * était neuf, le CSS ancien : noms de clubs coupés, mise en page cassée.
 *
 * 1. Nos CSS/JS sont exclus de la minification : ils sont petits, déjà
 *    compacts, et leur numéro de version suffit à invalider le cache navigateur.
 * 2. À chaque changement de version de l'extension : purge des caches.
 * 3. Après une mise à jour FFHB qui a modifié les données : purge du cache
 *    des pages, pour que les visiteurs voient les nouveaux résultats.
 */

defined( 'ABSPATH' ) || exit;

class VHB_Cache {

	public static function init() {
		add_filter( 'w3tc_minify_css_do_tag_minification', array( __CLASS__, 'skip_minify' ), 10, 3 );
		add_filter( 'w3tc_minify_js_do_tag_minification', array( __CLASS__, 'skip_minify' ), 10, 3 );
		add_action( 'vhb_ffhb_updated', array( __CLASS__, 'after_update' ) );
	}

	/** Pas de minification ni de concaténation pour les fichiers de l'extension. */
	public static function skip_minify( $do, $tag = '', $file = '' ) {
		$dossier = '/' . basename( VHB_CLUB_DIR ) . '/resultats/assets/'; // vhb-club ou hb-club selon l'édition
		if ( false !== strpos( (string) $tag, $dossier ) || false !== strpos( (string) $file, $dossier ) ) {
			return false;
		}
		return $do;
	}

	/** Numéro de version des fichiers : version de l'extension + date de modification. */
	public static function ver( $relative ) {
		$t = @filemtime( VHB_FFHB_DIR . $relative ); // phpcs:ignore
		return VHB_FFHB_VERSION . ( $t ? '.' . $t : '' );
	}

	/** Purge complète (changement de version de l'extension). */
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

	/** Purge du cache des pages seulement (nouveaux résultats). */
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

	public static function after_update( $entry ) {
		if ( ! empty( $entry['modifie'] ) ) {
			self::flush_pages();
		}
	}
}
