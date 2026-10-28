<?php
/**
 * Événements du club (lot 3, élargi en 3.1.0) : stages, tournois, loto, AG...
 * Dates (éventuellement « à confirmer »), horaires, public, lieu, tarifs,
 * conditions, lien HelloAsso. Un événement passé disparaît seul du site.
 */

defined( 'ABSPATH' ) || exit;

class VHB_Club_Stages {

	public static function init() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'add_meta_boxes_' . VHB_Club_Types::STAGE, array( __CLASS__, 'boxes' ) );
		add_action( 'save_post_' . VHB_Club_Types::STAGE, array( __CLASS__, 'save' ), 5, 2 );
		$t = VHB_Club_Types::STAGE;
		add_filter( "manage_{$t}_posts_columns", array( __CLASS__, 'columns' ) );
		add_action( "manage_{$t}_posts_custom_column", array( __CLASS__, 'column' ), 10, 2 );
		add_action( 'pre_get_posts', array( __CLASS__, 'order_admin' ) );
		add_filter( 'enter_title_here', array( __CLASS__, 'placeholder' ), 10, 2 );
	}

	/* ------------------------------------------------------------------ */
	/* Lecture                                                             */
	/* ------------------------------------------------------------------ */

	public static function stage( $id ) {
		$id = (int) $id;
		$m  = static function ( $k, $d = '' ) use ( $id ) {
			return VHB_Club_Types::meta( $id, $k, $d );
		};
		$s  = array(
			'id'         => $id,
			'titre'      => get_the_title( $id ),
			'type'       => (string) $m( 'type', 'stage' ),
			'debut'      => (string) $m( 'debut' ),
			'fin'        => (string) $m( 'fin' ),
			'horaires'   => (string) $m( 'horaires' ),
			'public'     => (string) $m( 'public' ),
			'groupes'    => (string) $m( 'groupes' ),
			'lieu'       => (int) $m( 'lieu', 0 ),
			'lieu_libre' => (string) $m( 'lieu_libre' ),
			'infos'      => array_values( array_filter( (array) $m( 'infos', array() ) ) ),
			'tarifs'     => array_values( (array) $m( 'tarifs', array() ) ),
			'conditions' => array_values( array_filter( (array) $m( 'conditions', array() ) ) ),
			'helloasso'  => (string) $m( 'helloasso' ),
			'limite'     => (string) $m( 'limite' ),
			'complet'    => (bool) $m( 'complet', false ),
			// 3.0.1 : texte de présentation et article du site lié (agenda de la page Actualités).
			'presentation' => (string) $m( 'presentation' ),
			'article'      => (int) $m( 'article', 0 ),
			// 3.1.0 : date pas encore arrêtée ; la date saisie sert à classer, le texte remplace la date à l'affichage.
			'a_confirmer'  => (bool) $m( 'a_confirmer', false ),
			'date_texte'   => (string) $m( 'date_texte' ),
		);
		if ( '' === $s['fin'] ) {
			$s['fin'] = $s['debut'];
		}
		$today       = VHB_Club_Donnees::today();
		$s['etat']   = '' === $s['debut'] ? 'sans_date' : ( $s['fin'] < $today ? 'termine' : ( $s['debut'] <= $today ? 'en_cours' : 'a_venir' ) );
		$s['ouvert'] = ! $s['complet'] && 'termine' !== $s['etat'] && ( '' === $s['limite'] || $today <= $s['limite'] ) && '' !== $s['helloasso'];
		return $s;
	}

	/**
	 * Stages publiés, du plus proche au plus lointain.
	 *
	 * @param string $quand a_venir (en cours compris) | passes | tous
	 */
	public static function liste( $quand = 'a_venir', $types = array(), $nombre = 0 ) {
		$ids  = get_posts(
			array(
				'post_type'        => VHB_Club_Types::STAGE,
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		$list = array();
		foreach ( $ids as $id ) {
			$s = self::stage( $id );
			if ( 'sans_date' === $s['etat'] || ( $types && ! in_array( $s['type'], $types, true ) ) ) {
				continue;
			}
			if ( ( 'a_venir' === $quand && 'termine' === $s['etat'] ) || ( 'passes' === $quand && 'termine' !== $s['etat'] ) ) {
				continue;
			}
			$list[] = $s;
		}
		usort(
			$list,
			static function ( $a, $b ) use ( $quand ) {
				return 'passes' === $quand ? strcmp( $b['debut'], $a['debut'] ) : strcmp( $a['debut'], $b['debut'] );
			}
		);
		return $nombre > 0 ? array_slice( $list, 0, $nombre ) : $list;
	}

	/** Ligne courte : « 19-23 octobre 2026 · 8h45-16h30 · -9 à -15 ». */
	public static function resume( array $s ) {
		return implode( ' · ', array_filter( array( VHB_Club_Donnees::periode( $s['debut'], $s['fin'], true ), $s['horaires'], $s['public'] ) ) );
	}

	public static function lieu_txt( array $s ) {
		$l = $s['lieu'] ? VHB_Club_Donnees::lieu( $s['lieu'] ) : null;
		if ( $l && $l['publie'] ) {
			return array( $l['nom'] . ( '' !== $l['adresse'] ? ', ' . $l['adresse'] : '' ), VHB_Club_Donnees::liens_carte( $l )['google'] );
		}
		return array( $s['lieu_libre'], '' );
	}

	/** Lien du widget HelloAsso (même adresse + « /widget »). */
	public static function widget_url( $url ) {
		$url = untrailingslashit( (string) $url );
		if ( ! preg_match( '#^https://www\.helloasso\.com/associations/[^/]+/(evenements|adhesions|boutiques|formulaires|collectes)/[^/?]+#', $url, $m ) ) {
			return '';
		}
		return $m[0] . '/widget';
	}

	/* ------------------------------------------------------------------ */
	/* Saisie                                                              */
	/* ------------------------------------------------------------------ */

	public static function placeholder( $text, $post ) {
		return VHB_Club_Types::STAGE === $post->post_type ? 'Titre (ex. Stage Vacances Toussaint, Tournoi de Noël, Loto du club)' : $text;
	}

	public static function boxes() {
		remove_meta_box( 'slugdiv', VHB_Club_Types::STAGE, 'normal' );
		add_meta_box( 'vhbc_stage', 'Événement', array( __CLASS__, 'box' ), VHB_Club_Types::STAGE, 'normal', 'high' );
		add_meta_box( 'vhbc_stage_tarifs', 'Tarifs et conditions', array( __CLASS__, 'box_tarifs' ), VHB_Club_Types::STAGE, 'normal', 'high' );
	}

	public static function box( $post ) {
		wp_nonce_field( 'vhbc_save', 'vhbc_nonce' );
		$s = self::stage( $post->ID );
		// Création depuis le calendrier : date du jour cliqué.
		if ( '' === $s['debut'] && isset( $_GET['vhbc_debut'] ) ) { // phpcs:ignore
			$d = sanitize_text_field( wp_unslash( $_GET['vhbc_debut'] ) ); // phpcs:ignore
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
				$s['debut'] = $d;
				$s['fin']   = $d;
			}
		}
		?>
		<table class="form-table vhbc-form" role="presentation">
			<tr><th scope="row"><label for="vhbc-stype">Type</label></th><td>
				<select id="vhbc-stype" name="vhbc_stage[type]">
					<?php foreach ( VHB_Club_Types::TYPES_STAGE as $k => $v ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['type'], $k ); ?>><?php echo esc_html( $v ); ?></option>
					<?php endforeach; ?>
				</select>
			</td></tr>
			<tr><th scope="row">Dates</th><td>
				<label>du <input type="date" name="vhbc_stage[debut]" value="<?php echo esc_attr( $s['debut'] ); ?>" required></label>
				<label>au <input type="date" name="vhbc_stage[fin]" value="<?php echo esc_attr( $s['fin'] ); ?>"></label>
				<p class="description">L'événement disparaît seul du site le lendemain de la date de fin.</p>
				<p><label><input type="checkbox" name="vhbc_stage[a_confirmer]" value="1" <?php checked( $s['a_confirmer'] ); ?>> Date à confirmer</label>
				<input type="text" name="vhbc_stage[date_texte]" value="<?php echo esc_attr( $s['date_texte'] ); ?>" class="regular-text" placeholder="ex. fin février ou début mars" aria-label="Date annoncée"></p>
				<p class="description">Date pas encore arrêtée : saisissez ci-dessus une date approchée (elle sert à classer l'événement), l'agenda affiche « À venir » et le texte à côté de la case.</p>
			</td></tr>
			<tr><th scope="row"><label for="vhbc-shor">Horaires</label></th><td>
				<input type="text" id="vhbc-shor" name="vhbc_stage[horaires]" value="<?php echo esc_attr( $s['horaires'] ); ?>" class="regular-text" placeholder="ex. 8h45-16h30">
			</td></tr>
			<tr><th scope="row"><label for="vhbc-spub">Public</label></th><td>
				<input type="text" id="vhbc-spub" name="vhbc_stage[public]" value="<?php echo esc_attr( $s['public'] ); ?>" class="regular-text" placeholder="ex. -9 à -15">
				<p class="description">Version courte, pour l'accueil (ex. « licenciés et parents »).</p>
			</td></tr>
			<tr><th scope="row"><label for="vhbc-sgrp">Groupes</label></th><td>
				<input type="text" id="vhbc-sgrp" name="vhbc_stage[groupes]" value="<?php echo esc_attr( $s['groupes'] ); ?>" class="large-text" placeholder="ex. 2 groupes : -9/-11 et -13/-15">
			</td></tr>
			<tr><th scope="row"><label for="vhbc-slieu">Lieu</label></th><td>
				<select id="vhbc-slieu" name="vhbc_stage[lieu]">
					<option value="0">(autre lieu, à saisir à droite)</option>
					<?php foreach ( VHB_Club_Donnees::lieux() as $l ) : ?>
						<option value="<?php echo (int) $l['id']; ?>" <?php selected( $s['lieu'], $l['id'] ); ?>><?php echo esc_html( $l['nom'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="text" name="vhbc_stage[lieu_libre]" value="<?php echo esc_attr( $s['lieu_libre'] ); ?>" class="regular-text" placeholder="Autre lieu" aria-label="Autre lieu">
			</td></tr>
			<tr><th scope="row"><label for="vhbc-sinfos">Infos pratiques</label></th><td>
				<textarea id="vhbc-sinfos" name="vhbc_stage[infos]" rows="3" class="large-text" placeholder="ex. Repas tiré du sac, prévoir une gourde"><?php echo esc_textarea( implode( "\n", $s['infos'] ) ); ?></textarea>
				<p class="description">Une info par ligne.</p>
			</td></tr>
			<tr><th scope="row"><label for="vhbc-spres">Présentation</label></th><td>
				<textarea id="vhbc-spres" name="vhbc_stage[presentation]" rows="3" class="large-text" placeholder="ex. Le club se mobilise pour Octobre Rose : les équipes jouent en rose et plusieurs animations sont organisées au gymnase."><?php echo esc_textarea( $s['presentation'] ); ?></textarea>
				<p class="description">Deux ou trois phrases, affichées dans l'agenda du club. Les infos pratiques ci-dessus s'affichent en points.</p>
			</td></tr>
			<tr><th scope="row"><label for="vhbc-sart">Article lié</label></th><td>
				<select id="vhbc-sart" name="vhbc_stage[article]">
					<option value="0">(aucun)</option>
					<?php
					$arts = get_posts( array( 'post_type' => 'post', 'post_status' => array( 'publish', 'future', 'draft' ), 'posts_per_page' => 100, 'orderby' => 'date', 'order' => 'DESC', 'suppress_filters' => true ) );
					foreach ( $arts as $a ) :
						?>
						<option value="<?php echo (int) $a->ID; ?>" <?php selected( $s['article'], $a->ID ); ?>><?php echo esc_html( get_the_title( $a ) . ( 'publish' === $a->post_status ? '' : ' (non publié)' ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description">Facultatif. L'agenda affiche « Lire l'article » ; sans article ni lien HelloAsso, pas de bouton.</p>
			</td></tr>
			<tr><th scope="row"><label for="vhbc-sha">Lien HelloAsso</label></th><td>
				<input type="url" id="vhbc-sha" name="vhbc_stage[helloasso]" value="<?php echo esc_attr( $s['helloasso'] ); ?>" class="large-text" placeholder="https://www.helloasso.com/associations/…">
				<p class="description">Page de la billetterie. Le bloc affiche le bouton « Réserver », et le formulaire HelloAsso si l'option est cochée dans le bloc.</p>
			</td></tr>
			<tr><th scope="row">Réservations</th><td>
				<label>Clôture le <input type="date" name="vhbc_stage[limite]" value="<?php echo esc_attr( $s['limite'] ); ?>"></label>
				<label class="vhbc-avant"><input type="checkbox" name="vhbc_stage[complet]" value="1" <?php checked( $s['complet'] ); ?>> Complet</label>
				<p class="description">Facultatif. Après la clôture ou si complet, le bouton est remplacé par la mention correspondante.</p>
			</td></tr>
		</table>
		<?php
	}

	public static function box_tarifs( $post ) {
		$s = self::stage( $post->ID );
		VHB_Club_Inscriptions_Admin::repeat(
			'vhbc-stage-tarifs',
			array( 'Formule', 'Adhérent (€)', 'Extérieur (€)' ),
			$s['tarifs'],
			static function ( $i, $r ) {
				$n = 'vhbc_stage[tarifs][' . $i . ']';
				echo '<tr><td><input type="text" name="' . esc_attr( $n . '[libelle]' ) . '" value="' . esc_attr( $r['libelle'] ) . '" placeholder="ex. Semaine complète"></td>';
				echo '<td><input type="text" inputmode="decimal" class="small-text" name="' . esc_attr( $n . '[adherent]' ) . '" value="' . esc_attr( $r['adherent'] ) . '"></td>';
				echo '<td><input type="text" inputmode="decimal" class="small-text" name="' . esc_attr( $n . '[exterieur]' ) . '" value="' . esc_attr( $r['exterieur'] ) . '"></td>';
				echo VHB_Club_Inscriptions_Admin::del() . '</tr>'; // phpcs:ignore
			},
			array( 'libelle' => '', 'adherent' => '', 'exterieur' => '' )
		);
		?>
		<p class="description">Colonne « Extérieur » vide partout : une seule colonne « Tarif » s'affiche.</p>
		<p><label for="vhbc-scond"><strong>Conditions</strong></label></p>
		<textarea id="vhbc-scond" name="vhbc_stage[conditions]" rows="4" class="large-text" placeholder="Une condition par ligne"><?php echo esc_textarea( implode( "\n", $s['conditions'] ) ); ?></textarea>
		<?php
	}

	private static function lignes( $t ) {
		return array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', sanitize_textarea_field( (string) $t ) ) ) ) );
	}

	private static function nombre( $v ) {
		$v = str_replace( array( ' ', '€', ',' ), array( '', '', '.' ), trim( (string) $v ) );
		return is_numeric( $v ) && (float) $v >= 0 ? (string) ( 0 + $v ) : '';
	}

	public static function save( $post_id, $post ) {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		if ( ! isset( $_POST['vhbc_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['vhbc_nonce'] ) ), 'vhbc_save' ) || ! current_user_can( VHB_Club_Reglages::CAP_STAGES ) ) {
			return;
		}
		$in   = isset( $_POST['vhbc_stage'] ) ? (array) wp_unslash( $_POST['vhbc_stage'] ) : array(); // phpcs:ignore
		$date = static function ( $v ) {
			$v = sanitize_text_field( (string) $v );
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
		};
		$type  = sanitize_key( $in['type'] ?? 'stage' );
		$debut = $date( $in['debut'] ?? '' );
		$fin   = $date( $in['fin'] ?? '' );
		if ( $debut && $fin && $fin < $debut ) {
			VHB_Club_Admin::notice( 'warning', 'La date de fin précédait la date de début : les deux dates ont été inversées.' );
			list( $debut, $fin ) = array( $fin, $debut );
		}
		if ( ! $debut ) {
			VHB_Club_Admin::notice( 'warning', 'Aucune date de début : l\'événement ne s\'affichera pas sur le site (pour une date pas encore arrêtée, saisissez une date approchée et cochez « Date à confirmer »).' );
		}
		$tarifs = array();
		foreach ( (array) ( $in['tarifs'] ?? array() ) as $k => $r ) {
			if ( '__i__' === (string) $k || ! is_array( $r ) || '' === trim( (string) ( $r['libelle'] ?? '' ) ) ) {
				continue;
			}
			$tarifs[] = array(
				'libelle'   => sanitize_text_field( $r['libelle'] ),
				'adherent'  => self::nombre( $r['adherent'] ?? '' ),
				'exterieur' => self::nombre( $r['exterieur'] ?? '' ),
			);
		}
		$ha = esc_url_raw( trim( (string) ( $in['helloasso'] ?? '' ) ) );
		if ( 'publish' === $post->post_status && '' === $ha && 'stage' === $type ) {
			VHB_Club_Admin::notice( 'warning', 'Pas de lien HelloAsso : le stage s\'affiche sans bouton de réservation.' );
		}
		$vals = array(
			'type'       => isset( VHB_Club_Types::TYPES_STAGE[ $type ] ) ? $type : 'stage',
			'debut'      => $debut,
			'fin'        => $fin,
			'horaires'   => sanitize_text_field( (string) ( $in['horaires'] ?? '' ) ),
			'public'     => sanitize_text_field( (string) ( $in['public'] ?? '' ) ),
			'groupes'    => sanitize_text_field( (string) ( $in['groupes'] ?? '' ) ),
			'lieu'       => absint( $in['lieu'] ?? 0 ),
			'lieu_libre' => sanitize_text_field( (string) ( $in['lieu_libre'] ?? '' ) ),
			'infos'      => self::lignes( $in['infos'] ?? '' ),
			'tarifs'     => $tarifs,
			'conditions' => self::lignes( $in['conditions'] ?? '' ),
			'helloasso'  => $ha,
			'limite'     => $date( $in['limite'] ?? '' ),
			'complet'    => empty( $in['complet'] ) ? '' : '1',
			'presentation' => sanitize_textarea_field( (string) ( $in['presentation'] ?? '' ) ),
			'article'      => absint( $in['article'] ?? 0 ),
			'a_confirmer'  => empty( $in['a_confirmer'] ) ? '' : '1',
			'date_texte'   => sanitize_text_field( (string) ( $in['date_texte'] ?? '' ) ),
		);
		foreach ( $vals as $k => $v ) {
			VHB_Club_Types::set_meta( $post_id, $k, $v );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Liste d'administration                                              */
	/* ------------------------------------------------------------------ */

	public static function columns( $cols ) {
		return array(
			'cb'          => $cols['cb'] ?? '',
			'title'       => 'Événement',
			'vhbc_type'   => 'Type',
			'vhbc_dates'  => 'Dates',
			'vhbc_etat'   => 'Sur le site',
			'vhbc_ha'     => 'HelloAsso',
		);
	}

	public static function column( $col, $id ) {
		$s = self::stage( $id );
		switch ( $col ) {
			case 'vhbc_type':
				$c = VHB_Club_Types::COULEURS_STAGE[ $s['type'] ] ?? '#6B7080';
				echo '<span class="vhbc-type"><span class="vhbc-type-pt" style="background:' . esc_attr( $c ) . '"></span>' . esc_html( VHB_Club_Types::TYPES_STAGE[ $s['type'] ] ?? $s['type'] ) . '</span>';
				break;
			case 'vhbc_dates':
				echo esc_html( VHB_Club_Donnees::periode( $s['debut'], $s['fin'], true ) );
				if ( $s['a_confirmer'] ) {
					echo '<br><span class="vhbc-pill">à confirmer' . ( '' !== $s['date_texte'] ? ' : ' . esc_html( $s['date_texte'] ) : '' ) . '</span>';
				}
				break;
			case 'vhbc_etat':
				$e = array(
					'a_venir'   => 'à venir',
					'en_cours'  => 'en cours',
					'termine'   => 'terminé, masqué',
					'sans_date' => 'sans date, masqué',
				);
				echo '<span class="vhbc-pill' . ( in_array( $s['etat'], array( 'termine', 'sans_date' ), true ) ? ' vhbc-pill--off' : ' vhbc-pill--en_cours' ) . '">' . esc_html( $e[ $s['etat'] ] ) . '</span>';
				echo $s['complet'] ? ' <span class="vhbc-pill">complet</span>' : '';
				break;
			case 'vhbc_ha':
				if ( $s['helloasso'] ) {
					echo '<a href="' . esc_url( $s['helloasso'] ) . '" target="_blank" rel="noopener">billetterie</a>';
				} else {
					echo 'stage' === $s['type'] ? '<span class="vhbc-err">manquant</span>' : '<span aria-label="aucun">-</span>';
				}
				break;
		}
	}

	public static function order_admin( $q ) {
		if ( is_admin() && $q->is_main_query() && VHB_Club_Types::STAGE === $q->get( 'post_type' ) && ! $q->get( 'orderby' ) ) {
			$q->set( 'meta_key', VHB_Club_Types::M . 'debut' );
			$q->set( 'orderby', 'meta_value' );
			$q->set( 'order', 'DESC' );
		}
	}


}
