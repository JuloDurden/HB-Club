<?php
/**
 * Écran « VHB Club > Inscriptions ».
 * Partie haute : réglages de la saison choisie. Partie basse : communs à toutes les saisons.
 */

defined( 'ABSPATH' ) || exit;

class VHB_Club_Inscriptions_Admin {

	const SLUG = 'vhb-club-inscriptions';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 10 );
		add_action( 'admin_post_vhbc_inscriptions_save', array( __CLASS__, 'save' ) );
	}

	public static function menu() {
		add_submenu_page( VHB_Club_Admin_Slugs::MENU, 'Inscriptions', 'Inscriptions', VHB_Club_Reglages::CAP_DONNEES, self::SLUG, array( __CLASS__, 'page' ) );
	}

	private static function saison_demandee() {
		$s = isset( $_REQUEST['saison'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['saison'] ) ) : ''; // phpcs:ignore
		return VHB_Club_Reglages::valid_id( $s ) ? $s : VHB_Club_Reglages::saison_en_cours();
	}

	public static function save() {
		if ( ! current_user_can( VHB_Club_Reglages::CAP_DONNEES ) ) {
			wp_die( 'Accès refusé.', 403 );
		}
		check_admin_referer( 'vhbc_inscriptions_save' );
		$saison = self::saison_demandee();
		$in     = wp_unslash( $_POST ); // phpcs:ignore
		VHB_Club_Inscriptions::save_saison( $saison, VHB_Club_Inscriptions::sanitize_saison( (array) ( $in['saison_data'] ?? array() ) ) );
		update_option( VHB_Club_Inscriptions::OPTION, VHB_Club_Inscriptions::sanitize( (array) ( $in['commun'] ?? array() ) ), false );
		VHB_Club_Cache::changed();
		$s = VHB_Club_Inscriptions::saison( $saison );
		if ( $s['ouverture'] && $s['cloture'] && $s['cloture'] < $s['ouverture'] ) {
			VHB_Club_Admin::notice( 'warning', 'La date de clôture précède la date d\'ouverture : le bandeau ne s\'affichera jamais.' );
		}
		VHB_Club_Admin::notice( 'success', 'Inscriptions enregistrées. Le site est à jour.' );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'saison' => $saison ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Tableau de lignes répétables : en-têtes, lignes, modèle « __i__ ». */
	public static function repeat( $id, array $heads, array $rows, callable $row, array $empty ) {
		echo '<table class="widefat vhbc-repeat"><thead><tr>';
		foreach ( $heads as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '<th><span class="screen-reader-text">Retirer</span></th></tr></thead><tbody class="vhbc-repeat-rows" id="' . esc_attr( $id ) . '">';
		if ( ! $rows ) {
			$rows = array( $empty );
		}
		foreach ( $rows as $i => $r ) {
			$row( $i, $r );
		}
		echo '</tbody></table><script type="text/html" id="tmpl-' . esc_attr( $id ) . '">';
		$row( '__i__', $empty );
		echo '</script><p><button type="button" class="button vhbc-repeat-add" data-target="' . esc_attr( $id ) . '">Ajouter une ligne</button></p>';
	}

	public static function del() {
		return '<td><button type="button" class="button-link vhbc-repeat-del">Retirer</button></td>';
	}

	public static function page() {
		if ( ! current_user_can( VHB_Club_Reglages::CAP_DONNEES ) ) {
			return;
		}
		$saison = self::saison_demandee();
		$s      = VHB_Club_Inscriptions::saison( $saison );
		$c      = VHB_Club_Inscriptions::get();
		$url    = admin_url( 'admin.php?page=' . self::SLUG );
		$actif  = VHB_Club_Inscriptions::bandeau_actif( $saison ) && $saison === VHB_Club_Reglages::saison_en_cours();
		?>
		<div class="wrap vhbc-admin">
			<h1>Inscriptions</h1>
			<p class="description">Contenu des pages Tarifs et Inscription. Le tarif de chaque catégorie se saisit dans la catégorie (encadré « Inscription »).</p>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="vhbc-saison-pick">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<label for="vhbc-saison-pick"><strong>Saison</strong></label>
				<select id="vhbc-saison-pick" name="saison" onchange="this.form.submit()">
					<?php foreach ( array_reverse( VHB_Club_Reglages::saisons() ) as $x ) : ?>
						<option value="<?php echo esc_attr( $x['id'] ); ?>" <?php selected( $saison, $x['id'] ); ?>><?php echo esc_html( $x['id'] . ' (' . strtolower( VHB_Club_Reglages::STATUTS_SAISON[ $x['statut'] ] ?? '' ) . ')' ); ?></option>
					<?php endforeach; ?>
				</select>
				<noscript><button class="button">Afficher</button></noscript>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'vhbc_inscriptions_save' ); ?>
				<input type="hidden" name="action" value="vhbc_inscriptions_save">
				<input type="hidden" name="saison" value="<?php echo esc_attr( $saison ); ?>">

				<h2 class="vhbc-section">Saison <?php echo esc_html( $saison ); ?></h2>

				<h3>Période d'inscription et bandeau</h3>
				<table class="form-table vhbc-form" role="presentation">
					<tr><th scope="row">Bandeau</th><td>
						<label><input type="checkbox" name="saison_data[bandeau]" value="1" <?php checked( $s['bandeau'] ); ?>> Afficher le bandeau d'inscription (bloc « <?php echo esc_html( VHB_MARQUE ); ?> : bandeau inscriptions »)</label>
						<p class="description">Aujourd'hui : <strong><?php echo $actif ? 'visible' : 'masqué'; ?></strong>.</p>
					</td></tr>
					<tr><th scope="row">Période</th><td>
						<label>du <input type="date" name="saison_data[ouverture]" value="<?php echo esc_attr( $s['ouverture'] ); ?>"></label>
						<label>au <input type="date" name="saison_data[cloture]" value="<?php echo esc_attr( $s['cloture'] ); ?>"></label>
						<p class="description">Facultatif. Sans dates, le bandeau suit uniquement la case ci-dessus. Avec des dates, il s'allume et s'éteint tout seul.</p>
					</td></tr>
					<tr><th scope="row"><label for="vhbc-btexte">Texte du bandeau</label></th><td>
						<input type="text" id="vhbc-btexte" name="saison_data[texte]" value="<?php echo esc_attr( $s['texte'] ); ?>" class="regular-text">
					</td></tr>
					<tr><th scope="row"><label for="vhbc-blien">Lien du bouton</label></th><td>
						<input type="url" id="vhbc-blien" name="saison_data[lien]" value="<?php echo esc_attr( $s['lien'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( home_url( '/inscription/' ) ); ?>">
						<p class="description">Vide : pas de bouton.</p>
					</td></tr>
				</table>

				<h3>Autres licences</h3>
				<p class="description">Licences sans catégorie d'entraînement, affichées sous la grille tarifaire.</p>
				<?php
				self::repeat(
					'vhbc-autres',
					array( 'Licence', 'Années (facultatif)', 'Montant (€)', 'Mention à la place du montant' ),
					$s['autres'],
					static function ( $i, $r ) {
						$n = 'saison_data[autres][' . $i . ']';
						echo '<tr>';
						echo '<td><input type="text" name="' . esc_attr( $n . '[nom]' ) . '" value="' . esc_attr( $r['nom'] ) . '" placeholder="ex. Dirigeant (bénévole)"></td>';
						echo '<td><input type="text" name="' . esc_attr( $n . '[annees]' ) . '" value="' . esc_attr( $r['annees'] ) . '" placeholder="ex. 2009 et avant"></td>';
						echo '<td><input type="text" inputmode="decimal" class="small-text" name="' . esc_attr( $n . '[montant]' ) . '" value="' . esc_attr( $r['montant'] ) . '"></td>';
						echo '<td><input type="text" name="' . esc_attr( $n . '[mention]' ) . '" value="' . esc_attr( $r['mention'] ) . '" placeholder="ex. Offerte"></td>';
						echo self::del() . '</tr>'; // phpcs:ignore
					},
					array( 'nom' => '', 'annees' => '', 'montant' => '', 'mention' => '' )
				);
				?>

				<h3>Réductions et aides</h3>
				<?php
				self::repeat(
					'vhbc-reductions',
					array( 'Réduction', 'Montant', 'Unité', 'Précision' ),
					$s['reductions'],
					static function ( $i, $r ) {
						$n = 'saison_data[reductions][' . $i . ']';
						echo '<tr>';
						echo '<td><input type="text" name="' . esc_attr( $n . '[libelle]' ) . '" value="' . esc_attr( $r['libelle'] ) . '" placeholder="ex. Famille"></td>';
						echo '<td><input type="text" inputmode="decimal" class="small-text" name="' . esc_attr( $n . '[montant]' ) . '" value="' . esc_attr( $r['montant'] ) . '"></td>';
						echo '<td><select name="' . esc_attr( $n . '[unite]' ) . '">';
						foreach ( VHB_Club_Inscriptions::UNITES as $k => $v ) {
							echo '<option value="' . esc_attr( $k ) . '"' . selected( $r['unite'], $k, false ) . '>' . esc_html( $v ) . '</option>';
						}
						echo '</select></td>';
						echo '<td><input type="text" class="large-text" name="' . esc_attr( $n . '[precision]' ) . '" value="' . esc_attr( $r['precision'] ) . '" placeholder="ex. de moins pour chaque personne inscrite à partir de la 2e"></td>';
						echo self::del() . '</tr>'; // phpcs:ignore
					},
					array( 'libelle' => '', 'montant' => '', 'unite' => 'euro', 'precision' => '' )
				);
				?>

				<table class="form-table vhbc-form" role="presentation">
					<tr><th scope="row"><label for="vhbc-note">Mention sous les tarifs</label></th><td>
						<input type="text" id="vhbc-note" name="saison_data[note]" value="<?php echo esc_attr( $s['note'] ); ?>" class="large-text" placeholder="ex. Tarifs validés par l'Assemblée générale du 27 juin 2026.">
					</td></tr>
				</table>

				<h2 class="vhbc-section">Toutes saisons</h2>
				<table class="form-table vhbc-form" role="presentation">
					<tr><th scope="row"><label for="vhbc-pieces">Pièces à fournir</label></th><td>
						<textarea id="vhbc-pieces" name="commun[pieces]" rows="5" class="large-text"><?php echo esc_textarea( implode( "\n", $c['pieces'] ) ); ?></textarea>
						<p class="description">Une pièce par ligne.</p>
					</td></tr>
					<tr><th scope="row"><label for="vhbc-depot">Où déposer</label></th><td>
						<input type="text" id="vhbc-depot" name="commun[depot]" value="<?php echo esc_attr( $c['depot'] ); ?>" class="large-text">
					</td></tr>
					<tr><th scope="row"><label for="vhbc-paiement">Modes de paiement</label></th><td>
						<textarea id="vhbc-paiement" name="commun[paiement]" rows="4" class="large-text"><?php echo esc_textarea( implode( "\n", $c['paiement'] ) ); ?></textarea>
						<p class="description">Un mode par ligne.</p>
					</td></tr>
					<tr><th scope="row"><label for="vhbc-essai">Séances d'essai</label></th><td>
						<input type="text" id="vhbc-essai" name="commun[essai]" value="<?php echo esc_attr( $c['essai'] ); ?>" class="large-text">
					</td></tr>
					<tr><th scope="row"><label for="vhbc-equip">Équipement</label></th><td>
						<input type="text" id="vhbc-equip" name="commun[equipement]" value="<?php echo esc_attr( $c['equipement'] ); ?>" class="large-text">
						<p class="description">Pour toutes les pratiques. Pour une pratique différente, remplir sa ligne ci-dessous (vide : liste générale).</p>
						<?php foreach ( VHB_Club_Types::PRATIQUES as $k => $v ) : ?>
							<p class="vhbc-sub-field"><label><span><?php echo esc_html( $v ); ?></span>
								<input type="text" name="commun[equipement_pratique][<?php echo esc_attr( $k ); ?>]" value="<?php echo esc_attr( $c['equipement_pratique'][ $k ] ); ?>" class="regular-text"></label></p>
						<?php endforeach; ?>
					</td></tr>
				</table>

				<h3>Documents à télécharger</h3>
				<p class="description">Un fichier de la médiathèque ou un lien (par exemple vers le site de la FFHB, toujours à jour).</p>
				<?php
				self::repeat(
					'vhbc-documents',
					array( 'Titre', 'Fichier', 'ou lien' ),
					$c['documents'],
					static function ( $i, $d ) {
						$n    = 'commun[documents][' . $i . ']';
						$file = $d['fichier'] ? get_the_title( $d['fichier'] ) : '';
						echo '<tr>';
						echo '<td><input type="text" name="' . esc_attr( $n . '[titre]' ) . '" value="' . esc_attr( $d['titre'] ) . '" placeholder="ex. Certificat médical (modèle FFHB)"></td>';
						echo '<td class="vhbc-file"><input type="hidden" class="vhbc-file-id" name="' . esc_attr( $n . '[fichier]' ) . '" value="' . (int) $d['fichier'] . '">';
						echo '<span class="vhbc-file-name">' . esc_html( $file ) . '</span> <button type="button" class="button vhbc-file-pick">Choisir</button></td>';
						echo '<td><input type="url" name="' . esc_attr( $n . '[url]' ) . '" value="' . esc_attr( $d['url'] ) . '" placeholder="https://"></td>';
						echo self::del() . '</tr>'; // phpcs:ignore
					},
					array( 'titre' => '', 'fichier' => 0, 'url' => '' )
				);
				?>
				<?php submit_button( 'Enregistrer' ); ?>
			</form>
		</div>
		<?php
	}
}
