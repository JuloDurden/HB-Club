<?php
/**
 * Écrans « VHB Club > Organigramme » et « VHB Club > Vie du club ».
 */

defined( 'ABSPATH' ) || exit;

class VHB_Club_Vie_Admin {

	const ORGA = 'vhb-club-organigramme';
	const VIE  = 'vhb-club-vie';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 10 );
		add_action( 'admin_post_vhbc_orga_save', array( __CLASS__, 'save_orga' ) );
		add_action( 'admin_post_vhbc_vie_save', array( __CLASS__, 'save_vie' ) );
	}

	public static function menu() {
		add_submenu_page( VHB_Club_Admin_Slugs::MENU, 'Organigramme', 'Organigramme', VHB_Club_Reglages::CAP_DONNEES, self::ORGA, array( __CLASS__, 'page_orga' ) );
		add_submenu_page( VHB_Club_Admin_Slugs::MENU, 'Vie du club', 'Vie du club', VHB_Club_Reglages::CAP_DONNEES, self::VIE, array( __CLASS__, 'page_vie' ) );
	}

	private static function check( $action ) {
		if ( ! current_user_can( VHB_Club_Reglages::CAP_DONNEES ) ) {
			wp_die( 'Accès refusé.', 403 );
		}
		check_admin_referer( $action );
		return (array) wp_unslash( $_POST ); // phpcs:ignore
	}

	public static function save_orga() {
		$in = self::check( 'vhbc_orga_save' );
		VHB_Club_Vie::save( VHB_Club_Vie::sanitize_organigramme( $in, VHB_Club_Vie::get() ) );
		VHB_Club_Admin::notice( 'success', 'Organigramme enregistré. Le site est à jour.' );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::ORGA ) );
		exit;
	}

	public static function save_vie() {
		$in = self::check( 'vhbc_vie_save' );
		$o  = VHB_Club_Vie::sanitize_vie( $in, VHB_Club_Vie::get() );
		VHB_Club_Vie::save( $o );
		foreach ( $o['alertes'] as $a ) {
			if ( $a['debut'] && $a['fin'] && $a['fin'] < $a['debut'] ) {
				VHB_Club_Admin::notice( 'warning', 'Alerte « ' . esc_html( $a['message'] ) . ' » : la date de fin précède le début, elle ne s\'affichera jamais.' );
			}
		}
		if ( $o['boutique']['actif'] && '' === $o['boutique']['url'] ) {
			VHB_Club_Admin::notice( 'warning', 'Boutique activée sans lien : le bandeau reste masqué.' );
		}
		VHB_Club_Admin::notice( 'success', 'Vie du club enregistrée. Le site est à jour.' );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::VIE ) );
		exit;
	}

	private static function media_cell( $name, $id, $label ) {
		$thumb = $id ? wp_get_attachment_image_url( $id, 'thumbnail' ) : '';
		return '<td class="vhbc-file vhbc-file--img"><input type="hidden" class="vhbc-file-id" name="' . esc_attr( $name ) . '" value="' . (int) $id . '">'
			. '<span class="vhbc-file-name">' . ( $thumb ? '<img src="' . esc_url( $thumb ) . '" alt="">' : '' ) . '</span> '
			. '<button type="button" class="button vhbc-file-pick" data-image="1">' . esc_html( $label ) . '</button></td>';
	}


	private static function move() {
		return '<td class="vhbc-move"><button type="button" class="button-link vhbc-repeat-up" aria-label="Monter">&#8593;</button> <button type="button" class="button-link vhbc-repeat-down" aria-label="Descendre">&#8595;</button></td>';
	}

	/** Pastille « à venir », « aujourd'hui » ou « passée » d'une AG. */
	private static function statut_ag( $date ) {
		if ( '' === (string) $date ) {
			return '<br><span class="vhbc-pill vhbc-pill--preparation">sans date</span>';
		}
		$t = VHB_Club_Donnees::today();
		if ( $date === $t ) {
			return '<br><span class="vhbc-pill vhbc-pill--en_cours">aujourd\'hui</span>';
		}
		return $date > $t ? '<br><span class="vhbc-pill vhbc-pill--en_cours">à venir</span>' : '<br><span class="vhbc-pill vhbc-pill--off">passée</span>';
	}

	private static function select( $name, array $opts, $cur, $label = '' ) {
		$h = '<select name="' . esc_attr( $name ) . '"' . ( $label ? ' aria-label="' . esc_attr( $label ) . '" title="' . esc_attr( $label ) . '"' : '' ) . '>';
		foreach ( $opts as $k => $v ) {
			$h .= '<option value="' . esc_attr( $k ) . '"' . selected( $cur, $k, false ) . '>' . esc_html( $v ) . '</option>';
		}
		return $h . '</select>';
	}

	/* ------------------------------------------------------------------ */
	/* Organigramme                                                        */
	/* ------------------------------------------------------------------ */

	public static function page_orga() {
		if ( ! current_user_can( VHB_Club_Reglages::CAP_DONNEES ) ) {
			return;
		}
		$o = VHB_Club_Vie::get();
		?>
		<div class="wrap vhbc-admin">
			<h1>Organigramme</h1>
			<p class="description">Membres du bureau, du CA, des commissions et bénévoles, affichés par le bloc « <?php echo esc_html( VHB_MARQUE ); ?> : organigramme ». L'ordre des lignes est l'ordre d'affichage (flèches pour déplacer).</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'vhbc_orga_save' ); ?>
				<input type="hidden" name="action" value="vhbc_orga_save">
				<table class="form-table vhbc-form" role="presentation">
					<tr><th scope="row">Affichage des noms</th><td>
						<label><input type="radio" name="affichage_noms" value="complet" <?php checked( $o['affichage_noms'], 'complet' ); ?>> Nom complet (Camille Martin)</label><br>
						<label><input type="radio" name="affichage_noms" value="initiale" <?php checked( $o['affichage_noms'], 'initiale' ); ?>> Initiale du nom (Camille M.)</label>
						<p class="description">Sans photo, un rond avec les initiales s'affiche, comme sur la page actuelle. Photo : seulement avec l'accord de la personne.</p>
					</td></tr>
				</table>
				<?php
				VHB_Club_Inscriptions_Admin::repeat(
					'vhbc-personnes',
					array( 'Ordre', 'Prénom', 'Nom', 'Fonction', 'Groupe', 'Email de fonction (facultatif)', 'Photo (facultatif)' ),
					$o['personnes'],
					static function ( $i, $p ) {
						$n = 'personnes[' . $i . ']';
						echo '<tr>' . self::move(); // phpcs:ignore
						echo '<td><input type="text" name="' . esc_attr( $n . '[prenom]' ) . '" value="' . esc_attr( $p['prenom'] ) . '"></td>';
						echo '<td><input type="text" name="' . esc_attr( $n . '[nom]' ) . '" value="' . esc_attr( $p['nom'] ) . '"></td>';
						echo '<td><input type="text" name="' . esc_attr( $n . '[fonction]' ) . '" value="' . esc_attr( $p['fonction'] ) . '" placeholder="ex. Trésorière"></td>';
						echo '<td>' . self::select( $n . '[instance]', VHB_Club_Vie::INSTANCES, $p['instance'] ) . '</td>'; // phpcs:ignore
						echo '<td><input type="email" name="' . esc_attr( $n . '[email]' ) . '" value="' . esc_attr( $p['email'] ) . '"></td>';
						echo self::media_cell( $n . '[photo]', $p['photo'], 'Photo' ); // phpcs:ignore
						echo VHB_Club_Inscriptions_Admin::del() . '</tr>'; // phpcs:ignore
					},
					array( 'prenom' => '', 'nom' => '', 'fonction' => '', 'instance' => 'ca', 'email' => '', 'photo' => 0 )
				);
				submit_button( 'Enregistrer' );
				?>
			</form>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Vie du club                                                         */
	/* ------------------------------------------------------------------ */

	public static function page_vie() {
		if ( ! current_user_can( VHB_Club_Reglages::CAP_DONNEES ) ) {
			return;
		}
		$o = VHB_Club_Vie::get();
		$b = $o['boutique'];
		$m = $o['mentions'];
		$p = VHB_Club_Vie::president();
		$r = VHB_Club_Reglages::get();
		?>
		<div class="wrap vhbc-admin">
			<h1>Vie du club</h1>
			<p class="vhbc-sommaire">Aller à : <a href="#vhbc-s-alertes">Alertes</a> · <a href="#vhbc-s-ag">Assemblées générales</a> · <a href="#vhbc-s-boutique">Boutique</a> · <a href="#vhbc-s-partenaires">Partenaires et sponsors</a> · <a href="#vhbc-s-mentions">Mentions légales</a></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'vhbc_vie_save' ); ?>
				<input type="hidden" name="action" value="vhbc_vie_save">

				<h2 class="vhbc-section" id="vhbc-s-alertes">Alertes</h2>
				<p class="description">Fermeture du gymnase, entraînement annulé, vacances… Bloc « <?php echo esc_html( VHB_MARQUE ); ?> : alertes ». Une alerte s'affiche entre ses deux dates, puis disparaît seule.</p>
				<?php
				VHB_Club_Inscriptions_Admin::repeat(
					'vhbc-alertes',
					array( 'Message', 'Du', 'Au', 'Niveau', 'Lien (facultatif)' ),
					$o['alertes'],
					static function ( $i, $a ) {
						$n = 'alertes[' . $i . ']';
						echo '<tr><td><input type="text" name="' . esc_attr( $n . '[message]' ) . '" value="' . esc_attr( $a['message'] ) . '" placeholder="ex. Gymnase fermé pendant les vacances de Noël"></td>';
						echo '<td><input type="date" name="' . esc_attr( $n . '[debut]' ) . '" value="' . esc_attr( $a['debut'] ) . '"></td>';
						echo '<td><input type="date" name="' . esc_attr( $n . '[fin]' ) . '" value="' . esc_attr( $a['fin'] ) . '"></td>';
						echo '<td>' . self::select( $n . '[niveau]', VHB_Club_Vie::NIVEAUX_ALERTE, $a['niveau'] ) . '</td>'; // phpcs:ignore
						echo '<td><input type="url" name="' . esc_attr( $n . '[lien]' ) . '" value="' . esc_attr( $a['lien'] ) . '"></td>';
						echo VHB_Club_Inscriptions_Admin::del() . '</tr>'; // phpcs:ignore
					},
					array( 'message' => '', 'debut' => '', 'fin' => '', 'niveau' => 'info', 'lien' => '' )
				);
				?>

				<h2 class="vhbc-section" id="vhbc-s-ag">Assemblées générales</h2>
				<p class="description">Bloc « <?php echo esc_html( VHB_MARQUE ); ?> : assemblées générales ». Les AG à venir s'affichent avec leur date ; les AG passées gardent leur document (compte rendu). Classées par date à l'enregistrement, la plus récente en haut. La dernière AG grand public passée date les tarifs de la page S'inscrire.</p>
				<?php
				VHB_Club_Inscriptions_Admin::repeat(
					'vhbc-ag',
					array( 'Type', 'Date et heure', 'Titre et lieu', 'Précision', 'Compte rendu (document ou lien)' ),
					VHB_Club_Vie::trier_ag( $o['ag'] ),
					static function ( $i, $a ) {
						$n = 'ag[' . $i . ']';
						echo '<tr><td>' . self::select( $n . '[type]', VHB_Club_Vie::TYPES_AG, $a['type'] ) . self::statut_ag( $a['date'] ) . '</td>'; // phpcs:ignore
						echo '<td><input type="date" name="' . esc_attr( $n . '[date]' ) . '" value="' . esc_attr( $a['date'] ) . '" aria-label="Date">';
						echo '<input type="text" class="vhbc-sous" name="' . esc_attr( $n . '[heure]' ) . '" value="' . esc_attr( $a['heure'] ) . '" placeholder="Heure (ex. 18h30)" aria-label="Heure"></td>';
						echo '<td><input type="text" name="' . esc_attr( $n . '[titre]' ) . '" value="' . esc_attr( $a['titre'] ) . '" placeholder="Titre (ex. Assemblée générale 2027)" aria-label="Titre">';
						echo '<input type="text" class="vhbc-sous" name="' . esc_attr( $n . '[lieu]' ) . '" value="' . esc_attr( $a['lieu'] ) . '" placeholder="Lieu" aria-label="Lieu"></td>';
						echo '<td><textarea name="' . esc_attr( $n . '[description]' ) . '" rows="2" placeholder="ex. vote des tarifs" aria-label="Précision">' . esc_textarea( $a['description'] ) . '</textarea></td>';
						echo '<td class="vhbc-ag-cr"><span class="vhbc-file"><input type="hidden" class="vhbc-file-id" name="' . esc_attr( $n . '[fichier]' ) . '" value="' . (int) $a['fichier'] . '">'
							. '<span class="vhbc-file-name">' . esc_html( $a['fichier'] ? get_the_title( $a['fichier'] ) : '' ) . '</span> <button type="button" class="button vhbc-file-pick">Choisir</button></span>';
						echo '<input type="url" class="vhbc-sous" name="' . esc_attr( $n . '[url]' ) . '" value="' . esc_attr( $a['url'] ) . '" placeholder="ou lien https://" aria-label="Lien du compte rendu"></td>';
						echo VHB_Club_Inscriptions_Admin::del() . '</tr>'; // phpcs:ignore
					},
					array( 'type' => 'publique', 'titre' => '', 'date' => '', 'heure' => '', 'lieu' => '', 'description' => '', 'fichier' => 0, 'url' => '' )
				);
				?>

				<h2 class="vhbc-section" id="vhbc-s-boutique">Boutique</h2>
				<table class="form-table vhbc-form" role="presentation">
					<tr><th scope="row">Bandeau</th><td>
						<label><input type="checkbox" name="boutique[actif]" value="1" <?php checked( $b['actif'] ); ?>> Afficher le bandeau boutique (bloc « <?php echo esc_html( VHB_MARQUE ); ?> : boutique »)</label>
					</td></tr>
					<tr><th scope="row"><label for="vhbc-bt">Titre</label></th><td><input type="text" id="vhbc-bt" name="boutique[titre]" value="<?php echo esc_attr( $b['titre'] ); ?>" class="regular-text"></td></tr>
					<tr><th scope="row"><label for="vhbc-bx">Texte</label></th><td><input type="text" id="vhbc-bx" name="boutique[texte]" value="<?php echo esc_attr( $b['texte'] ); ?>" class="large-text" placeholder="ex. Maillots, sweats et gourdes aux couleurs du club"></td></tr>
					<tr><th scope="row"><label for="vhbc-bu">Lien</label></th><td><input type="url" id="vhbc-bu" name="boutique[url]" value="<?php echo esc_attr( $b['url'] ); ?>" class="large-text" placeholder="https://"></td></tr>
					<tr><th scope="row">Période de commande</th><td>
						<label>du <input type="date" name="boutique[debut]" value="<?php echo esc_attr( $b['debut'] ); ?>"></label>
						<label>au <input type="date" name="boutique[fin]" value="<?php echo esc_attr( $b['fin'] ); ?>"></label>
						<p class="description">Facultatif.</p>
					</td></tr>
				</table>

				<h2 class="vhbc-section" id="vhbc-s-partenaires">Partenaires et sponsors</h2>
				<p class="description">Bloc « <?php echo esc_html( VHB_MARQUE ); ?> : partenaires » et pied de page du site (données des pages). Logos de la médiathèque, de préférence sur fond transparent. Dans le pied de page, chaque logo est affiché en blanc, dans une case de taille fixe : « Logo en blanc » dit comment le blanchir (silhouette blanche pour un logo sur fond transparent, inversé pour un logo sur fond blanc), « Taille » l'agrandit dans sa case si besoin.</p>
				<?php
				VHB_Club_Inscriptions_Admin::repeat(
					'vhbc-partenaires',
					array( 'Ordre', 'Nom', 'Logo', 'Site web', 'Niveau', 'Logo en blanc', 'Taille (hauteur, agrandissement)' ),
					$o['partenaires'],
					static function ( $i, $p ) {
						$n = 'partenaires[' . $i . ']';
						echo '<tr>' . self::move(); // phpcs:ignore
						echo '<td><input type="text" name="' . esc_attr( $n . '[nom]' ) . '" value="' . esc_attr( $p['nom'] ) . '"></td>';
						echo self::media_cell( $n . '[logo]', $p['logo'], 'Logo' ); // phpcs:ignore
						echo '<td><input type="url" name="' . esc_attr( $n . '[url]' ) . '" value="' . esc_attr( $p['url'] ) . '"></td>';
						echo '<td>' . self::select( $n . '[niveau]', VHB_Club_Vie::NIVEAUX, $p['niveau'] ) . '</td>'; // phpcs:ignore
						echo '<td>' . self::select( $n . '[rendu]', VHB_Club_Vie::RENDUS_LOGO, $p['rendu'] ?? 'silhouette' ) . '</td>'; // phpcs:ignore
						echo '<td class="vhbc-taille">' . self::select( $n . '[hauteur]', VHB_Club_Vie::HAUTEURS_LOGO, $p['hauteur'] ?? 'normale', 'Hauteur du logo' ) . self::select( $n . '[zoom]', VHB_Club_Vie::ZOOMS_LOGO, (string) ( $p['zoom'] ?? '100' ), 'Agrandissement du logo' ) . '</td>'; // phpcs:ignore
						echo VHB_Club_Inscriptions_Admin::del() . '</tr>'; // phpcs:ignore
					},
					array( 'nom' => '', 'logo' => 0, 'url' => '', 'niveau' => 'partenaire', 'rendu' => 'silhouette', 'hauteur' => 'normale', 'zoom' => '100' )
				);
				?>

				<h2 class="vhbc-section" id="vhbc-s-mentions">Mentions légales</h2>
				<p class="description">Bloc « <?php echo esc_html( VHB_MARQUE ); ?> : mentions légales », à poser sur une page dédiée. Le nom, le siège et les emails viennent des Réglages du club ; le directeur de la publication est par défaut la personne « Président(e) » de l'organigramme.</p>
				<table class="form-table vhbc-form" role="presentation">
					<tr><th scope="row"><label for="vhbc-rna">N° RNA</label></th><td><input type="text" id="vhbc-rna" name="mentions[rna]" value="<?php echo esc_attr( $m['rna'] ); ?>" class="regular-text" placeholder="W..."></td></tr>
					<tr><th scope="row"><label for="vhbc-siret">SIRET</label></th><td><input type="text" id="vhbc-siret" name="mentions[siret]" value="<?php echo esc_attr( $m['siret'] ); ?>" class="regular-text"><p class="description">Facultatif.</p></td></tr>
					<tr><th scope="row"><label for="vhbc-dir">Directeur de la publication</label></th><td><input type="text" id="vhbc-dir" name="mentions[directeur]" value="<?php echo esc_attr( $m['directeur'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( $p ? VHB_Club_Vie::nom( $p, 'complet' ) . ', ' . $p['fonction'] : '' ); ?>"><p class="description">Vide : la présidente ou le président de l'organigramme.</p></td></tr>
					<tr><th scope="row"><label for="vhbc-heb">Hébergeur</label></th><td><input type="text" id="vhbc-heb" name="mentions[hebergeur]" value="<?php echo esc_attr( $m['hebergeur'] ); ?>" class="regular-text" placeholder="Nom de la société"></td></tr>
					<tr><th scope="row"><label for="vhbc-heba">Adresse de l'hébergeur</label></th><td><input type="text" id="vhbc-heba" name="mentions[hebergeur_adr]" value="<?php echo esc_attr( $m['hebergeur_adr'] ); ?>" class="large-text"></td></tr>
					<tr><th scope="row"><label for="vhbc-rgc">Contact données personnelles</label></th><td><input type="email" id="vhbc-rgc" name="mentions[contact_rgpd]" value="<?php echo esc_attr( $m['contact_rgpd'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( $r['email'] ); ?>"><p class="description">Vide : l'email du secrétariat.</p></td></tr>
					<tr><th scope="row"><label for="vhbc-rgpd">Texte données personnelles</label></th><td><textarea id="vhbc-rgpd" name="mentions[rgpd]" rows="5" class="large-text" placeholder="Vide : texte type fourni par l'extension (à faire relire)."><?php echo esc_textarea( $m['rgpd'] ); ?></textarea></td></tr>
				</table>
				<?php submit_button( 'Enregistrer' ); ?>
			</form>
		</div>
		<?php
	}
}
