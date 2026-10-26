<?php
/**
 * Cache local des blasons de clubs.
 *
 * Les blasons sont téléchargés une fois depuis le CDN FFHB puis servis depuis
 * wp-content/uploads/vhb-ffhb-logos/ (pas de hotlink, et le dossier survit
 * aux mises à jour de l'extension). Sans blason : pastille grise à initiales.
 */

defined( 'ABSPATH' ) || exit;

class VHB_Logos {

	const CDN        = 'https://media-logos-clubs.ffhandball.fr/64/';
	const DIR        = 'vhb-ffhb-logos';
	const MISS_OPT   = 'vhb_ffhb_logos_absents';
	const MAX_PAR_TOUR = 12;
	const RETRY_DAYS = 7;

	public static function dir() {
		$u = wp_upload_dir( null, false );
		return trailingslashit( $u['basedir'] ) . self::DIR;
	}

	public static function base_url() {
		$u = wp_upload_dir( null, false );
		return trailingslashit( $u['baseurl'] ) . self::DIR;
	}

	private static function valid( $stem ) {
		return is_string( $stem ) && '' !== $stem && preg_match( '/^[A-Za-z0-9._-]+$/', $stem );
	}

	public static function path( $stem ) {
		return self::dir() . '/' . $stem . '.webp';
	}

	/** URL locale du blason, ou '' s'il n'est pas (encore) en cache. */
	public static function url( $stem ) {
		if ( ! self::valid( $stem ) || ! file_exists( self::path( $stem ) ) ) {
			return '';
		}
		return self::base_url() . '/' . rawurlencode( $stem ) . '.webp';
	}

	/** Blasons référencés par une équipe normalisée. */
	public static function stems_of( array $team ) {
		$s = array();
		foreach ( (array) ( $team['rencontres'] ?? array() ) as $r ) {
			$s[] = $r['logo1'] ?? '';
			$s[] = $r['logo2'] ?? '';
		}
		foreach ( (array) ( $team['classement'] ?? array() ) as $c ) {
			$s[] = $c['logo'] ?? '';
		}
		return array_values( array_unique( array_filter( $s, array( __CLASS__, 'valid' ) ) ) );
	}

	/**
	 * Télécharge les blasons manquants (au plus MAX_PAR_TOUR par appel, le
	 * reste au tour suivant). Retourne le nombre de blasons ajoutés.
	 */
	public static function ensure( array $stems, VHB_Fetcher $fetcher ) {
		$dir = self::dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return 0;
		}
		if ( ! file_exists( $dir . '/index.html' ) ) {
			@file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore
		}
		$miss  = get_option( self::MISS_OPT, array() );
		$miss  = is_array( $miss ) ? $miss : array();
		$added = 0;
		$tries = 0;
		foreach ( $stems as $stem ) {
			if ( ! self::valid( $stem ) || file_exists( self::path( $stem ) ) ) {
				continue;
			}
			if ( isset( $miss[ $stem ] ) && $miss[ $stem ] > time() - self::RETRY_DAYS * DAY_IN_SECONDS ) {
				continue;
			}
			if ( $tries++ >= self::MAX_PAR_TOUR ) {
				break;
			}
			try {
				$bin = $fetcher->get( self::CDN . rawurlencode( $stem ) . '.webp', 0.5 );
			} catch ( VHB_Fetch_Exception $e ) {
				$miss[ $stem ] = time();
				continue;
			}
			// Contrôle : un vrai fichier WebP commence par RIFF....WEBP.
			if ( strlen( $bin ) < 16 || 'RIFF' !== substr( $bin, 0, 4 ) || 'WEBP' !== substr( $bin, 8, 4 ) ) {
				$miss[ $stem ] = time();
				continue;
			}
			if ( false !== file_put_contents( self::path( $stem ), $bin ) ) { // phpcs:ignore
				++$added;
				unset( $miss[ $stem ] );
			}
		}
		update_option( self::MISS_OPT, $miss, false );
		return $added;
	}

	public static function count() {
		$f = glob( self::dir() . '/*.webp' );
		return $f ? count( $f ) : 0;
	}

	/** Vide le cache ; les blasons seront retéléchargés au prochain rafraîchissement. */
	public static function clear() {
		foreach ( (array) glob( self::dir() . '/*.webp' ) as $f ) {
			if ( $f ) {
				wp_delete_file( $f );
			}
		}
		delete_option( self::MISS_OPT );
	}

	/** Initiales pour la pastille de remplacement : « ADVERSAIRE HB 3 » donne « AD ». */
	public static function initials( $label ) {
		$stop  = array( 'HB', 'HBC', 'HANDBALL', 'HAND', 'BALL', 'CLUB', 'AS', 'ASC', 'CC', 'US', 'UC', 'DE', 'DU', 'LA', 'LE', 'LES', 'ET', 'SPORT', 'SPORTS' );
		$words = preg_split( '/[\s\-\(\)\/]+/', VHB_Settings::upper( (string) $label ), -1, PREG_SPLIT_NO_EMPTY );
		$keep  = array_values(
			array_filter(
				$words,
				static function ( $w ) use ( $stop ) {
					return ! in_array( $w, $stop, true ) && ! preg_match( '/^[0-9]+$/', $w );
				}
			)
		);
		if ( ! $keep ) {
			$keep = $words ? $words : array( '?' );
		}
		if ( 1 === count( $keep ) ) {
			return function_exists( 'mb_substr' ) ? mb_substr( $keep[0], 0, 2, 'UTF-8' ) : substr( $keep[0], 0, 2 );
		}
		$f = static function ( $w ) {
			return function_exists( 'mb_substr' ) ? mb_substr( $w, 0, 1, 'UTF-8' ) : substr( $w, 0, 1 );
		};
		return $f( $keep[0] ) . $f( $keep[1] );
	}
}
