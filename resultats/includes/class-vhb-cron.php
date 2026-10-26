<?php
/**
 * Planification et exécution des mises à jour.
 *
 * Une exécution (« run ») traite les équipes une par une (« step »). Chaque
 * étape tient en quelques secondes, ce qui évite les coupures des hébergeurs
 * à 30 s : le cron enchaîne les étapes dans un budget de temps puis replanifie
 * la suite, le bouton « Rafraîchir maintenant » les enchaîne en AJAX.
 *
 * Verrou : transient « vhb_ffhb_lock » (10 min) tant qu'une exécution est en cours.
 */

defined( 'ABSPATH' ) || exit;

class VHB_Cron {

	const HOOK_TICK     = 'vhb_ffhb_tick';
	const HOOK_CONTINUE = 'vhb_ffhb_continue';
	const HOOK_RUN_NOW  = 'vhb_ffhb_run_now';

	const LOCK       = 'vhb_ffhb_lock';
	const LOCK_TTL   = 600;
	const RUN        = 'vhb_ffhb_run';
	const MUTEX      = 'vhb_ffhb_step_mutex';
	const LOG        = 'vhb_ffhb_log';
	const LOG_MAX    = 20;
	const FAILS      = 'vhb_ffhb_failures';
	const FAILS_ALERT = 3;
	const LAST_START = 'vhb_ffhb_last_start';

	/** Budget de temps d'un passage cron, en secondes. */
	const BUDGET = 22;

	/** @var VHB_Fetcher|null partagé dans une même requête pour respecter la pause */
	private static $fetcher;

	public static function init() {
		add_action( self::HOOK_TICK, array( __CLASS__, 'tick' ) );
		add_action( self::HOOK_CONTINUE, array( __CLASS__, 'work' ) );
		add_action( self::HOOK_RUN_NOW, array( __CLASS__, 'run_now' ) );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
	}

	public static function activate() {
		VHB_Settings::install();
		if ( false === get_option( 'vhb_ffhb_version' ) ) {
			add_option( 'vhb_ffhb_version', VHB_FFHB_VERSION );
		}
		self::ensure_scheduled();
		// Première exécution dans la minute qui suit l'activation.
		if ( ! wp_next_scheduled( self::HOOK_RUN_NOW ) ) {
			wp_schedule_single_event( time() + 30, self::HOOK_RUN_NOW );
		}
	}

	public static function deactivate() {
		foreach ( array( self::HOOK_TICK, self::HOOK_CONTINUE, self::HOOK_RUN_NOW ) as $h ) {
			wp_unschedule_hook( $h ); // toutes les occurrences, avec ou sans arguments
		}
		delete_transient( self::LOCK );
		delete_option( self::RUN );
		delete_option( self::MUTEX );
	}

	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::HOOK_TICK ) ) {
			// Aligné sur l'heure pleine suivante.
			wp_schedule_event( (int) ( ceil( time() / HOUR_IN_SECONDS ) * HOUR_IN_SECONDS ) + 60, 'hourly', self::HOOK_TICK );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Calendrier                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Samedi et dimanche de 14 h à 23 h : toutes les heures.
	 * Le reste du temps : 7 h et 19 h.
	 */
	public static function is_due( ?DateTimeImmutable $now = null ) {
		$now  = $now ? $now : new DateTimeImmutable( 'now', wp_timezone() );
		$h    = (int) $now->format( 'G' );
		$wkd  = (int) $now->format( 'N' ) >= 6;
		$due  = ( $wkd && $h >= 14 && $h <= 23 ) || 7 === $h || 19 === $h;
		$last = (int) get_option( self::LAST_START, 0 );
		if ( $last && ( time() - $last ) < 50 * MINUTE_IN_SECONDS ) {
			$due = false;
		}
		return (bool) apply_filters( 'vhb_ffhb_is_due', $due, $now );
	}

	public static function tick() {
		if ( self::is_due() ) {
			self::run_now( 'cron' );
		} elseif ( get_option( self::RUN ) ) {
			self::work(); // une exécution interrompue à reprendre
		}
	}

	public static function run_now( $source = 'planifiée' ) {
		$r = self::start( is_string( $source ) && '' !== $source ? $source : 'planifiée' );
		if ( 'started' === $r['status'] ) {
			self::work();
		}
	}

	/** Enchaîne les étapes dans le budget de temps, puis replanifie la suite. */
	public static function work() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore
		}
		$t0 = microtime( true );
		do {
			$r = self::step();
		} while ( 'running' === $r['status'] && ( microtime( true ) - $t0 ) < self::BUDGET );

		if ( in_array( $r['status'], array( 'running', 'busy' ), true ) && ! wp_next_scheduled( self::HOOK_CONTINUE ) ) {
			wp_schedule_single_event( time() + 30, self::HOOK_CONTINUE );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Exécution                                                           */
	/* ------------------------------------------------------------------ */

	public static function state() {
		$run = get_option( self::RUN );
		return is_array( $run ) ? $run : null;
	}

	/**
	 * Démarre une exécution si aucune n'est en cours.
	 *
	 * @return array{status:string,run:?array}
	 */
	public static function start( $source ) {
		$run  = self::state();
		$lock = get_transient( self::LOCK );
		if ( $run && $lock ) {
			return array( 'status' => 'busy', 'run' => self::public_state( $run ) );
		}
		if ( $run && ! $lock ) {
			self::finish( $run, true ); // exécution abandonnée (verrou expiré)
		}

		$queue = array();
		// Une entrée par compétition : « slug|identifiant de compétition ».
		foreach ( VHB_Settings::equipes() as $e ) {
			foreach ( $e['competitions'] as $c ) {
				$queue[] = $e['slug'] . '|' . $c['cle'];
			}
		}
		$run = array(
			'id'       => wp_generate_password( 8, false ),
			'source'   => $source,
			'started'  => microtime( true ),
			'total'    => count( $queue ),
			'queue'    => $queue,
			'ok'       => array(),
			'ko'       => array(),
			'warnings' => array(),
			'logos'    => 0,
			'dernier'  => '',
			'empreinte' => VHB_Store::fingerprint(),
		);
		set_transient( self::LOCK, $run['id'], self::LOCK_TTL );
		update_option( self::RUN, $run, false );
		update_option( self::LAST_START, time(), false );
		return array( 'status' => 'started', 'run' => self::public_state( $run ) );
	}

	/**
	 * Traite l'équipe suivante de la file.
	 *
	 * @return array{status:string,run:?array}
	 */
	public static function step() {
		if ( ! self::state() ) {
			return array( 'status' => 'idle', 'run' => null );
		}
		if ( ! self::acquire_mutex() ) {
			return array( 'status' => 'busy', 'run' => self::public_state( self::state() ) );
		}
		try {
			$run = self::state(); // relu sous mutex
			if ( ! $run ) {
				return array( 'status' => 'idle', 'run' => null );
			}
			if ( empty( $run['queue'] ) ) {
				self::finish( $run );
				return array( 'status' => 'done', 'run' => self::public_state( $run ) );
			}

			$item = array_shift( $run['queue'] );
			update_option( self::RUN, $run, false ); // une compétition qui plante n'est pas rejouée en boucle

			list( $slug, $cle ) = array_pad( explode( '|', (string) $item, 2 ), 2, '' );
			$cfg                = VHB_Settings::equipe( $slug );
			$comp               = null;
			foreach ( $cfg ? $cfg['competitions'] : array() as $c ) {
				if ( $c['cle'] === $cle ) {
					$comp = $c;
				}
			}
			if ( $comp ) {
				self::one( $cfg, $comp, $run );
			}
			set_transient( self::LOCK, $run['id'], self::LOCK_TTL );

			if ( empty( $run['queue'] ) ) {
				self::finish( $run );
				return array( 'status' => 'done', 'run' => self::public_state( $run ) );
			}
			update_option( self::RUN, $run, false );
			return array( 'status' => 'running', 'run' => self::public_state( $run ) );
		} finally {
			delete_option( self::MUTEX );
		}
	}

	private static function one( array $cfg, array $comp, array &$run ) {
		if ( ! self::$fetcher ) {
			self::$fetcher = new VHB_Fetcher( VHB_Settings::get()['club_id'] );
		}
		$f     = self::$fetcher;
		$fails = get_option( self::FAILS, array() );
		$fails = is_array( $fails ) ? $fails : array();
		// Nom dans le journal : « Seniors Filles » ou « Seniors Filles (Coupe de France) ».
		$nom = $cfg['nom'] . ( count( $cfg['competitions'] ) > 1 ? ' (' . VHB_Store::comp_label( $comp ) . ')' : '' );
		$key = $cfg['slug'] . '|' . $comp['cle'];
		try {
			$data = $f->team( array_merge( $cfg, array( 'url' => $comp['url'] ) ) );
			VHB_Store::save_comp( $cfg['slug'], $comp['cle'], $data );
			foreach ( $f->warnings as $w ) {
				$run['warnings'][] = $nom . ' : ' . $w;
			}
			$saved         = VHB_Store::comp( $cfg['slug'], $comp['cle'] );
			$run['logos'] += VHB_Logos::ensure( VHB_Logos::stems_of( $saved ), $f );
			if ( 'copie' === ( $cfg['fdm'] ?? 'copie' ) ) {
				$run['fdm'] = (int) ( $run['fdm'] ?? 0 ) + VHB_Fdm::ensure( $saved, $f );
			}
			$run['ok'][]    = $nom;
			$run['dernier'] = $nom . ' : ok';
			$fails[ $key ]  = 0;
		} catch ( Throwable $e ) {
			$msg = $e->getMessage();
			VHB_Store::mark_error( $cfg['slug'], $comp['cle'], $msg );
			$run['ko'][ $nom ] = $msg;
			$run['dernier']    = $nom . ' : échec';
			$fails[ $key ]     = (int) ( $fails[ $key ] ?? 0 ) + 1;
			if ( self::FAILS_ALERT === $fails[ $key ] ) {
				self::alert( array_merge( $cfg, array( 'nom' => $nom ) ), $msg );
			}
		}
		update_option( self::FAILS, $fails, false );
	}

	private static function acquire_mutex() {
		if ( add_option( self::MUTEX, time(), '', false ) ) {
			return true;
		}
		$since = (int) get_option( self::MUTEX, 0 );
		if ( $since && $since < time() - 150 ) { // étape plantée : on libère
			delete_option( self::MUTEX );
			return (bool) add_option( self::MUTEX, time(), '', false );
		}
		return false;
	}

	private static function finish( array $run, $interrompu = false ) {
		$entry = array(
			'date'       => ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'c' ),
			'source'     => $run['source'],
			'duree'      => round( microtime( true ) - (float) $run['started'], 1 ),
			'ok'         => $run['ok'],
			'ko'         => $run['ko'],
			'warnings'   => array_slice( $run['warnings'], 0, 20 ),
			'logos'      => (int) $run['logos'],
			'fdm'        => (int) ( $run['fdm'] ?? 0 ),
			'interrompu' => $interrompu,
			'restantes'  => $interrompu ? count( $run['queue'] ) : 0,
			'modifie'    => VHB_Store::fingerprint() !== ( $run['empreinte'] ?? '' ) || ! empty( $run['fdm'] ),
		);
		$log = get_option( self::LOG, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift( $log, $entry );
		update_option( self::LOG, array_slice( $log, 0, self::LOG_MAX ), false );

		delete_option( self::RUN );
		delete_transient( self::LOCK );
		VHB_Store::prune();
		do_action( 'vhb_ffhb_updated', $entry );
	}

	public static function log() {
		$log = get_option( self::LOG, array() );
		return is_array( $log ) ? $log : array();
	}

	/** Date de la dernière mise à jour réussie (au moins une équipe). */
	public static function last_success() {
		foreach ( self::log() as $e ) {
			if ( ! empty( $e['ok'] ) ) {
				return $e['date'];
			}
		}
		return '';
	}

	public static function public_state( $run ) {
		if ( ! $run ) {
			return null;
		}
		return array(
			'source'  => $run['source'],
			'total'   => (int) $run['total'],
			'fait'    => (int) $run['total'] - count( $run['queue'] ),
			'ok'      => $run['ok'],
			'ko'      => $run['ko'],
			'dernier' => $run['dernier'],
		);
	}

	private static function alert( array $cfg, $msg ) {
		$s  = VHB_Settings::get();
		$to = $s['alert_email'] ? $s['alert_email'] : get_option( 'admin_email' );
		if ( ! $to ) {
			return;
		}
		$body = sprintf(
			"Bonjour,\n\nLa récupération des données FFHB échoue pour l'équipe « %s » depuis %d exécutions consécutives.\n\nDernière erreur :\n%s\n\nLes données affichées sur le site restent celles de la dernière récupération réussie.\n\nCauses possibles : la FFHB a modifié son site, l'URL de l'équipe a changé (nouvelle saison ou nouvelle phase), ou le site de la FFHB est indisponible.\n\nJournal et réglages : %s\n",
			$cfg['nom'],
			self::FAILS_ALERT,
			$msg,
			admin_url( 'admin.php?page=vhb-ffhb' )
		);
		wp_mail( $to, '[VHB] Résultats FFHB, échecs répétés : ' . $cfg['nom'], $body );
	}
}
