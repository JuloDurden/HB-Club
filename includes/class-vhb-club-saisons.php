<?php
/**
 * Saisons : préparer la suivante, la mettre en ligne d'un clic, revenir en arrière.
 *
 * 1. « Préparer » crée la saison suivante et y copie les catégories et leurs
 *    créneaux. Elle reste invisible sur le site.
 * 2. On ajuste horaires et catégories de la nouvelle saison.
 * 3. « Mettre en ligne » : tout le site passe à la nouvelle saison, années de
 *    naissance comprises. L'ancienne est archivée et reste consultable.
 */

defined( 'ABSPATH' ) || exit;

class VHB_Club_Saisons {

	public static function init() {
		add_action( 'admin_post_vhbc_saison_preparer', array( __CLASS__, 'preparer' ) );
		add_action( 'admin_post_vhbc_saison_basculer', array( __CLASS__, 'basculer' ) );
		add_action( 'admin_post_vhbc_saison_supprimer', array( __CLASS__, 'supprimer' ) );
	}

	private static function url( $msg = '' ) {
		$u = admin_url( 'admin.php?page=' . VHB_Club_Admin_Slugs::SAISONS );
		return $msg ? add_query_arg( 'vhbc_msg', rawurlencode( $msg ), $u ) : $u;
	}

	private static function check( $action ) {
		if ( ! current_user_can( VHB_Club_Reglages::CAP_REGLAGES ) ) {
			wp_die( 'Accès refusé.', 403 );
		}
		check_admin_referer( $action );
	}

	/** Identifiants des catégories d'une saison (tous statuts sauf corbeille). */
	public static function categories_ids( $saison ) {
		return get_posts(
			array(
				'post_type'        => VHB_Club_Types::CATEGORIE,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'meta_key'         => VHB_Club_Types::M . 'saison', // phpcs:ignore
				'meta_value'       => $saison, // phpcs:ignore
				'orderby'          => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
				'suppress_filters' => true,
			)
		);
	}

	/** Copie les catégories d'une saison vers une autre. @return int nombre copié */
	public static function copier( $from, $to ) {
		$n = 0;
		foreach ( self::categories_ids( $from ) as $id ) {
			$src = get_post( $id );
			$new = wp_insert_post(
				array(
					'post_type'   => VHB_Club_Types::CATEGORIE,
					'post_title'  => $src->post_title,
					'post_status' => $src->post_status,
					'menu_order'  => $src->menu_order,
				),
				true
			);
			if ( is_wp_error( $new ) ) {
				continue;
			}
			foreach ( get_post_meta( $id ) as $key => $values ) {
				if ( 0 !== strpos( $key, VHB_Club_Types::M ) ) {
					continue;
				}
				update_post_meta( $new, $key, maybe_unserialize( $values[0] ) );
			}
			update_post_meta( $new, VHB_Club_Types::M . 'saison', $to );
			++$n;
		}
		VHB_Club_Cache::changed();
		return $n;
	}

	public static function preparer() {
		self::check( 'vhbc_saison_preparer' );
		$list   = VHB_Club_Reglages::saisons();
		$cur    = VHB_Club_Reglages::saison_en_cours();
		$last   = $list ? end( $list )['id'] : $cur;
		$next   = VHB_Club_Reglages::saison_suivante( max( $last, $cur ) );
		$list[] = array( 'id' => $next, 'statut' => 'preparation' );
		if ( ! in_array( $cur, wp_list_pluck( $list, 'id' ), true ) ) {
			$list[] = array( 'id' => $cur, 'statut' => 'en_cours' );
		}
		VHB_Club_Reglages::save_saisons( $list );
		VHB_Club_Inscriptions::copier_saison( $cur, $next );
		$msg = 'Saison ' . $next . ' créée, en préparation (invisible sur le site). Réductions et autres licences reprises de ' . $cur . '.';
		if ( ! empty( $_POST['copier'] ) ) { // phpcs:ignore
			$n    = self::copier( $cur, $next );
			$msg .= ' ' . $n . ' catégorie(s) copiée(s) depuis ' . $cur . ', avec leurs créneaux.';
		}
		wp_safe_redirect( self::url( $msg ) );
		exit;
	}

	public static function basculer() {
		self::check( 'vhbc_saison_basculer' );
		$id = isset( $_POST['saison'] ) ? sanitize_text_field( wp_unslash( $_POST['saison'] ) ) : ''; // phpcs:ignore
		if ( empty( $_POST['confirme'] ) || ! VHB_Club_Reglages::statut( $id ) ) { // phpcs:ignore
			wp_safe_redirect( self::url( 'Rien n\'a changé : cochez la case de confirmation.' ) );
			exit;
		}
		$list = VHB_Club_Reglages::saisons();
		foreach ( $list as &$s ) {
			if ( $s['id'] === $id ) {
				$s['statut'] = 'en_cours';
			} else {
				$s['statut'] = $s['id'] < $id ? 'archivee' : 'preparation';
			}
		}
		unset( $s );
		VHB_Club_Reglages::save_saisons( $list );
		wp_safe_redirect( self::url( 'Le site affiche maintenant la saison ' . $id . '.' ) );
		exit;
	}

	public static function supprimer() {
		self::check( 'vhbc_saison_supprimer' );
		$id = isset( $_POST['saison'] ) ? sanitize_text_field( wp_unslash( $_POST['saison'] ) ) : ''; // phpcs:ignore
		if ( empty( $_POST['confirme'] ) || 'preparation' !== VHB_Club_Reglages::statut( $id ) ) { // phpcs:ignore
			wp_safe_redirect( self::url( 'Rien n\'a changé : seule une saison en préparation peut être supprimée, après confirmation.' ) );
			exit;
		}
		$ids = self::categories_ids( $id );
		foreach ( $ids as $pid ) {
			wp_trash_post( $pid );
		}
		VHB_Club_Reglages::save_saisons(
			array_filter(
				VHB_Club_Reglages::saisons(),
				static function ( $s ) use ( $id ) {
					return $s['id'] !== $id;
				}
			)
		);
		wp_safe_redirect( self::url( 'Saison ' . $id . ' supprimée. ' . count( $ids ) . ' catégorie(s) mise(s) à la corbeille.' ) );
		exit;
	}

	public static function page() {
		if ( ! current_user_can( VHB_Club_Reglages::CAP_REGLAGES ) ) {
			return;
		}
		$list = array_reverse( VHB_Club_Reglages::saisons() );
		$cur  = VHB_Club_Reglages::saison_en_cours();
		$last = $list ? $list[0]['id'] : $cur;
		$next = VHB_Club_Reglages::saison_suivante( max( $last, $cur ) );
		$msg  = isset( $_GET['vhbc_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['vhbc_msg'] ) ) : ''; // phpcs:ignore
		?>
		<div class="wrap vhbc-admin">
			<h1>Saisons</h1>
			<?php if ( $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
			<?php endif; ?>
			<p class="description">Le site affiche une seule saison à la fois. Les catégories et leurs créneaux appartiennent à une saison ; les gymnases, clubs en entente et réglages sont communs à toutes.</p>

			<table class="widefat striped vhbc-saisons">
				<thead><tr><th>Saison</th><th>Statut</th><th>Catégories</th><th>Action</th></tr></thead>
				<tbody>
				<?php foreach ( $list as $s ) : ?>
					<?php
					$n    = count( self::categories_ids( $s['id'] ) );
					$voir = admin_url( 'edit.php?post_type=' . VHB_Club_Types::CATEGORIE . '&vhbc_saison=' . rawurlencode( $s['id'] ) );
					?>
					<tr class="<?php echo 'en_cours' === $s['statut'] ? 'is-current' : ''; ?>">
						<td><strong><?php echo esc_html( $s['id'] ); ?></strong></td>
						<td><span class="vhbc-pill vhbc-pill--<?php echo esc_attr( $s['statut'] ); ?>"><?php echo esc_html( VHB_Club_Reglages::STATUTS_SAISON[ $s['statut'] ] ?? $s['statut'] ); ?></span></td>
						<td><a href="<?php echo esc_url( $voir ); ?>"><?php echo (int) $n; ?> catégorie(s)</a></td>
						<td>
							<?php if ( 'en_cours' === $s['statut'] ) : ?>
								<span class="description">Affichée sur le site</span>
							<?php else : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vhbc-inline-form">
									<?php wp_nonce_field( 'vhbc_saison_basculer' ); ?>
									<input type="hidden" name="action" value="vhbc_saison_basculer">
									<input type="hidden" name="saison" value="<?php echo esc_attr( $s['id'] ); ?>">
									<label><input type="checkbox" name="confirme" value="1" required> Je confirme</label>
									<button type="submit" class="button <?php echo 'preparation' === $s['statut'] ? 'button-primary' : ''; ?>"><?php echo 'preparation' === $s['statut'] ? 'Mettre en ligne' : 'Revenir à cette saison'; ?></button>
								</form>
								<?php if ( 'preparation' === $s['statut'] ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vhbc-inline-form vhbc-danger">
										<?php wp_nonce_field( 'vhbc_saison_supprimer' ); ?>
										<input type="hidden" name="action" value="vhbc_saison_supprimer">
										<input type="hidden" name="saison" value="<?php echo esc_attr( $s['id'] ); ?>">
										<label><input type="checkbox" name="confirme" value="1" required> Je confirme</label>
										<button type="submit" class="button-link vhbc-del">Supprimer</button>
									</form>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( 'preparation' !== VHB_Club_Reglages::statut( $next ) ) : ?>
			<div class="vhbc-admin-card vhbc-prep">
				<h2>Préparer la saison <?php echo esc_html( $next ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'vhbc_saison_preparer' ); ?>
					<input type="hidden" name="action" value="vhbc_saison_preparer">
					<p><label><input type="checkbox" name="copier" value="1" checked> Copier les catégories et créneaux de la saison <?php echo esc_html( $cur ); ?></label></p>
					<p class="description">La nouvelle saison reste invisible sur le site jusqu'à sa mise en ligne. Les années de naissance des catégories copiées sont décalées d'un an automatiquement.</p>
					<?php submit_button( 'Créer la saison ' . $next, 'secondary', 'submit', false ); ?>
				</form>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
