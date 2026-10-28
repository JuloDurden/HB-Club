=== HB Club ===
Contributors: julodurden
Tags: handball, club, sport, ffhb, résultats
Requires at least: 6.1
Requires PHP: 7.4
Stable tag: 3.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Console d'un club de handball : chaque information du club est saisie une seule fois et s'affiche partout sur le site. Résultats, calendriers et classements récupérés automatiquement sur le site de la FFHB.

== Description ==

Menu « HB Club » dans l'administration :

* Tableau de bord : saison affichée, chiffres, points à compléter, codes des catégories, aide.
* Catégories : nom, code, pratique, tarif, inclus dans la cotisation, statut des inscriptions (facultatif), public, années de naissance, entraînements (jour, heures, gymnase, période, précision), description, entente, équipes des Résultats FFHB.
* Lieux : gymnase du club ou d'un club en entente, adresse, GPS, nom FFHB, accès, accessibilité.
* Clubs en entente : nom, nom de l'entente FFHB, site, logo.
* Inscriptions : période et bandeau « Inscriptions ouvertes », autres licences (dirigeant), réductions et aides, mention sous les tarifs (par saison) ; pièces à fournir, paiement, séances d'essai, équipement, documents à télécharger (toutes saisons).
* Stages : stages et événements (dates, horaires, public, groupes, lieu, infos pratiques, tarifs adhérent/extérieur, conditions, lien HelloAsso, clôture, complet). Un stage passé disparaît seul.
* Organigramme : bureau, CA, commissions, bénévoles ; nom complet ou initiale ; photo facultative, sinon initiales.
* Vie du club : alertes datées, assemblées générales (AG du CA, AG grand public, comptes rendus), boutique, partenaires et sponsors, mentions légales.
* Saisons : préparer la saison suivante (copie des catégories), la mettre en ligne d'un clic, revenir en arrière.
* Photos d'équipe : une photo par équipe des Résultats FFHB, avec point de cadrage, pour les cartes Panini des pages.
* Résultats FFHB : saison, équipes et compétitions FFHB, blasons, gymnases, apparence des blocs Résultats, journal. Matchs à domicile : organisation des week-ends.
* État : mises à jour FFHB, erreurs par équipe, photos, points à compléter, versions, cache.
* Export et import : tout HB Club (club, photos, réglages des Résultats FFHB) dans un fichier JSON (sauvegarde, passage d'un site de test au site en ligne) ; import avec sauvegarde automatique de l'état précédent.
* Réglages du club : nom, sigle, contacts, réseaux sociaux, logo, gymnase principal, apparence.

Affichage (un bloc « HB Club : … » existe pour chacun, catégorie Widgets) :

* [vhb_planning] : planning des entraînements. Options : vue="tableau|jours", pratique="competition,loisir,handfit,babyhand", categorie="u13-f,u13-g", lieu="<slug>", groupe="pratique", lieux="non", titre="…", surtitre="…".
* [vhb_categorie categorie="u13-f"] : fiche d'une catégorie. Options : description="non", creneaux="non", tarif="non", pratique_infos="non", matchs="non".
* [vhb_lieu] : gymnase principal avec Google Maps et Waze. Options : lieu="tous|club|partenaire|<slug>".
* [vhb_tarifs] : grille tarifaire. Options : pratique="…", regrouper="non", inclus="non", autres="non", mention="non", titre="…", surtitre="…".
* [vhb_reductions] : réductions et paiement. Options : reductions="non", paiement="non", essai="oui".
* [vhb_pieces] : pièces à fournir et documents. Options : pieces="non", documents="non", equipement="oui".
* [vhb_bandeau_inscriptions] : bandeau « Inscriptions ouvertes », visible seulement s'il est activé (et dans la période, si elle est réglée).
* [vhb_stages] : stages à venir. Options : vue="cartes|compact", quand="a_venir|passes|tous", nombre="1", type="stage,tournoi", tarifs="non", widget="oui" (formulaire HelloAsso), lien="/stages/", vide="…".
* [vhb_alertes], [vhb_boutique] : visibles seulement pendant leur période.
* [vhb_organigramme] : options groupe="bureau,ca", groupes="non", emails="non".
* [vhb_ag] : options quand="a_venir|passees|toutes", type="ca|publique", vide="…".
* [vhb_partenaires] : option niveau="principal,partenaire,institutionnel". [vhb_mentions] : mentions légales.
* [vhb_info cle="email" lien="oui"] : une donnée dans un texte. Clés : nom, sigle, saison, email, email_licences, telephone, siege, affiliation, facebook, instagram, gymnase, adresse, annees et tarif (avec categorie="u13-f"), essai, equipement, president, prochain_stage.

Résultats FFHB : le serveur du site va chercher les données sur ffhandball.fr (page « équipe » de chaque compétition + flux iCal du club pour les salles), les range dans la base WordPress, et les pages ne lisent que cette copie locale. Le navigateur des visiteurs ne contacte jamais la FFHB. Affichage (un bloc « HB Club : … » existe pour chacun) :

* [vhb_matchs] : « Les matchs du <sigle du club> », résultats de la dernière semaine jouée et matchs de la semaine à venir, toutes équipes. Le cadre mis en avant change seul : « Résultats » le jour et le lendemain du dernier match joué, « À venir » ensuite. Options : titre="…", surtitre="…", mise_en_avant="auto|resultats|avenir".
* [vhb_equipe slug="seniors-filles"] : panneau complet d'une équipe (à venir, résultats, classement ; onglets sur mobile). Option resultats="5" : 5 derniers résultats visibles, les autres repliés.
* [vhb_classement slug="u18-filles"] : classement seul.
* [vhb_prochain slug="u11-garcons"] : prochain match seul.
* [vhb_agenda slug="club"] : bouton d'abonnement à l'agenda des matchs (club entier ou une équipe).

Un bloc Gutenberg existe pour chacun (catégorie Widgets, nom commençant par « HB Club : »), avec choix de l'équipe dans le panneau de droite et aperçu réel.

Données brutes (copie locale) : /wp-json/vhb/v1/equipes et /wp-json/vhb/v1/equipes/<slug>

== Principes ==

* Années de naissance : stockées en âges, pas en années. Au changement de saison, U13 passe seul de 2014-2015 à 2015-2016.
* Code de catégorie : identique d'une saison à l'autre. Une page qui affiche « u13-f » montre la catégorie U13 F de la saison en cours, sans être modifiée.
* Tirets : tous les tirets affichés sont protégés de wptexturize (aucun tiret long sur le site).
* Cache : toute modification d'une donnée vide le cache des pages W3 Total Cache. Les CSS/JS de l'extension sont exclus de la minification.
* Aucune dépendance : types de contenu et métadonnées natifs WordPress.
* Droits : capacités « vhb_club_gerer » (données), « vhb_club_stages » (stages) et « vhb_club_reglages » (réglages, saisons, export). Rôles « Gestionnaire club » (données et stages, médiathèque) et « Éditeur de stages » (stages seulement, médiathèque).
* Club et Résultats FFHB liés : les gymnases avec un nom FFHB corrigent nom, adresse et GPS des matchs ; la fiche catégorie affiche le prochain match des équipes liées ; le tableau de bord signale les équipes non rattachées.

* Photos d'équipe : diffusées dans /wp-json/vhb/v1/equipes (champ « photo » : url, largeur, hauteur, moyenne, x, y, position, alt) et dans les données des pages (script vhb-club-donnees : « photos » par équipe, « photo » de chaque catégorie).
* Préfixes techniques vhb_, vhbc_ et vhb-club/ (options, shortcodes, blocs, route REST) : hérités de l'extension d'origine, gardés pour la compatibilité.

== Résultats FFHB : fonctionnement ==

* Fréquence : le week-end de 14 h à 23 h, toutes les heures ; le reste de la semaine, à 7 h et 19 h. Filtre « vhb_ffhb_is_due » pour changer la règle.
* Politesse : 1,5 s entre deux requêtes vers la FFHB, user-agent identifiant l'extension et le site.
* Découpage : une équipe par étape, pour ne jamais dépasser la limite de 30 s des hébergeurs. Le cron enchaîne les étapes puis replanifie la suite.
* Verrou : transient vhb_ffhb_lock (10 min), une exécution manuelle et le cron ne se marchent pas dessus.
* Échecs : une équipe en erreur garde ses dernières données ; l'erreur est notée dans le journal ; un e-mail part après 3 échecs consécutifs sur la même équipe.
* Blasons : téléchargés une fois dans wp-content/uploads/vhb-ffhb-logos/, jamais de lien direct vers les serveurs FFHB. Sans blason : pastille grise à initiales.
* Carte du lieu : chargée seulement quand un visiteur ouvre le lieu, en desktop (OpenStreetMap). En mobile : adresse + boutons Google Maps et Waze.

* Apparence des blocs Résultats : « Comme les pages du site » suit le bouton clair / sombre de l'en-tête (classe vhb-sombre sur <html>) ; « Sombre », « Clair » et « Automatique » restent disponibles.

== Installation ==

1. Extensions > Ajouter > Téléverser : envoyer hb-club-3.0.0.zip, puis Activer.
2. Au premier lancement, la saison en cours est créée et le nom du club reprend le nom du site.
3. HB Club > Réglages du club : nom, sigle, contacts, logo, gymnase principal.
4. Saisir les gymnases, les clubs en entente puis les catégories et leurs créneaux.
5. HB Club > Résultats FFHB : saison FFHB, identifiant du club, équipes et URL de leurs compétitions.
6. Contrôler HB Club > État.

Données de départ (profil) : si le dossier de l'extension contient profil/donnees.json (au format de HB Club > Export et import), ses données sont importées à la première activation sur une base vide. Un profil/logo.svg (silhouette blanche, un seul tracé) remplace l'icône du menu et des blocs. Pratique pour une édition propre à un club ou pour réinstaller un site.

== Édition propre à un club ==

Le code est le même pour tous. Une édition propre à un club ajoute seulement :

* un fichier principal à son nom, qui définit VHB_CLUB_FILE et VHB_MARQUE (nom affiché) puis inclut includes/charge.php, à la place de hb-club.php ;
* un dossier profil/ (données de départ et logo).

Deux éditions ne peuvent pas être actives en même temps sur un site.

== Nouvelle saison ==

Club :

1. HB Club > Saisons > « Créer la saison 2027-2028 » (copie des catégories cochée).
2. Ajuster les créneaux de la nouvelle saison (filtre « Saison 2027-2028 » dans la liste des catégories).
3. Le jour venu : « Mettre en ligne ». Tout le site bascule. « Revenir à cette saison » annule.

Résultats FFHB :

1. Changer « Saison en cours » (ex. saison-2027-2028-23).
2. Remplacer chaque URL de compétition des équipes par la nouvelle (les identifiants de compétition et d'équipe changent). Un avertissement s'affiche sous une URL qui ne correspond pas à la saison réglée.
3. Enregistrer : une mise à jour part dans la minute.

== Désinstallation ==

Désactiver ne supprime rien. Supprimer l'extension garde les données du club, les photos d'équipe et les réglages des Résultats FFHB : une réinstallation les retrouve. Sont effacés : les rôles « Gestionnaire club » et « Éditeur de stages » (comptes à réattribuer) et les caches des Résultats FFHB (résultats, journal, blasons et feuilles de match téléchargés), récupérés de nouveau à la réinstallation.

== Licence ==

Copyright (C) 2026 Julien Clavel.

Ce programme est un logiciel libre : vous pouvez le redistribuer et le modifier selon les termes de la Licence publique générale GNU (GPL) publiée par la Free Software Foundation, version 2 ou (à votre choix) toute version ultérieure. Il est distribué sans aucune garantie. Texte complet : fichier LICENSE.

== Journal des versions ==

= 3.2.0 =
* Données des pages : « breves » (alertes en cours de Vie du club : message, lien, niveau) et « boutique » (si elle est ouverte : titre, texte, lien), pour la page d'accueil de la refonte.

= 3.1.0 =
* Événements (ex « Stages ») : tous les événements du club dans un seul menu. Types : Stage, Tournoi, Loto, Assemblée générale, Fête / soirée du club, Action solidaire, Autre événement. Colonne « Type » dans la liste.
* Date à confirmer : case et texte libre (ex. « fin février ou début mars ») ; la date saisie sert à classer l'événement. Transmis aux données des pages (a_confirmer, date_texte, type_nom).
* Calendrier des événements (administration) : grille du mois avec navigation, ajout d'un événement en cliquant sur un jour, liste de la saison mois par mois.
* Lien HelloAsso demandé seulement pour les stages (avertissements, tableau de bord). La valeur « prochain stage » ne retient que les stages.

= 3.0.1 =
* Stages et événements : champs « Présentation » et « Article lié », transmis aux données des pages (agenda de la page Actualités).
* Articles : champ « Sous-titre » (méta vhb_sous_titre, lisible par l'API REST), pour la liste des actualités et le gabarit d'article.

= 3.0.0 =
* Première version publique. Issue de l'extension VHB 2.0.2 du Villeneuve Handball (elle-même née de VHB Résultats FFHB 1.0.0 à 1.4.0 et VHB Club 1.0.0 à 1.5.2), débarrassée des données propres à ce club.
* Données de départ et logo fournis par un profil facultatif (profil/donnees.json, profil/logo.svg), importé à la première activation.
* Nom affiché configurable (VHB_MARQUE) : menu, écrans, titres des blocs. Nom et sigle du club repris des réglages dans l'agenda iCal, les données structurées et le titre par défaut « Les matchs du … ».
* Réglages des Résultats FFHB vides par défaut (identifiant club, gymnases, équipes) ; sans identifiant club, les lieux des matchs ne sont pas demandés à la FFHB.
* Icône neutre (ballon) quand aucun logo n'est fourni.
