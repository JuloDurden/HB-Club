<?php
/**
 * Administration : menu « VHB Club », tableau de bord, réglages, listes.
 * Nonce et capacité vérifiés sur chaque action.
 */

defined( 'ABSPATH' ) || exit;

class VHB_Club_Admin {

	const MENU     = VHB_Club_Admin_Slugs::MENU;
	const REGLAGES = VHB_Club_Admin_Slugs::REGLAGES;
	const SAISONS  = VHB_Club_Admin_Slugs::SAISONS;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 9 );
		add_action( 'admin_menu', array( __CLASS__, 'submenus' ), 11 );
		add_action( 'admin_init', array( __CLASS__, 'settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( VHB_CLUB_FILE ), array( __CLASS__, 'action_links' ) );
		add_filter( 'parent_file', array( __CLASS__, 'parent_file' ) );

		// Listes d'administration.
		$c = VHB_Club_Types::CATEGORIE;
		add_filter( "manage_{$c}_posts_columns", array( __CLASS__, 'cat_columns' ) );
		add_action( "manage_{$c}_posts_custom_column", array( __CLASS__, 'cat_column' ), 10, 2 );
		$l = VHB_Club_Types::LIEU;
		add_filter( "manage_{$l}_posts_columns", array( __CLASS__, 'lieu_columns' ) );
		add_action( "manage_{$l}_posts_custom_column", array( __CLASS__, 'lieu_column' ), 10, 2 );
		$e = VHB_Club_Types::ENTENTE;
		add_filter( "manage_{$e}_posts_columns", array( __CLASS__, 'entente_columns' ) );
		add_action( "manage_{$e}_posts_custom_column", array( __CLASS__, 'entente_column' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'filtre_saison' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'query_admin' ) );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_placeholder' ), 10, 2 );
	}

	/* ------------------------------------------------------------------ */
	/* Menu                                                                */
	/* ------------------------------------------------------------------ */

	public static function menu() {
		// Un seul menu, au nom de l'édition (club et résultats réunis depuis 2.0.0).
		add_menu_page( VHB_MARQUE, VHB_MARQUE, VHB_Club_Reglages::CAP_DONNEES, self::MENU, array( __CLASS__, 'dashboard' ), self::icone_menu(), '58.5' );
		add_submenu_page( self::MENU, VHB_MARQUE, 'Tableau de bord', VHB_Club_Reglages::CAP_DONNEES, self::MENU, array( __CLASS__, 'dashboard' ) );
	}

	/**
	 * Icône du menu : logo du club en silhouette blanche (profil/logo.svg) s'il est fourni, sinon un ballon (assets/icone.svg). En SVG base64,
	 * WordPress le colore comme les autres icônes du menu (blanc au survol).
	 */
	public static function icone_menu() {
		$svg = @file_get_contents( VHB_Club_Reglages::fichier_icone() ); // phpcs:ignore
		return $svg ? 'data:image/svg+xml;base64,' . base64_encode( $svg ) : 'dashicons-id-alt'; // phpcs:ignore
	}

	public static function submenus() {
		add_submenu_page( self::MENU, 'Saisons', 'Saisons', VHB_Club_Reglages::CAP_REGLAGES, self::SAISONS, array( 'VHB_Club_Saisons', 'page' ) );
		add_submenu_page( self::MENU, 'Export et import', 'Export et import', VHB_Club_Reglages::CAP_REGLAGES, 'vhb-club-export', array( 'VHB_Club_Export', 'page' ) );
		add_submenu_page( self::MENU, 'Réglages du club', 'Réglages du club', VHB_Club_Reglages::CAP_REGLAGES, self::REGLAGES, array( __CLASS__, 'page_reglages' ) );
	}

	/** Écrans d'édition des types : le menu VHB Club reste ouvert. */
	public static function parent_file( $parent ) {
		$screen = get_current_screen();
		if ( $screen && current_user_can( VHB_Club_Reglages::CAP_DONNEES ) && in_array( $screen->post_type, array( VHB_Club_Types::CATEGORIE, VHB_Club_Types::LIEU, VHB_Club_Types::ENTENTE, VHB_Club_Types::STAGE ), true ) ) {
			return self::MENU;
		}
		return $parent;
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::MENU ) ) . '">Tableau de bord</a>' );
		return $links;
	}

	public static function is_ours() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}
		if ( in_array( $screen->post_type, array( VHB_Club_Types::CATEGORIE, VHB_Club_Types::LIEU, VHB_Club_Types::ENTENTE, VHB_Club_Types::STAGE ), true ) ) {
			return true;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore
		return in_array( $page, array( self::MENU, self::REGLAGES, self::SAISONS, 'vhb-club-inscriptions', 'vhb-club-organigramme', 'vhb-club-vie', 'vhb-club-export', 'vhb-club-photos', 'vhb-etat', 'vhb-club-calendrier' ), true );
	}

	public static function assets() {
		if ( ! self::is_ours() ) {
			return;
		}
		wp_enqueue_style( 'vhb-club-admin', VHB_CLUB_URL . 'assets/admin.css', array(), VHB_Club_Cache::ver( 'assets/admin.css' ) );
		wp_enqueue_media();
		wp_enqueue_script( 'vhb-club-admin', VHB_CLUB_URL . 'assets/admin.js', array(), VHB_Club_Cache::ver( 'assets/admin.js' ), true );
	}

	/* ------------------------------------------------------------------ */
	/* Messages après enregistrement                                       */
	/* ------------------------------------------------------------------ */

	public static function notice( $type, $msg ) {
		$k    = 'vhbc_notices_' . get_current_user_id();
		$list = get_transient( $k );
		$list = is_array( $list ) ? $list : array();
		$list[] = array( $type, $msg );
		set_transient( $k, $list, 120 );
	}

	public static function notices() {
		if ( ! self::is_ours() ) {
			return;
		}
		$k    = 'vhbc_notices_' . get_current_user_id();
		$list = get_transient( $k );
		if ( ! is_array( $list ) || ! $list ) {
			return;
		}
		delete_transient( $k );
		foreach ( $list as $n ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $n[0] ), wp_kses_post( $n[1] ) );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Réglages du club                                                    */
	/* ------------------------------------------------------------------ */

	public static function settings() {
		register_setting(
			'vhb_club',
			VHB_Club_Reglages::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'VHB_Club_Reglages', 'sanitize' ),
				'default'           => VHB_Club_Reglages::defaults(),
			)
		);
	}

	/** La page « options.php » vérifie « manage_options » : on l'aligne sur notre capacité. */
	public static function option_page_cap() {
		return VHB_Club_Reglages::CAP_REGLAGES;
	}

	private static function field( $name, $label, $value, $type = 'text', $help = '' ) {
		$id = 'vhbc-' . $name;
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		if ( 'textarea' === $type ) {
			echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( VHB_Club_Reglages::OPTION . '[' . $name . ']' ) . '" rows="3" class="large-text">' . esc_textarea( $value ) . '</textarea>';
		} else {
			echo '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( VHB_Club_Reglages::OPTION . '[' . $name . ']' ) . '" value="' . esc_attr( $value ) . '" class="regular-text">';
		}
		if ( $help ) {
			echo '<p class="description">' . wp_kses_post( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	public static function page_reglages() {
		if ( ! current_user_can( VHB_Club_Reglages::CAP_REGLAGES ) ) {
			return;
		}
		$r    = VHB_Club_Reglages::get();
		$o    = VHB_Club_Reglages::OPTION;
		$logo = $r['logo'] ? wp_get_attachment_image_url( $r['logo'], 'thumbnail' ) : '';
		?>
		<div class="wrap vhbc-admin">
			<h1>Réglages du club</h1>
			<?php if ( isset( $_GET['settings-updated'] ) ) : // phpcs:ignore ?>
				<div class="notice notice-success is-dismissible"><p>Réglages enregistrés. Le site est à jour.</p></div>
			<?php endif; ?>
			<p class="description">Informations générales, affichées sur le site par les blocs « <?php echo esc_html( VHB_MARQUE ); ?> » et le shortcode <code>[vhb_info]</code>. Une modification ici se répercute sur toutes les pages.</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'vhb_club' ); ?>
				<h2 class="title">Identité</h2>
				<table class="form-table" role="presentation">
					<?php
					self::field( 'nom', 'Nom du club', $r['nom'] );
					self::field( 'sigle', 'Sigle', $r['sigle'] );
					self::field( 'affiliation', 'N° d\'affiliation FFHB', $r['affiliation'] );
					?>
					<tr><th scope="row">Logo</th><td>
						<div class="vhbc-media" data-target="vhbc-logo">
							<span class="vhbc-media-preview"><?php echo $logo ? '<img src="' . esc_url( $logo ) . '" alt="">' : ''; ?></span>
							<input type="hidden" id="vhbc-logo" name="<?php echo esc_attr( $o ); ?>[logo]" value="<?php echo (int) $r['logo']; ?>">
							<button type="button" class="button vhbc-media-pick">Choisir</button>
							<button type="button" class="button-link vhbc-media-clear"<?php echo $r['logo'] ? '' : ' hidden'; ?>>Retirer</button>
						</div>
					</td></tr>
				</table>

				<h2 class="title">Contacts</h2>
				<table class="form-table" role="presentation">
					<?php
					self::field( 'email', 'Email du secrétariat', $r['email'], 'email', 'Shortcode : <code>[vhb_info cle="email" lien="oui"]</code>' );
					self::field( 'email_licences', 'Email des licences', $r['email_licences'], 'email', 'Shortcode : <code>[vhb_info cle="email_licences" lien="oui"]</code>' );
					self::field( 'telephone', 'Téléphone', $r['telephone'], 'tel' );
					self::field( 'siege', 'Adresse du siège', $r['siege'], 'textarea', 'Adresse postale de l\'association, si elle diffère du gymnase.' );
					?>
				</table>

				<h2 class="title">Chiffres du club</h2>
				<p>Affichés dans la bande de chiffres de la page « Le club ». Un champ vide n'est pas affiché. Le nombre d'équipes en championnat est calculé tout seul (équipes des Résultats FFHB rattachées aux catégories).</p>
				<table class="form-table" role="presentation">
					<?php
					self::field( 'annee_creation', 'Année de création', $r['annee_creation'], 'number' );
					self::field( 'licencies', 'Nombre de licenciés', $r['licencies'], 'number', 'À mettre à jour chaque saison.' );
					self::field( 'licenciees_pct', 'Part de licenciées (%)', $r['licenciees_pct'], 'number', 'Pourcentage de filles et de femmes parmi les licenciés, sans le signe %.' );
					?>
				</table>

				<h2 class="title">Réseaux sociaux</h2>
				<table class="form-table" role="presentation">
					<?php
					self::field( 'facebook', 'Facebook', $r['facebook'], 'url' );
					self::field( 'instagram', 'Instagram', $r['instagram'], 'url' );
					?>
				</table>

				<h2 class="title">Affichage</h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="vhbc-lieu-principal">Gymnase principal</label></th><td>
						<select id="vhbc-lieu-principal" name="<?php echo esc_attr( $o ); ?>[lieu_principal]">
							<option value="0">Premier gymnase du club (automatique)</option>
							<?php foreach ( VHB_Club_Donnees::lieux() as $l ) : ?>
								<option value="<?php echo (int) $l['id']; ?>" <?php selected( (int) $r['lieu_principal'], $l['id'] ); ?>><?php echo esc_html( $l['nom'] ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">Utilisé par <code>[vhb_lieu]</code> et <code>[vhb_info cle="adresse"]</code>.</p>
					</td></tr>
					<tr><th scope="row"><label for="vhbc-theme">Apparence</label></th><td>
						<select id="vhbc-theme" name="<?php echo esc_attr( $o ); ?>[theme]">
							<?php foreach ( VHB_Club_Reglages::THEMES as $k => $v ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $r['theme'], $k ); ?>><?php echo esc_html( $v ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">« Site du club » reprend les couleurs et les polices du CSS additionnel du site : si elles changent, les blocs suivent.</p>
					</td></tr>
				</table>
				<?php submit_button( 'Enregistrer' ); ?>
			</form>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Tableau de bord                                                     */
	/* ------------------------------------------------------------------ */

	/** Points à compléter : array( message, lien d'édition ). */
	public static function controles() {
		$out    = array();
		$saison = VHB_Club_Reglages::saison_en_cours();
		$codes  = array();
		foreach ( VHB_Club_Donnees::categories( $saison ) as $c ) {
			$edit = get_edit_post_link( $c['id'], 'raw' );
			if ( '' === $c['code'] ) {
				$out[] = array( $c['nom'] . ' : code manquant (nécessaire aux blocs et shortcodes).', $edit );
			} elseif ( isset( $codes[ $c['code'] ] ) ) {
				$out[] = array( $c['nom'] . ' : le code « ' . $c['code'] . ' » est déjà utilisé par ' . $codes[ $c['code'] ] . '.', $edit );
			}
			$codes[ $c['code'] ] = $c['nom'];
			if ( '' === $c['annees'] ) {
				$out[] = array( $c['nom'] . ' : années de naissance non renseignées.', $edit );
			}
			if ( ! $c['creneaux'] ) {
				$out[] = array( $c['nom'] . ' : aucun créneau d\'entraînement.', $edit );
			}
			if ( '' === $c['tarif'] ) {
				$out[] = array( $c['nom'] . ' : tarif non renseigné.', $edit );
			}
			foreach ( $c['creneaux'] as $s ) {
				$jour = VHB_Club_Types::JOURS[ $s['jour'] ] ?? '?';
				$l    = VHB_Club_Donnees::lieu( $s['lieu'] );
				if ( ! $l ) {
					$out[] = array( $c['nom'] . ' : créneau du ' . strtolower( $jour ) . ' sans gymnase.', $edit );
				} elseif ( ! $l['publie'] ) {
					$out[] = array( $c['nom'] . ' : le gymnase « ' . $l['nom'] . ' » du ' . strtolower( $jour ) . ' n\'est pas publié.', $edit );
				}
				if ( '' === $s['fin'] || $s['fin'] <= $s['debut'] ) {
					$out[] = array( $c['nom'] . ' : créneau du ' . strtolower( $jour ) . ', heure de fin manquante ou avant le début.', $edit );
				}
			}
		}
		foreach ( VHB_Club_Donnees::lieux() as $l ) {
			if ( '' === $l['adresse_complete'] ) {
				$out[] = array( $l['nom'] . ' : adresse manquante.', get_edit_post_link( $l['id'], 'raw' ) );
			}
		}
		foreach ( VHB_Club_Stages::liste( 'a_venir', array( 'stage' ) ) as $s ) {
			if ( '' === $s['helloasso'] ) {
				$out[] = array( $s['titre'] . ' : pas de lien HelloAsso, aucun bouton de réservation.', get_edit_post_link( $s['id'], 'raw' ) );
			}
		}
		$out = array_merge( $out, VHB_Club_Ffhb::controles() );
		$m   = VHB_Club_Vie::get()['mentions'];
		if ( '' === $m['hebergeur'] || '' === $m['rna'] ) {
			$out[] = array( 'Mentions légales : hébergeur ou n° RNA non renseigné.', admin_url( 'admin.php?page=vhb-club-vie' ) );
		}
		if ( '' === trim( VHB_Club_Reglages::get()['siege'] ) ) {
			$out[] = array( 'Mentions légales : adresse du siège non renseignée (Réglages du club).', admin_url( 'admin.php?page=' . self::REGLAGES ) );
		}
		$r = VHB_Club_Reglages::get();
		if ( '' === $r['nom'] || '' === $r['email'] ) {
			$out[] = array( 'Réglages du club : nom ou email du secrétariat manquant.', admin_url( 'admin.php?page=' . self::REGLAGES ) );
		}
		return $out;
	}

	public static function dashboard() {
		if ( ! current_user_can( VHB_Club_Reglages::CAP_DONNEES ) ) {
			return;
		}
		$saison  = VHB_Club_Reglages::saison_en_cours();
		$cats    = VHB_Club_Donnees::categories( $saison );
		$nb_cren = array_sum(
			array_map(
				static function ( $c ) {
					return count( $c['creneaux'] );
				},
				$cats
			)
		);
		$prep    = array_filter(
			VHB_Club_Reglages::saisons(),
			static function ( $s ) {
				return 'preparation' === $s['statut'];
			}
		);
		$ctrl    = self::controles();
		$list    = static function ( $type ) {
			return admin_url( 'edit.php?post_type=' . $type );
		};
		?>
		<div class="wrap vhbc-admin">
			<h1><?php echo esc_html( VHB_MARQUE ); ?></h1>
			<p class="description">Les informations du club, saisies une seule fois. Les pages du site les affichent avec les blocs « <?php echo esc_html( VHB_MARQUE ); ?> : … » ou les shortcodes ci-dessous.</p>

			<div class="vhbc-admin-grid">
				<div class="vhbc-admin-card">
					<h2>Saison affichée sur le site</h2>
					<p class="vhbc-big"><?php echo esc_html( $saison ); ?></p>
					<?php if ( $prep ) : ?>
						<p>En préparation : <strong><?php echo esc_html( implode( ', ', wp_list_pluck( $prep, 'id' ) ) ); ?></strong>.</p>
					<?php endif; ?>
					<?php if ( current_user_can( VHB_Club_Reglages::CAP_REGLAGES ) ) : ?>
						<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SAISONS ) ); ?>">Gérer les saisons</a></p>
					<?php endif; ?>
				</div>

				<div class="vhbc-admin-card">
					<h2>En chiffres</h2>
					<table class="vhbc-kv">
						<tr><th><a href="<?php echo esc_url( $list( VHB_Club_Types::CATEGORIE ) ); ?>">Catégories publiées</a></th><td><?php echo (int) count( $cats ); ?></td></tr>
						<tr><th>Créneaux d'entraînement</th><td><?php echo (int) $nb_cren; ?></td></tr>
						<tr><th><a href="<?php echo esc_url( $list( VHB_Club_Types::LIEU ) ); ?>">Gymnases</a></th><td><?php echo (int) count( VHB_Club_Donnees::lieux() ); ?></td></tr>
						<tr><th><a href="<?php echo esc_url( $list( VHB_Club_Types::ENTENTE ) ); ?>">Clubs en entente</a></th><td><?php echo (int) count( VHB_Club_Donnees::ententes() ); ?></td></tr>
					</table>
				</div>

				<div class="vhbc-admin-card">
					<h2>Qui peut modifier</h2>
					<table class="vhbc-kv">
						<?php foreach ( array( 'administrator' => 'Administrateurs' ) + VHB_Club_Types::ROLES as $role => $label ) : ?>
							<?php $nb = count( get_users( array( 'role' => $role, 'fields' => 'ID' ) ) ); ?>
							<tr><th><?php echo esc_html( $label ); ?></th><td><?php echo (int) $nb; ?></td></tr>
						<?php endforeach; ?>
					</table>
					<p class="description">Gestionnaire club : toutes les données du club, sans les réglages ni les saisons. Éditeur de stages : les événements seulement (stages, tournois, loto...). Rôle à donner dans Comptes &gt; Profil de la personne.</p>
					<?php if ( current_user_can( 'list_users' ) ) : ?>
						<p><a class="button" href="<?php echo esc_url( admin_url( 'users.php' ) ); ?>">Gérer les comptes</a></p>
					<?php endif; ?>
				</div>

				<div class="vhbc-admin-card vhbc-admin-card--wide">
					<h2>À compléter <?php echo $ctrl ? '<span class="vhbc-pill">' . (int) count( $ctrl ) . '</span>' : ''; ?></h2>
					<?php if ( ! $ctrl ) : ?>
						<p class="vhbc-ok">Rien à signaler : toutes les données de la saison <?php echo esc_html( $saison ); ?> sont complètes.</p>
					<?php else : ?>
						<ul class="vhbc-ctrl">
							<?php foreach ( $ctrl as $it ) : ?>
								<li><?php echo esc_html( $it[0] ); ?> <?php if ( $it[1] ) : ?><a href="<?php echo esc_url( $it[1] ); ?>">Corriger</a><?php endif; ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>

				<div class="vhbc-admin-card vhbc-admin-card--wide">
					<h2>Codes des catégories (saison <?php echo esc_html( $saison ); ?>)</h2>
					<p class="description">Le code identifie une catégorie d'une saison à l'autre : une page qui affiche <code>u13-f</code> montrera automatiquement la catégorie U13 de la nouvelle saison.</p>
					<table class="widefat striped vhbc-codes">
						<thead><tr><th>Catégorie</th><th>Code</th><th>Pratique</th><th>Années</th><th>Créneaux</th></tr></thead>
						<tbody>
						<?php foreach ( $cats as $c ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( get_edit_post_link( $c['id'] ) ); ?>"><strong><?php echo esc_html( $c['nom'] ); ?></strong></a></td>
								<td><code><?php echo esc_html( $c['code'] ); ?></code></td>
								<td><?php echo esc_html( VHB_Club_Types::PRATIQUES[ $c['pratique'] ] ?? '' ); ?></td>
								<td><?php echo esc_html( $c['annees'] ); ?></td>
								<td><?php echo (int) count( $c['creneaux'] ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<div class="vhbc-admin-card vhbc-admin-card--wide">
					<h2>Utiliser sur le site</h2>
					<p>Dans l'éditeur, ajouter un bloc dont le nom commence par <strong>« <?php echo esc_html( VHB_MARQUE ); ?> : »</strong> (catégorie Widgets) et le régler dans le panneau de droite. Équivalents en shortcode :</p>
					<ul class="vhbc-codes-list">
						<li><code>[vhb_planning]</code> planning complet. Options : <code>vue="jours"</code>, <code>pratique="competition"</code>, <code>categorie="u13-f,u13-g"</code>, <code>lieu="<em>slug du gymnase</em>"</code>, <code>groupe="pratique"</code>, <code>lieux="non"</code>.</li>
						<li><code>[vhb_categorie categorie="u13-f"]</code> fiche d'une catégorie : années, description, entente, entraînements.</li>
						<li><code>[vhb_lieu]</code> gymnase principal avec itinéraire. Options : <code>lieu="tous"</code>, <code>lieu="partenaire"</code>, <code>lieu="<em>slug</em>"</code>.</li>
						<li><code>[vhb_tarifs]</code> grille tarifaire. Options : <code>pratique="loisir"</code>, <code>regrouper="non"</code>, <code>autres="non"</code>, <code>mention="non"</code>.</li>
						<li><code>[vhb_reductions]</code> réductions et paiement. Option : <code>essai="oui"</code>.</li>
						<li><code>[vhb_pieces]</code> pièces à fournir et documents. Option : <code>equipement="oui"</code>.</li>
						<li><code>[vhb_bandeau_inscriptions]</code> bandeau « Inscriptions ouvertes », visible seulement s'il est activé dans <?php echo esc_html( VHB_MARQUE ); ?> &gt; Inscriptions.</li>
						<li><code>[vhb_stages]</code> stages à venir (fiches). Options : <code>vue="compact"</code> (accueil), <code>nombre="1"</code>, <code>widget="oui"</code> (formulaire HelloAsso), <code>lien="/stages/"</code>.</li>
						<li><code>[vhb_alertes]</code> alertes en cours ; <code>[vhb_boutique]</code> bandeau boutique ; <code>[vhb_partenaires]</code> logos des partenaires.</li>
						<li><code>[vhb_organigramme]</code> bureau, CA, commissions. Options : <code>groupe="bureau,ca"</code>, <code>emails="non"</code>.</li>
						<li><code>[vhb_ag]</code> assemblées générales. Options : <code>quand="a_venir"</code>, <code>type="publique"</code>. <code>[vhb_mentions]</code> mentions légales.</li>
						<li><code>[vhb_info cle="email" lien="oui"]</code> une donnée dans un texte. Clés : <?php echo esc_html( implode( ', ', array_keys( VHB_Club_Render::cles_info() ) ) ); ?>. Pour les années : <code>[vhb_info cle="annees" categorie="u13-f"]</code>.</li>
					</ul>
				</div>
			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Listes                                                              */
	/* ------------------------------------------------------------------ */

	public static function title_placeholder( $text, $post ) {
		switch ( $post->post_type ) {
			case VHB_Club_Types::CATEGORIE:
				return 'Nom affiché (ex. U13 F)';
			case VHB_Club_Types::LIEU:
				return 'Nom du gymnase (ex. Gymnase municipal)';
			case VHB_Club_Types::ENTENTE:
				return 'Nom du club partenaire';
		}
		return $text;
	}

	public static function cat_columns( $cols ) {
		return array(
			'cb'           => $cols['cb'] ?? '',
			'title'        => 'Catégorie',
			'vhbc_code'    => 'Code',
			'vhbc_prat'    => 'Pratique',
			'vhbc_annees'  => 'Années',
			'vhbc_cren'    => 'Entraînements',
			'vhbc_tarif'   => 'Tarif',
			'vhbc_saison'  => 'Saison',
		);
	}

	public static function cat_column( $col, $id ) {
		$c = VHB_Club_Donnees::categorie( $id );
		switch ( $col ) {
			case 'vhbc_code':
				echo '<code>' . esc_html( $c['code'] ) . '</code>';
				break;
			case 'vhbc_prat':
				echo esc_html( VHB_Club_Types::PRATIQUES[ $c['pratique'] ] ?? '' );
				break;
			case 'vhbc_annees':
				echo esc_html( $c['annees'] );
				break;
			case 'vhbc_cren':
				$parts = array();
				foreach ( $c['creneaux'] as $s ) {
					$l       = VHB_Club_Donnees::lieu( $s['lieu'] );
					$parts[] = esc_html( mb_substr( VHB_Club_Types::JOURS[ $s['jour'] ], 0, 3 ) . '. ' . VHB_Club_Donnees::plage( $s['debut'], $s['fin'] ) . ( $l ? ' · ' . $l['nom_court'] : '' ) );
				}
				echo $parts ? implode( '<br>', $parts ) : '<span class="vhbc-err">aucun</span>'; // phpcs:ignore
				break;
			case 'vhbc_tarif':
				echo '' === $c['tarif'] ? '<span class="vhbc-err">à saisir</span>' : esc_html( VHB_Club_Inscriptions::montant( $c['tarif'] ) );
				echo $c['statut'] ? '<br><span class="description">' . esc_html( VHB_Club_Inscriptions::STATUTS_CATEGORIE[ $c['statut'] ] ?? '' ) . '</span>' : '';
				break;
			case 'vhbc_saison':
				$st = VHB_Club_Reglages::statut( $c['saison'] );
				echo esc_html( $c['saison'] ) . ( $st && 'en_cours' !== $st ? ' <span class="vhbc-pill vhbc-pill--off">' . esc_html( strtolower( VHB_Club_Reglages::STATUTS_SAISON[ $st ] ) ) . '</span>' : '' );
				break;
		}
	}

	public static function lieu_columns( $cols ) {
		return array(
			'cb'           => $cols['cb'] ?? '',
			'title'        => 'Gymnase',
			'vhbc_court'   => 'Nom court',
			'vhbc_type'    => 'Type',
			'vhbc_adresse' => 'Adresse',
			'vhbc_ffhb'    => 'Nom FFHB',
			'vhbc_slug'    => 'Slug',
		);
	}

	public static function lieu_column( $col, $id ) {
		$l = VHB_Club_Donnees::lieu( $id );
		if ( ! $l ) {
			return;
		}
		switch ( $col ) {
			case 'vhbc_court':
				echo esc_html( $l['nom_court'] );
				break;
			case 'vhbc_type':
				echo esc_html( VHB_Club_Types::TYPES_LIEU[ $l['type'] ] ?? '' );
				$e = $l['entente'] ? VHB_Club_Donnees::entente( $l['entente'] ) : null;
				echo $e ? '<br><span class="description">' . esc_html( $e['nom'] ) . '</span>' : '';
				break;
			case 'vhbc_adresse':
				echo $l['adresse_complete'] ? esc_html( $l['adresse_complete'] ) : '<span class="vhbc-err">manquante</span>';
				break;
			case 'vhbc_ffhb':
				echo esc_html( $l['nom_federal'] );
				break;
			case 'vhbc_slug':
				echo '<code>' . esc_html( $l['slug'] ) . '</code>';
				break;
		}
	}

	public static function entente_columns( $cols ) {
		return array(
			'cb'          => $cols['cb'] ?? '',
			'title'       => 'Club',
			'vhbc_ent'    => 'Nom de l\'entente (FFHB)',
			'vhbc_cats'   => 'Catégories (saison en cours)',
		);
	}

	public static function entente_column( $col, $id ) {
		if ( 'vhbc_ent' === $col ) {
			echo esc_html( VHB_Club_Types::meta( $id, 'nom_entente' ) );
		} elseif ( 'vhbc_cats' === $col ) {
			$noms = array();
			foreach ( VHB_Club_Donnees::categories() as $c ) {
				if ( in_array( (int) $id, $c['ententes'], true ) ) {
					$noms[] = $c['nom'];
				}
			}
			echo esc_html( implode( ', ', $noms ) );
		}
	}

	/** Liste des catégories : filtre par saison (saison en cours par défaut). */
	public static function filtre_saison( $post_type ) {
		if ( VHB_Club_Types::CATEGORIE !== $post_type ) {
			return;
		}
		$cur = isset( $_GET['vhbc_saison'] ) ? sanitize_text_field( wp_unslash( $_GET['vhbc_saison'] ) ) : VHB_Club_Reglages::saison_en_cours(); // phpcs:ignore
		echo '<select name="vhbc_saison"><option value="toutes">Toutes les saisons</option>';
		foreach ( array_reverse( VHB_Club_Reglages::saisons() ) as $s ) {
			printf( '<option value="%1$s" %2$s>Saison %1$s (%3$s)</option>', esc_attr( $s['id'] ), selected( $cur, $s['id'], false ), esc_html( strtolower( VHB_Club_Reglages::STATUTS_SAISON[ $s['statut'] ] ?? '' ) ) );
		}
		echo '</select>';
	}

	public static function query_admin( $q ) {
		if ( ! is_admin() || ! $q->is_main_query() || VHB_Club_Types::CATEGORIE !== $q->get( 'post_type' ) ) {
			return;
		}
		$s = isset( $_GET['vhbc_saison'] ) ? sanitize_text_field( wp_unslash( $_GET['vhbc_saison'] ) ) : VHB_Club_Reglages::saison_en_cours(); // phpcs:ignore
		if ( 'toutes' !== $s && VHB_Club_Reglages::valid_id( $s ) ) {
			$q->set( 'meta_key', VHB_Club_Types::M . 'saison' );
			$q->set( 'meta_value', $s );
		}
		if ( ! $q->get( 'orderby' ) ) {
			$q->set( 'orderby', array( 'menu_order' => 'ASC', 'title' => 'ASC' ) );
		}
	}
}

add_filter( 'option_page_capability_vhb_club', array( 'VHB_Club_Admin', 'option_page_cap' ) );
