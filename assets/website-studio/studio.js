/**
 * AI Website Studio admin interface.
 *
 * Built on WordPress's bundled React (wp.element) and components, without JSX, so it needs
 * no build step. All data goes through the aiscs/v1 REST API with the core REST nonce.
 */
( function ( wp, boot ) {
	'use strict';

	if ( ! wp || ! wp.element || ! boot ) {
		return;
	}

	const { createElement: el, Fragment, useState, useEffect, useCallback, createRoot, render } = wp.element;
	const { Button, TextControl, SelectControl, CheckboxControl, Notice, Spinner } = wp.components;
	const apiFetch = wp.apiFetch;
	const { __, sprintf } = wp.i18n;
	const T = 'ai-seo-content-studio';

	const COLOR_ROLES = [ 'primary', 'secondary', 'accent', 'text', 'heading', 'background', 'surface', 'border' ];
	const SCALE_KEYS = [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'body' ];
	const DEVICES = [ 'desktop', 'tablet', 'mobile' ];

	const SECTION_LABELS = {
		hero: __( 'Hero', T ),
		cards: __( 'Cards / services', T ),
		cta: __( 'Call to action', T ),
		faq: __( 'FAQ', T ),
		testimonials: __( 'Testimonials', T ),
		contact: __( 'Contact', T ),
		image_grid: __( 'Image grid', T ),
		statistics: __( 'Statistics', T ),
		header: __( 'Header', T ),
		footer: __( 'Footer', T ),
		content: __( 'Content', T ),
	};

	const SOURCE_LABELS = {
		elementor_kit: __( 'Elementor Site Settings', T ),
		elementor_widgets: __( 'Page widgets / blocks', T ),
		theme_json: __( 'Theme styles (theme.json)', T ),
		css: __( 'Stylesheets', T ),
		user: __( 'Edited by you', T ),
		none: __( 'Not found', T ),
	};

	const NOTE_LABELS = {
		atomic_elements: __( 'This page uses Elementor 4 atomic elements. Their structure was read, but their styles are not analyzed yet.', T ),
		unavailable_widgets: __( 'The page uses widgets that are not available on this site (for example Elementor Pro widgets while Pro is inactive).', T ),
		no_kit: __( 'No Elementor Site Settings were found, so global colours and fonts come from the page and theme only.', T ),
		classic_content: __( 'This page uses the classic editor; sections were detected from its headings.', T ),
		elementor_inactive: __( 'This page was built with Elementor, but Elementor is not active. Activate Elementor for a full analysis.', T ),
	};

	/** REST error to a readable message. */
	function errorText( error ) {
		if ( error && error.message ) {
			const details = error.data && Array.isArray( error.data.errors ) ? ' ' + error.data.errors.join( '; ' ) : '';
			return error.message + details;
		}
		return __( 'The request failed. Check your connection and try again.', T );
	}

	function useNotice() {
		const [ notice, setNotice ] = useState( null );
		const view = notice
			? el( Notice, { status: notice.status, onRemove: () => setNotice( null ), className: 'aiscs-studio__notice' }, notice.text )
			: null;
		return [ view, setNotice ];
	}

	function Swatch( { hex, label } ) {
		if ( ! hex ) {
			return el( 'span', { className: 'aiscs-swatch aiscs-swatch--empty', title: __( 'Not found', T ) }, '—' );
		}
		return el(
			'span',
			{ className: 'aiscs-swatch' },
			el( 'span', { className: 'aiscs-swatch__chip', style: { background: hex }, 'aria-hidden': 'true' } ),
			el( 'code', null, hex ),
			label ? el( 'span', { className: 'aiscs-swatch__label' }, label ) : null
		);
	}

	/** Read-only summary of a token set. */
	function TokenSummary( { tokens } ) {
		if ( ! tokens ) {
			return null;
		}
		const t = tokens;
		const font = ( f ) => ( f && f.family ? f.family + ( f.weight ? ' · ' + f.weight : '' ) : '—' );
		const src = ( group ) => SOURCE_LABELS[ ( t.sources || {} )[ group ] || 'none' ];
		return el(
			'div',
			{ className: 'aiscs-tokens' },
			el( 'h3', null, __( 'Colours', T ), el( 'small', null, ' ' + src( 'colors' ) ) ),
			el(
				'div',
				{ className: 'aiscs-tokens__grid' },
				COLOR_ROLES.map( ( role ) => el( 'div', { key: role }, el( 'strong', null, role ), el( Swatch, { hex: t.colors[ role ] } ) ) )
			),
			t.palette && t.palette.length
				? el(
						'div',
						{ className: 'aiscs-tokens__palette' },
						el( 'span', null, __( 'Palette (most used first):', T ) ),
						t.palette.map( ( p ) => el( 'span', { key: p.hex, className: 'aiscs-swatch__chip aiscs-swatch__chip--lg', style: { background: p.hex }, title: p.hex + ( p.roles.length ? ' — ' + p.roles.join( ', ' ) : '' ) } ) )
				  )
				: null,
			el( 'h3', null, __( 'Typography', T ), el( 'small', null, ' ' + src( 'typography' ) ) ),
			el(
				'table',
				{ className: 'widefat striped aiscs-tokens__table' },
				el(
					'tbody',
					null,
					el( 'tr', null, el( 'th', null, __( 'Headings', T ) ), el( 'td', null, font( t.typography.heading ) ) ),
					el( 'tr', null, el( 'th', null, __( 'Body', T ) ), el( 'td', null, font( t.typography.body ) ) ),
					el( 'tr', null, el( 'th', null, __( 'Buttons', T ) ), el( 'td', null, font( t.typography.button ) ) ),
					SCALE_KEYS.map( ( k ) =>
						el( 'tr', { key: k }, el( 'th', null, k.toUpperCase() ), el( 'td', null, DEVICES.map( ( d ) => t.typography.scale[ k ][ d ] || '—' ).join( ' / ' ) ) )
					)
				)
			),
			el( 'p', { className: 'description' }, __( 'Sizes are desktop / tablet / mobile.', T ) ),
			el( 'h3', null, __( 'Components and layout', T ), el( 'small', null, ' ' + src( 'components' ) ) ),
			el(
				'table',
				{ className: 'widefat striped aiscs-tokens__table' },
				el(
					'tbody',
					null,
					el( 'tr', null, el( 'th', null, __( 'Button', T ) ), el( 'td', null, [ t.components.button.background, t.components.button.color, t.components.button.radius && sprintf( __( 'radius %s', T ), t.components.button.radius ), t.components.button.padding && sprintf( __( 'padding %s', T ), t.components.button.padding ) ].filter( Boolean ).join( ' · ' ) || '—' ) ),
					el( 'tr', null, el( 'th', null, __( 'Card', T ) ), el( 'td', null, [ t.components.card.background, t.components.card.radius && sprintf( __( 'radius %s', T ), t.components.card.radius ), t.components.card.shadow && sprintf( __( 'shadow %s', T ), t.components.card.shadow ) ].filter( Boolean ).join( ' · ' ) || '—' ) ),
					el( 'tr', null, el( 'th', null, __( 'Container width', T ) ), el( 'td', null, t.layout.container_width || '—' ) ),
					el( 'tr', null, el( 'th', null, __( 'Section padding', T ) ), el( 'td', null, DEVICES.map( ( d ) => t.spacing.section_padding[ d ] || '—' ).join( ' / ' ) ) ),
					el( 'tr', null, el( 'th', null, __( 'Breakpoints', T ) ), el( 'td', null, [ t.layout.breakpoints.tablet ? sprintf( __( 'tablet ≤ %dpx', T ), t.layout.breakpoints.tablet ) : '', t.layout.breakpoints.mobile ? sprintf( __( 'mobile ≤ %dpx', T ), t.layout.breakpoints.mobile ) : '' ].filter( Boolean ).join( ' · ' ) || '—' ) )
				)
			)
		);
	}

	/* ------------------------------------------------------------------ Dashboard */

	function Dashboard() {
		const [ profiles, setProfiles ] = useState( null );
		const [ noticeView, setNotice ] = useNotice();
		useEffect( () => {
			apiFetch( { path: '/aiscs/v1/website/brand-profiles?page=1' } )
				.then( setProfiles )
				.catch( ( e ) => setNotice( { status: 'error', text: errorText( e ) } ) );
		}, [] );
		const elementor = boot.elementor || {};
		const modules = [
			[ __( 'Website Brand Analyzer', T ), __( 'Available', T ), boot.urls.brand ],
			[ __( 'Brand Profiles', T ), __( 'Available', T ), boot.urls.profiles ],
			[ __( 'Clone Existing Page', T ), __( 'Planned: phase 2', T ) ],
			[ __( 'Native Elementor engine', T ), __( 'Planned: phase 3', T ) ],
			[ __( 'HTML to Elementor', T ), __( 'Planned: phase 4', T ) ],
			[ __( 'Image to Design', T ), __( 'Planned: phase 5', T ) ],
			[ __( 'AI Page Generator, images and internal links', T ), __( 'Planned: phase 6', T ) ],
			[ __( 'Consistency, accessibility, preview, revisions, design library', T ), __( 'Planned: phase 7', T ) ],
		];
		return el(
			Fragment,
			null,
			noticeView,
			el(
				'div',
				{ className: 'aiscs-studio__cards' },
				el(
					'div',
					{ className: 'aiscs-studio__card' },
					el( 'h2', null, __( 'Elementor', T ) ),
					elementor.active
						? el( 'p', null, sprintf( __( 'Elementor %s is active.', T ), elementor.version || '' ), ' ', elementor.pro_active ? __( 'Elementor Pro is active.', T ) : __( 'Elementor Pro is not active; generated pages will only use free widgets.', T ) )
						: el( Notice, { status: 'warning', isDismissible: false }, __( 'Elementor is not active. Design analysis still works for block and classic pages; Elementor page generation needs Elementor.', T ) )
				),
				el(
					'div',
					{ className: 'aiscs-studio__card' },
					el( 'h2', null, __( 'Brand profiles', T ) ),
					null === profiles ? el( Spinner ) : el( 'p', null, sprintf( __( '%d saved.', T ), profiles.total ) ),
					el( Button, { variant: 'primary', href: boot.urls.brand }, __( 'Analyze your website', T ) )
				)
			),
			el( 'h2', null, __( 'Modules', T ) ),
			el(
				'table',
				{ className: 'widefat striped' },
				el( 'thead', null, el( 'tr', null, el( 'th', null, __( 'Module', T ) ), el( 'th', null, __( 'Status', T ) ) ) ),
				el(
					'tbody',
					null,
					modules.map( ( [ name, status, url ] ) =>
						el( 'tr', { key: name }, el( 'td', null, url ? el( 'a', { href: url }, name ) : name ), el( 'td', null, status ) )
					)
				)
			)
		);
	}

	/* ------------------------------------------------------------- Brand analyzer */

	function BrandAnalyzer() {
		const [ query, setQuery ] = useState( { page: 1, search: '', post_type: 'page', builder: '' } );
		const [ search, setSearch ] = useState( '' );
		const [ list, setList ] = useState( null );
		const [ selected, setSelected ] = useState( [] );
		const [ busy, setBusy ] = useState( false );
		const [ result, setResult ] = useState( null );
		const [ name, setName ] = useState( '' );
		const [ saved, setSaved ] = useState( null );
		const [ noticeView, setNotice ] = useNotice();
		const max = 10;

		const load = useCallback( () => {
			setList( null );
			const params = new URLSearchParams( { page: String( query.page ), search: query.search, post_type: query.post_type, builder: query.builder } );
			apiFetch( { path: '/aiscs/v1/pages/available?' + params.toString() } )
				.then( setList )
				.catch( ( e ) => {
					setList( { items: [], total: 0, pages: 0 } );
					setNotice( { status: 'error', text: errorText( e ) } );
				} );
		}, [ query ] );
		useEffect( load, [ load ] );

		const toggle = ( id, on ) => {
			setSelected( ( cur ) => ( on ? ( cur.length >= max ? cur : cur.concat( id ) ) : cur.filter( ( x ) => x !== id ) ) );
		};

		const analyze = ( force ) => {
			setBusy( true );
			setSaved( null );
			apiFetch( { path: '/aiscs/v1/website/analyze', method: 'POST', data: { page_ids: selected, force: !! force } } )
				.then( ( r ) => {
					setResult( r );
					setNotice( null );
				} )
				.catch( ( e ) => setNotice( { status: 'error', text: errorText( e ) } ) )
				.finally( () => setBusy( false ) );
		};

		const save = () => {
			setBusy( true );
			apiFetch( {
				path: '/aiscs/v1/website/brand-profiles',
				method: 'POST',
				data: { name: name.trim(), analysis_ids: result.analyses.map( ( a ) => a.id ) },
			} )
				.then( ( p ) => {
					setSaved( p );
					setNotice( { status: 'success', text: sprintf( __( 'Brand profile "%s" saved.', T ), p.name ) } );
				} )
				.catch( ( e ) => setNotice( { status: 'error', text: errorText( e ) } ) )
				.finally( () => setBusy( false ) );
		};

		return el(
			Fragment,
			null,
			noticeView,
			el( 'p', { className: 'description' }, __( 'Choose up to 10 pages that represent your website. Their Elementor Site Settings, page styles, theme styles and stylesheets are read locally; no AI request is made and nothing is changed.', T ) ),
			el(
				'div',
				{ className: 'aiscs-studio__filters' },
				el( TextControl, { label: __( 'Search', T ), value: search, onChange: setSearch, __nextHasNoMarginBottom: true } ),
				el( Button, { variant: 'secondary', onClick: () => setQuery( Object.assign( {}, query, { page: 1, search } ) ) }, __( 'Search', T ) ),
				el( SelectControl, {
					label: __( 'Type', T ),
					value: query.post_type,
					options: [ { label: __( 'Pages', T ), value: 'page' }, { label: __( 'Posts', T ), value: 'post' } ],
					onChange: ( v ) => setQuery( Object.assign( {}, query, { page: 1, post_type: v } ) ),
					__nextHasNoMarginBottom: true,
				} ),
				el( SelectControl, {
					label: __( 'Builder', T ),
					value: query.builder,
					options: [ { label: __( 'All', T ), value: '' }, { label: 'Elementor', value: 'elementor' } ],
					onChange: ( v ) => setQuery( Object.assign( {}, query, { page: 1, builder: v } ) ),
					__nextHasNoMarginBottom: true,
				} )
			),
			null === list
				? el( Spinner )
				: el(
						Fragment,
						null,
						el(
							'table',
							{ className: 'widefat striped aiscs-studio__pages' },
							el(
								'thead',
								null,
								el( 'tr', null, el( 'td', { className: 'check-column' } ), el( 'th', null, __( 'Title', T ) ), el( 'th', null, 'ID' ), el( 'th', null, __( 'Status', T ) ), el( 'th', null, __( 'Builder', T ) ), el( 'th', null, __( 'Modified', T ) ) )
							),
							el(
								'tbody',
								null,
								list.items.length
									? list.items.map( ( p ) =>
											el(
												'tr',
												{ key: p.id },
												el( 'th', { className: 'check-column' }, el( CheckboxControl, { checked: selected.includes( p.id ), onChange: ( on ) => toggle( p.id, on ), label: '', 'aria-label': p.title, __nextHasNoMarginBottom: true } ) ),
												el(
													'td',
													null,
													p.featured_image ? el( 'img', { src: p.featured_image, alt: '', className: 'aiscs-studio__thumb' } ) : null,
													el( 'a', { href: p.url, target: '_blank', rel: 'noopener noreferrer' }, p.title || __( '(no title)', T ) )
												),
												el( 'td', null, p.id ),
												el( 'td', null, p.status ),
												el( 'td', null, p.builder ),
												el( 'td', null, new Date( p.modified ).toLocaleDateString() )
											)
									  )
									: el( 'tr', null, el( 'td', { colSpan: 6 }, __( 'No pages you can edit were found.', T ) ) )
							)
						),
						list.pages > 1
							? el(
									'div',
									{ className: 'aiscs-studio__pager' },
									el( Button, { variant: 'secondary', disabled: query.page <= 1, onClick: () => setQuery( Object.assign( {}, query, { page: query.page - 1 } ) ) }, __( 'Previous', T ) ),
									el( 'span', null, sprintf( __( 'Page %1$d of %2$d', T ), query.page, list.pages ) ),
									el( Button, { variant: 'secondary', disabled: query.page >= list.pages, onClick: () => setQuery( Object.assign( {}, query, { page: query.page + 1 } ) ) }, __( 'Next', T ) )
							  )
							: null
				  ),
			el(
				'div',
				{ className: 'aiscs-studio__actions' },
				el( Button, { variant: 'primary', disabled: busy || ! selected.length, isBusy: busy, onClick: () => analyze( false ) }, sprintf( __( 'Analyze %d selected', T ), selected.length ) ),
				result ? el( Button, { variant: 'tertiary', disabled: busy, onClick: () => analyze( true ) }, __( 'Re-analyze (ignore cache)', T ) ) : null,
				selected.length >= max ? el( 'span', { className: 'description' }, __( 'Maximum of 10 pages reached.', T ) ) : null
			),
			result ? el( AnalysisResult, { result } ) : null,
			result
				? el(
						'div',
						{ className: 'aiscs-studio__save' },
						el( TextControl, { label: __( 'Profile name', T ), value: name, onChange: setName, __nextHasNoMarginBottom: true } ),
						el( Button, { variant: 'primary', disabled: busy || ! name.trim(), onClick: save }, __( 'Save as brand profile', T ) ),
						saved ? el( Button, { variant: 'link', href: boot.urls.profiles + '&profile=' + saved.id }, __( 'Open and edit the profile', T ) ) : null
				  )
				: null
		);
	}

	function AnalysisResult( { result } ) {
		return el(
			'div',
			{ className: 'aiscs-studio__result' },
			el( 'h2', null, __( 'Combined design tokens', T ) ),
			el( TokenSummary, { tokens: result.tokens } ),
			el( 'h2', null, __( 'Pages', T ) ),
			result.analyses.map( ( a ) => {
				const an = a.analysis;
				const s = an.structure || {};
				return el(
					'details',
					{ key: a.id, className: 'aiscs-studio__analysis' },
					el( 'summary', null, ( an.page && an.page.title ) || '#' + a.source_page_id, ' — ', an.page && an.page.builder, a.cached ? ' ' + __( '(unchanged since last analysis)', T ) : '' ),
					( an.notes || [] ).map( ( n ) => el( Notice, { key: n, status: 'info', isDismissible: false }, NOTE_LABELS[ n ] || n ) ),
					el(
						'p',
						null,
						[
							sprintf( __( '%d elements', T ), s.element_count || 0 ),
							undefined !== s.widget_count ? sprintf( __( '%d widgets', T ), s.widget_count ) : '',
							s.max_depth ? sprintf( __( 'nesting depth %d', T ), s.max_depth ) : '',
							s.uses_containers ? __( 'Flexbox containers', T ) : '',
							s.uses_legacy_sections ? __( 'Legacy sections/columns', T ) : '',
							s.responsive_settings ? sprintf( __( '%d responsive settings', T ), s.responsive_settings ) : '',
							s.global_colors || s.global_typography ? sprintf( __( '%1$d global colour and %2$d global font references', T ), s.global_colors || 0, s.global_typography || 0 ) : '',
							s.custom_css ? __( 'custom CSS present', T ) : '',
						]
							.filter( Boolean )
							.join( ' · ' )
					),
					el(
						'table',
						{ className: 'widefat striped' },
						el( 'thead', null, el( 'tr', null, el( 'th', null, '#' ), el( 'th', null, __( 'Section type', T ) ), el( 'th', null, __( 'First heading', T ) ), el( 'th', null, __( 'Columns', T ) ), el( 'th', null, __( 'Images', T ) ), el( 'th', null, __( 'Buttons', T ) ) ) ),
						el(
							'tbody',
							null,
							( an.sections || [] ).map( ( sec ) =>
								el( 'tr', { key: sec.index }, el( 'td', null, sec.index + 1 ), el( 'td', null, SECTION_LABELS[ sec.type ] || sec.type ), el( 'td', null, sec.heading || '—' ), el( 'td', null, sec.columns ), el( 'td', null, sec.images ), el( 'td', null, sec.buttons ) )
							)
						)
					),
					el( 'h4', null, __( 'Tokens from this page alone', T ) ),
					el( TokenSummary, { tokens: an.tokens } )
				);
			} )
		);
	}

	/* ------------------------------------------------------------- Brand profiles */

	function clone( obj ) {
		return JSON.parse( JSON.stringify( obj ) );
	}

	function setPath( obj, path, value ) {
		const out = clone( obj );
		let cur = out;
		path.slice( 0, -1 ).forEach( ( k ) => {
			cur = cur[ k ];
		} );
		cur[ path[ path.length - 1 ] ] = value;
		return out;
	}

	function ColorField( { label, value, onChange } ) {
		const valid = /^#[0-9A-Fa-f]{6}$/.test( value || '' );
		return el(
			'div',
			{ className: 'aiscs-color-field' },
			el( 'input', { type: 'color', value: valid ? value : '#000000', onChange: ( e ) => onChange( e.target.value.toUpperCase() ), 'aria-label': label } ),
			el( TextControl, { label, value: value || '', onChange, placeholder: '#1F365C', __nextHasNoMarginBottom: true } )
		);
	}

	function ProfileEditor( { profile, onSaved, onDeleted } ) {
		const [ name, setName ] = useState( profile.name );
		const [ tokens, setTokens ] = useState( clone( profile.tokens ) );
		const [ busy, setBusy ] = useState( false );
		const [ noticeView, setNotice ] = useNotice();
		const set = ( path ) => ( v ) => setTokens( setPath( tokens, path, v ) );
		const field = ( label, path ) => el( TextControl, { key: path.join( '.' ), label, value: path.reduce( ( o, k ) => o[ k ], tokens ) || '', onChange: set( path ), __nextHasNoMarginBottom: true } );

		const save = () => {
			setBusy( true );
			apiFetch( { path: '/aiscs/v1/website/brand-profiles/' + profile.id, method: 'PUT', data: { name: name.trim(), tokens } } )
				.then( ( p ) => {
					setTokens( clone( p.tokens ) );
					setNotice( { status: 'success', text: __( 'Saved.', T ) } );
					onSaved( p );
				} )
				.catch( ( e ) => setNotice( { status: 'error', text: errorText( e ) } ) )
				.finally( () => setBusy( false ) );
		};
		const duplicate = () => {
			setBusy( true );
			apiFetch( { path: '/aiscs/v1/website/brand-profiles', method: 'POST', data: { name: sprintf( __( '%s (copy)', T ), name.trim() ).slice( 0, 191 ), tokens } } )
				.then( ( p ) => onSaved( p, true ) )
				.catch( ( e ) => setNotice( { status: 'error', text: errorText( e ) } ) )
				.finally( () => setBusy( false ) );
		};
		const remove = () => {
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( sprintf( __( 'Delete the brand profile "%s"? This cannot be undone.', T ), profile.name ) ) ) {
				return;
			}
			setBusy( true );
			apiFetch( { path: '/aiscs/v1/website/brand-profiles/' + profile.id, method: 'DELETE' } )
				.then( () => onDeleted( profile.id ) )
				.catch( ( e ) => {
					setNotice( { status: 'error', text: errorText( e ) } );
					setBusy( false );
				} );
		};

		return el(
			'div',
			{ className: 'aiscs-studio__editor' },
			noticeView,
			el( TextControl, { label: __( 'Profile name', T ), value: name, onChange: setName, __nextHasNoMarginBottom: true } ),
			el( 'h3', null, __( 'Colours', T ) ),
			el( 'div', { className: 'aiscs-studio__fields' }, COLOR_ROLES.map( ( role ) => el( ColorField, { key: role, label: role, value: tokens.colors[ role ], onChange: set( [ 'colors', role ] ) } ) ) ),
			el( 'h3', null, __( 'Fonts', T ) ),
			el(
				'div',
				{ className: 'aiscs-studio__fields' },
				[ 'heading', 'body', 'button' ].map( ( role ) => el( Fragment, { key: role }, field( sprintf( __( '%s font', T ), role ), [ 'typography', role, 'family' ] ), field( sprintf( __( '%s weight', T ), role ), [ 'typography', role, 'weight' ] ) ) )
			),
			el( 'h3', null, __( 'Type scale', T ) ),
			el(
				'table',
				{ className: 'widefat aiscs-studio__scale' },
				el( 'thead', null, el( 'tr', null, el( 'th', null ), DEVICES.map( ( d ) => el( 'th', { key: d }, d ) ) ) ),
				el(
					'tbody',
					null,
					SCALE_KEYS.map( ( k ) =>
						el(
							'tr',
							{ key: k },
							el( 'th', null, k.toUpperCase() ),
							DEVICES.map( ( d ) => el( 'td', { key: d }, el( TextControl, { value: tokens.typography.scale[ k ][ d ] || '', onChange: set( [ 'typography', 'scale', k, d ] ), placeholder: '32px', 'aria-label': k + ' ' + d, __nextHasNoMarginBottom: true } ) ) )
						)
					)
				)
			),
			el( 'h3', null, __( 'Components', T ) ),
			el(
				'div',
				{ className: 'aiscs-studio__fields' },
				el( ColorField, { label: __( 'Button background', T ), value: tokens.components.button.background, onChange: set( [ 'components', 'button', 'background' ] ) } ),
				el( ColorField, { label: __( 'Button text', T ), value: tokens.components.button.color, onChange: set( [ 'components', 'button', 'color' ] ) } ),
				field( __( 'Button radius', T ), [ 'components', 'button', 'radius' ] ),
				field( __( 'Button padding', T ), [ 'components', 'button', 'padding' ] ),
				el( ColorField, { label: __( 'Card background', T ), value: tokens.components.card.background, onChange: set( [ 'components', 'card', 'background' ] ) } ),
				field( __( 'Card radius', T ), [ 'components', 'card', 'radius' ] ),
				field( __( 'Card shadow', T ), [ 'components', 'card', 'shadow' ] ),
				field( __( 'Card padding', T ), [ 'components', 'card', 'padding' ] ),
				field( __( 'Image radius', T ), [ 'components', 'image', 'radius' ] )
			),
			el( 'h3', null, __( 'Spacing and layout', T ) ),
			el(
				'div',
				{ className: 'aiscs-studio__fields' },
				DEVICES.map( ( d ) => field( sprintf( __( 'Section padding (%s)', T ), d ), [ 'spacing', 'section_padding', d ] ) ),
				field( __( 'Gap between elements', T ), [ 'spacing', 'element_gap' ] ),
				field( __( 'Container width', T ), [ 'layout', 'container_width' ] )
			),
			el(
				'div',
				{ className: 'aiscs-studio__actions' },
				el( Button, { variant: 'primary', isBusy: busy, disabled: busy || ! name.trim(), onClick: save }, __( 'Save profile', T ) ),
				el( Button, { variant: 'secondary', disabled: busy, onClick: duplicate }, __( 'Duplicate', T ) ),
				el( Button, { variant: 'tertiary', isDestructive: true, disabled: busy, onClick: remove }, __( 'Delete', T ) )
			)
		);
	}

	function Profiles() {
		const [ page, setPage ] = useState( 1 );
		const [ list, setList ] = useState( null );
		const initial = parseInt( new URLSearchParams( window.location.search ).get( 'profile' ) || '0', 10 );
		const [ openId, setOpenId ] = useState( initial || 0 );
		const [ open, setOpen ] = useState( null );
		const [ noticeView, setNotice ] = useNotice();

		const load = useCallback( () => {
			apiFetch( { path: '/aiscs/v1/website/brand-profiles?page=' + page } )
				.then( setList )
				.catch( ( e ) => setNotice( { status: 'error', text: errorText( e ) } ) );
		}, [ page ] );
		useEffect( load, [ load ] );
		useEffect( () => {
			if ( ! openId ) {
				setOpen( null );
				return;
			}
			setOpen( null );
			apiFetch( { path: '/aiscs/v1/website/brand-profiles/' + openId } )
				.then( setOpen )
				.catch( ( e ) => setNotice( { status: 'error', text: errorText( e ) } ) );
		}, [ openId ] );

		return el(
			'div',
			{ className: 'aiscs-studio__split' },
			el(
				'div',
				{ className: 'aiscs-studio__list' },
				noticeView,
				null === list
					? el( Spinner )
					: list.items.length
					? el(
							'ul',
							null,
							list.items.map( ( p ) =>
								el(
									'li',
									{ key: p.id, className: p.id === openId ? 'is-active' : '' },
									el(
										Button,
										{ variant: 'link', onClick: () => setOpenId( p.id ) },
										el( 'span', { className: 'aiscs-swatch__chip', style: { background: p.tokens.colors.primary || 'transparent' }, 'aria-hidden': 'true' } ),
										p.name
									),
									el( 'small', null, new Date( p.updated_at ).toLocaleString() )
								)
							)
					  )
					: el( 'p', null, __( 'No brand profiles yet.', T ), ' ', el( 'a', { href: boot.urls.brand }, __( 'Analyze your website to create one.', T ) ) ),
				list && list.pages > 1
					? el(
							'div',
							{ className: 'aiscs-studio__pager' },
							el( Button, { variant: 'secondary', disabled: page <= 1, onClick: () => setPage( page - 1 ) }, __( 'Previous', T ) ),
							el( Button, { variant: 'secondary', disabled: page >= list.pages, onClick: () => setPage( page + 1 ) }, __( 'Next', T ) )
					  )
					: null
			),
			el(
				'div',
				{ className: 'aiscs-studio__detail' },
				openId && ! open ? el( Spinner ) : null,
				open
					? el( ProfileEditor, {
							key: open.id + ':' + open.updated_at,
							profile: open,
							onSaved: ( p, isNew ) => {
								// The editor already shows the saved values; refresh the list (names, colours, order).
								load();
								if ( isNew ) {
									setOpenId( p.id );
								}
							},
							onDeleted: () => {
								setOpenId( 0 );
								load();
							},
					  } )
					: ! openId
					? el( 'p', { className: 'description' }, __( 'Select a profile to view and edit its colours, fonts and spacing.', T ) )
					: null
			)
		);
	}

	/* ---------------------------------------------------------------------- Mount */

	const VIEWS = {
		dashboard: [ __( 'AI Website Studio', T ), Dashboard ],
		brand: [ __( 'Website Brand Analyzer', T ), BrandAnalyzer ],
		profiles: [ __( 'Brand Profiles', T ), Profiles ],
	};

	function App( { view } ) {
		const [ title, Component ] = VIEWS[ view ] || VIEWS.dashboard;
		return el( 'div', { className: 'aiscs-studio' }, el( 'h1', null, title ), el( Component ) );
	}

	function mount() {
		const node = document.getElementById( 'aiscs-studio' );
		if ( ! node ) {
			return;
		}
		const app = el( App, { view: node.getAttribute( 'data-view' ) || 'dashboard' } );
		if ( createRoot ) {
			createRoot( node ).render( app );
		} else {
			render( app, node );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', mount );
	} else {
		mount();
	}
} )( window.wp, window.aiscsStudio );
