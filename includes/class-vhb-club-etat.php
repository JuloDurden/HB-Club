<?php
/**
 * Écran « État » (2.0.0) : tout ce qui peut aller de travers, sur une page.
 *
 * Mises à jour FFHB (dernière réussie, prochaine planifiée, WP-Cron qui ne
 * passe plus), erreurs par équipe et par compétition, photos d'équipe,
 * points à compléter de VHB Club, versions, cache. Lecture seule, sauf le
 * bouton « Vider le cache des pages ».
 */

defined( 'ABSPATH' ) || exit;

class VHB_Club_Etat {

	const PAGE = 'vhb-etat';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 12 );
		add_action( 'admin_post_vhb_etat_cache', array( __CLASS__, 'vider_cache' ) );
	}

	public static function menu() {
		add_submenu_page( VHB_Club_Admin_Slugs::MENU, 'État', 'État', VHB_Club_Reglages::CAP_DONNEES, self::PAGE, array( __CLASS__, 'page' ) );
	}

	public static function vider_cache() {
		if ( ! current_user_can( VHB_Club_Reglages::CAP_DONNEES ) ) {
			wp_die( 'Accès refusé.', 403 );
		}
		check_admin_referer( 'vhb_etat_cache' );
		VHB_Club_Cache::flush_all();
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&cache=1' ) );
		exit;
	}

	/** Âge lisible : « il y a 3 h », « il y a 2 jours ». */
	private static function age( $ts ) {
		return $ts ? 'il y a ' . human_time_diff( $ts, time() ) : '';
	}

	private static function ligne( $niveau, $titre, $texte, $lien = '', $libelle = '' ) {
		$icones = array( 'ok' => 'yes-alt', 'alerte' => 'warning', 'erreur' => 'dismiss', 'info' => 'info-outline' );
		echo '<tr class="vhb-etat--' . esc_attr( $niveau ) . '"><td class="vhb-etat-icone"><span class="dashicons dashicons-' . esc_attr( $icones[ $niveau ] ?? 'marker' ) . '"></span></td>';
		echo '<th scope="row">' . esc_html( $titre ) . '</th><td>' . wp_kses_post( $texte );
		if ( $lien ) {
			echo ' <a href="' . esc_url( $lien ) . '">' . esc_html( $libelle ? $libelle : 'Voir' ) . '</a>';
		}
		echo '</td></tr>';
	}

	public static function page() {
		if ( ! current_user_can( VHB_Club_Reglages::CAP_DONNEES ) ) {
			return;
		}
		$admin = current_user_can( 'manage_options' );
		$res   = $admin ? admin_url( 'admin.php?page=vhb-ffhb' ) : '';
		?>
		<div class="wrap vhbc-admin vhb-etat">
			<h1>État</h1>
			<?php if ( isset( $_GET['cache'] ) ) : // phpcs:ignore ?>
				<div class="notice notice-success is-dismissible"><p>Cache des pages vidé.</p></div>
			<?php endif; ?>
			<p class="description">Vue d'ensemble de l'extension. Vert : rien à faire. Orange : à surveiller. Rouge : à corriger.</p>

			<h2>Résultats FFHB</h2>
			<table class="widefat vhb-etat-table"><tbody>
			<?php
			if ( ! class_exists( 'VHB_Cron' ) || ! defined( 'VHB_RESULTATS_INTEGRE' ) || ! VHB_RESULTATS_INTEGRE ) {
				if ( VHB_Fusion::ancienne_active() ) {
					self::ligne( 'alerte', 'Module', 'L\'ancienne extension « VHB Résultats FFHB » est encore active : elle est désactivée automatiquement à la visite d\'un administrateur.', admin_url( 'plugins.php' ), 'Extensions' );
				} else {
					self::ligne( 'erreur', 'Module', 'Module Résultats FFHB non chargé.' );
				}
			} else {
				$ok   = VHB_Cron::last_success();
				$ts   = $ok ? VHB_Render::ts( $ok ) : 0;
				$h    = $ts ? ( time() - $ts ) / HOUR_IN_SECONDS : 0;
				$next = wp_next_scheduled( VHB_Cron::HOOK_TICK );
				$run  = VHB_Cron::state();
				// Hors week-end, deux passages par jour (7 h et 19 h) : 13 h sans mise à jour est normal.
				$niv = ! $ts ? 'erreur' : ( $h > 72 ? 'erreur' : ( $h > 26 ? 'alerte' : 'ok' ) );
				self::ligne( $niv, 'Dernière mise à jour réussie', $ts ? esc_html( wp_date( 'd/m/Y à H:i', $ts ) . ' (' . self::age( $ts ) . ')' ) : '<em>jamais</em>', $res, 'Résultats FFHB' );
				if ( ! $next ) {
					self::ligne( 'erreur', 'Prochaine vérification', 'Non planifiée. Elle se replanifie seule au prochain chargement de page ; si ce message reste, désactivez puis réactivez ' . VHB_MARQUE . '.' );
				} elseif ( $next < time() - 2 * HOUR_IN_SECONDS ) {
					self::ligne( 'alerte', 'Prochaine vérification', 'En retard depuis ' . esc_html( human_time_diff( $next, time() ) ) . ' : WP-Cron ne passe plus. Vérifiez la tâche cron de l\'hébergeur (wp-cron.php) ou qu\'aucune extension ne bloque WP-Cron.' );
				} else {
					self::ligne( 'ok', 'Prochaine vérification', esc_html( wp_date( 'd/m/Y à H:i', $next ) ) . ' <span class="description">(mise à jour le week-end de 14 h à 23 h chaque heure, sinon à 7 h et 19 h)</span>' );
				}
				if ( $run ) {
					self::ligne( 'info', 'Mise à jour en cours', esc_html( sprintf( '%d / %d équipes traitées', $run['total'] - count( (array) $run['queue'] ), $run['total'] ) ) );
				}
				$fails = get_option( VHB_Cron::FAILS, array() );
				$fails = array_filter( is_array( $fails ) ? $fails : array() );
				$views = VHB_Store::views( true );
				$err   = array();
				foreach ( $views as $v ) {
					foreach ( (array) $v['competitions'] as $c ) {
						if ( '' !== $c['erreur'] ) {
							$n     = (int) ( $fails[ $v['slug'] . '|' . $c['cle'] ] ?? 0 );
							$err[] = '<strong>' . esc_html( $v['equipe'] . ( count( $v['competitions'] ) > 1 ? ' (' . $c['nom'] . ')' : '' ) ) . '</strong> : ' . esc_html( $c['erreur'] ) . ( $n > 1 ? ' <em>(' . $n . ' échecs de suite)</em>' : '' );
						}
					}
				}
				self::ligne( $err ? ( count( $err ) > 1 ? 'erreur' : 'alerte' ) : 'ok', 'Erreurs de récupération', $err ? implode( '<br>', $err ) : 'Aucune.', $err ? $res : '', 'Journal' );
				if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
					self::ligne( 'info', 'WP-Cron', 'Déclenché par les visites. Une tâche cron chez l\'hébergeur rend les mises à jour plus ponctuelles (voir Résultats FFHB).' );
				} else {
					self::ligne( 'ok', 'WP-Cron', 'Déclenché par une tâche planifiée de l\'hébergeur.' );
				}
			}
			?>
			</tbody></table>

			<?php if ( class_exists( 'VHB_Store' ) ) : ?>
			<h2>Équipes</h2>
			<?php
			$photos = VHB_Club_Photos::get();
			$cats   = array();
			foreach ( VHB_Club_Donnees::categories() as $c ) {
				foreach ( (array) $c['equipes'] as $slug ) {
					$cats[ $slug ][] = html_entity_decode( $c['nom'], ENT_QUOTES, 'UTF-8' );
				}
			}
			?>
			<table class="widefat striped vhb-etat-equipes">
				<thead><tr><th>Équipe</th><th>Publiée</th><th>Compétitions</th><th>Dernière donnée</th><th>Catégorie du club</th><th>Photo</th></tr></thead>
				<tbody>
				<?php
				$vues = VHB_Store::views( true );
				if ( ! $vues ) {
					echo '<tr><td colspan="6"><em>Aucune équipe.</em></td></tr>';
				}
				foreach ( $vues as $v ) :
					$maj = $v['maj'] ? VHB_Render::ts( $v['maj'] ) : 0;
					if ( $v['masquee'] ) {
						$pub = 'masquée';
					} elseif ( ! $v['publie'] ) {
						$pub = 'non (aucune compétition)';
					} else {
						$pub = 'oui';
					}
					?>
					<tr>
						<td><strong><?php echo esc_html( $v['equipe'] ); ?></strong> <code><?php echo esc_html( $v['slug'] ); ?></code></td>
						<td><?php echo esc_html( $pub ); ?></td>
						<td><?php echo (int) count( $v['competitions'] ); ?></td>
						<td><?php echo $maj ? esc_html( self::age( $maj ) ) : ( $v['competitions'] ? '<span class="vhb-etat-ko">jamais</span>' : '' ); ?></td>
						<td><?php echo ! empty( $cats[ $v['slug'] ] ) ? esc_html( implode( ', ', $cats[ $v['slug'] ] ) ) : '<span class="vhb-etat-moyen">aucune</span>'; ?></td>
						<td><?php echo isset( $photos[ $v['slug'] ] ) ? 'oui' : '<span class="vhb-etat-moyen">non</span>'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . VHB_Club_Photos::PAGE ) ); ?>">Photos d'équipe</a><?php echo $res ? ' · <a href="' . esc_url( $res ) . '">Équipes et compétitions</a>' : ''; ?></p>
			<?php endif; ?>

			<h2>Données du club</h2>
			<table class="widefat vhb-etat-table"><tbody>
			<?php
			$saison = VHB_Club_Reglages::saison_en_cours();
			self::ligne( 'info', 'Saison affichée', esc_html( $saison ), current_user_can( VHB_Club_Reglages::CAP_REGLAGES ) ? admin_url( 'admin.php?page=' . VHB_Club_Admin_Slugs::SAISONS ) : '', 'Saisons' );
			$ctl = VHB_Club_Admin::controles();
			self::ligne( $ctl ? 'alerte' : 'ok', 'Points à compléter', $ctl ? esc_html( count( $ctl ) . ' point(s), le premier : ' . $ctl[0][0] ) : 'Aucun.', $ctl ? admin_url( 'admin.php?page=' . VHB_Club_Admin_Slugs::MENU ) : '', 'Tableau de bord' );
			$sv = get_option( VHB_Club_Export::SAUVEGARDE );
			if ( is_array( $sv ) && ! empty( $sv['date'] ) ) {
				self::ligne( 'info', 'Sauvegarde d\'avant import', 'Du ' . esc_html( wp_date( 'd/m/Y à H:i', strtotime( $sv['date'] ) ) ) . ', restaurable.', current_user_can( VHB_Club_Reglages::CAP_REGLAGES ) ? admin_url( 'admin.php?page=vhb-club-export' ) : '', 'Export et import' );
			}
			?>
			</tbody></table>

			<h2>Site</h2>
			<table class="widefat vhb-etat-table"><tbody>
			<?php
			global $wp_version;
			self::ligne( 'info', 'Versions', esc_html( VHB_MARQUE . ' ' . VHB_CLUB_VERSION . ' · WordPress ' . $wp_version . ' · PHP ' . PHP_VERSION ) );
			if ( VHB_Fusion::ancienne_presente() ) {
				self::ligne( 'alerte', 'Ancienne extension', '« VHB Résultats FFHB » est encore installée' . ( VHB_Fusion::ancienne_active() ? ' et active' : '' ) . '. Elle est incluse dans VHB : vous pouvez la supprimer, ses données sont conservées.', current_user_can( 'delete_plugins' ) ? admin_url( 'plugins.php' ) : '', 'Extensions' );
			}
			$w3 = function_exists( 'w3tc_flush_all' );
			self::ligne( 'info', 'Cache des pages', $w3 ? 'W3 Total Cache détecté : vidé automatiquement à chaque modification des données et après chaque mise à jour FFHB qui change un score ou un classement.' : 'Aucune extension de cache détectée.' );
			?>
			</tbody></table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="vhb_etat_cache">
				<?php wp_nonce_field( 'vhb_etat_cache' ); ?>
				<p><button type="submit" class="button">Vider le cache des pages</button> <span class="description">En cas de doute sur une page qui n'affiche pas la dernière version.</span></p>
			</form>
		</div>
		<style>
			.vhb-etat h2{margin-top:28px}
			.vhb-etat-table{max-width:1000px}
			.vhb-etat-table th{width:230px;font-weight:600}
			.vhb-etat-table td,.vhb-etat-table th{vertical-align:top;padding:10px}
			.vhb-etat-icone{width:24px}
			.vhb-etat--ok .dashicons{color:#00a32a}
			.vhb-etat--alerte .dashicons{color:#dba617}
			.vhb-etat--erreur .dashicons{color:#d63638}
			.vhb-etat--info .dashicons{color:#72aee6}
			.vhb-etat-equipes{max-width:1000px}
			.vhb-etat-ko{color:#d63638}
			.vhb-etat-moyen{color:#996800}
		</style>
		<?php
	}
}
