<?php
/**
 * Liaison avec VHB Résultats (lot 4). Aucune dépendance obligatoire :
 * tout est inactif si VHB Résultats n'est pas là.
 *
 * - Gymnases : les lieux qui ont un « Nom FFHB » sont transmis à VHB Résultats
 *   (filtre vhb_ffhb_salles) et priment sur sa table « Salles ».
 * - Catégories : une catégorie liée à des équipes VHB Résultats peut afficher
 *   leur prochain match dans sa fiche.
 */

defined( 'ABSPATH' ) || exit;

class VHB_Club_Ffhb {

	public static function init() {
		add_filter( 'vhb_ffhb_salles', array( __CLASS__, 'salles' ) );
	}

	public static function actif() {
		return defined( 'VHB_FFHB_VERSION' ) && class_exists( 'VHB_Settings' ) && class_exists( 'VHB_Store' );
	}

	/** Gymnases de VHB Club ajoutés à la table des salles de VHB Résultats. */
	public static function salles( $salles ) {
		$salles = is_array( $salles ) ? $salles : array();
		foreach ( VHB_Club_Donnees::lieux() as $l ) {
			if ( '' === $l['nom_federal'] ) {
				continue;
			}
			$cle            = class_exists( 'VHB_Settings' ) ? VHB_Settings::upper( $l['nom_federal'] ) : mb_strtoupper( $l['nom_federal'] );
			$salles[ $cle ] = array(
				'nom'     => $l['nom'],
				'adresse' => $l['adresse_complete'],
				'lat'     => $l['lat'],
				'lon'     => $l['lon'],
			);
		}
		return $salles;
	}

	/**
	 * Prochain match d'une équipe VHB Résultats, ou null.
	 *
	 * @return array{equipe:string,date:string,ts:int,adversaire:string,domicile:bool,lieu:string,competition:string}|null
	 */
	public static function prochain( $slug ) {
		if ( ! self::actif() || ! method_exists( 'VHB_Render', 'prochain' ) ) {
			return null;
		}
		$v = VHB_Store::view( $slug );
		if ( ! $v || ! empty( $v['masquee'] ) || empty( $v['rencontres'] ) ) {
			return null;
		}
		$r = VHB_Render::prochain( $v['rencontres'] );
		if ( ! $r ) {
			return null;
		}
		$dom  = ! empty( $r['a_domicile'] );
		$adv  = VHB_Render::label( $dom ? ( $r['exterieur'] ?? '' ) : ( $r['domicile'] ?? '' ) );
		$lieu = '';
		if ( ! empty( $r['adresse'] ) && method_exists( 'VHB_Render', 'lieu_affiche' ) ) {
			$lieu = VHB_Render::lieu_affiche( $r )['nom'];
		}
		return array(
			'equipe'      => (string) $v['equipe'],
			'ts'          => (int) VHB_Render::ts( $r['date'] ?? '' ),
			'journee'     => array( (string) ( $r['journee_debut'] ?? '' ), (string) ( $r['journee_fin'] ?? '' ) ),
			'adversaire'  => (string) $adv,
			'domicile'    => $dom,
			'lieu'        => $lieu,
			'competition' => empty( $r['principale'] ) ? (string) ( $r['comp_nom'] ?? '' ) : '',
		);
	}

	/** Contrôles de liaison pour le tableau de bord. */
	public static function controles() {
		if ( ! self::actif() ) {
			return array();
		}
		$equipes = VHB_Club_Donnees::equipes_ffhb();
		$liees   = array();
		$out     = array();
		foreach ( VHB_Club_Donnees::categories() as $c ) {
			foreach ( $c['equipes'] as $slug ) {
				if ( ! isset( $equipes[ $slug ] ) ) {
					$out[] = array( $c['nom'] . ' : l\'équipe « ' . $slug . ' » n\'existe plus dans les Résultats FFHB.', get_edit_post_link( $c['id'], 'raw' ) );
				}
				$liees[ $slug ] = true;
			}
		}
		foreach ( $equipes as $slug => $nom ) {
			if ( empty( $liees[ $slug ] ) ) {
				$out[] = array( 'Résultats FFHB : l\'équipe « ' . $nom . ' » n\'est rattachée à aucune catégorie.', admin_url( 'edit.php?post_type=' . VHB_Club_Types::CATEGORIE ) );
			}
		}
		return $out;
	}
}
