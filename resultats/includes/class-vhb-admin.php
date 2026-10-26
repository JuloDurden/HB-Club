<?php
/**
 * Page d'administration : réglages, état, rafraîchissement manuel, journal.
 * Capacité requise : manage_options. Nonce sur toutes les actions.
 */

defined( 'ABSPATH' ) || exit;

class VHB_Admin {

	const SLUG          = 'vhb-ffhb';
	const SLUG_DOMICILE = 'vhb-ffhb-domicile';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_vhb_ffhb_refresh', array( __CLASS__, 'ajax_refresh' ) );
		add_action( 'admin_post_vhb_ffhb_clear_logos', array( __CLASS__, 'clear_logos' ) );
		add_action( 'admin_post_vhb_ffhb_clear_fdm', array( __CLASS__, 'clear_fdm' ) );
		add_action( 'update_option_' . VHB_Settings::OPTION, array( __CLASS__, 'after_save' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( VHB_FFHB_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/** Menu commun « VHB » de VHB Club, s'il est actif (1.3.0 ou plus). */
	public static function menu_commun() {
		return defined( 'VHB_CLUB_VERSION' ) && version_compare( VHB_CLUB_VERSION, '1.3.0', '>=' ) ? 'vhb-club' : '';
	}

	/**
	 * Capacité de la page « Matchs à domicile » : avec VHB Club, le rôle
	 * « Gestionnaire club » y a accès (organisation buvette, bénévoles).
	 */
	public static function cap_domicile() {
		return self::menu_commun() ? 'vhb_club_gerer' : 'manage_options';
	}

	public static function menu() {
		$parent = self::menu_commun();
		if ( $parent ) {
			add_submenu_page( $parent, 'Résultats FFHB', 'Résultats FFHB', 'manage_options', self::SLUG, array( __CLASS__, 'page' ) );
			add_submenu_page( $parent, 'Matchs à domicile', 'Matchs à domicile', self::cap_domicile(), self::SLUG_DOMICILE, array( 'VHB_Domicile', 'page' ) );
			return;
		}
		add_menu_page( 'VHB Résultats FFHB', 'VHB Résultats', 'manage_options', self::SLUG, array( __CLASS__, 'page' ), 'dashicons-awards', 58 );
		add_submenu_page( self::SLUG, 'VHB Résultats FFHB', 'Réglages et état', 'manage_options', self::SLUG, array( __CLASS__, 'page' ) );
		add_submenu_page( self::SLUG, 'Matchs à domicile', 'Matchs à domicile', 'manage_options', self::SLUG_DOMICILE, array( 'VHB_Domicile', 'page' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">Résultats FFHB</a>' );
		return $links;
	}

	public static function settings() {
		register_setting(
			'vhb_ffhb',
			VHB_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'VHB_Settings', 'sanitize' ),
				'default'           => VHB_Settings::defaults(),
			)
		);
	}

	/** Si les équipes, la saison ou le club changent : mise à jour dans la minute. */
	public static function after_save( $old, $new ) {
		$keys = array( 'saison', 'club_id', 'equipes' );
		foreach ( $keys as $k ) {
			if ( ( $old[ $k ] ?? null ) !== ( $new[ $k ] ?? null ) ) {
				if ( ! wp_next_scheduled( VHB_Cron::HOOK_RUN_NOW ) ) {
					wp_schedule_single_event( time() + 15, VHB_Cron::HOOK_RUN_NOW, array( 'réglages' ) );
				}
				return;
			}
		}
	}

	public static function assets( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore
		if ( ! in_array( $page, array( self::SLUG, self::SLUG_DOMICILE ), true ) ) {
			return;
		}
		wp_enqueue_style( 'vhb-ffhb-admin', VHB_FFHB_URL . 'assets/admin.css', array(), VHB_FFHB_VERSION );
		wp_enqueue_media();
		wp_enqueue_script( 'vhb-ffhb-admin', VHB_FFHB_URL . 'assets/admin.js', array(), VHB_FFHB_VERSION, true );
		wp_localize_script(
			'vhb-ffhb-admin',
			'vhbFfhbAdmin',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'vhb_ffhb_refresh' ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Actions                                                             */
	/* ------------------------------------------------------------------ */

	public static function ajax_refresh() {
		check_ajax_referer( 'vhb_ffhb_refresh', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Accès refusé.' ), 403 );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 90 ); // phpcs:ignore
		}
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : 'step';
		$r  = 'start' === $op ? VHB_Cron::start( 'manuelle' ) : VHB_Cron::step();
		wp_send_json_success( $r );
	}

	public static function clear_fdm() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Accès refusé.', 403 );
		}
		check_admin_referer( 'vhb_ffhb_clear_fdm' );
		VHB_Fdm::clear();
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'vhb_msg' => 'fdm' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function clear_logos() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Accès refusé.', 403 );
		}
		check_admin_referer( 'vhb_ffhb_clear_logos' );
		VHB_Logos::clear();
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'vhb_msg' => 'logos' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Page                                                                */
	/* ------------------------------------------------------------------ */

	private static function fmt( $iso ) {
		$ts = VHB_Render::ts( $iso );
		return $ts ? wp_date( 'd/m/Y H:i', $ts ) : '';
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s    = VHB_Settings::get();
		$run  = VHB_Cron::state();
		$next = wp_next_scheduled( VHB_Cron::HOOK_TICK );
		$ok   = VHB_Cron::last_success();
		?>
		<div class="wrap vhb-admin">
			<h1>Résultats FFHB</h1>
			<?php settings_errors( VHB_Settings::OPTION ); ?>
			<?php if ( isset( $_GET['vhb_msg'] ) && 'logos' === $_GET['vhb_msg'] ) : // phpcs:ignore ?>
				<div class="notice notice-success is-dismissible"><p>Cache des blasons vidé. Ils seront retéléchargés au prochain rafraîchissement.</p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['vhb_msg'] ) && 'fdm' === $_GET['vhb_msg'] ) : // phpcs:ignore ?>
				<div class="notice notice-success is-dismissible"><p>Feuilles de match supprimées. Celles des équipes en mode « copie » seront retéléchargées au prochain rafraîchissement.</p></div>
			<?php endif; ?>

			<div class="vhb-admin-grid">
				<div class="vhb-admin-card">
					<h2>État</h2>
					<table class="vhb-kv">
						<tr><th>Dernière mise à jour réussie</th><td><?php echo $ok ? esc_html( self::fmt( $ok ) ) : '<em>jamais</em>'; ?></td></tr>
						<tr><th>Prochain passage du cron</th><td><?php echo $next ? esc_html( wp_date( 'd/m/Y H:i', $next ) ) : '<em>non planifié</em>'; ?> <span class="description">(exécute la mise à jour le week-end de 14 h à 23 h chaque heure, sinon à 7 h et 19 h)</span></td></tr>
						<tr><th>Exécution en cours</th><td><?php echo $run ? esc_html( sprintf( 'oui, %d / %d équipes', $run['total'] - count( $run['queue'] ), $run['total'] ) ) : 'non'; ?></td></tr>
						<tr><th>Blasons en cache</th><td><?php echo (int) VHB_Logos::count(); ?></td></tr>
						<?php $charge = VHB_Domicile::prochain_jour_charge(); ?>
						<tr><th>Prochain jour à plusieurs matchs à domicile</th><td><?php echo $charge ? '<strong>' . esc_html( VHB_Domicile::jour_long( $charge[0] ) ) . '</strong> (' . (int) $charge[1] . ' matchs) ' : '<em>aucun pour l\'instant</em> '; ?><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_DOMICILE ) ); ?>">Voir les matchs à domicile</a></td></tr>
						<tr><th>Feuilles de match hébergées</th><td><?php echo (int) VHB_Fdm::count(); ?> (<?php echo esc_html( size_format( VHB_Fdm::size(), 1 ) ?: '0 o' ); ?>)</td></tr>
					</table>
					<p>
						<button type="button" class="button button-primary" id="vhb-refresh">Rafraîchir maintenant</button>
						<span id="vhb-refresh-status" class="vhb-status" aria-live="polite"></span>
					</p>
					<div class="vhb-progress" hidden><div class="vhb-progress-bar"></div></div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vhb-inline">
						<input type="hidden" name="action" value="vhb_ffhb_clear_logos">
						<?php wp_nonce_field( 'vhb_ffhb_clear_logos' ); ?>
						<button type="submit" class="button">Vider le cache des blasons</button>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vhb-inline" onsubmit="return confirm('Supprimer toutes les feuilles de match hébergées ?');">
						<input type="hidden" name="action" value="vhb_ffhb_clear_fdm">
						<?php wp_nonce_field( 'vhb_ffhb_clear_fdm' ); ?>
						<button type="submit" class="button">Supprimer les feuilles de match</button>
					</form>
					<?php if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) : ?>
						<div class="vhb-tip">
							<strong>Conseil :</strong> WP-Cron ne se déclenche qu'à la visite d'une page. Pour une mise à jour à l'heure dite, ajoutez chez l'hébergeur une tâche cron toutes les 30 minutes :
							<code>*/30 * * * * curl -s <?php echo esc_html( site_url( 'wp-cron.php?doing_wp_cron' ) ); ?> &gt;/dev/null</code>
							puis <code>define( 'DISABLE_WP_CRON', true );</code> dans <code>wp-config.php</code>.
						</div>
					<?php endif; ?>
				</div>

				<div class="vhb-admin-card">
					<h2>Insérer dans une page</h2>
					<p>Blocs disponibles dans l'éditeur (catégorie Widgets) : <strong>VHB : les matchs du club</strong>, <strong>VHB : équipe</strong>, <strong>VHB : classement</strong>, <strong>VHB : prochain match</strong>, <strong>VHB : ajouter à mon agenda</strong>.</p>
					<p>Ou shortcodes, à coller dans un bloc Code court :</p>
					<ul class="vhb-codes">
						<li><code>[vhb_matchs]</code> résultats et matchs à venir de toutes les équipes</li>
						<li><code>[vhb_equipe slug="seniors-filles"]</code> panneau complet d'une équipe (option <code>resultats="5"</code>)</li>
						<li><code>[vhb_classement slug="u18-filles"]</code> classement seul</li>
						<li><code>[vhb_prochain slug="u11-garcons"]</code> prochain match seul</li>
						<li><code>[vhb_agenda slug="club"]</code> abonnement agenda, tout le club ou une équipe</li>
					</ul>
					<p class="description">Données brutes : <a href="<?php echo esc_url( rest_url( 'vhb/v1/equipes' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( rest_url( 'vhb/v1/equipes' ) ); ?></a></p>
				</div>
			</div>

			<h2>Équipes</h2>
			<table class="widefat vhb-teams">
				<thead><tr><th>Équipe</th><th>Slug</th><th>Compétition</th><th>Libellé FFHB</th><th>Matchs</th><th>Joués</th><th>Classement</th><th>Mise à jour</th><th>Dernière erreur</th></tr></thead>
				<?php
				foreach ( VHB_Settings::equipes() as $e ) :
					$v     = VHB_Store::view( $e['slug'] );
					$comps = $v['competitions'] ? $v['competitions'] : array( null );
					$span  = count( $comps );
					echo '<tbody class="vhb-team-group">';
					foreach ( $comps as $k => $c ) :
						if ( ! $c ) {
							$nbm = 0;
							$nbj = 0;
							$cl  = '<em>compétition non publiée</em>';
						} else {
							$rs  = array_filter( $v['rencontres'], static function ( $r ) use ( $c ) { return $r['comp'] === $c['cle']; } );
							$nbm = count( $rs );
							$nbj = count( array_filter( $rs, static function ( $r ) { return $r['joue']; } ) );
							if ( ! $c['recupere'] ) {
								$cl = '<em>pas encore récupéré</em>';
							} elseif ( 'coupe' === $c['type'] ) {
								$cl = 'sans objet (coupe)';
							} elseif ( ! $c['classement_actif'] ) {
								$cl = 'aucun, par règle fédérale';
							} else {
								$cl = count( $c['classement'] ) . ' équipes' . ( $e['classement'] ? '' : ' <em>(masqué, lien FFHB)</em>' );
							}
						}
						?>
					<tr>
						<?php if ( 0 === $k ) : ?>
						<td rowspan="<?php echo (int) $span; ?>"><strong><?php echo esc_html( $e['nom'] ); ?></strong><?php echo $e['entente'] ? ' <span class="vhb-pill">entente</span>' : ''; ?><?php echo $e['masquee'] ? ' <span class="vhb-pill vhb-pill--off">masquée</span>' : ''; ?></td>
						<td rowspan="<?php echo (int) $span; ?>"><code><?php echo esc_html( $e['slug'] ); ?></code></td>
						<?php endif; ?>
						<td><?php echo $c ? esc_html( $c['nom'] ) . ( $c['principale'] && $v['multi'] ? ' <span class="vhb-pill vhb-pill--off">principale</span>' : '' ) : ''; ?></td>
						<td><?php echo $c ? esc_html( $c['libelle'] ) : ''; ?></td>
						<td><?php echo (int) $nbm; ?></td>
						<td><?php echo (int) $nbj; ?></td>
						<td><?php echo wp_kses_post( $cl ); ?></td>
						<td><?php echo $c ? esc_html( self::fmt( $c['maj'] ) ) : ''; ?></td>
						<td class="vhb-err"><?php echo $c && $c['erreur'] ? esc_html( self::fmt( $c['erreur_date'] ) . ' : ' . $c['erreur'] ) : ''; ?></td>
					</tr>
						<?php
					endforeach;
					echo '</tbody>';
				endforeach;
				?>
			</table>

			<h2>Réglages</h2>
			<form method="post" action="options.php" class="vhb-settings">
				<?php settings_fields( 'vhb_ffhb' ); ?>
				<?php $n = VHB_Settings::OPTION; ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="vhb-saison">Saison en cours</label></th>
						<td><input id="vhb-saison" class="regular-text code" name="<?php echo esc_attr( $n ); ?>[saison]" value="<?php echo esc_attr( $s['saison'] ); ?>">
						<p class="description">Segment d'URL FFHB, par exemple <code>saison-2026-2027-22</code>. Il remplace le jeton <code>{saison}</code> dans les URL ci-dessous. À chaque nouvelle saison : changez-le, puis mettez à jour les URL des équipes (les identifiants de compétition changent aussi).</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="vhb-club">Identifiant club FFHB</label></th>
						<td><input id="vhb-club" class="code" style="width:90px" name="<?php echo esc_attr( $n ); ?>[club_id]" value="<?php echo esc_attr( $s['club_id'] ); ?>"> <span class="description">Numéro du club sur le site de la FFHB (sert au flux iCal des lieux).</span></td>
					</tr>
					<tr>
						<th scope="row"><label for="vhb-mail">E-mail d'alerte</label></th>
						<td><input id="vhb-mail" type="email" class="regular-text" name="<?php echo esc_attr( $n ); ?>[alert_email]" value="<?php echo esc_attr( $s['alert_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
						<p class="description">Prévenu après <?php echo (int) VHB_Cron::FAILS_ALERT; ?> échecs consécutifs sur une même équipe. Vide : e-mail d'administration du site.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="vhb-theme">Apparence</label></th>
						<td><select id="vhb-theme" name="<?php echo esc_attr( $n ); ?>[theme]">
							<?php foreach ( array( 'pages' => 'Comme les pages du site (clair, ou sombre si le visiteur a choisi le mode sombre)', 'sombre' => 'Sombre (graphite)', 'clair' => 'Clair', 'auto' => 'Automatique (suit le réglage de l\'appareil du visiteur)' ) as $k => $label ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['theme'], $k ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select></td>
					</tr>
					<tr>
						<th scope="row">Ajouter à mon agenda</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[agenda]" value="1" <?php checked( ! empty( $s['agenda'] ) ); ?>> Afficher le bouton d'abonnement au calendrier des matchs</label>
						<p class="description">Flux iCal servi par le site (salles corrigées, scores ajoutés après les matchs). Flux du club entier : <code><?php echo esc_html( VHB_Agenda::feed_url( 'club' ) ); ?></code></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="vhb-nbres">Résultats affichés</label></th>
						<td><input id="vhb-nbres" type="number" min="0" max="50" class="small-text" name="<?php echo esc_attr( $n ); ?>[nb_resultats]" value="<?php echo (int) $s['nb_resultats']; ?>">
						<span class="description">derniers résultats visibles dans le panneau d'une équipe, les précédents sont repliés. 0 : tous. Modifiable bloc par bloc.</span></td>
					</tr>
					<tr>
						<th scope="row">Données structurées Google</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[jsonld]" value="1" <?php checked( ! empty( $s['jsonld'] ) ); ?>> Décrire les matchs affichés pour les moteurs de recherche (schema.org SportsEvent)</label>
						<p class="description">Aide Google à présenter les matchs (date, lieu, équipes). Invisible pour les visiteurs.</p></td>
					</tr>
				</table>

				<h3>Équipes du club</h3>
				<p class="description">Collez l'URL de la page « équipe » de la compétition sur ffhandball.fr (elle se termine par <code>/equipe-&lt;numéro&gt;/</code>). Une équipe peut être engagée dans plusieurs compétitions (championnat, coupe de France…) : « + Ajouter une compétition », une URL par compétition. La première est la compétition principale (classement, en-tête) ; les matchs des autres portent un bandeau à leur nom. Laissez l'URL vide pour une équipe déclarée dont la compétition n'est pas encore publiée. Le slug sert dans les shortcodes ; évitez de le changer une fois utilisé dans une page.<br><strong>Classement</strong> décoché : le tableau est remplacé par un lien vers le classement sur ffhandball.fr. <strong>Pastille V/D</strong> : V, N ou D à côté de chaque résultat. <strong>Masquée</strong> : l'équipe disparaît du site (blocs, matchs du club, agenda) sans perdre ses réglages ; ses données continuent d'être mises à jour. <strong>Feuilles de match</strong> : copie hébergée par le site, lien vers la FFHB, ou masquées. <strong>Blason</strong> : image de la médiathèque qui remplace le blason FFHB de notre équipe (utile pour les ententes).</p>
				<table class="widefat vhb-team-edit">
					<thead><tr><th>Nom affiché</th><th>Slug</th><th>Compétitions : nom court (facultatif) et URL de la page équipe FFHB</th><th>Entente</th><th>Affichage</th><th title="Remplace le blason FFHB de notre équipe dans les résultats et le classement.">Blason</th><th></th></tr></thead>
					<tbody id="vhb-team-rows">
					<?php
					$rows = (array) $s['equipes'];
					foreach ( $rows as $i => $e ) {
						self::team_row( $n, $i, $e, $s['saison'] );
					}
					?>
					</tbody>
				</table>
				<p><button type="button" class="button" id="vhb-add-team">Ajouter une équipe</button></p>
				<script type="text/html" id="tmpl-vhb-team-row"><?php self::team_row( $n, '__i__', array(), $s['saison'] ); ?></script>

				<h3>Tables de réécriture</h3>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="vhb-salles">Salles</label></th>
						<td><textarea id="vhb-salles" class="large-text code" rows="4" name="<?php echo esc_attr( $n ); ?>[salles]"><?php echo esc_textarea( $s['salles'] ); ?></textarea>
						<p class="description">Une salle par ligne : <code>NOM FFHB = Nom affiché | Adresse | latitude, longitude</code>. L'adresse et les coordonnées sont facultatives ; elles corrigent celles de la FFHB (carte, Google Maps, Waze). Une adresse corrigée sans coordonnées : la navigation se fait par l'adresse et la carte n'est pas affichée. Le nom fédéral reste indiqué sous l'adresse.</p>
						<?php if ( has_filter( 'vhb_ffhb_salles' ) ) : ?>
							<p class="description"><strong>Gymnases du club :</strong> les gymnases saisis dans VHB &gt; Lieux avec un « Nom FFHB » sont pris en compte automatiquement et priment sur cette table. Inutile de les répéter ici.</p>
						<?php endif; ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="vhb-libelles">Noms d'équipe</label></th>
						<td><textarea id="vhb-libelles" class="large-text code" rows="4" name="<?php echo esc_attr( $n ); ?>[libelles]"><?php echo esc_textarea( $s['libelles'] ); ?></textarea>
						<p class="description">Facultatif, même format : <code>HB CLUB EXEMPLE 2 = Handball Club Exemple</code>. Les libellés FFHB sont affichés tels quels sinon.</p></td>
					</tr>
				</table>
				<?php submit_button( 'Enregistrer les réglages' ); ?>
			</form>

			<h2>Journal des <?php echo (int) VHB_Cron::LOG_MAX; ?> dernières exécutions</h2>
			<table class="widefat striped vhb-log">
				<thead><tr><th>Date</th><th>Origine</th><th>Durée</th><th>Réussies</th><th>En échec</th><th>Remarques</th></tr></thead>
				<tbody>
				<?php
				$log = VHB_Cron::log();
				if ( ! $log ) {
					echo '<tr><td colspan="6"><em>Aucune exécution pour le moment.</em></td></tr>';
				}
				foreach ( $log as $l ) :
					$notes = (array) $l['warnings'];
					if ( ! empty( $l['logos'] ) ) {
						$notes[] = (int) $l['logos'] . ' blason(s) téléchargé(s)';
					}
					if ( ! empty( $l['modifie'] ) ) {
						$notes[] = 'Données modifiées : cache des pages vidé';
					}
					if ( ! empty( $l['fdm'] ) ) {
						$notes[] = (int) $l['fdm'] . ' feuille(s) de match téléchargée(s)';
					}
					if ( ! empty( $l['interrompu'] ) ) {
						$notes[] = 'Exécution interrompue (' . (int) $l['restantes'] . ' équipe(s) non traitée(s))';
					}
					?>
					<tr class="<?php echo $l['ko'] ? 'vhb-has-ko' : ''; ?>">
						<td><?php echo esc_html( self::fmt( $l['date'] ) ); ?></td>
						<td><?php echo esc_html( $l['source'] ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (float) $l['duree'], 1 ) ); ?> s</td>
						<td><?php echo esc_html( count( $l['ok'] ) . ( $l['ok'] ? ' : ' . implode( ', ', $l['ok'] ) : '' ) ); ?></td>
						<td class="vhb-err">
							<?php
							foreach ( (array) $l['ko'] as $nom => $msg ) {
								echo '<div><strong>' . esc_html( $nom ) . '</strong> : ' . esc_html( $msg ) . '</div>';
							}
							?>
						</td>
						<td><?php echo $notes ? '<div>' . implode( '</div><div>', array_map( 'esc_html', $notes ) ) . '</div>' : ''; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/** Sélecteur d'image de la médiathèque pour le blason local d'une équipe. */
	private static function blason_field( $base, $id ) {
		$url = $id ? wp_get_attachment_image_url( $id, 'thumbnail' ) : '';
		return '<input type="hidden" class="vhb-blason-id" name="' . $base . '[blason]" value="' . ( $id ? (int) $id : '' ) . '">' // phpcs:ignore
			. '<span class="vhb-blason-preview">' . ( $url ? '<img src="' . esc_url( $url ) . '" alt="">' : '<em>FFHB</em>' ) . '</span>'
			. '<button type="button" class="button-link vhb-blason-pick">Choisir</button>'
			. '<button type="button" class="button-link vhb-blason-clear" title="Revenir au blason FFHB"' . ( $id ? '' : ' hidden' ) . '>Par défaut</button>';
	}

	private static function fdm_options( $cur ) {
		$h = '';
		foreach ( VHB_Settings::FDM_MODES as $k => $label ) {
			$h .= '<option value="' . esc_attr( $k ) . '"' . selected( $cur, $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		return $h;
	}

	/** Une ligne « compétition » : nom court facultatif + URL de la page équipe FFHB. */
	public static function comp_line( $base, $k, array $c, $saison = '' ) {
		$url  = (string) ( $c['url'] ?? '' );
		$warn = '';
		if ( '' !== $url && $saison ) {
			$us = VHB_Settings::saison_of_url( $url );
			if ( $us && $us !== $saison ) {
				$warn = '<div class="vhb-warn">Cette URL désigne ' . esc_html( $us ) . ', pas la saison réglée (' . esc_html( $saison ) . ').</div>';
			}
		}
		$b = $base . '[competitions][' . esc_attr( $k ) . ']';
		return '<div class="vhb-comp-line">'
			. '<input class="vhb-comp-nom" name="' . $b . '[nom]" value="' . esc_attr( (string) ( $c['nom'] ?? '' ) ) . '" placeholder="' . ( 0 === $k ? 'Championnat' : 'Coupe de France' ) . '" title="Nom court affiché sur le site (facultatif)">' // phpcs:ignore
			. '<input class="code vhb-comp-url" name="' . $b . '[url]" value="' . esc_attr( $url ) . '" placeholder="https://www.ffhandball.fr/competitions/{saison}/.../equipe-123456/">' // phpcs:ignore
			. '<button type="button" class="button-link vhb-comp-del" aria-label="Retirer cette compétition">×</button>'
			. $warn . '</div>';
	}

	private static function team_row( $n, $i, array $e, $saison ) {
		$new  = empty( $e );
		$e    = array_merge( VHB_Settings::EQUIPE_DEFAUT, $e );
		$base  = esc_attr( $n ) . '[equipes][' . esc_attr( $i ) . ']';
		$comps = isset( $e['competitions'] ) && is_array( $e['competitions'] ) ? $e['competitions'] : ( ! empty( $e['url'] ) ? array( array( 'url' => $e['url'], 'nom' => '' ) ) : array() );
		if ( ! $comps ) {
			$comps = array( array( 'url' => '', 'nom' => '' ) );
		}
		$lines = '';
		foreach ( array_values( $comps ) as $k => $c ) {
			$lines .= self::comp_line( $base, $k, $c, $saison );
		}
		echo '<tr>'
			. '<td><input class="vhb-nom" name="' . $base . '[nom]" value="' . esc_attr( $e['nom'] ) . '" placeholder="U13 Filles"></td>' // phpcs:ignore
			. '<td><input class="code vhb-slug" name="' . $base . '[slug]" value="' . esc_attr( $e['slug'] ) . '" placeholder="automatique"></td>' // phpcs:ignore
			. '<td class="vhb-comps" data-base="' . $base . '[competitions]">' . $lines . '<button type="button" class="button-link vhb-comp-add">+ Ajouter une compétition</button></td>' // phpcs:ignore
			. '<td><input type="checkbox" name="' . $base . '[entente]" value="1" ' . checked( ! empty( $e['entente'] ), true, false ) . '></td>' // phpcs:ignore
			. '<td class="vhb-aff">' // phpcs:ignore
			. '<label><input type="checkbox" name="' . $base . '[classement]" value="1" ' . checked( $new || ! empty( $e['classement'] ), true, false ) . '> Classement</label>' // phpcs:ignore
			. '<label><input type="checkbox" name="' . $base . '[vd]" value="1" ' . checked( ! empty( $e['vd'] ), true, false ) . '> Pastille V/D</label>' // phpcs:ignore
			. '<label class="vhb-masq"><input type="checkbox" name="' . $base . '[masquee]" value="1" ' . checked( ! empty( $e['masquee'] ), true, false ) . '> Masquée</label>' // phpcs:ignore
			. '<label class="vhb-aff-fdm">Feuilles de match : <select name="' . $base . '[fdm]">' . self::fdm_options( $e['fdm'] ) . '</select></label>' // phpcs:ignore
			. '</td>'
			. '<td class="vhb-blason">' . self::blason_field( $base, (int) $e['blason'] ) . '</td>'
			. '<td><button type="button" class="button-link vhb-del" aria-label="Retirer cette équipe">Supprimer</button></td>'
			. '</tr>';
	}
}
