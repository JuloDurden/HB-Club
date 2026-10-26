/**
 * Blocs VHB Résultats FFHB : éditeur.
 * Sans étape de compilation : on utilise les globales wp.* (pas de JSX).
 */
( function ( wp ) {
	var M = window.vhbMarque || 'HB Club'; // nom de l'édition (HB Club, VHB…)
	if ( ! wp || ! wp.blocks ) {
		return;
	}
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var TextControl = wp.components.TextControl;
	var Placeholder = wp.components.Placeholder;
	var ServerSideRender = wp.serverSideRender;

	var equipes = [ { value: '', label: '(choisir une équipe)' } ].concat( window.vhbFfhbEquipes || [] );

	function teamBlock( name, title, description, icon, extra ) {
		wp.blocks.registerBlockType( name, {
			apiVersion: 2,
			title: title,
			description: description,
			icon: icon,
			category: 'widgets',
			keywords: [ 'vhb', 'handball', 'ffhb' ],
			attributes: Object.assign( { slug: { type: 'string', default: '' } }, extra ? extra.attributes : {} ),
			edit: function ( props ) {
				var slug = props.attributes.slug;
				var picker = el( SelectControl, {
					label: 'Équipe',
					value: slug,
					options: equipes,
					onChange: function ( v ) {
						props.setAttributes( { slug: v } );
					},
				} );
				return el(
					'div',
					useBlockProps(),
					el( InspectorControls, null, el( PanelBody, { title: 'Réglages', initialOpen: true }, picker, extra ? extra.controls( props ) : null ) ),
					slug
						? el( ServerSideRender, { block: name, attributes: props.attributes } )
						: el( Placeholder, { icon: icon, label: title, instructions: description }, picker )
				);
			},
			save: function () {
				return null;
			},
		} );
	}

	teamBlock( 'vhb/equipe', M + ' : équipe', 'Panneau complet d’une équipe : à venir, résultats, classement.', 'groups', {
		attributes: { resultats: { type: 'string', default: '' } },
		controls: function ( props ) {
			return el( TextControl, {
				label: 'Résultats affichés',
				help: 'Nombre de derniers résultats visibles, les autres sont repliés. Vide : réglage général. 0 : tous.',
				type: 'number',
				min: 0,
				value: props.attributes.resultats,
				onChange: function ( v ) { props.setAttributes( { resultats: v } ); },
			} );
		},
	} );
	teamBlock( 'vhb/classement', M + ' : classement', 'Tableau de classement d’une équipe.', 'editor-ol' );
	teamBlock( 'vhb/prochain', M + ' : prochain match', 'La prochaine rencontre d’une équipe.', 'calendar-alt' );

	wp.blocks.registerBlockType( 'vhb/agenda', {
		apiVersion: 2,
		title: M + ' : ajouter à mon agenda',
		description: 'Bouton d’abonnement au calendrier des matchs (une équipe ou tout le club).',
		icon: 'calendar',
		category: 'widgets',
		keywords: [ 'vhb', 'agenda', 'calendrier', 'ical' ],
		attributes: { slug: { type: 'string', default: 'club' } },
		edit: function ( props ) {
			var opts = [ { value: 'club', label: 'Tout le club' } ].concat( window.vhbFfhbEquipes || [] );
			return el(
				'div',
				useBlockProps(),
				el( InspectorControls, null, el( PanelBody, { title: 'Réglages', initialOpen: true }, el( SelectControl, { label: 'Agenda de', value: props.attributes.slug, options: opts, onChange: function ( v ) { props.setAttributes( { slug: v } ); } } ) ) ),
				el( ServerSideRender, { block: 'vhb/agenda', attributes: props.attributes } )
			);
		},
		save: function () {
			return null;
		},
	} );

	wp.blocks.registerBlockType( 'vhb/matchs', {
		apiVersion: 2,
		title: M + ' : les matchs du club',
		description: 'Résultats et matchs à venir de toutes les équipes.',
		icon: 'awards',
		category: 'widgets',
		keywords: [ 'vhb', 'handball', 'ffhb', 'résultats' ],
		attributes: {
			titre: { type: 'string', default: 'Les matchs du ' + ( window.vhbSigle || 'club' ) },
			surtitre: { type: 'string', default: 'Sur le terrain' },
			miseEnAvant: { type: 'string', default: 'auto' },
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
						{ title: 'Réglages', initialOpen: true },
						el( TextControl, { label: 'Sur-titre', value: a.surtitre, onChange: function ( v ) { props.setAttributes( { surtitre: v } ); } } ),
						el( TextControl, { label: 'Titre', value: a.titre, onChange: function ( v ) { props.setAttributes( { titre: v } ); } } ),
						el( SelectControl, {
							label: 'Cadre mis en avant',
							value: a.miseEnAvant,
							options: [
								{ value: 'auto', label: 'Automatique (selon la date)' },
								{ value: 'resultats', label: 'Toujours les résultats' },
								{ value: 'avenir', label: 'Toujours les matchs à venir' },
							],
							onChange: function ( v ) { props.setAttributes( { miseEnAvant: v } ); },
						} )
					)
				),
				el( ServerSideRender, { block: 'vhb/matchs', attributes: a } )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
