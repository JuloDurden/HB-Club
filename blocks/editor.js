/**
 * Blocs VHB Club : éditeur.
 * Sans étape de compilation : globales wp.* (pas de JSX), comme VHB Résultats.
 */
( function ( wp ) {
	var M = window.vhbMarque || 'HB Club'; // nom de l'édition (HB Club, VHB…)
	if ( ! wp || ! wp.blocks ) {
		return;
	}
	var el = wp.element.createElement;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var TextControl = wp.components.TextControl;
	var ToggleControl = wp.components.ToggleControl;
	var CheckboxControl = wp.components.CheckboxControl;
	var BaseControl = wp.components.BaseControl;
	var Placeholder = wp.components.Placeholder;
	var ServerSideRender = wp.serverSideRender;

	var data = window.vhbClub || { categories: [], lieux: [], pratiques: [], cles: [], saison: '' };
	// Logo du club en silhouette, coloré par l'éditeur (currentColor).
	var icon = data.icone && data.icone.d
		? el( 'svg', { xmlns: 'http://www.w3.org/2000/svg', viewBox: data.icone.viewBox, width: 24, height: 24 }, el( 'path', { d: data.icone.d, fill: 'currentColor', fillRule: 'evenodd' } ) )
		: 'id-alt';

	function setter( props, key ) {
		return function ( v ) {
			var o = {};
			o[ key ] = v;
			props.setAttributes( o );
		};
	}

	function oui( props, key, label, help ) {
		return el( ToggleControl, {
			label: label,
			help: help,
			checked: 'oui' === props.attributes[ key ],
			onChange: function ( v ) {
				var o = {};
				o[ key ] = v ? 'oui' : 'non';
				props.setAttributes( o );
			},
		} );
	}

	/** Cases à cocher stockées en liste « a,b,c » (ordre de la liste d'origine). */
	function multi( props, key, label, options, help ) {
		var current = ( props.attributes[ key ] || '' ).split( ',' ).filter( Boolean );
		return el(
			BaseControl,
			{ label: label, help: help, id: 'vhbc-' + key },
			options.map( function ( o ) {
				return el( CheckboxControl, {
					key: o.value,
					label: o.label,
					checked: current.indexOf( o.value ) !== -1,
					onChange: function ( checked ) {
						var next = options
							.map( function ( x ) {
								return x.value;
							} )
							.filter( function ( v ) {
								return v === o.value ? checked : current.indexOf( v ) !== -1;
							} );
						var a = {};
						a[ key ] = next.join( ',' );
						props.setAttributes( a );
					},
				} );
			} )
		);
	}

	function preview( name, props ) {
		return el( ServerSideRender, { block: name, attributes: props.attributes } );
	}

	function register( name, def ) {
		wp.blocks.registerBlockType(
			name,
			Object.assign(
				{
					apiVersion: 2,
					icon: icon,
					category: 'widgets',
					keywords: [ 'vhb', 'club', 'handball' ],
					save: function () {
						return null;
					},
				},
				def
			)
		);
	}

	/* Planning ------------------------------------------------------------ */
	register( 'vhb-club/planning', {
		title: M + ' : planning des entraînements',
		description: 'Jours, heures et lieux d’entraînement, lus dans ' + M + ' (saison ' + data.saison + ').',
		keywords: [ 'vhb', 'planning', 'horaires', 'entraînements' ],
		attributes: {
			pratique: { type: 'string', default: '' },
			categorie: { type: 'string', default: '' },
			lieu: { type: 'string', default: '' },
			vue: { type: 'string', default: 'tableau' },
			groupe: { type: 'string', default: 'aucun' },
			lieux: { type: 'string', default: 'oui' },
			titre: { type: 'string', default: '' },
			surtitre: { type: 'string', default: '' },
		},
		edit: function ( props ) {
			var a = props.attributes;
			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: 'Affichage', initialOpen: true },
						el( SelectControl, {
							label: 'Présentation',
							value: a.vue,
							options: [
								{ value: 'tableau', label: 'Tableau par catégorie' },
								{ value: 'jours', label: 'Par jour de la semaine' },
							],
							onChange: setter( props, 'vue' ),
						} ),
						'tableau' === a.vue
							? el( ToggleControl, {
									label: 'Regrouper par pratique',
									checked: 'pratique' === a.groupe,
									onChange: function ( v ) {
										props.setAttributes( { groupe: v ? 'pratique' : 'aucun' } );
									},
							  } )
							: null,
						oui( props, 'lieux', 'Adresses sous le planning', 'Liste des gymnases utilisés, avec lien d’itinéraire.' ),
						el( TextControl, { label: 'Sur-titre (facultatif)', value: a.surtitre, onChange: setter( props, 'surtitre' ) } ),
						el( TextControl, { label: 'Titre (facultatif)', value: a.titre, onChange: setter( props, 'titre' ) } )
					),
					el(
						PanelBody,
						{ title: 'Filtres', initialOpen: false },
						multi( props, 'pratique', 'Pratiques', data.pratiques, 'Aucune case cochée : toutes les pratiques.' ),
						multi( props, 'categorie', 'Catégories', data.categories, 'Aucune case cochée : toutes les catégories.' ),
						el( SelectControl, {
							label: 'Gymnase',
							value: a.lieu,
							options: [ { value: '', label: 'Tous les gymnases' } ].concat( data.lieux ),
							onChange: setter( props, 'lieu' ),
						} )
					)
				),
				preview( 'vhb-club/planning', props )
			);
		},
	} );

	/* Catégorie ----------------------------------------------------------- */
	register( 'vhb-club/categorie', {
		title: M + ' : fiche catégorie',
		description: 'Années de naissance, description et créneaux d’une catégorie.',
		keywords: [ 'vhb', 'catégorie', 'équipe' ],
		attributes: {
			categorie: { type: 'string', default: '' },
			description: { type: 'string', default: 'oui' },
			creneaux: { type: 'string', default: 'oui' },
			tarif: { type: 'string', default: 'oui' },
			pratique_infos: { type: 'string', default: 'oui' },
			matchs: { type: 'string', default: 'oui' },
		},
		edit: function ( props ) {
			var a = props.attributes;
			var picker = el( SelectControl, {
				label: 'Catégorie',
				value: a.categorie,
				options: [ { value: '', label: '(choisir une catégorie)' } ].concat( data.categories ),
				onChange: setter( props, 'categorie' ),
			} );
			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: 'Réglages', initialOpen: true },
						picker,
						oui( props, 'description', 'Afficher la description' ),
						oui( props, 'creneaux', 'Afficher les entraînements' ),
						oui( props, 'tarif', 'Afficher le tarif' ),
						oui( props, 'pratique_infos', 'Afficher l’équipement et les séances d’essai' ),
						oui( props, 'matchs', 'Prochain match (Résultats FFHB)', 'Pour les catégories liées à une équipe des Résultats FFHB.' )
					)
				),
				a.categorie
					? preview( 'vhb-club/categorie', props )
					: el( Placeholder, { icon: icon, label: M + ' : fiche catégorie', instructions: 'Choisissez la catégorie à afficher.' }, picker )
			);
		},
	} );

	/* Lieu ---------------------------------------------------------------- */
	register( 'vhb-club/lieu', {
		title: M + ' : gymnase',
		description: 'Adresse d’un gymnase avec liens Google Maps et Waze.',
		keywords: [ 'vhb', 'gymnase', 'adresse', 'lieu' ],
		attributes: { lieu: { type: 'string', default: 'principal' } },
		edit: function ( props ) {
			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: 'Réglages', initialOpen: true },
						el( SelectControl, {
							label: 'Gymnase',
							value: props.attributes.lieu,
							options: [
								{ value: 'principal', label: 'Gymnase principal du club' },
								{ value: 'club', label: 'Tous les gymnases du club' },
								{ value: 'partenaire', label: 'Tous les gymnases partenaires' },
								{ value: 'tous', label: 'Tous les gymnases' },
							].concat( data.lieux ),
							onChange: setter( props, 'lieu' ),
						} )
					)
				),
				preview( 'vhb-club/lieu', props )
			);
		},
	} );

	/* Titres facultatifs (partagés) ------------------------------------- */
	function titres( props ) {
		return [
			el( TextControl, { key: 's', label: 'Sur-titre (facultatif)', value: props.attributes.surtitre, onChange: setter( props, 'surtitre' ) } ),
			el( TextControl, { key: 't', label: 'Titre (facultatif)', value: props.attributes.titre, onChange: setter( props, 'titre' ) } ),
		];
	}
	function simple( name, def, controls ) {
		register( name, Object.assign( def, {
			edit: function ( props ) {
				return el(
					'div',
					useBlockProps(),
					controls ? el( InspectorControls, null, el( PanelBody, { title: 'Réglages', initialOpen: true }, controls( props ) ) ) : null,
					preview( name, props )
				);
			},
		} ) );
	}
	var tTitres = { titre: { type: 'string', default: '' }, surtitre: { type: 'string', default: '' } };

	/* Tarifs --------------------------------------------------------------- */
	simple( 'vhb-club/tarifs', {
		title: M + ' : grille tarifaire',
		description: 'Tarif de chaque catégorie de la saison, autres licences et mention de l’AG.',
		keywords: [ 'vhb', 'tarifs', 'prix', 'licence', 'cotisation' ],
		attributes: Object.assign( {
			pratique: { type: 'string', default: '' },
			regrouper: { type: 'string', default: 'oui' },
			inclus: { type: 'string', default: 'oui' },
			autres: { type: 'string', default: 'oui' },
			mention: { type: 'string', default: 'oui' },
		}, tTitres ),
	}, function ( props ) {
		return [
			multi( props, 'pratique', 'Pratiques', data.pratiques, 'Aucune case cochée : toutes les pratiques.' ),
			oui( props, 'regrouper', 'Regrouper les catégories de même tarif', 'Ex. « U13 F · U13 G » sur une ligne.' ),
			oui( props, 'inclus', 'Colonne « Inclus » (si renseignée)' ),
			oui( props, 'autres', 'Autres licences (dirigeant…)' ),
			oui( props, 'mention', 'Mention sous les tarifs' ),
		].concat( titres( props ) );
	} );

	/* Réductions et paiement ------------------------------------------------ */
	simple( 'vhb-club/reductions', {
		title: M + ' : réductions et paiement',
		description: 'Réductions (famille, Pass’Sport…), modes de paiement, séances d’essai.',
		keywords: [ 'vhb', 'réduction', 'paiement', 'pass sport', 'helloasso' ],
		attributes: Object.assign( {
			reductions: { type: 'string', default: 'oui' },
			paiement: { type: 'string', default: 'oui' },
			essai: { type: 'string', default: 'non' },
		}, tTitres ),
	}, function ( props ) {
		return [
			oui( props, 'reductions', 'Réductions et aides' ),
			oui( props, 'paiement', 'Modes de paiement' ),
			oui( props, 'essai', 'Séances d’essai' ),
		].concat( titres( props ) );
	} );

	/* Pièces et documents --------------------------------------------------- */
	simple( 'vhb-club/pieces', {
		title: M + ' : pièces à fournir',
		description: 'Pièces du dossier d’inscription, documents à télécharger, équipement.',
		keywords: [ 'vhb', 'inscription', 'certificat', 'documents', 'dossier' ],
		attributes: Object.assign( {
			pieces: { type: 'string', default: 'oui' },
			documents: { type: 'string', default: 'oui' },
			equipement: { type: 'string', default: 'non' },
		}, tTitres ),
	}, function ( props ) {
		return [
			oui( props, 'pieces', 'Pièces à fournir' ),
			oui( props, 'documents', 'Documents à télécharger' ),
			oui( props, 'equipement', 'Équipement' ),
		].concat( titres( props ) );
	} );

	/* Bandeau --------------------------------------------------------------- */
	simple( 'vhb-club/bandeau-inscriptions', {
		title: M + ' : bandeau inscriptions',
		description: 'Bandeau « Inscriptions ouvertes », visible seulement quand il est activé dans ' + M + ' > Inscriptions.',
		keywords: [ 'vhb', 'inscriptions', 'bandeau' ],
		attributes: {},
	} );

	/* Lot 3 ---------------------------------------------------------------- */
	function attrs( o ) {
		var r = {};
		Object.keys( o ).forEach( function ( k ) {
			r[ k ] = { type: 'string', default: o[ k ] };
		} );
		return r;
	}
	function sel( props, key, label, options ) {
		return el( SelectControl, { key: key, label: label, value: props.attributes[ key ], options: options, onChange: setter( props, key ) } );
	}

	simple( 'vhb-club/stages', {
		title: M + ' : stages et événements',
		description: 'Stages à venir avec dates, horaires, tarifs et réservation HelloAsso. Un stage passé disparaît seul.',
		keywords: [ 'vhb', 'stage', 'vacances', 'helloasso', 'événement' ],
		attributes: attrs( { quand: 'a_venir', nombre: '0', type: '', vue: 'cartes', tarifs: 'oui', widget: 'non', lien: '', vide: 'Aucun stage programmé pour le moment. Les prochains stages seront annoncés ici.', titre: '', surtitre: '' } ),
	}, function ( props ) {
		return [
			sel( props, 'vue', 'Présentation', [ { value: 'cartes', label: 'Fiches complètes (page Stages)' }, { value: 'compact', label: 'Lignes courtes (accueil)' } ] ),
			sel( props, 'quand', 'Stages', [ { value: 'a_venir', label: 'À venir et en cours' }, { value: 'passes', label: 'Passés' }, { value: 'tous', label: 'Tous' } ] ),
			el( TextControl, { key: 'n', label: 'Nombre maximum (0 : tous)', type: 'number', min: 0, value: props.attributes.nombre, onChange: setter( props, 'nombre' ) } ),
			multi( props, 'type', 'Types', data.typesStage || [], 'Aucune case cochée : tous les types.' ),
			oui( props, 'tarifs', 'Tableau des tarifs' ),
			oui( props, 'widget', 'Formulaire HelloAsso intégré', 'En plus du bouton « Réserver ».' ),
			el( TextControl, { key: 'l', label: 'Lien des lignes courtes (facultatif)', help: 'Ex. la page Stages. Vide : la billetterie HelloAsso.', value: props.attributes.lien, onChange: setter( props, 'lien' ) } ),
			el( TextControl, { key: 'v', label: 'Texte quand il n’y a aucun stage', value: props.attributes.vide, onChange: setter( props, 'vide' ) } ),
		].concat( titres( props ) );
	} );

	simple( 'vhb-club/alertes', {
		title: M + ' : alertes',
		description: 'Alertes en cours (gymnase fermé, entraînement annulé…). Rien ne s’affiche quand il n’y en a pas.',
		keywords: [ 'vhb', 'alerte', 'fermeture', 'annulation' ],
		attributes: {},
	} );

	simple( 'vhb-club/organigramme', {
		title: M + ' : organigramme',
		description: 'Bureau, CA, commissions et bénévoles, avec photo ou initiales.',
		keywords: [ 'vhb', 'organigramme', 'bureau', 'ca', 'qui fait quoi' ],
		attributes: attrs( { groupe: '', groupes: 'oui', emails: 'oui', titre: '', surtitre: '' } ),
	}, function ( props ) {
		return [
			multi( props, 'groupe', 'Groupes affichés', data.instances || [], 'Aucune case cochée : tous.' ),
			oui( props, 'groupes', 'Titre de chaque groupe' ),
			oui( props, 'emails', 'Emails de fonction' ),
		].concat( titres( props ) );
	} );

	simple( 'vhb-club/ag', {
		title: M + ' : assemblées générales',
		description: 'AG à venir et comptes rendus des AG passées.',
		keywords: [ 'vhb', 'assemblée', 'ag', 'compte rendu' ],
		attributes: attrs( { quand: 'toutes', type: '', vide: '', titre: '', surtitre: '' } ),
	}, function ( props ) {
		return [
			sel( props, 'quand', 'AG', [ { value: 'toutes', label: 'Toutes' }, { value: 'a_venir', label: 'À venir' }, { value: 'passees', label: 'Passées' } ] ),
			sel( props, 'type', 'Type', [ { value: '', label: 'Tous' }, { value: 'publique', label: 'AG grand public' }, { value: 'ca', label: 'AG du CA' } ] ),
			el( TextControl, { key: 'v', label: 'Texte quand il n’y en a aucune', value: props.attributes.vide, onChange: setter( props, 'vide' ) } ),
		].concat( titres( props ) );
	} );

	simple( 'vhb-club/boutique', {
		title: M + ' : boutique',
		description: 'Bandeau de la boutique du club, visible quand il est activé dans ' + M + ' > Vie du club.',
		keywords: [ 'vhb', 'boutique', 'maillot', 'commande' ],
		attributes: {},
	} );

	simple( 'vhb-club/partenaires', {
		title: M + ' : partenaires',
		description: 'Logos des partenaires et sponsors.',
		keywords: [ 'vhb', 'partenaires', 'sponsors' ],
		attributes: attrs( { niveau: '', groupes: 'oui', titre: '', surtitre: '' } ),
	}, function ( props ) {
		return [
			multi( props, 'niveau', 'Niveaux', data.niveaux || [], 'Aucune case cochée : tous.' ),
			oui( props, 'groupes', 'Titre de chaque niveau' ),
		].concat( titres( props ) );
	} );

	simple( 'vhb-club/mentions', {
		title: M + ' : mentions légales',
		description: 'Éditeur, directeur de la publication, hébergeur, données personnelles.',
		keywords: [ 'vhb', 'mentions légales', 'rgpd' ],
		attributes: {},
	} );

	/* Donnée unitaire ----------------------------------------------------- */
	register( 'vhb-club/info', {
		title: M + ' : donnée du club',
		description: 'Une information isolée (email, saison, adresse…), toujours à jour. Dans un paragraphe, utiliser plutôt le shortcode [vhb_info cle="…"].',
		keywords: [ 'vhb', 'email', 'saison', 'adresse', 'info' ],
		attributes: {
			cle: { type: 'string', default: '' },
			lien: { type: 'string', default: 'non' },
			categorie: { type: 'string', default: '' },
		},
		edit: function ( props ) {
			var a = props.attributes;
			var picker = el( SelectControl, {
				label: 'Donnée',
				value: a.cle,
				options: [ { value: '', label: '(choisir une donnée)' } ].concat( data.cles ),
				onChange: setter( props, 'cle' ),
			} );
			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: 'Réglages', initialOpen: true },
						picker,
						'annees' === a.cle
							? el( SelectControl, {
									label: 'Catégorie',
									value: a.categorie,
									options: [ { value: '', label: '(choisir une catégorie)' } ].concat( data.categories ),
									onChange: setter( props, 'categorie' ),
							  } )
							: null,
						oui( props, 'lien', 'Cliquable', 'Email, téléphone, réseau social ou itinéraire.' )
					)
				),
				a.cle ? preview( 'vhb-club/info', props ) : el( Placeholder, { icon: icon, label: M + ' : donnée du club' }, picker )
			);
		},
	} );
} )( window.wp );
