<?php
/**
 * Matchs à domicile, jour par jour, pour l'organisation (buvette,
 * animations, bénévoles). Page d'administration uniquement.
 *
 * Toutes les équipes sont prises en compte, y compris les équipes masquées
 * sur le site : elles jouent quand même au gymnase.
 */

defined( 'ABSPATH' ) || exit;

class VHB_Domicile {

	/**
	 * Matchs à domicile à venir.
	 *
	 * @return array{dates:array<string,array>,sans_date:array<string,array>}
	 *   dates : 'Y-m-d' => liste de matchs triés par heure
	 *   sans_date : 'debut|fin' (dates de la journée FFHB) => liste de matchs
	 */
	public static function collect() {
		$today = ( new DateTimeImmutable( 'today', wp_timezone() ) )->getTimestamp();
		$dates = array();
		$sans  = array();
		foreach ( VHB_Store::views( true ) as $v ) {
			foreach ( $v['rencontres'] as $r ) {
				if ( empty( $r['a_domicile'] ) || ! empty( $r['joue'] ) ) {
					continue;
				}
				$ts   = VHB_Render::ts( $r['date'] );
				$lieu = empty( $r['adresse'] ) ? 'Salle à confirmer' : VHB_Render::lieu_affiche( $r )['nom'];
				$m    = array(
					'ts'         => $ts,
					'equipe'     => $v['equipe'],
					'masquee'    => ! empty( $v['masquee'] ),
					'adversaire' => VHB_Render::label( $r['exterieur'] ),
					'competition' => $r['comp_nom'] ?? '',
					'principale' => ! isset( $r['principale'] ) || $r['principale'],
					'tour'       => $r['tour_long'] ?? '',
					'salle'      => $lieu,
				);
				if ( $ts ) {
					if ( $ts < $today ) {
						continue;
					}
					$dates[ wp_date( 'Y-m-d', $ts ) ][] = $m;
				} elseif ( ! empty( $r['journee_debut'] ) && $r['journee_debut'] >= wp_date( 'Y-m-d', $today ) ) {
					$sans[ $r['journee_debut'] . '|' . ( $r['journee_fin'] ?: $r['journee_debut'] ) ][] = $m;
				}
			}
		}
		ksort( $dates );
		ksort( $sans );
		foreach ( $dates as &$l ) {
			usort(
				$l,
				static function ( $a, $b ) {
					return $a['ts'] <=> $b['ts'];
				}
			);
		}
		unset( $l );
		return array( 'dates' => $dates, 'sans_date' => $sans );
	}

	/** Prochain jour avec au moins deux matchs à domicile : array( 'Y-m-d', nombre ) ou null. */
	public static function prochain_jour_charge() {
		foreach ( self::collect()['dates'] as $d => $l ) {
			if ( count( $l ) >= 2 ) {
				return array( $d, count( $l ) );
			}
		}
		return null;
	}

	public static function jour_long( $ymd ) {
		$d = DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, wp_timezone() );
		if ( ! $d ) {
			return $ymd;
		}
		$j = (int) $d->format( 'j' );
		return ucfirst( VHB_Render::JOURS[ (int) $d->format( 'N' ) ] ) . ' ' . ( 1 === $j ? '1er' : $j ) . ' ' . VHB_Render::MOIS[ (int) $d->format( 'n' ) ] . ' ' . $d->format( 'Y' );
	}

	/** « 7 et 8 novembre 2026 », « du 31 octobre au 1er novembre 2026 ». */
	public static function plage( $a, $b ) {
		$tz = wp_timezone();
		$da = DateTimeImmutable::createFromFormat( '!Y-m-d', $a, $tz );
		$db = DateTimeImmutable::createFromFormat( '!Y-m-d', $b, $tz );
		if ( ! $da || ! $db || $a === $b ) {
			return self::jour_long( $a );
		}
		$j  = static function ( $d ) {
			return 1 === (int) $d->format( 'j' ) ? '1er' : $d->format( 'j' );
		};
		$mb = VHB_Render::MOIS[ (int) $db->format( 'n' ) ] . ' ' . $db->format( 'Y' );
		if ( $da->format( 'n' ) === $db->format( 'n' ) ) {
			return 'Samedi ' . $j( $da ) . ' et dimanche ' . $j( $db ) . ' ' . $mb;
		}
		return 'Du ' . $j( $da ) . ' ' . VHB_Render::MOIS[ (int) $da->format( 'n' ) ] . ' au ' . $j( $db ) . ' ' . $mb;
	}

	/** Page « Matchs à domicile ». */
	public static function page() {
		if ( ! current_user_can( VHB_Admin::cap_domicile() ) ) {
			return;
		}
		$tous = ! empty( $_GET['tous'] ); // phpcs:ignore
		$data = self::collect();
		$url  = admin_url( 'admin.php?page=' . VHB_Admin::SLUG_DOMICILE );
		$nb2  = count( array_filter( $data['dates'], static function ( $l ) { return count( $l ) >= 2; } ) );
		?>
		<div class="wrap vhb-admin vhb-dom">
			<h1>Matchs à domicile</h1>
			<p class="description">Matchs à venir joués à domicile, toutes équipes et toutes compétitions confondues (équipes masquées comprises), pour organiser la buvette, les animations et les bénévoles. Mis à jour à chaque récupération FFHB.</p>

			<div class="vhb-dom-bar">
				<span class="vhb-dom-count"><strong><?php echo (int) $nb2; ?></strong> jour(s) à plusieurs matchs à domicile</span>
				<span class="subsubsub">
					<a href="<?php echo esc_url( $url ); ?>" class="<?php echo $tous ? '' : 'current'; ?>">Jours à plusieurs matchs</a> |
					<a href="<?php echo esc_url( add_query_arg( 'tous', 1, $url ) ); ?>" class="<?php echo $tous ? 'current' : ''; ?>">Tous les matchs à domicile</a>
				</span>
			</div>

			<?php
			$shown = 0;
			foreach ( $data['dates'] as $ymd => $list ) :
				$n = count( $list );
				if ( ! $tous && $n < 2 ) {
					continue;
				}
				++$shown;
				$debut = $list[0]['ts'];
				$fin   = end( $list )['ts'] + VHB_Agenda::DUREE;
				?>
				<div class="vhb-dom-day<?php echo $n >= 2 ? ' is-multi' : ''; ?>">
					<div class="vhb-dom-head">
						<h2><?php echo esc_html( self::jour_long( $ymd ) ); ?></h2>
						<span class="vhb-dom-badge"><?php echo (int) $n; ?> match<?php echo $n > 1 ? 's' : ''; ?> à domicile</span>
						<span class="vhb-dom-window">de <?php echo esc_html( wp_date( 'G\hi', $debut ) ); ?> à <?php echo esc_html( wp_date( 'G\hi', $fin ) ); ?> environ</span>
					</div>
					<table class="widefat striped">
						<thead><tr><th>Heure</th><th>Équipe</th><th>Adversaire</th><th>Compétition</th><th>Salle</th></tr></thead>
						<tbody>
						<?php foreach ( $list as $m ) : ?>
							<tr>
								<td class="vhb-dom-h"><?php echo esc_html( wp_date( 'G\hi', $m['ts'] ) ); ?></td>
								<td><strong><?php echo esc_html( $m['equipe'] ); ?></strong><?php echo $m['masquee'] ? ' <span class="vhb-pill vhb-pill--off">masquée</span>' : ''; ?></td>
								<td><?php echo esc_html( $m['adversaire'] ); ?></td>
								<td><?php echo esc_html( trim( $m['competition'] . ( ! $m['principale'] && $m['tour'] ? ' · ' . $m['tour'] : '' ) ) ); ?></td>
								<td><?php echo esc_html( $m['salle'] ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endforeach; ?>

			<?php if ( ! $shown ) : ?>
				<div class="notice notice-info inline"><p><?php echo $tous ? 'Aucun match à domicile à venir avec une date fixée.' : 'Aucun jour à venir avec plusieurs matchs à domicile (parmi les matchs dont la date est fixée).'; ?></p></div>
			<?php endif; ?>

			<?php if ( $data['sans_date'] ) : ?>
				<h2 class="vhb-dom-sub">Matchs à domicile sans date ni horaire fixés</h2>
				<p class="description">La FFHB ne donne encore que le week-end de la journée. À surveiller : ces matchs viendront s'ajouter aux jours ci-dessus.</p>
				<table class="widefat striped vhb-dom-tbc">
					<thead><tr><th>Week-end</th><th>Matchs</th><th>Équipes</th></tr></thead>
					<tbody>
					<?php
					foreach ( $data['sans_date'] as $k => $list ) :
						list( $a, $b ) = explode( '|', $k );
						$lib           = self::plage( $a, $b );
						?>
						<tr class="<?php echo count( $list ) >= 2 ? 'is-multi' : ''; ?>">
							<td><?php echo esc_html( $lib ); ?></td>
							<td><strong><?php echo (int) count( $list ); ?></strong></td>
							<td><?php echo esc_html( implode( ', ', array_map( static function ( $m ) { return $m['equipe'] . ' - ' . $m['adversaire'] . ( $m['principale'] ? '' : ' (' . $m['competition'] . ')' ); }, $list ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
