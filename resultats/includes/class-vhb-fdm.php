<?php
/**
 * Feuilles de match (FDME).
 *
 * L'adresse du PDF se déduit du code de la feuille, déjà présent dans la page
 * équipe de la FFHB : https://fdm.fdme.ffhandball.fr/W/A/G/U/WAGUILX.pdf
 *
 * Trois modes par équipe :
 *  - « masquees » : rien n'est affiché ;
 *  - « lien »     : lien direct vers le PDF de la FFHB ;
 *  - « copie »    : le PDF est téléchargé une fois par le cron et servi par le
 *                   site (dossier uploads protégé, noms non devinables,
 *                   en-tête X-Robots-Tag noindex).
 *
 * Seules les rencontres jouées et sans forfait ont une feuille exploitable
 * (la FFHB renvoie 404 pour un forfait, et une feuille vierge avant le match).
 */

defined( 'ABSPATH' ) || exit;

class VHB_Fdm {

	const CDN        = 'https://fdm.fdme.ffhandball.fr/';
	const DIR        = 'vhb-ffhb-fdm';
	const META       = 'vhb_ffhb_fdm';
	const MAX_TOUR   = 6;
	const MAX_OCTETS = 5242880; // 5 Mo
	/** Une copie prise moins de 48 h après le match est reprise une fois (feuille corrigée). */
	const DELAI_FINAL = 2 * DAY_IN_SECONDS;
	const QUERY      = 'vhb_fdm';

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'serve' ), 0 );
	}

	public static function valid( $code ) {
		return is_string( $code ) && (bool) preg_match( '/^[A-Z0-9]{4,16}$/', $code );
	}

	public static function remote_url( $code ) {
		if ( ! self::valid( $code ) ) {
			return '';
		}
		return self::CDN . $code[0] . '/' . $code[1] . '/' . $code[2] . '/' . $code[3] . '/' . $code . '.pdf';
	}

	public static function dir() {
		$u = wp_upload_dir( null, false );
		return trailingslashit( $u['basedir'] ) . self::DIR;
	}

	/** Nom de fichier non devinable : le code seul ne suffit pas à trouver la copie. */
	public static function path( $code ) {
		return self::dir() . '/' . $code . '-' . substr( wp_hash( 'vhb-fdm-' . $code ), 0, 12 ) . '.pdf';
	}

	public static function has_copy( $code ) {
		return self::valid( $code ) && file_exists( self::path( $code ) );
	}

	/** La rencontre a-t-elle une feuille exploitable ? */
	public static function eligible( array $r ) {
		return ! empty( $r['joue'] ) && empty( $r['forfait'] ) && self::valid( $r['fdm'] ?? '' );
	}

	/**
	 * URL à afficher pour une rencontre, selon le mode de l'équipe. '' si rien.
	 */
	public static function link( array $r, $mode ) {
		if ( 'masquees' === $mode || ! self::eligible( $r ) ) {
			return '';
		}
		if ( 'lien' === $mode ) {
			return self::remote_url( $r['fdm'] );
		}
		return self::has_copy( $r['fdm'] ) ? add_query_arg( self::QUERY, $r['fdm'], home_url( '/' ) ) : '';
	}

	private static function prepare_dir() {
		$dir = self::dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		if ( ! file_exists( $dir . '/index.html' ) ) {
			@file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			// Apache : accès direct interdit, les PDF passent par le site.
			@file_put_contents( $dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore
		}
		return true;
	}

	/**
	 * Télécharge les feuilles manquantes d'une équipe en mode « copie ».
	 *
	 * @return int nombre de feuilles ajoutées ou rafraîchies
	 */
	public static function ensure( array $team, VHB_Fetcher $fetcher ) {
		if ( ! self::prepare_dir() ) {
			return 0;
		}
		$meta  = get_option( self::META, array() );
		$meta  = is_array( $meta ) ? $meta : array();
		$added = 0;
		$tries = 0;
		foreach ( (array) ( $team['rencontres'] ?? array() ) as $r ) {
			if ( ! self::eligible( $r ) ) {
				continue;
			}
			$code  = $r['fdm'];
			$match = VHB_Render::ts( $r['date'] );
			$m     = $meta[ $code ] ?? array();
			$have  = file_exists( self::path( $code ) );

			if ( $have && ( ! empty( $m['final'] ) || time() < $match + self::DELAI_FINAL ) ) {
				continue; // copie définitive, ou trop tôt pour la reprendre
			}
			if ( ! $have && ! empty( $m['absent'] ) ) {
				// Feuille pas encore publiée : on réessaie chaque jour pendant 3 semaines.
				if ( time() - (int) $m['absent'] < DAY_IN_SECONDS || time() > $match + 21 * DAY_IN_SECONDS ) {
					continue;
				}
			}
			if ( $tries++ >= self::MAX_TOUR ) {
				break;
			}
			try {
				$pdf = $fetcher->get( self::remote_url( $code ), 1.0 );
			} catch ( VHB_Fetch_Exception $e ) {
				if ( ! $have ) {
					$meta[ $code ] = array( 'absent' => time() );
				}
				continue;
			}
			if ( '%PDF' !== substr( $pdf, 0, 4 ) || strlen( $pdf ) > self::MAX_OCTETS ) {
				continue;
			}
			if ( false !== file_put_contents( self::path( $code ), $pdf ) ) { // phpcs:ignore
				++$added;
				$meta[ $code ] = array(
					't'     => time(),
					'final' => $match && time() >= $match + self::DELAI_FINAL,
				);
			}
		}
		update_option( self::META, $meta, false );
		return $added;
	}

	/** Sert une copie locale : ?vhb_fdm=CODE */
	public static function serve() {
		if ( ! isset( $_GET[ self::QUERY ] ) ) { // phpcs:ignore
			return;
		}
		$code = strtoupper( sanitize_key( wp_unslash( $_GET[ self::QUERY ] ) ) ); // phpcs:ignore
		$ctx  = self::find( $code );
		if ( ! $ctx || 'copie' !== $ctx['mode'] || ! self::has_copy( $code ) ) {
			status_header( 404 );
			nocache_headers();
			header( 'X-Robots-Tag: noindex, nofollow' );
			exit( 'Feuille de match introuvable.' );
		}
		$r    = $ctx['rencontre'];
		$name = sanitize_file_name( 'VHB-' . $ctx['equipe'] . '-' . ( $r['tour'] ?? 'J' . (int) $r['journee'] ) . '-' . $r['domicile'] . '-' . $r['exterieur'] ) . '.pdf';
		$file = self::path( $code );
		header( 'Content-Type: application/pdf' );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'Content-Disposition: inline; filename="' . $name . '"' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Cache-Control: public, max-age=86400' );
		readfile( $file ); // phpcs:ignore
		exit;
	}

	/** Retrouve la rencontre et le mode de son équipe (équipes visibles seulement). */
	private static function find( $code ) {
		if ( ! self::valid( $code ) ) {
			return null;
		}
		foreach ( VHB_Store::views() as $v ) {
			foreach ( $v['rencontres'] as $r ) {
				if ( ( $r['fdm'] ?? '' ) === $code && self::eligible( $r ) ) {
					return array(
						'mode'      => $v['fdm_mode'],
						'equipe'    => $v['equipe'],
						'rencontre' => $r,
					);
				}
			}
		}
		return null;
	}

	public static function count() {
		$f = glob( self::dir() . '/*.pdf' );
		return $f ? count( $f ) : 0;
	}

	public static function size() {
		$t = 0;
		foreach ( (array) glob( self::dir() . '/*.pdf' ) as $f ) {
			$t += $f ? (int) filesize( $f ) : 0;
		}
		return $t;
	}

	public static function clear() {
		foreach ( (array) glob( self::dir() . '/*.pdf' ) as $f ) {
			if ( $f ) {
				wp_delete_file( $f );
			}
		}
		delete_option( self::META );
	}
}
