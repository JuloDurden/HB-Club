# HB Club

Extension WordPress pour les clubs de handball : chaque information du club est saisie une seule fois et s'affiche partout sur le site.

- **Club** : saisons, gymnases, clubs en entente, catégories et créneaux, tarifs, inscriptions, stages (HelloAsso), organigramme, vie du club, photos d'équipe.
- **Résultats FFHB** : résultats, calendriers et classements récupérés côté serveur sur ffhandball.fr (les visiteurs ne contactent jamais la FFHB), agenda iCal, matchs à domicile.
- **Affichage** : blocs Gutenberg et shortcodes, données JSON pour les pages, route REST `/wp-json/vhb/v1/equipes`.
- **Administration** : écran État, export et import JSON, rôles « Gestionnaire club » et « Éditeur de stages ».

Documentation complète (shortcodes, options, fonctionnement) : [readme.txt](readme.txt).

## Installation

Télécharger `hb-club-x.y.z.zip` dans l'onglet *Releases*, puis dans WordPress : Extensions > Ajouter > Téléverser, et Activer. Ensuite : HB Club > Réglages du club, puis HB Club > Résultats FFHB.

Prérequis : WordPress 6.1, PHP 7.4.

## Édition propre à un club

Le code est commun. Un club peut avoir son édition avec un fichier principal à son nom (constantes `VHB_CLUB_FILE` et `VHB_MARQUE`, puis `includes/charge.php`) et un dossier `profil/` :

- `profil/donnees.json` : données de départ, au format de l'export (HB Club > Export et import), importées à la première activation sur une base vide ;
- `profil/logo.svg` : logo en silhouette blanche (un seul tracé), pour le menu et les blocs.

## Origine

HB Club est la version générique de l'extension développée pour le Villeneuve Handball (VHB Résultats FFHB, VHB Club, puis VHB), sans les données propres à ce club. Les préfixes techniques `vhb_` en viennent et sont gardés pour la compatibilité.

## Licence

Copyright (C) 2026 Julien Clavel. Logiciel libre sous licence [GNU GPL version 2 ou ultérieure](LICENSE).
