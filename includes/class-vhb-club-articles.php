<?php
/**
 * Articles du site (3.0.1) : champ « Sous-titre », lu par la page Actualités
 * et le gabarit d'article de la refonte (REST : meta.vhb_sous_titre).
 */

defined( 'ABSPATH' ) || exit;

class VHB_Club_Articles {

	const META = 'vhb_sous_titre';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		if ( is_admin() ) {
			add_action( 'add_meta_boxes_post', array( __CLASS__, 'box_add' ) );
			add_action( 'save_post_post', array( __CLASS__, 'save' ), 10, 2 );
		}
	}

	public static function register() {
		register_post_meta(
			'post',
			self::META,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => static function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}

	/** Sous-titre d'un article (chaîne vide si aucun). */
	public static function sous_titre( $id ) {
		return (string) get_post_meta( (int) $id, self::META, true );
	}

	public static function box_add() {
		add_meta_box( 'vhbc-sous-titre', 'Sous-titre', array( __CLASS__, 'box' ), 'post', 'normal', 'high' );
	}

	public static function box( $post ) {
		wp_nonce_field( 'vhbc_sous_titre', 'vhbc_sous_titre_nonce' );
		echo '<input type="text" name="vhbc_sous_titre" value="' . esc_attr( self::sous_titre( $post->ID ) ) . '" class="widefat" maxlength="160" placeholder="ex. Week-end du 10 octobre, au gymnase" aria-label="Sous-titre">';
		echo '<p class="description">Une ligne sous le titre, dans la liste des actualités et en tête de l\'article.</p>';
	}

	public static function save( $post_id, $post ) {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['vhbc_sous_titre_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['vhbc_sous_titre_nonce'] ) ), 'vhbc_sous_titre' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$v = sanitize_text_field( wp_unslash( (string) ( $_POST['vhbc_sous_titre'] ?? '' ) ) ); // phpcs:ignore
		if ( '' === $v ) {
			delete_post_meta( $post_id, self::META );
		} else {
			update_post_meta( $post_id, self::META, $v );
		}
	}
}
