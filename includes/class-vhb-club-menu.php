<?php
/**
 * Menu « VHB » rangé par thème (3.4.0) : les pages restent les mêmes, seuls
 * leur ordre et des intertitres changent.
 */

defined( 'ABSPATH' ) || exit;

class VHB_Club_Menu {

	/** Groupes du menu : intertitre (vide pour le premier), puis pages dans l'ordre. */
	public static function groupes() {
		return array(
			array( '', array( VHB_Club_Admin_Slugs::MENU ) ),
			array( 'Le club', array( VHB_Club_Admin_Slugs::REGLAGES, VHB_Club_Admin_Slugs::SAISONS, 'edit.php?post_type=vhbc_categorie', 'edit.php?post_type=vhbc_lieu', 'edit.php?post_type=vhbc_entente', 'vhb-club-organigramme', 'vhb-club-vie' ) ),
			array( 'Inscriptions', array( 'vhb-club-inscriptions' ) ),
			array( 'Événements', array( 'edit.php?post_type=vhbc_stage', 'vhb-club-calendrier' ) ),
			array( 'Matchs', array( 'vhb-ffhb', 'vhb-loisirs', 'vhb-ffhb-domicile' ) ),
			array( 'Photos', array( 'vhb-club-photos-site', 'vhb-club-photos' ) ), // photos d'équipe : onglet de la page Photos, entrée masquée
			array( 'Outils', array( 'vhb-etat', 'vhb-club-export' ) ),
		);
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'ranger' ), 999 );
		add_action( 'admin_head', array( __CLASS__, 'styles' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'courant' ), 10, 2 );
	}

	public static function ranger() {
		global $submenu;
		$m = VHB_Club_Admin_Slugs::MENU;
		if ( empty( $submenu[ $m ] ) || ! is_array( $submenu[ $m ] ) ) {
			return;
		}
		$par = array();
		foreach ( $submenu[ $m ] as $item ) {
			$par[ (string) $item[2] ] = $item;
		}
		$out = array();
		foreach ( self::groupes() as $g ) {
			foreach ( $g[1] as $slug ) {
				if ( isset( $par[ $slug ] ) ) {
					$out[] = $par[ $slug ];
					unset( $par[ $slug ] );
				}
			}
		}
		foreach ( $par as $item ) {
			$out[] = $item; // pages ajoutées par une autre extension : à la fin
		}
		$submenu[ $m ] = $out; // phpcs:ignore
	}

	/** La page des photos d'équipe éclaire l'entrée « Photos ». */
	public static function courant( $submenu_file, $parent_file ) {
		if ( isset( $_GET['page'] ) && 'vhb-club-photos' === $_GET['page'] ) { // phpcs:ignore
			return 'vhb-club-photos-site';
		}
		return $submenu_file;
	}

	/** Intertitres du menu, au-dessus de la première page de chaque groupe. */
	public static function styles() {
		global $submenu;
		$m    = VHB_Club_Admin_Slugs::MENU;
		$vus  = array();
		foreach ( (array) ( $submenu[ $m ] ?? array() ) as $item ) {
			$vus[ (string) $item[2] ] = true;
		}
		$css = '';
		foreach ( self::groupes() as $g ) {
			if ( '' === $g[0] ) {
				continue;
			}
			foreach ( $g[1] as $slug ) {
				if ( ! isset( $vus[ $slug ] ) ) {
					continue;
				}
				$href = false !== strpos( $slug, '.php' ) ? $slug : 'admin.php?page=' . $slug;
				$css .= '#toplevel_page_' . $m . ' .wp-submenu li:has(> a[href="' . esc_attr( $href ) . '"])::before{content:"' . esc_attr( $g[0] ) . '"}';
				break;
			}
		}
		if ( '' === $css ) {
			return;
		}
		$css .= '#toplevel_page_' . $m . ' .wp-submenu li:has(> a[href="admin.php?page=vhb-club-photos"]){display:none}';
		echo '<style>' . $css . '#toplevel_page_' . esc_attr( $m ) . ' .wp-submenu li::before{display:block;margin:10px 12px 2px;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:rgba(240,246,252,.5);pointer-events:none}#toplevel_page_' . esc_attr( $m ) . '.wp-not-current-submenu .wp-submenu li::before{margin-top:6px}</style>'; // phpcs:ignore
	}
}
