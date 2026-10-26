<?php
/**
 * Types de contenu : lieux, clubs en entente, catégories.
 *
 * Types natifs WordPress, sans extension tierce. Ils ne sont pas publics :
 * aucune page propre sur le site, ils n'apparaissent qu'à travers les blocs.
 * Toutes les actions passent par la capacité « vhb_club_gerer », donnée aux
 * administrateurs (un rôle « Gestionnaire club » pourra la recevoir plus tard).
 */

defined( 'ABSPATH' ) || exit;

class VHB_Club_Types {

	const LIEU      = 'vhbc_lieu';
	const ENTENTE   = 'vhbc_entente';
	const CATEGORIE = 'vhbc_categorie';
	const STAGE     = 'vhbc_stage';

	const TYPES_STAGE = array(
		'stage'   => 'Stage',
		'tournoi' => 'Tournoi',
		'fete'    => 'Fête du club',
		'autre'   => 'Événement',
	);

	/** Préfixe des métadonnées. */
	const M = '_vhbc_';

	const PRATIQUES = array(
		'competition' => 'Compétition',
		'loisir'      => 'Loisir',
		'handfit'     => 'HandFit',
		'babyhand'    => 'BabyHand',
	);

	const GENRES = array(
		'f'     => 'Filles',
		'm'     => 'Garçons',
		'fm'    => 'Filles et garçons',
		'mixte' => 'Mixte',
	);

	const JOURS = array(
		1 => 'Lundi',
		2 => 'Mardi',
		3 => 'Mercredi',
		4 => 'Jeudi',
		5 => 'Vendredi',
		6 => 'Samedi',
		7 => 'Dimanche',
	);

	const PERIODES = array(
		'saison'        => 'Toute la saison',
		'hors_vacances' => 'Hors vacances scolaires',
	);

	const TYPES_LIEU = array(
		'club'       => 'Gymnase du club',
		'partenaire' => 'Gymnase d\'un club en entente',
	);

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Rôles et capacités (activation et mise à jour).
	 * - Administrateur : tout.
	 * - Gestionnaire club : toutes les données du club (catégories, horaires,
	 *   tarifs, stages, organigramme, vie du club), médiathèque ; ni réglages,
	 *   ni saisons, ni le reste du site.
	 * - Éditeur de stages : les stages seulement, médiathèque.
	 */
	const ROLES = array(
		'vhb_gestionnaire'   => 'Gestionnaire club',
		'vhb_editeur_stages' => 'Éditeur de stages',
	);

	public static function add_caps() {
		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->add_cap( VHB_Club_Reglages::CAP_DONNEES );
			$role->add_cap( VHB_Club_Reglages::CAP_REGLAGES );
			$role->add_cap( VHB_Club_Reglages::CAP_STAGES );
		}
		$base = array( 'read' => true, 'upload_files' => true );
		$defs = array(
			'vhb_gestionnaire'   => $base + array( VHB_Club_Reglages::CAP_DONNEES => true, VHB_Club_Reglages::CAP_STAGES => true ),
			'vhb_editeur_stages' => $base + array( VHB_Club_Reglages::CAP_STAGES => true ),
		);
		foreach ( $defs as $slug => $caps ) {
			$r = get_role( $slug );
			if ( ! $r ) {
				add_role( $slug, self::ROLES[ $slug ], $caps );
				continue;
			}
			foreach ( $caps as $cap => $grant ) {
				$r->add_cap( $cap, $grant );
			}
		}
	}

	public static function remove_roles() {
		foreach ( array_keys( self::ROLES ) as $slug ) {
			remove_role( $slug );
		}
	}

	private static function caps( $c = null ) {
		$c = $c ?: VHB_Club_Reglages::CAP_DONNEES;
		return array(
			'edit_post'              => $c,
			'read_post'              => 'read',
			'delete_post'            => $c,
			'edit_posts'             => $c,
			'edit_others_posts'      => $c,
			'publish_posts'          => $c,
			'read_private_posts'     => $c,
			'delete_posts'           => $c,
			'delete_private_posts'   => $c,
			'delete_published_posts' => $c,
			'delete_others_posts'    => $c,
			'edit_private_posts'     => $c,
			'edit_published_posts'   => $c,
			'create_posts'           => $c,
		);
	}

	private static function base( $singular, $plural, $feminine, array $extra = array(), $cap = null ) {
		$un      = $feminine ? 'une' : 'un';
		$nouveau = $feminine ? 'Nouvelle' : 'Nouveau';
		$aucun   = $feminine ? 'Aucune' : 'Aucun';
		$trouve  = $feminine ? 'trouvée' : 'trouvé';
		return array_merge(
			array(
				'labels'              => array(
					'name'               => $plural,
					'singular_name'      => $singular,
					'menu_name'          => $plural,
					'all_items'          => $plural,
					'add_new'            => 'Ajouter',
					'add_new_item'       => 'Ajouter ' . $un . ' ' . lcfirst( $singular ),
					'edit_item'          => 'Modifier ' . ( $feminine ? 'la' : 'le' ) . ' ' . lcfirst( $singular ),
					'new_item'           => $nouveau . ' ' . lcfirst( $singular ),
					'search_items'       => 'Rechercher',
					'not_found'          => $aucun . ' ' . lcfirst( $singular ) . ' ' . $trouve,
					'not_found_in_trash' => $aucun . ' ' . lcfirst( $singular ) . ' dans la corbeille',
					'item_published'     => ucfirst( lcfirst( $singular ) ) . ' ' . ( $feminine ? 'publiée' : 'publié' ),
					'item_updated'       => ucfirst( lcfirst( $singular ) ) . ' ' . ( $feminine ? 'mise à jour' : 'mis à jour' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => VHB_Club_Admin_Slugs::MENU,
				'show_in_rest'        => false, // Écran classique : métaboxes simples pour les bénévoles.
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'capabilities'        => self::caps( $cap ),
				'map_meta_cap'        => false,
				'supports'            => array( 'title' ),
			),
			$extra
		);
	}

	public static function register() {
		register_post_type( self::CATEGORIE, self::base( 'Catégorie', 'Catégories', true ) );
		// Un éditeur de stages n'a pas le menu VHB : ses stages ont leur propre entrée.
		// 2.0.2 : image mise en avant = affiche du stage (page Stages de la refonte).
		$stage = self::base( 'Stage ou événement', 'Stages', false, array( 'supports' => array( 'title', 'thumbnail' ) ), VHB_Club_Reglages::CAP_STAGES );
		if ( is_user_logged_in() && ! current_user_can( VHB_Club_Reglages::CAP_DONNEES ) ) {
			$stage['show_in_menu']  = true;
			$stage['menu_icon']     = 'dashicons-calendar-alt';
			$stage['menu_position'] = 25;
		}
		register_post_type( self::STAGE, $stage );
		register_post_type( self::LIEU, self::base( 'Lieu', 'Lieux', false ) );
		$entente           = self::base( 'Club en entente', 'Clubs en entente', false, array( 'supports' => array( 'title', 'thumbnail' ) ) );
		$entente['labels'] += array(
			'featured_image'        => 'Logo',
			'set_featured_image'    => 'Choisir le logo',
			'remove_featured_image' => 'Retirer le logo',
			'use_featured_image'    => 'Utiliser comme logo',
		);
		register_post_type( self::ENTENTE, $entente );
	}

	/* ------------------------------------------------------------------ */
	/* Métadonnées                                                         */
	/* ------------------------------------------------------------------ */

	public static function meta( $post_id, $key, $default = '' ) {
		$v = get_post_meta( $post_id, self::M . $key, true );
		return ( '' === $v || null === $v || false === $v ) ? $default : $v;
	}

	public static function set_meta( $post_id, $key, $value ) {
		if ( '' === $value || array() === $value || null === $value ) {
			delete_post_meta( $post_id, self::M . $key );
			return;
		}
		update_post_meta( $post_id, self::M . $key, $value );
	}
}

/** Slugs d'administration, disponibles aussi hors de l'admin (enregistrement des types). */
final class VHB_Club_Admin_Slugs {
	const MENU     = 'vhb-club';
	const REGLAGES = 'vhb-club-reglages';
	const SAISONS  = 'vhb-club-saisons';
}
