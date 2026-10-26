<?php
/**
 * Écrans de saisie : catégories (avec leurs créneaux), lieux, clubs en entente.
 * Contrôles à l'enregistrement, avec un message clair pour le bénévole.
 */

defined( 'ABSPATH' ) || exit;

class VHB_Club_Metaboxes {

	const NONCE = 'vhbc_save';

	public static function init() {
		add_action( 'add_meta_boxes_' . VHB_Club_Types::CATEGORIE, array( __CLASS__, 'boxes_categorie' ) );
		add_action( 'add_meta_boxes_' . VHB_Club_Types::LIEU, array( __CLASS__, 'boxes_lieu' ) );
		add_action( 'add_meta_boxes_' . VHB_Club_Types::ENTENTE, array( __CLASS__, 'boxes_entente' ) );
		add_action( 'save_post_' . VHB_Club_Types::CATEGORIE, array( __CLASS__, 'save_categorie' ), 5, 2 );
		add_action( 'save_post_' . VHB_Club_Types::LIEU, array( __CLASS__, 'save_lieu' ), 5, 2 );
		add_action( 'save_post_' . VHB_Club_Types::ENTENTE, array( __CLASS__, 'save_entente' ), 5, 2 );
	}

	private static function can_save( $post_id, $post ) {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || 'auto-draft' === $post->post_status ) {
			return false;
		}
		if ( ! isset( $_POST['vhbc_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['vhbc_nonce'] ) ), self::NONCE ) ) {
			return false;
		}
		return current_user_can( VHB_Club_Reglages::CAP_DONNEES );
	}

	private static function post( $key, $default = '' ) {
		return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $default; // phpcs:ignore
	}

	/** « 9h », « 9:5 », « 18h15 » deviennent « 09:00 », « 09:05 », « 18:15 ». */
	public static function norm_heure( $v ) {
		$v = strtolower( trim( (string) $v ) );
		if ( preg_match( '/^(\d{1,2})\s*[h:]\s*(\d{0,2})$/', $v, $m ) ) {
			$h  = (int) $m[1];
			$mi = '' === $m[2] ? 0 : (int) $m[2];
			if ( $h <= 23 && $mi <= 59 ) {
				return sprintf( '%02d:%02d', $h, $mi );
			}
		}
		return '';
	}

	/* ------------------------------------------------------------------ */
	/* Catégories                                                          */
	/* ------------------------------------------------------------------ */

	public static function boxes_categorie() {
		remove_meta_box( 'slugdiv', VHB_Club_Types::CATEGORIE, 'normal' );
		add_meta_box( 'vhbc_cat_id', 'Catégorie', array( __CLASS__, 'box_cat_identite' ), VHB_Club_Types::CATEGORIE, 'normal', 'high' );
		add_meta_box( 'vhbc_cat_cren', 'Entraînements', array( __CLASS__, 'box_cat_creneaux' ), VHB_Club_Types::CATEGORIE, 'normal', 'high' );
		add_meta_box( 'vhbc_cat_insc', 'Inscription', array( __CLASS__, 'box_cat_inscription' ), VHB_Club_Types::CATEGORIE, 'normal', 'high' );
		add_meta_box( 'vhbc_cat_desc', 'Description', array( __CLASS__, 'box_cat_description' ), VHB_Club_Types::CATEGORIE, 'normal', 'default' );
		add_meta_box( 'vhbc_cat_equipe', 'Encadrement et maillots', array( __CLASS__, 'box_cat_equipe' ), VHB_Club_Types::CATEGORIE, 'normal', 'default' );
		add_meta_box( 'vhbc_cat_liens', 'Entente et équipes FFHB', array( __CLASS__, 'box_cat_liens' ), VHB_Club_Types::CATEGORIE, 'side', 'default' );
	}

	public static function box_cat_identite( $post ) {
		wp_nonce_field( self::NONCE, 'vhbc_nonce' );
		$c      = VHB_Club_Donnees::categorie( $post->ID );
		$saison = VHB_Club_Types::meta( $post->ID, 'saison', VHB_Club_Reglages::saison_en_cours() );
		$d      = VHB_Club_Reglages::debut( $saison );
		$avant  = null !== $c['age_min'] && null === $c['age_max'];
		$y_from = null !== $c['age_max'] ? $d - $c['age_max'] : '';
		$y_to   = null !== $c['age_min'] ? $d - $c['age_min'] : '';
		$saisons = VHB_Club_Reglages::saisons();
		if ( ! in_array( $saison, wp_list_pluck( $saisons, 'id' ), true ) ) {
			$saisons[] = array( 'id' => $saison, 'statut' => '' );
		}
		?>
		<table class="form-table vhbc-form" role="presentation">
			<tr><th scope="row"><label for="vhbc-saison">Saison</label></th><td>
				<select id="vhbc-saison" name="vhbc_saison">
					<?php foreach ( array_reverse( $saisons ) as $s ) : ?>
						<option value="<?php echo esc_attr( $s['id'] ); ?>" <?php selected( $saison, $s['id'] ); ?>><?php echo esc_html( $s['id'] . ( $s['statut'] ? ' (' . strtolower( VHB_Club_Reglages::STATUTS_SAISON[ $s['statut'] ] ) . ')' : '' ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</td></tr>
			<tr><th scope="row"><label for="vhbc-code">Code</label></th><td>
				<input type="text" id="vhbc-code" name="vhbc_code" value="<?php echo esc_attr( $c['code'] ); ?>" class="regular-text code" placeholder="ex. u13-f">
				<p class="description">Identifiant court utilisé par les blocs et shortcodes, identique d'une saison à l'autre. Vide : déduit du nom.</p>
			</td></tr>
			<tr><th scope="row"><label for="vhbc-pratique">Pratique</label></th><td>
				<select id="vhbc-pratique" name="vhbc_pratique">
					<?php foreach ( VHB_Club_Types::PRATIQUES as $k => $v ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $c['pratique'], $k ); ?>><?php echo esc_html( $v ); ?></option>
					<?php endforeach; ?>
				</select>
				<label for="vhbc-genre" class="vhbc-inline-label">Public</label>
				<select id="vhbc-genre" name="vhbc_genre">
					<option value="">(non précisé)</option>
					<?php foreach ( VHB_Club_Types::GENRES as $k => $v ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $c['genre'], $k ); ?>><?php echo esc_html( $v ); ?></option>
					<?php endforeach; ?>
				</select>
			</td></tr>
			<tr><th scope="row">Années de naissance</th><td>
				<div class="vhbc-annees" data-debut="<?php echo (int) $d; ?>">
					<label>de <input type="number" name="vhbc_annee_de" value="<?php echo esc_attr( $y_from ); ?>" min="1900" max="2100" class="small-text vhbc-annee-de"<?php disabled( $avant ); ?>></label>
					<label>à <input type="number" name="vhbc_annee_a" value="<?php echo esc_attr( $y_to ); ?>" min="1900" max="2100" class="small-text"></label>
					<label class="vhbc-avant"><input type="checkbox" name="vhbc_et_avant" value="1" <?php checked( $avant ); ?>> et avant</label>
				</div>
				<p class="description">Pour la saison choisie ci-dessus. À la saison suivante, les années se décalent toutes seules (U13 : 2014-2015, puis 2015-2016). Cocher « et avant » pour les adultes (ex. 2009 et avant).</p>
			</td></tr>
			<tr><th scope="row"><label for="vhbc-ordre">Ordre d'affichage</label></th><td>
				<input type="number" id="vhbc-ordre" name="vhbc_ordre" value="<?php echo (int) $post->menu_order; ?>" class="small-text" step="1">
				<p class="description">Les plus petits nombres s'affichent en premier dans le planning.</p>
			</td></tr>
		</table>
		<?php
	}

	public static function box_cat_creneaux( $post ) {
		$list  = VHB_Club_Donnees::creneaux_tries( (array) VHB_Club_Types::meta( $post->ID, 'creneaux', array() ) );
		$lieux = VHB_Club_Donnees::lieux();
		if ( ! $list ) {
			$list = array( array( 'jour' => '', 'debut' => '', 'fin' => '', 'lieu' => 0, 'periode' => 'saison', 'note' => '' ) );
		}
		?>
		<?php if ( ! $lieux ) : ?>
			<div class="notice notice-warning inline"><p>Aucun gymnase enregistré. <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . VHB_Club_Types::LIEU ) ); ?>">Ajouter un gymnase</a> avant de saisir les créneaux.</p></div>
		<?php endif; ?>
		<table class="widefat vhbc-creneaux">
			<thead><tr><th>Jour</th><th>Début</th><th>Fin</th><th>Gymnase</th><th>Période</th><th>Précision (facultatif)</th><th><span class="screen-reader-text">Retirer</span></th></tr></thead>
			<tbody class="vhbc-creneaux-rows">
			<?php
			foreach ( $list as $i => $s ) {
				self::creneau_row( $i, $s, $lieux );
			}
			?>
			</tbody>
		</table>
		<script type="text/html" id="tmpl-vhbc-creneau"><?php self::creneau_row( '__i__', array( 'jour' => '', 'debut' => '', 'fin' => '', 'lieu' => 0, 'periode' => 'saison', 'note' => '' ), $lieux ); ?></script>
		<p><button type="button" class="button vhbc-add-creneau">Ajouter un créneau</button></p>
		<p class="description">Les créneaux s'affichent triés par jour puis par heure. Une ligne sans jour ou sans heure de début est ignorée.</p>
		<?php
	}

	private static function creneau_row( $i, array $s, array $lieux ) {
		$n = 'vhbc_creneaux[' . $i . ']';
		echo '<tr class="vhbc-creneau">';
		echo '<td><select name="' . esc_attr( $n . '[jour]' ) . '" aria-label="Jour"><option value="">-</option>';
		foreach ( VHB_Club_Types::JOURS as $k => $v ) {
			echo '<option value="' . (int) $k . '"' . selected( (int) $s['jour'], $k, false ) . '>' . esc_html( $v ) . '</option>';
		}
		echo '</select></td>';
		echo '<td><input type="time" step="300" name="' . esc_attr( $n . '[debut]' ) . '" value="' . esc_attr( $s['debut'] ) . '" aria-label="Début"></td>';
		echo '<td><input type="time" step="300" name="' . esc_attr( $n . '[fin]' ) . '" value="' . esc_attr( $s['fin'] ) . '" aria-label="Fin"></td>';
		echo '<td><select name="' . esc_attr( $n . '[lieu]' ) . '" aria-label="Gymnase"><option value="0">(choisir)</option>';
		foreach ( $lieux as $l ) {
			echo '<option value="' . (int) $l['id'] . '"' . selected( (int) $s['lieu'], $l['id'], false ) . '>' . esc_html( $l['nom_court'] . ' · ' . $l['nom'] ) . '</option>';
		}
		echo '</select></td>';
		echo '<td><select name="' . esc_attr( $n . '[periode]' ) . '" aria-label="Période">';
		foreach ( VHB_Club_Types::PERIODES as $k => $v ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $s['periode'], $k, false ) . '>' . esc_html( $v ) . '</option>';
		}
		echo '</select></td>';
		echo '<td><input type="text" name="' . esc_attr( $n . '[note]' ) . '" value="' . esc_attr( $s['note'] ) . '" placeholder="ex. gardiens" aria-label="Précision"></td>';
		echo '<td><button type="button" class="button-link vhbc-del-creneau" aria-label="Retirer ce créneau">Retirer</button></td>';
		echo '</tr>';
	}

	public static function box_cat_inscription( $post ) {
		$c = VHB_Club_Donnees::categorie( $post->ID );
		?>
		<table class="form-table vhbc-form" role="presentation">
			<tr><th scope="row"><label for="vhbc-tarif">Tarif de la licence</label></th><td>
				<input type="text" inputmode="decimal" id="vhbc-tarif" name="vhbc_tarif" value="<?php echo esc_attr( '' === $c['tarif'] ? '' : str_replace( '.', ',', $c['tarif'] ) ); ?>" class="small-text"> €
				<p class="description">Pour la saison de la catégorie. Les réductions (famille, Pass'Sport) se règlent dans <?php echo esc_html( VHB_MARQUE ); ?> &gt; Inscriptions.</p>
			</td></tr>
			<tr><th scope="row"><label for="vhbc-inclus">Inclus dans la cotisation</label></th><td>
				<input type="text" id="vhbc-inclus" name="vhbc_inclus" value="<?php echo esc_attr( $c['inclus'] ); ?>" class="large-text" placeholder="ex. maillot de match, tee-shirt d'entraînement, short">
				<p class="description">Facultatif. Change chaque année selon le vote de l'AG.</p>
			</td></tr>
			<tr><th scope="row"><label for="vhbc-statut">Statut des inscriptions</label></th><td>
				<select id="vhbc-statut" name="vhbc_statut">
					<?php foreach ( VHB_Club_Inscriptions::STATUTS_CATEGORIE as $k => $v ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $c['statut'], $k ); ?>><?php echo esc_html( $v ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description">Facultatif. « Complet » ou « Liste d'attente » s'affiche à côté de la catégorie dans le planning, la fiche et les tarifs.</p>
			</td></tr>
		</table>
		<?php
	}

	public static function box_cat_description( $post ) {
		$v = VHB_Club_Types::meta( $post->ID, 'description' );
		echo '<textarea name="vhbc_description" rows="3" class="large-text" maxlength="600">' . esc_textarea( $v ) . '</textarea>';
		echo '<p class="description">Texte court affiché dans la fiche de la catégorie (bloc « ' . VHB_MARQUE . ' : fiche catégorie »). Facultatif.</p>';
	}

	/** Encadrement et maillots (page « Nos équipes »). */
	public static function box_cat_equipe( $post ) {
		$enc  = (string) VHB_Club_Types::meta( $post->ID, 'encadrement' );
		$m    = array_values( (array) VHB_Club_Types::meta( $post->ID, 'maillots', array() ) );
		$note = (string) VHB_Club_Types::meta( $post->ID, 'maillots_note' );
		?>
		<table class="form-table vhbc-form" role="presentation">
			<tr><th scope="row"><label for="vhbc-encadrement">Encadrement</label></th><td>
				<input type="text" id="vhbc-encadrement" name="vhbc_encadrement" value="<?php echo esc_attr( $enc ); ?>" class="large-text" placeholder="ex. Éducateurs et éducatrices du club">
				<p class="description">Facultatif. Affiché dans la fiche de l'équipe (page « Nos équipes »). Un nom par entraîneur, séparés par des virgules, ou un texte général.</p>
			</td></tr>
			<tr><th scope="row">Maillots</th><td>
				<?php for ( $i = 0; $i < 4; $i++ ) : $k = $m[ $i ] ?? array( 'n' => '', 'c' => '' ); ?>
					<p class="vhbc-maillot">
						<input type="text" name="vhbc_maillots[<?php echo (int) $i; ?>][n]" value="<?php echo esc_attr( $k['n'] ?? '' ); ?>" placeholder="ex. Domicile" aria-label="Nom du maillot <?php echo (int) ( $i + 1 ); ?>">
						<input type="color" name="vhbc_maillots[<?php echo (int) $i; ?>][c]" value="<?php echo esc_attr( ! empty( $k['c'] ) ? $k['c'] : '#000000' ); ?>" aria-label="Couleur du maillot <?php echo (int) ( $i + 1 ); ?>">
						<label><input type="checkbox" name="vhbc_maillots[<?php echo (int) $i; ?>][on]" value="1" <?php checked( ! empty( $k['c'] ) ); ?>> affiché</label>
					</p>
				<?php endfor; ?>
				<p><label for="vhbc-maillots-note">Précision</label><br><input type="text" id="vhbc-maillots-note" name="vhbc_maillots_note" value="<?php echo esc_attr( $note ); ?>" class="large-text" placeholder="ex. Short et chaussettes marine."></p>
				<p class="description">Jusqu'à 4 maillots, dans l'ordre d'affichage. Seuls les maillots cochés « affiché » apparaissent sur le site.</p>
			</td></tr>
		</table>
		<?php
	}

	public static function box_cat_liens( $post ) {
		$c        = VHB_Club_Donnees::categorie( $post->ID );
		$ententes = VHB_Club_Donnees::ententes();
		echo '<p><strong>En entente avec</strong></p>';
		if ( ! $ententes ) {
			echo '<p class="description">Aucun club en entente enregistré.</p>';
		}
		foreach ( $ententes as $e ) {
			echo '<label class="vhbc-check"><input type="checkbox" name="vhbc_ententes[]" value="' . (int) $e['id'] . '"' . checked( in_array( $e['id'], $c['ententes'], true ), true, false ) . '> ' . esc_html( $e['nom'] ) . '</label>';
		}
		$equipes = VHB_Club_Donnees::equipes_ffhb();
		echo '<p><strong>Équipes des Résultats FFHB</strong></p>';
		if ( ! $equipes ) {
			echo '<p class="description">Module Résultats FFHB non chargé.</p>';
			foreach ( $c['equipes'] as $slug ) {
				echo '<input type="hidden" name="vhbc_equipes[]" value="' . esc_attr( $slug ) . '">';
			}
			return;
		}
		foreach ( $equipes as $slug => $nom ) {
			echo '<label class="vhbc-check"><input type="checkbox" name="vhbc_equipes[]" value="' . esc_attr( $slug ) . '"' . checked( in_array( $slug, $c['equipes'], true ), true, false ) . '> ' . esc_html( $nom ) . '</label>';
		}
		echo '<p class="description">Servira à relier la catégorie à ses matchs.</p>';
	}

	public static function save_categorie( $post_id, $post ) {
		if ( ! self::can_save( $post_id, $post ) ) {
			return;
		}
		$saison = sanitize_text_field( self::post( 'vhbc_saison' ) );
		if ( ! VHB_Club_Reglages::valid_id( $saison ) ) {
			$saison = VHB_Club_Reglages::saison_en_cours();
		}
		VHB_Club_Types::set_meta( $post_id, 'saison', $saison );

		// Code unique dans la saison.
		$code = sanitize_title( self::post( 'vhbc_code' ) );
		if ( '' === $code ) {
			$code = sanitize_title( $post->post_title );
		}
		if ( '' !== $code ) {
			$base = $code;
			$n    = 2;
			while ( self::code_pris( $code, $saison, $post_id ) ) {
				$code = $base . '-' . $n++;
			}
			if ( $code !== $base ) {
				VHB_Club_Admin::notice( 'warning', 'Le code « ' . esc_html( $base ) . ' » est déjà utilisé par une autre catégorie de la saison ' . esc_html( $saison ) . ' : cette catégorie a reçu le code « ' . esc_html( $code ) . ' ».' );
			}
		}
		VHB_Club_Types::set_meta( $post_id, 'code', $code );

		$p = sanitize_key( self::post( 'vhbc_pratique' ) );
		VHB_Club_Types::set_meta( $post_id, 'pratique', isset( VHB_Club_Types::PRATIQUES[ $p ] ) ? $p : 'competition' );
		$g = sanitize_key( self::post( 'vhbc_genre' ) );
		VHB_Club_Types::set_meta( $post_id, 'genre', isset( VHB_Club_Types::GENRES[ $g ] ) ? $g : '' );

		// Années saisies, âges stockés.
		$d     = VHB_Club_Reglages::debut( $saison );
		$de    = (int) self::post( 'vhbc_annee_de' );
		$a     = (int) self::post( 'vhbc_annee_a' );
		$avant = '1' === self::post( 'vhbc_et_avant' );
		if ( $avant ) {
			$de = 0;
		}
		if ( $de && $a && $de > $a ) {
			list( $de, $a ) = array( $a, $de );
		}
		if ( ! $avant && $de && ! $a ) {
			$a = $de;
		}
		VHB_Club_Types::set_meta( $post_id, 'age_min', $a ? (string) ( $d - $a ) : '' );
		VHB_Club_Types::set_meta( $post_id, 'age_max', $de ? (string) ( $d - $de ) : '' );

		VHB_Club_Types::set_meta( $post_id, 'description', sanitize_textarea_field( self::post( 'vhbc_description' ) ) );
		VHB_Club_Types::set_meta( $post_id, 'encadrement', sanitize_text_field( self::post( 'vhbc_encadrement' ) ) );
		$maillots = array();
		foreach ( (array) self::post( 'vhbc_maillots', array() ) as $row ) {
			$nom = sanitize_text_field( $row['n'] ?? '' );
			$col = sanitize_hex_color( $row['c'] ?? '' );
			if ( ! empty( $row['on'] ) && $col ) {
				$maillots[] = array( 'n' => $nom, 'c' => strtoupper( $col ) );
			}
		}
		VHB_Club_Types::set_meta( $post_id, 'maillots', $maillots );
		VHB_Club_Types::set_meta( $post_id, 'maillots_note', sanitize_text_field( self::post( 'vhbc_maillots_note' ) ) );

		$tarif = str_replace( array( ' ', '€', ',' ), array( '', '', '.' ), trim( (string) self::post( 'vhbc_tarif' ) ) );
		if ( '' !== $tarif && ( ! is_numeric( $tarif ) || (float) $tarif < 0 ) ) {
			VHB_Club_Admin::notice( 'warning', 'Tarif non reconnu (« ' . esc_html( self::post( 'vhbc_tarif' ) ) . ' ») : saisir un montant en euros, par exemple 229.' );
			$tarif = (string) VHB_Club_Types::meta( $post_id, 'tarif' );
		}
		VHB_Club_Types::set_meta( $post_id, 'tarif', '' === $tarif ? '' : (string) ( 0 + $tarif ) );
		VHB_Club_Types::set_meta( $post_id, 'inclus', sanitize_text_field( self::post( 'vhbc_inclus' ) ) );
		$st = sanitize_key( self::post( 'vhbc_statut' ) );
		VHB_Club_Types::set_meta( $post_id, 'statut_inscription', isset( VHB_Club_Inscriptions::STATUTS_CATEGORIE[ $st ] ) ? $st : '' );

		$ent = array_values( array_filter( array_map( 'absint', (array) self::post( 'vhbc_ententes', array() ) ) ) );
		VHB_Club_Types::set_meta( $post_id, 'ententes', $ent );
		$eq = array_values( array_filter( array_map( 'sanitize_key', (array) self::post( 'vhbc_equipes', array() ) ) ) );
		VHB_Club_Types::set_meta( $post_id, 'equipes_ffhb', $eq );

		// Créneaux.
		$out = array();
		$vus = array();
		$num = 0;
		foreach ( (array) self::post( 'vhbc_creneaux', array() ) as $k => $row ) {
			if ( '__i__' === (string) $k || ! is_array( $row ) ) {
				continue;
			}
			$jour  = (int) ( $row['jour'] ?? 0 );
			$debut = self::norm_heure( $row['debut'] ?? '' );
			$fin   = self::norm_heure( $row['fin'] ?? '' );
			$lieu  = absint( $row['lieu'] ?? 0 );
			$note  = sanitize_text_field( $row['note'] ?? '' );
			if ( ! $jour && '' === $debut && '' === $fin && ! $lieu && '' === $note ) {
				continue; // Ligne vide.
			}
			++$num;
			if ( ! isset( VHB_Club_Types::JOURS[ $jour ] ) || '' === $debut ) {
				VHB_Club_Admin::notice( 'error', 'Créneau n° ' . $num . ' ignoré : jour ou heure de début manquant.' );
				continue;
			}
			$label = VHB_Club_Types::JOURS[ $jour ] . ' ' . VHB_Club_Donnees::heure( $debut );
			if ( '' === $fin || $fin <= $debut ) {
				VHB_Club_Admin::notice( 'warning', 'Créneau du ' . esc_html( strtolower( $label ) ) . ' : heure de fin manquante ou avant le début. À corriger.' );
			}
			if ( ! $lieu ) {
				VHB_Club_Admin::notice( 'warning', 'Créneau du ' . esc_html( strtolower( $label ) ) . ' : aucun gymnase choisi.' );
			}
			$cle = $jour . '|' . $debut . '|' . $fin . '|' . $lieu;
			if ( isset( $vus[ $cle ] ) ) {
				VHB_Club_Admin::notice( 'warning', 'Créneau du ' . esc_html( strtolower( $label ) ) . ' en double : supprimé.' );
				continue;
			}
			$vus[ $cle ] = true;
			$per         = sanitize_key( $row['periode'] ?? 'saison' );
			$out[]       = array(
				'jour'    => $jour,
				'debut'   => $debut,
				'fin'     => $fin,
				'lieu'    => $lieu,
				'periode' => isset( VHB_Club_Types::PERIODES[ $per ] ) ? $per : 'saison',
				'note'    => $note,
			);
		}
		VHB_Club_Types::set_meta( $post_id, 'creneaux', VHB_Club_Donnees::creneaux_tries( $out ) );

		// Ordre d'affichage (menu_order) sans relancer save_post en boucle.
		$ordre = (int) self::post( 'vhbc_ordre', 0 );
		if ( (int) $post->menu_order !== $ordre ) {
			global $wpdb;
			$wpdb->update( $wpdb->posts, array( 'menu_order' => $ordre ), array( 'ID' => $post_id ) ); // phpcs:ignore
			clean_post_cache( $post_id );
		}
	}

	private static function code_pris( $code, $saison, $except ) {
		$ids = get_posts(
			array(
				'post_type'        => VHB_Club_Types::CATEGORIE,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'post__not_in'     => array( (int) $except ),
				'suppress_filters' => true,
				'meta_query'       => array( // phpcs:ignore
					array( 'key' => VHB_Club_Types::M . 'code', 'value' => $code ),
					array( 'key' => VHB_Club_Types::M . 'saison', 'value' => $saison ),
				),
			)
		);
		return (bool) $ids;
	}

	/* ------------------------------------------------------------------ */
	/* Lieux                                                               */
	/* ------------------------------------------------------------------ */

	public static function boxes_lieu() {
		remove_meta_box( 'slugdiv', VHB_Club_Types::LIEU, 'normal' );
		add_meta_box( 'vhbc_lieu', 'Gymnase', array( __CLASS__, 'box_lieu' ), VHB_Club_Types::LIEU, 'normal', 'high' );
	}

	public static function box_lieu( $post ) {
		wp_nonce_field( self::NONCE, 'vhbc_nonce' );
		$l        = VHB_Club_Donnees::lieu( $post->ID );
		$m        = static function ( $k, $d = '' ) use ( $post ) {
			return VHB_Club_Types::meta( $post->ID, $k, $d );
		};
		$gps      = ( '' !== $m( 'lat' ) && '' !== $m( 'lon' ) ) ? $m( 'lat' ) . ', ' . $m( 'lon' ) : '';
		$type     = $m( 'type', 'club' );
		$ententes = VHB_Club_Donnees::ententes();
		?>
		<table class="form-table vhbc-form" role="presentation">
			<tr><th scope="row"><label for="vhbc-type">Type</label></th><td>
				<select id="vhbc-type" name="vhbc_type" class="vhbc-type">
					<?php foreach ( VHB_Club_Types::TYPES_LIEU as $k => $v ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $type, $k ); ?>><?php echo esc_html( $v ); ?></option>
					<?php endforeach; ?>
				</select>
				<span class="vhbc-if-partenaire"<?php echo 'partenaire' === $type ? '' : ' hidden'; ?>>
					<label for="vhbc-entente" class="vhbc-inline-label">Club</label>
					<select id="vhbc-entente" name="vhbc_entente">
						<option value="0">(non précisé)</option>
						<?php foreach ( $ententes as $e ) : ?>
							<option value="<?php echo (int) $e['id']; ?>" <?php selected( (int) $m( 'entente', 0 ), $e['id'] ); ?>><?php echo esc_html( $e['nom'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</span>
			</td></tr>
			<tr><th scope="row"><label for="vhbc-nom-court">Nom court</label></th><td>
				<input type="text" id="vhbc-nom-court" name="vhbc_nom_court" value="<?php echo esc_attr( $m( 'nom_court' ) ); ?>" class="regular-text" placeholder="ex. Centre-ville">
				<p class="description">Affiché dans le planning quand il y a plusieurs gymnases. Vide : la ville.</p>
			</td></tr>
			<tr><th scope="row"><label for="vhbc-adresse">Adresse</label></th><td>
				<input type="text" id="vhbc-adresse" name="vhbc_adresse" value="<?php echo esc_attr( $m( 'adresse' ) ); ?>" class="large-text" placeholder="ex. Allée du Collège">
			</td></tr>
			<tr><th scope="row"><label for="vhbc-cp">Code postal et ville</label></th><td>
				<input type="text" id="vhbc-cp" name="vhbc_cp" value="<?php echo esc_attr( $m( 'cp' ) ); ?>" class="small-text" inputmode="numeric" maxlength="5" aria-label="Code postal">
				<input type="text" name="vhbc_ville" value="<?php echo esc_attr( $m( 'ville' ) ); ?>" class="regular-text" aria-label="Ville">
			</td></tr>
			<tr><th scope="row"><label for="vhbc-gps">Coordonnées GPS</label></th><td>
				<input type="text" id="vhbc-gps" name="vhbc_gps" value="<?php echo esc_attr( $gps ); ?>" class="regular-text" placeholder="43.5336, 3.8524">
				<p class="description">Facultatif, mais plus précis pour Google Maps et Waze. Dans Google Maps : clic droit sur l'entrée du gymnase, puis clic sur les coordonnées pour les copier.</p>
			</td></tr>
			<tr><th scope="row"><label for="vhbc-ffhb">Nom FFHB</label></th><td>
				<input type="text" id="vhbc-ffhb" name="vhbc_nom_federal" value="<?php echo esc_attr( $m( 'nom_federal' ) ); ?>" class="regular-text" placeholder="ex. GYMNASE MUNICIPAL">
				<p class="description">Nom du gymnase sur le site de la FFHB. Renseigné, il transmet le nom, l'adresse et le GPS de ce lieu aux Résultats FFHB : les matchs qui s'y jouent affichent ces informations.</p>
			</td></tr>
			<tr><th scope="row"><label for="vhbc-acces">Accès et stationnement</label></th><td>
				<textarea id="vhbc-acces" name="vhbc_acces" rows="2" class="large-text"><?php echo esc_textarea( $m( 'acces' ) ); ?></textarea>
			</td></tr>
			<tr><th scope="row">Accessibilité</th><td>
				<label><input type="checkbox" name="vhbc_pmr" value="1" <?php checked( (bool) $m( 'pmr', false ) ); ?>> Accessible aux personnes à mobilité réduite</label>
			</td></tr>
			<?php if ( $l && $l['slug'] ) : ?>
			<tr><th scope="row">Shortcode</th><td><code>[vhb_lieu lieu="<?php echo esc_html( $l['slug'] ); ?>"]</code></td></tr>
			<?php endif; ?>
		</table>
		<?php
	}

	public static function save_lieu( $post_id, $post ) {
		if ( ! self::can_save( $post_id, $post ) ) {
			return;
		}
		$type = sanitize_key( self::post( 'vhbc_type', 'club' ) );
		$type = isset( VHB_Club_Types::TYPES_LIEU[ $type ] ) ? $type : 'club';
		VHB_Club_Types::set_meta( $post_id, 'type', $type );
		VHB_Club_Types::set_meta( $post_id, 'entente', 'partenaire' === $type ? absint( self::post( 'vhbc_entente', 0 ) ) : '' );
		foreach ( array( 'nom_court', 'adresse', 'ville', 'nom_federal' ) as $k ) {
			VHB_Club_Types::set_meta( $post_id, $k, sanitize_text_field( self::post( 'vhbc_' . $k ) ) );
		}
		VHB_Club_Types::set_meta( $post_id, 'cp', preg_replace( '/\D/', '', (string) self::post( 'vhbc_cp' ) ) );
		VHB_Club_Types::set_meta( $post_id, 'acces', sanitize_textarea_field( self::post( 'vhbc_acces' ) ) );
		VHB_Club_Types::set_meta( $post_id, 'pmr', '1' === self::post( 'vhbc_pmr' ) ? '1' : '' );

		$gps = trim( (string) self::post( 'vhbc_gps' ) );
		$lat = '';
		$lon = '';
		if ( '' !== $gps ) {
			if ( preg_match( '/^(-?\d{1,2}(?:[.,]\d+)?)\s*[,; ]\s*(-?\d{1,3}(?:[.,]\d+)?)$/', $gps, $mm ) ) {
				$lat = str_replace( ',', '.', $mm[1] );
				$lon = str_replace( ',', '.', $mm[2] );
				if ( abs( (float) $lat ) > 90 || abs( (float) $lon ) > 180 ) {
					$lat = '';
					$lon = '';
				}
			}
			if ( '' === $lat ) {
				VHB_Club_Admin::notice( 'warning', 'Coordonnées GPS non reconnues (format attendu : 43.5336, 3.8524). Les itinéraires utiliseront l\'adresse.' );
			}
		}
		VHB_Club_Types::set_meta( $post_id, 'lat', $lat );
		VHB_Club_Types::set_meta( $post_id, 'lon', $lon );
	}

	/* ------------------------------------------------------------------ */
	/* Clubs en entente                                                    */
	/* ------------------------------------------------------------------ */

	public static function boxes_entente() {
		remove_meta_box( 'slugdiv', VHB_Club_Types::ENTENTE, 'normal' );
		add_meta_box( 'vhbc_entente', 'Entente', array( __CLASS__, 'box_entente' ), VHB_Club_Types::ENTENTE, 'normal', 'high' );
	}

	public static function box_entente( $post ) {
		wp_nonce_field( self::NONCE, 'vhbc_nonce' );
		?>
		<table class="form-table vhbc-form" role="presentation">
			<tr><th scope="row"><label for="vhbc-nom-entente">Nom de l'entente (FFHB)</label></th><td>
				<input type="text" id="vhbc-nom-entente" name="vhbc_nom_entente" value="<?php echo esc_attr( VHB_Club_Types::meta( $post->ID, 'nom_entente' ) ); ?>" class="regular-text" placeholder="ex. CLUB A-CLUB B">
				<p class="description">Tel qu'il apparaît dans les compétitions FFHB.</p>
			</td></tr>
			<tr><th scope="row"><label for="vhbc-site">Site web</label></th><td>
				<input type="url" id="vhbc-site" name="vhbc_site" value="<?php echo esc_attr( VHB_Club_Types::meta( $post->ID, 'site' ) ); ?>" class="regular-text" placeholder="https://">
			</td></tr>
		</table>
		<p class="description">Les catégories concernées se cochent depuis chaque catégorie (encadré « Entente et équipes FFHB »). Logo : encadré « Logo » à droite.</p>
		<?php
	}

	public static function save_entente( $post_id, $post ) {
		if ( ! self::can_save( $post_id, $post ) ) {
			return;
		}
		VHB_Club_Types::set_meta( $post_id, 'nom_entente', sanitize_text_field( self::post( 'vhbc_nom_entente' ) ) );
		VHB_Club_Types::set_meta( $post_id, 'site', esc_url_raw( trim( (string) self::post( 'vhbc_site' ) ) ) );
	}
}
