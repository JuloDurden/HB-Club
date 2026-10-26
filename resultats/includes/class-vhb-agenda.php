<?php
/**
 * Agenda : flux iCal servis par le site, à partir de la copie locale.
 *
 *   ?vhb_agenda=seniors-filles   une équipe
 *   ?vhb_agenda=club             toutes les équipes visibles
 *
 * Les parents s'abonnent une fois (Google Agenda, iPhone, Outlook) ; leur
 * agenda se met à jour tout seul. Le flux porte les salles corrigées et les
 * scores des matchs joués, et ne sollicite jamais la FFHB.
 */

defined( 'ABSPATH' ) || exit;

class VHB_Agenda {

	const QUERY = 'vhb_agenda';
	const DUREE = 5400; // 1 h 30

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'serve' ), 0 );
	}

	public static function feed_url( $slug ) {
		return add_query_arg( self::QUERY, $slug, home_url( '/' ) );
	}

	/** Liens d'abonnement pour une équipe (ou « club »). */
	public static function links( $slug ) {
		$http   = self::feed_url( $slug );
		$webcal = preg_replace( '#^https?://#', 'webcal://', $http );
		return array(
			'google' => 'https://calendar.google.com/calendar/render?cid=' . rawurlencode( $webcal ),
			'webcal' => $webcal,
			'ics'    => $http,
		);
	}

	public static function serve() {
		if ( ! isset( $_GET[ self::QUERY ] ) ) { // phpcs:ignore
			return;
		}
		$slug = sanitize_title( wp_unslash( $_GET[ self::QUERY ] ) ); // phpcs:ignore
		if ( ! VHB_Settings::get()['agenda'] ) {
			status_header( 404 );
			exit;
		}
		if ( 'club' === $slug ) {
			$views = VHB_Store::views();
			$nom   = VHB_Club_Reglages::nom_club() . ' : tous les matchs';
		} else {
			$v     = VHB_Store::view( $slug );
			$views = $v && empty( $v['masquee'] ) ? array( $v ) : array();
			$nom   = $v ? VHB_Club_Reglages::sigle_club() . ' ' . $v['equipe'] : '';
		}
		if ( ! $views ) {
			status_header( 404 );
			exit( 'Agenda introuvable.' );
		}
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: inline; filename="vhb-' . $slug . '.ics"' );
		header( 'X-Robots-Tag: noindex' );
		header( 'Cache-Control: public, max-age=1800' );
		echo self::build( $views, $nom ); // phpcs:ignore
		exit;
	}

	public static function build( array $views, $nom ) {
		$host  = wp_parse_url( home_url(), PHP_URL_HOST );
		$stamp = gmdate( 'Ymd\THis\Z' );
		$out   = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//HB Club//Resultats FFHB ' . VHB_FFHB_VERSION . '//FR',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			self::line( 'X-WR-CALNAME', $nom ),
			'X-WR-TIMEZONE:Europe/Paris',
			'REFRESH-INTERVAL;VALUE=DURATION:PT6H',
			'X-PUBLISHED-TTL:PT6H',
		);
		foreach ( $views as $v ) {
			if ( ! $v['publie'] ) {
				continue;
			}
			foreach ( $v['rencontres'] as $r ) {
				$ts = VHB_Render::ts( $r['date'] );
				if ( ! $ts ) {
					continue; // journée sans horaire : pas d'événement
				}
				$titre = 'VHB ' . $v['equipe'] . ( empty( $r['principale'] ) && isset( $r['comp_nom'] ) ? ' (' . $r['comp_nom'] . ')' : '' ) . ' : ' . VHB_Render::label( $r['domicile'] ) . ' - ' . VHB_Render::label( $r['exterieur'] );
				if ( $r['joue'] ) {
					$titre .= $r['forfait'] ? ' (forfait)' : ' (' . $r['score1'] . '-' . $r['score2'] . ')';
				}
				$comp = null;
				foreach ( $v['competitions'] as $c ) {
					if ( $c['cle'] === ( $r['comp'] ?? '' ) ) {
						$comp = $c;
					}
				}
				$desc = trim( ( $comp ? $comp['competition'] . ( $comp['poule'] && 'coupe' !== $comp['type'] ? ', ' . $comp['poule'] : '' ) : $v['competition'] ) . ', ' . ( $r['tour_long'] ?? 'journée ' . (int) $r['journee'] ) );
				$desc .= "\n" . ( $r['a_domicile'] ? 'Match à domicile' : 'Match à l\'extérieur' );

				$out[] = 'BEGIN:VEVENT';
				$out[] = 'UID:rencontre-' . preg_replace( '/[^0-9]/', '', (string) $r['id'] ) . '@' . $host;
				$out[] = 'DTSTAMP:' . $stamp;
				$out[] = 'DTSTART:' . gmdate( 'Ymd\THis\Z', $ts );
				$out[] = 'DTEND:' . gmdate( 'Ymd\THis\Z', $ts + self::DUREE );
				$out[] = self::line( 'SUMMARY', $titre );
				$out[] = self::line( 'DESCRIPTION', $desc );
				if ( ! empty( $r['adresse'] ) ) {
					$l     = VHB_Render::lieu_affiche( $r );
					$out[] = self::line( 'LOCATION', $l['corrige'] ? $l['nom'] . ', ' . $l['adresse'] : $l['adresse'] );
					if ( null !== $l['lat'] ) {
						$out[] = 'GEO:' . $l['lat'] . ';' . $l['lon'];
					}
				}
				if ( ! empty( $r['url'] ) ) {
					$out[] = self::line( 'URL', $r['url'] );
				}
				$out[] = 'END:VEVENT';
			}
		}
		$out[] = 'END:VCALENDAR';
		return implode( "\r\n", $out ) . "\r\n";
	}

	/** Ligne iCal échappée et repliée à 75 octets. */
	private static function line( $name, $value ) {
		$value = str_replace( array( '\\', ';', ',', "\r\n", "\n" ), array( '\\\\', '\\;', '\\,', '\\n', '\\n' ), (string) $value );
		$line  = $name . ':' . $value;
		$out   = '';
		$max   = 75;
		while ( strlen( $line ) > $max ) {
			$cut = $max;
			// Ne pas couper au milieu d'un caractère UTF-8.
			while ( $cut > 0 && ( ord( $line[ $cut ] ) & 0xC0 ) === 0x80 ) {
				--$cut;
			}
			$out .= substr( $line, 0, $cut ) . "\r\n ";
			$line = substr( $line, $cut );
			$max  = 74; // la ligne de suite commence par une espace
		}
		return $out . $line;
	}
}
