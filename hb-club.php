<?php
/**
 * Plugin Name:       HB Club
 * Plugin URI:        https://github.com/JuloDurden/HB-Club
 * Description:       Console d'un club de handball : saisons, gymnases, clubs en entente, catégories, créneaux, tarifs, inscriptions, stages, organigramme, vie du club, photos d'équipe, et résultats, calendriers et classements récupérés automatiquement sur le site de la FFHB. Chaque information est saisie une seule fois et s'affiche partout sur le site (blocs Gutenberg et shortcodes).
 * Version:           3.2.0
 * Requires at least: 6.1
 * Requires PHP:      7.4
 * Author:            Julien Clavel
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain:       hb-club
 *
 * Copyright (C) 2026 Julien Clavel. Logiciel libre, sous licence GNU GPL
 * version 2 ou ultérieure (fichier LICENSE).
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'VHB_CLUB_VERSION' ) ) {
	// Une autre édition de l'extension est déjà active (même code, mêmes classes).
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p><strong>HB Club</strong> n\'est pas chargée : une autre édition de l\'extension est déjà active. Désactivez l\'une des deux.</p></div>';
		}
	);
	return;
}

define( 'VHB_CLUB_FILE', __FILE__ );
define( 'VHB_MARQUE', 'HB Club' );

require_once __DIR__ . '/includes/charge.php';
