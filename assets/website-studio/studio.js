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
			[ __( 'Clone Existing Page', T ), __( 'Available', T ), boot.urls.clone ],
			[ __( 'Native Elementor engine and Generated Pages', T ), __( 'Available', T ), boot.urls.pages ],
			[ __( 'HTML to Elementor', T ), __( 'Available', T ), boot.urls.html ],
			[ __( 'Image to Design', T ), __( 'Available', T ), boot.urls.image ],
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

	/* ------------------------------------------------------------ Clone a page */

	const REASON_LABELS = {
		site_chrome: __( 'Header or footer', T ),
		brand: __( 'Business name', T ),
		legal: __( 'Legal text', T ),
		contact: __( 'Contact details', T ),
		testimonial: __( 'Testimonial: real words are never rewritten', T ),
		statistic: __( 'Numbers: never invented', T ),
		dynamic: __( 'Dynamic content', T ),
	};

	const ROLE_LABELS = {
		heading: __( 'Heading', T ),
		paragraph: __( 'Paragraph', T ),
		button: __( 'Button', T ),
		card_title: __( 'Card title', T ),
		card_text: __( 'Card text', T ),
		caption: __( 'Caption', T ),
		list_item: __( 'List item', T ),
		faq_question: __( 'FAQ question', T ),
		faq_answer: __( 'FAQ answer', T ),
		tab_title: __( 'Tab title', T ),
		testimonial: __( 'Testimonial', T ),
		statistic: __( 'Statistic', T ),
	};

	/** Links and messages for a created draft. */
	function DraftResult( { result } ) {
		const issues = ( result.ai_warnings || [] ).map( ( w ) => {
			const where = w.role ? ( ROLE_LABELS[ w.role ] || w.role ) + ': “' + ( w.text || '' ).slice( 0, 60 ) + '”' : w.key;
			return 'longer_than_design' === w.issue
				? sprintf( __( '%s — the new text is much longer than the original and may not fit the design.', T ), where )
				: sprintf( __( '%s — the AI did not write this one, so the original text was kept.', T ), where );
		} );
		const warnings = [].concat( result.plan_warnings || [], result.warnings || [], issues );
		return el(
			'div',
			{ className: 'aiscs-studio__card aiscs-studio__done' },
			el( 'h2', null, __( 'Draft page created', T ) ),
			el( 'p', null, __( 'The new page is saved as a draft. The original page was not changed.', T ), result.applied ? ' ' + sprintf( __( '%d text fields were rewritten.', T ), result.applied.length ) : '' ),
			el(
				'div',
				{ className: 'aiscs-studio__actions' },
				result.elementor_url ? el( Button, { variant: 'primary', href: result.elementor_url, target: '_blank' }, __( 'Open in Elementor', T ) ) : null,
				result.preview_url ? el( Button, { variant: 'secondary', href: result.preview_url, target: '_blank' }, __( 'Preview', T ) ) : null,
				result.edit_url ? el( Button, { variant: 'tertiary', href: result.edit_url }, __( 'Edit page settings', T ) ) : null
			),
			warnings.length ? el( Notice, { status: 'warning', isDismissible: false }, el( 'ul', null, warnings.map( ( w, i ) => el( 'li', { key: i }, w ) ) ) ) : null
		);
	}

	/** Poll a background job until it ends. */
	function useJob( projectId, run ) {
		const [ state, setState ] = useState( null );
		useEffect( () => {
			if ( ! projectId ) {
				return undefined;
			}
			let stop = false;
			let timer = null;
			const poll = () => {
				apiFetch( { path: '/aiscs/v1/design/jobs/' + projectId } )
					.then( ( r ) => {
						if ( stop ) {
							return;
						}
						setState( r );
						const status = r.job ? r.job.status : '';
						if ( 'queued' === status || 'running' === status ) {
							timer = setTimeout( poll, 3000 );
						}
					} )
					.catch( ( e ) => {
						if ( ! stop ) {
							setState( { error: errorText( e ) } );
						}
					} );
			};
			poll();
			return () => {
				stop = true;
				clearTimeout( timer );
			};
		}, [ projectId, run ] );
		return state;
	}

	function JobProgress( { projectId, onCancel } ) {
		const state = useJob( projectId );
		if ( ! state ) {
			return el( Spinner );
		}
		if ( state.error ) {
			return el( Notice, { status: 'error', isDismissible: false }, state.error );
		}
		const job = state.job || {};
		if ( 'completed' === job.status && state.result ) {
			return el( DraftResult, { result: state.result } );
		}
		if ( 'failed' === job.status || 'cancelled' === job.status ) {
			return el( Notice, { status: 'failed' === job.status ? 'error' : 'warning', isDismissible: false }, job.message || job.error || __( 'The job stopped.', T ) );
		}
		return el(
			'div',
			{ className: 'aiscs-studio__card' },
			el( 'h2', null, __( 'Working…', T ) ),
			el(
				'ol',
				{ className: 'aiscs-studio__steps' },
				( job.steps || [] ).map( ( s, i ) =>
					el( 'li', { key: s.id, className: i < job.step_index ? 'is-done' : i === job.step_index ? 'is-current' : '' }, s.label, i === job.step_index ? el( Spinner ) : null )
				)
			),
			el( 'p', { className: 'description' }, job.message ),
			el( Button, { variant: 'tertiary', isDestructive: true, onClick: onCancel }, __( 'Cancel', T ) )
		);
	}

	function ClonePage() {
		const [ search, setSearch ] = useState( '' );
		const [ query, setQuery ] = useState( { page: 1, search: '' } );
		const [ list, setList ] = useState( null );
		const [ source, setSource ] = useState( null );
		const [ plan, setPlan ] = useState( null );
		const [ chosen, setChosen ] = useState( {} );
		const [ profiles, setProfiles ] = useState( [] );
		const [ form, setForm ] = useState( { topic: '', primary_keyword: '', secondary: '', audience: '', intent: '', location: '', tone: '', notes: '', title: '', profile_id: '0' } );
		const [ busy, setBusy ] = useState( false );
		const [ projectId, setProjectId ] = useState( 0 );
		const [ duplicate, setDuplicate ] = useState( null );
		const [ noticeView, setNotice ] = useNotice();

		useEffect( () => {
			setList( null );
			const params = new URLSearchParams( { page: String( query.page ), search: query.search, post_type: 'page', builder: 'elementor' } );
			apiFetch( { path: '/aiscs/v1/pages/available?' + params.toString() } )
				.then( setList )
				.catch( ( e ) => setNotice( { status: 'error', text: errorText( e ) } ) );
		}, [ query ] );
		useEffect( () => {
			apiFetch( { path: '/aiscs/v1/website/brand-profiles?page=1' } )
				.then( ( r ) => setProfiles( r.items || [] ) )
				.catch( () => setProfiles( [] ) );
		}, [] );

		const choose = ( page ) => {
			setSource( page );
			setPlan( null );
			setProjectId( 0 );
			setDuplicate( null );
			apiFetch( { path: '/aiscs/v1/pages/' + page.id + '/clone/plan' } )
				.then( ( p ) => {
					setPlan( p );
					const c = {};
					p.slots.forEach( ( s ) => {
						c[ s.key ] = !! s.replace;
					} );
					setChosen( c );
				} )
				.catch( ( e ) => setNotice( { status: 'error', text: errorText( e ) } ) );
		};
		const set = ( key ) => ( v ) => setForm( Object.assign( {}, form, { [ key ]: v } ) );

		const runDuplicate = () => {
			setBusy( true );
			apiFetch( { path: '/aiscs/v1/pages/' + source.id + '/clone', method: 'POST', data: { title: form.title } } )
				.then( setDuplicate )
				.catch( ( e ) => setNotice( { status: 'error', text: errorText( e ) } ) )
				.finally( () => setBusy( false ) );
		};
		const runGenerate = () => {
			setBusy( true );
			apiFetch( {
				path: '/aiscs/v1/pages/' + source.id + '/clone-and-generate',
				method: 'POST',
				data: {
					topic: form.topic,
					primary_keyword: form.primary_keyword,
					secondary_keywords: form.secondary.split( ',' ).map( ( s ) => s.trim() ).filter( Boolean ),
					audience: form.audience,
					intent: form.intent,
					location: form.location,
					tone: form.tone,
					notes: form.notes,
					title: form.title,
					profile_id: parseInt( form.profile_id, 10 ) || 0,
					selected: Object.keys( chosen ).filter( ( k ) => chosen[ k ] ),
				},
			} )
				.then( ( r ) => setProjectId( r.project_id ) )
				.catch( ( e ) => setNotice( { status: 'error', text: errorText( e ) } ) )
				.finally( () => setBusy( false ) );
		};
		const cancel = () => {
			apiFetch( { path: '/aiscs/v1/design/jobs/' + projectId + '/cancel', method: 'POST' } ).finally( () => setProjectId( 0 ) );
		};

		if ( projectId ) {
			return el( Fragment, null, noticeView, el( JobProgress, { projectId, onCancel: cancel } ), el( Button, { variant: 'link', onClick: () => setProjectId( 0 ) }, __( 'Back to the form', T ) ) );
		}
		if ( duplicate ) {
			return el( Fragment, null, el( DraftResult, { result: duplicate } ), el( Button, { variant: 'link', onClick: () => setDuplicate( null ) }, __( 'Back', T ) ) );
		}

		const count = Object.values( chosen ).filter( Boolean ).length;
		return el(
			Fragment,
			null,
			noticeView,
			el( 'p', { className: 'description' }, __( 'Choose an Elementor page whose design you want to reuse. A new draft page is created with the same layout and styles; the original page is never changed.', T ) ),
			el(
				'div',
				{ className: 'aiscs-studio__filters' },
				el( TextControl, { label: __( 'Search Elementor pages', T ), value: search, onChange: setSearch, __nextHasNoMarginBottom: true } ),
				el( Button, { variant: 'secondary', onClick: () => setQuery( { page: 1, search } ) }, __( 'Search', T ) )
			),
			null === list
				? el( Spinner )
				: el(
						'table',
						{ className: 'widefat striped' },
						el( 'tbody', null, list.items.length
							? list.items.map( ( p ) =>
									el(
										'tr',
										{ key: p.id, className: source && source.id === p.id ? 'is-selected' : '' },
										el( 'td', null, el( 'strong', null, p.title || __( '(no title)', T ) ), ' ', el( 'span', { className: 'description' }, p.status ) ),
										el( 'td', null, el( 'a', { href: p.url, target: '_blank', rel: 'noopener noreferrer' }, __( 'View', T ) ) ),
										el( 'td', null, el( Button, { variant: source && source.id === p.id ? 'primary' : 'secondary', onClick: () => choose( p ) }, __( 'Use this design', T ) ) )
									)
							  )
							: el( 'tr', null, el( 'td', null, __( 'No Elementor pages you can edit were found.', T ) ) ) )
				  ),
			list && list.pages > 1
				? el(
						'div',
						{ className: 'aiscs-studio__pager' },
						el( Button, { variant: 'secondary', disabled: query.page <= 1, onClick: () => setQuery( Object.assign( {}, query, { page: query.page - 1 } ) ) }, __( 'Previous', T ) ),
						el( Button, { variant: 'secondary', disabled: query.page >= list.pages, onClick: () => setQuery( Object.assign( {}, query, { page: query.page + 1 } ) ) }, __( 'Next', T ) )
				  )
				: null,
			source && ! plan ? el( Spinner ) : null,
			plan
				? el(
						'div',
						{ className: 'aiscs-studio__result' },
						el( 'h2', null, sprintf( __( 'Design: %s', T ), plan.source.title ) ),
						plan.warnings.map( ( w, i ) => el( Notice, { key: i, status: 'warning', isDismissible: false }, w ) ),
						el( 'p', null, sprintf( __( '%1$d sections, %2$d widgets. %3$d of %4$d text fields will be rewritten.', T ), plan.sections.length, plan.stats.widgets, count, plan.slots.filter( ( s ) => ! s.locked ).length ) ),
						el(
							'details',
							{ className: 'aiscs-studio__analysis' },
							el( 'summary', null, __( 'Choose which text to rewrite', T ) ),
							el(
								'table',
								{ className: 'widefat striped' },
								el( 'thead', null, el( 'tr', null, el( 'td', { className: 'check-column' } ), el( 'th', null, __( 'Section', T ) ), el( 'th', null, __( 'Type', T ) ), el( 'th', null, __( 'Current text', T ) ), el( 'th', null, __( 'Kept because', T ) ) ) ),
								el(
									'tbody',
									null,
									plan.slots.map( ( s ) =>
										el(
											'tr',
											{ key: s.key },
											el( 'th', { className: 'check-column' }, el( CheckboxControl, { checked: !! chosen[ s.key ], disabled: s.locked, onChange: ( on ) => setChosen( Object.assign( {}, chosen, { [ s.key ]: on } ) ), label: '', 'aria-label': s.text || s.role, __nextHasNoMarginBottom: true } ) ),
											el( 'td', null, ( SECTION_LABELS[ s.section_type ] || s.section_type ) + ' ' + ( s.section_index + 1 ) ),
											el( 'td', null, ( ROLE_LABELS[ s.role ] || s.role ) + ( s.level ? ' (' + s.level.toUpperCase() + ')' : '' ) ),
											el( 'td', null, s.text ? s.text.slice( 0, 140 ) : '—' ),
											el( 'td', null, s.reason ? REASON_LABELS[ s.reason ] || s.reason : '' )
										)
									)
								)
							)
						),
						el( 'h2', null, __( 'New page', T ) ),
						el(
							'div',
							{ className: 'aiscs-studio__fields' },
							el( TextControl, { label: __( 'Page topic (required)', T ), value: form.topic, onChange: set( 'topic' ), __nextHasNoMarginBottom: true } ),
							el( TextControl, { label: __( 'Primary keyword (required)', T ), value: form.primary_keyword, onChange: set( 'primary_keyword' ), __nextHasNoMarginBottom: true } ),
							el( TextControl, { label: __( 'Secondary keywords (comma separated)', T ), value: form.secondary, onChange: set( 'secondary' ), __nextHasNoMarginBottom: true } ),
							el( TextControl, { label: __( 'Target audience', T ), value: form.audience, onChange: set( 'audience' ), __nextHasNoMarginBottom: true } ),
							el( SelectControl, {
								label: __( 'Search intent', T ),
								value: form.intent,
								options: [
									{ label: __( 'Not specified', T ), value: '' },
									{ label: __( 'Informational', T ), value: 'informational' },
									{ label: __( 'Commercial', T ), value: 'commercial' },
									{ label: __( 'Transactional', T ), value: 'transactional' },
									{ label: __( 'Local', T ), value: 'local' },
								],
								onChange: set( 'intent' ),
								__nextHasNoMarginBottom: true,
							} ),
							el( TextControl, { label: __( 'Target location', T ), value: form.location, onChange: set( 'location' ), __nextHasNoMarginBottom: true } ),
							el( TextControl, { label: __( 'Tone', T ), value: form.tone, onChange: set( 'tone' ), placeholder: __( 'Friendly, expert', T ), __nextHasNoMarginBottom: true } ),
							el( TextControl, { label: __( 'New page title (optional)', T ), value: form.title, onChange: set( 'title' ), __nextHasNoMarginBottom: true } ),
							el( SelectControl, {
								label: __( 'Brand profile (optional)', T ),
								value: form.profile_id,
								options: [ { label: __( 'None', T ), value: '0' } ].concat( profiles.map( ( p ) => ( { label: p.name, value: String( p.id ) } ) ) ),
								onChange: set( 'profile_id' ),
								__nextHasNoMarginBottom: true,
							} )
						),
						el( wp.components.TextareaControl, { label: __( 'Facts and notes for the AI (services, prices, areas you cover). The AI does not invent facts.', T ), value: form.notes, onChange: set( 'notes' ), rows: 4 } ),
						el(
							'div',
							{ className: 'aiscs-studio__actions' },
							el( Button, { variant: 'primary', isBusy: busy, disabled: busy || ! form.topic.trim() || ! form.primary_keyword.trim() || ! count, onClick: runGenerate }, __( 'Clone and write new content', T ) ),
							el( Button, { variant: 'secondary', disabled: busy, onClick: runDuplicate }, __( 'Duplicate only (no AI)', T ) )
						),
						el( 'p', { className: 'description' }, __( 'Writing the content makes one request to your default AI provider and is counted in Usage and Costs.', T ) )
				  )
				: null
		);
	}

	/* ---------------------------------------------------------- Generated pages */

	function GeneratedPages() {
		const [ page, setPage ] = useState( 1 );
		const [ list, setList ] = useState( null );
		const [ json, setJson ] = useState( '' );
		const [ profiles, setProfiles ] = useState( [] );
		const [ profile, setProfile ] = useState( '0' );
		const [ busy, setBusy ] = useState( false );
		const [ built, setBuilt ] = useState( null );
		const [ errors, setErrors ] = useState( [] );
		const [ noticeView, setNotice ] = useNotice();

		const load = useCallback( () => {
			apiFetch( { path: '/aiscs/v1/design/pages?page=' + page } )
				.then( setList )
				.catch( ( e ) => setNotice( { status: 'error', text: errorText( e ) } ) );
		}, [ page ] );
		useEffect( load, [ load ] );
		useEffect( () => {
			apiFetch( { path: '/aiscs/v1/website/brand-profiles?page=1' } )
				.then( ( r ) => setProfiles( r.items || [] ) )
				.catch( () => setProfiles( [] ) );
		}, [] );

		const build = () => {
			let doc;
			try {
				doc = JSON.parse( json );
			} catch ( e ) {
				setErrors( [ __( 'The design document is not valid JSON.', T ) ] );
				return;
			}
			setBusy( true );
			setErrors( [] );
			setBuilt( null );
			apiFetch( { path: '/aiscs/v1/design/pages/build', method: 'POST', data: { document: doc, profile_id: parseInt( profile, 10 ) || 0 } } )
				.then( ( r ) => {
					setBuilt( r );
					load();
				} )
				.catch( ( e ) => setErrors( [ errorText( e ) ].concat( ( e.data && e.data.errors ) || [] ) ) )
				.finally( () => setBusy( false ) );
		};

		const TYPE_LABELS = { generated: __( 'Generated', T ), clone: __( 'Duplicate', T ), clone_generated: __( 'Clone + new content', T ), converted: __( 'From HTML', T ), screenshot: __( 'From screenshot', T ) };
		return el(
			Fragment,
			null,
			noticeView,
			null === list
				? el( Spinner )
				: el(
						'table',
						{ className: 'widefat striped' },
						el( 'thead', null, el( 'tr', null, el( 'th', null, __( 'Page', T ) ), el( 'th', null, __( 'Type', T ) ), el( 'th', null, __( 'Status', T ) ), el( 'th', null, __( 'Created', T ) ), el( 'th', null ) ) ),
						el(
							'tbody',
							null,
							list.items.length
								? list.items.map( ( p ) =>
										el(
											'tr',
											{ key: p.page_id },
											el( 'td', null, p.title ),
											el( 'td', null, TYPE_LABELS[ p.generation_type ] || p.generation_type ),
											el( 'td', null, p.status ),
											el( 'td', null, new Date( p.created_at ).toLocaleString() ),
											el( 'td', null, el( 'a', { href: p.elementor_url, target: '_blank' }, __( 'Open in Elementor', T ) ), ' · ', el( 'a', { href: p.preview_url, target: '_blank' }, __( 'Preview', T ) ) )
										)
								  )
								: el( 'tr', null, el( 'td', { colSpan: 5 }, __( 'No pages created yet.', T ), ' ', el( 'a', { href: boot.urls.clone }, __( 'Clone an existing page', T ) ) ) )
						)
				  ),
			list && list.pages > 1
				? el(
						'div',
						{ className: 'aiscs-studio__pager' },
						el( Button, { variant: 'secondary', disabled: page <= 1, onClick: () => setPage( page - 1 ) }, __( 'Previous', T ) ),
						el( Button, { variant: 'secondary', disabled: page >= list.pages, onClick: () => setPage( page + 1 ) }, __( 'Next', T ) )
				  )
				: null,
			el(
				'details',
				{ className: 'aiscs-studio__analysis' },
				el( 'summary', null, __( 'Advanced: build a page from a design document (JSON)', T ) ),
				el( 'p', { className: 'description' }, __( 'Paste a page design document (schema version 1, see docs/website-studio/REST-API.md). It is validated, styled with the chosen brand profile and saved as a new Elementor draft built from native widgets.', T ) ),
				el( SelectControl, {
					label: __( 'Brand profile', T ),
					value: profile,
					options: [ { label: __( 'None', T ), value: '0' } ].concat( profiles.map( ( p ) => ( { label: p.name, value: String( p.id ) } ) ) ),
					onChange: setProfile,
					__nextHasNoMarginBottom: true,
				} ),
				el( wp.components.TextareaControl, { label: __( 'Design document', T ), value: json, onChange: setJson, rows: 12, className: 'aiscs-studio__code' } ),
				el( Button, { variant: 'primary', isBusy: busy, disabled: busy || ! json.trim(), onClick: build }, __( 'Build draft page', T ) ),
				errors.length ? el( Notice, { status: 'error', isDismissible: false }, el( 'ul', null, errors.map( ( e, i ) => el( 'li', { key: i }, e ) ) ) ) : null,
				built ? el( DraftResult, { result: built } ) : null
			)
		);
	}

	/* ---------------------------------------------------------- HTML to Elementor */

	const UNSUPPORTED_LABELS = {
		form: __( 'Form: forms need a form plugin or Elementor Pro; not converted', T ),
		svg: __( 'SVG graphic: choose an Elementor icon or upload it as an image', T ),
		canvas: __( 'Canvas drawing: not convertible', T ),
		media: __( 'Self-hosted video/audio: add it with the Video widget', T ),
		embed: __( 'Embedded object: not convertible', T ),
		iframe: __( 'Embedded frame (only YouTube and Vimeo convert)', T ),
		interactive: __( 'Interactive element: not convertible', T ),
		image_url: __( 'Image without an absolute https address (set the base URL, or upload the image)', T ),
		icon: __( 'Icon not in the available icon set', T ),
		no_native_widget: __( 'No native widget', T ),
	};

	const APPROX_LABELS = {
		gradient_as_colour: __( 'A gradient background was replaced by its first colour.', T ),
		complex_shadow: __( 'A multi-layer shadow was not converted.', T ),
		contrast_fixed: __( 'Text colours that would be unreadable on their background were changed.', T ),
	};

	const PREVIEW_WIDTHS = { desktop: 1200, tablet: 768, mobile: 375 };

	/** Read a dropped/selected file into a field (never uploaded to the server as a file). */
	function FileField( { label, accept, onLoad } ) {
		return el(
			'label',
			{ className: 'aiscs-studio__file' },
			el( 'span', null, label ),
			el( 'input', {
				type: 'file',
				accept,
				onChange: ( e ) => {
					const file = e.target.files && e.target.files[ 0 ];
					if ( ! file ) {
						return;
					}
					if ( file.size > 1048576 ) {
						onLoad( null, __( 'The file is larger than 1 MB.', T ) );
						return;
					}
					const reader = new window.FileReader();
					reader.onload = () => onLoad( String( reader.result || '' ), null );
					reader.readAsText( file );
				},
			} )
		);
	}

	/** Sandboxed preview of the source design: no scripts, no same-origin access. */
	function SourcePreview( { html, css } ) {
		const [ device, setDevice ] = useState( 'desktop' );
		const doc = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>' + css.replace( /<\/style/gi, '' ) + '</style></head><body>' + html.replace( /<script[\s\S]*?<\/script>/gi, '' ) + '</body></html>';
		return el(
			'div',
			{ className: 'aiscs-studio__preview' },
			el(
				'div',
				{ className: 'aiscs-studio__actions' },
				Object.keys( PREVIEW_WIDTHS ).map( ( d ) => el( Button, { key: d, variant: d === device ? 'primary' : 'secondary', onClick: () => setDevice( d ) }, d + ' (' + PREVIEW_WIDTHS[ d ] + 'px)' ) )
			),
			el(
				'div',
				{ className: 'aiscs-studio__frame-wrap' },
				el( 'iframe', { title: __( 'Source design preview', T ), sandbox: '', srcDoc: doc, style: { width: PREVIEW_WIDTHS[ device ] + 'px' }, className: 'aiscs-studio__frame' } )
			),
			el( 'p', { className: 'description' }, __( 'This preview shows your source HTML. Scripts are switched off in it.', T ) )
		);
	}

	function ConversionReport( { result } ) {
		const r = result.report || {};
		const stats = r.stats || { sections: 0, elements: {} };
		const doc = result.document || { sections: [] };
		return el(
			'div',
			{ className: 'aiscs-studio__result' },
			el( 'h2', null, sprintf( __( 'Conversion report: %d%% of the content converts to native widgets', T ), r.coverage || 0 ) ),
			result.errors && result.errors.length ? el( Notice, { status: 'error', isDismissible: false }, el( 'ul', null, result.errors.map( ( e, i ) => el( 'li', { key: i }, e ) ) ) ) : null,
			el(
				'p',
				null,
				sprintf( __( '%d sections.', T ), stats.sections ),
				' ',
				Object.keys( stats.elements || {} )
					.map( ( k ) => stats.elements[ k ] + ' ' + k )
					.join( ', ' )
			),
			el(
				'table',
				{ className: 'widefat striped' },
				el( 'thead', null, el( 'tr', null, el( 'th', null, '#' ), el( 'th', null, __( 'Section', T ) ), el( 'th', null, __( 'Layout', T ) ), el( 'th', null, __( 'Columns (desktop / tablet / mobile)', T ) ), el( 'th', null, __( 'Elements', T ) ) ) ),
				el(
					'tbody',
					null,
					doc.sections.map( ( s, i ) =>
						el(
							'tr',
							{ key: s.id },
							el( 'td', null, i + 1 ),
							el( 'td', null, ( SECTION_LABELS[ s.type ] || s.type ) + ' (' + s.id + ')' ),
							el( 'td', null, s.layout.kind ),
							el( 'td', null, s.layout.columns.desktop + ' / ' + s.layout.columns.tablet + ' / ' + s.layout.columns.mobile ),
							el( 'td', null, s.elements.map( ( e ) => ( 'group' === e.type ? __( 'card', T ) + '(' + e.elements.map( ( g ) => g.type ).join( ', ' ) + ')' : e.type ) ).join( ', ' ) )
						)
					)
				)
			),
			( r.unsupported || [] ).length
				? el(
						'div',
						null,
						el( 'h3', null, __( 'Not converted to native widgets', T ) ),
						el(
							'ul',
							{ className: 'aiscs-studio__list-plain' },
							r.unsupported.map( ( u, i ) =>
								el( 'li', { key: i }, el( 'code', null, '<' + u.tag + '>' ), ' ', UNSUPPORTED_LABELS[ u.category ] || u.category, u.fallback ? ' — ' + __( 'kept in an HTML widget', T ) : '', u.text ? ' — “' + u.text + '”' : '' )
							)
						)
				  )
				: null,
			( r.skipped || [] ).length ? el( 'p', null, sprintf( __( 'Skipped site header/footer/navigation: %d (your theme provides them). Turn on "Include header and footer" to convert them as content.', T ), r.skipped.length ) ) : null,
			r.scripts_removed || ( r.javascript && r.javascript.bytes ) || ( r.javascript && r.javascript.inline_handlers_removed )
				? el(
						Notice,
						{ status: 'info', isDismissible: false },
						sprintf( __( 'JavaScript is never run or copied: %1$d script tags and %2$d inline event handlers were removed.', T ), r.scripts_removed || 0, ( r.javascript && r.javascript.inline_handlers_removed ) || 0 ),
						r.javascript && r.javascript.features && r.javascript.features.length ? ' ' + sprintf( __( 'The JavaScript provided uses: %s. Recreate that behaviour with Elementor features (for example Motion Effects or the Image Carousel widget).', T ), r.javascript.features.join( ', ' ) ) : ''
				  )
				: null,
			( r.external_stylesheets || [] ).length ? el( Notice, { status: 'warning', isDismissible: false }, __( 'Linked stylesheets are not downloaded. Paste their CSS into the CSS field to include them:', T ), ' ', r.external_stylesheets.join( ', ' ) ) : null,
			( r.relative_urls || [] ).length ? el( Notice, { status: 'warning', isDismissible: false }, __( 'Relative addresses could not be resolved (set the base URL):', T ), ' ', r.relative_urls.slice( 0, 10 ).join( ', ' ) ) : null,
			( r.approximations || [] ).length ? el( Notice, { status: 'warning', isDismissible: false }, el( 'ul', null, Array.from( new Set( r.approximations ) ).map( ( a ) => el( 'li', { key: a }, APPROX_LABELS[ a ] || a ) ) ) ) : null,
			r.css && ( r.css.unsupported_selectors || ( r.css.unsupported_features || [] ).length )
				? el( 'p', { className: 'description' }, sprintf( __( 'CSS not used: %d selectors (hover states, pseudo-elements, sibling selectors).', T ), r.css.unsupported_selectors || 0 ), ( r.css.unsupported_features || [] ).length ? ' ' + sprintf( __( 'Features without an equivalent: %s.', T ), r.css.unsupported_features.join( ', ' ) ) : '' )
				: null
		);
	}

	function HtmlToElementor() {
		const [ html, setHtml ] = useState( '' );
		const [ css, setCss ] = useState( '' );
		const [ js, setJs ] = useState( '' );
		const [ opts, setOpts ] = useState( { base_url: '', fallback: false, include_chrome: false, title: '', profile_id: '0' } );
		const [ profiles, setProfiles ] = useState( [] );
		const [ result, setResult ] = useState( null );
		const [ built, setBuilt ] = useState( null );
		const [ busy, setBusy ] = useState( false );
		const [ noticeView, setNotice ] = useNotice();

		useEffect( () => {
			apiFetch( { path: '/aiscs/v1/website/brand-profiles?page=1' } )
				.then( ( r ) => setProfiles( r.items || [] ) )
				.catch( () => setProfiles( [] ) );
		}, [] );
		const set = ( key ) => ( v ) => setOpts( Object.assign( {}, opts, { [ key ]: v } ) );
		const payload = () => ( {
			html,
			css,
			js,
			base_url: opts.base_url,
			fallback: opts.fallback ? 'html' : 'skip',
			include_chrome: opts.include_chrome,
			title: opts.title,
			profile_id: parseInt( opts.profile_id, 10 ) || 0,
		} );
		const loaded = ( setter ) => ( text, error ) => ( error ? setNotice( { status: 'error', text: error } ) : setter( text ) );

		const analyze = () => {
			setBusy( true );
			setBuilt( null );
			const data = payload();
			delete data.profile_id;
			apiFetch( { path: '/aiscs/v1/design/html/parse', method: 'POST', data } )
				.then( ( r ) => {
					setResult( r );
					setNotice( null );
				} )
				.catch( ( e ) => setNotice( { status: 'error', text: errorText( e ) } ) )
				.finally( () => setBusy( false ) );
		};
		const convert = () => {
			setBusy( true );
			apiFetch( { path: '/aiscs/v1/design/html/convert', method: 'POST', data: payload() } )
				.then( setBuilt )
				.catch( ( e ) => setNotice( { status: 'error', text: errorText( e ) } ) )
				.finally( () => setBusy( false ) );
		};

		if ( built ) {
			return el( Fragment, null, el( DraftResult, { result: built } ), el( Button, { variant: 'link', onClick: () => setBuilt( null ) }, __( 'Back to the converter', T ) ) );
		}
		return el(
			Fragment,
			null,
			noticeView,
			el( 'p', { className: 'description' }, __( 'Paste or upload your HTML and CSS. The layout is rebuilt with native, editable Elementor widgets. Nothing in the code is run, and nothing is saved until you create the draft.', T ) ),
			el(
				'div',
				{ className: 'aiscs-studio__actions' },
				el( FileField, { label: __( 'HTML file', T ), accept: '.html,.htm,text/html', onLoad: loaded( setHtml ) } ),
				el( FileField, { label: __( 'CSS file', T ), accept: '.css,text/css', onLoad: loaded( setCss ) } ),
				el( FileField, { label: __( 'JavaScript file (analysed only)', T ), accept: '.js,text/javascript', onLoad: loaded( setJs ) } )
			),
			el( wp.components.TextareaControl, { label: __( 'HTML', T ), value: html, onChange: setHtml, rows: 12, className: 'aiscs-studio__code' } ),
			el( wp.components.TextareaControl, { label: __( 'CSS', T ), value: css, onChange: setCss, rows: 10, className: 'aiscs-studio__code' } ),
			el( 'details', { className: 'aiscs-studio__analysis' }, el( 'summary', null, __( 'JavaScript (optional, never run)', T ) ), el( wp.components.TextareaControl, { label: __( 'JavaScript', T ), value: js, onChange: setJs, rows: 6, className: 'aiscs-studio__code' } ) ),
			el(
				'div',
				{ className: 'aiscs-studio__fields' },
				el( TextControl, { label: __( 'Base URL for relative links and images', T ), value: opts.base_url, onChange: set( 'base_url' ), placeholder: 'https://example.com/', __nextHasNoMarginBottom: true } ),
				el( TextControl, { label: __( 'Page title (optional)', T ), value: opts.title, onChange: set( 'title' ), __nextHasNoMarginBottom: true } ),
				el( SelectControl, {
					label: __( 'Styles', T ),
					value: opts.profile_id,
					options: [ { label: __( 'From this HTML’s own CSS', T ), value: '0' } ].concat( profiles.map( ( p ) => ( { label: sprintf( __( 'Brand profile: %s', T ), p.name ), value: String( p.id ) } ) ) ),
					onChange: set( 'profile_id' ),
					__nextHasNoMarginBottom: true,
				} )
			),
			el( CheckboxControl, { label: __( 'Keep content that has no native widget in an HTML widget (sanitised; scripts are always removed)', T ), checked: opts.fallback, onChange: set( 'fallback' ), __nextHasNoMarginBottom: true } ),
			el( CheckboxControl, { label: __( 'Include header, navigation and footer as page content', T ), checked: opts.include_chrome, onChange: set( 'include_chrome' ), __nextHasNoMarginBottom: true } ),
			el(
				'div',
				{ className: 'aiscs-studio__actions' },
				el( Button, { variant: 'secondary', isBusy: busy && ! result, disabled: busy || ! html.trim(), onClick: analyze }, __( 'Analyze', T ) ),
				el( Button, { variant: 'primary', isBusy: busy && !! result, disabled: busy || ! result || ( result.errors && result.errors.length > 0 ), onClick: convert }, __( 'Create Elementor draft', T ) )
			),
			result ? el( SourcePreview, { html, css } ) : null,
			result ? el( ConversionReport, { result } ) : null
		);
	}

	/* ------------------------------------------------------------ Image to Design */

	const VISION_NOTES = {
		visual_approximation: __( 'This is a visual approximation of the screenshot, not a pixel-perfect copy. A screenshot does not show the source code, exact fonts, hover effects or how the page behaves on other screens; tablet and mobile layouts are generated.', T ),
		fonts_guessed: __( 'Fonts were recognised by eye and may differ from the original.', T ),
		fonts_unknown: __( 'The fonts could not be recognised; your theme or brand profile fonts are used.', T ),
		photo_backgrounds: __( 'Photo backgrounds were not recreated. Add a background image in Elementor, or generate one in a later step.', T ),
	};

	function ImageToDesign() {
		const [ file, setFile ] = useState( null );
		const [ preview, setPreview ] = useState( '' );
		const [ model, setModel ] = useState( '' );
		const [ projectId, setProjectId ] = useState( 0 );
		const [ run, setRun ] = useState( 0 );
		const [ profiles, setProfiles ] = useState( [] );
		const [ form, setForm ] = useState( { keep_text: false, topic: '', primary_keyword: '', secondary: '', audience: '', intent: '', location: '', tone: '', notes: '', title: '', profile_id: '0', placeholders: true } );
		const [ busy, setBusy ] = useState( false );
		const [ noticeView, setNotice ] = useNotice();
		const state = useJob( projectId, run );

		useEffect( () => {
			apiFetch( { path: '/aiscs/v1/website/brand-profiles?page=1' } )
				.then( ( r ) => setProfiles( r.items || [] ) )
				.catch( () => setProfiles( [] ) );
		}, [] );
		useEffect( () => () => preview && window.URL.revokeObjectURL( preview ), [ preview ] );
		const set = ( key ) => ( v ) => setForm( Object.assign( {}, form, { [ key ]: v } ) );

		const choose = ( e ) => {
			const f = e.target.files && e.target.files[ 0 ];
			if ( ! f ) {
				return;
			}
			if ( f.size > 10485760 ) {
				setNotice( { status: 'error', text: __( 'The screenshot is larger than 10 MB.', T ) } );
				return;
			}
			setFile( f );
			setPreview( window.URL.createObjectURL( f ) );
		};
		const analyze = () => {
			const body = new window.FormData();
			body.append( 'screenshot', file );
			body.append( 'model', model.trim() );
			setBusy( true );
			apiFetch( { path: '/aiscs/v1/design/image/analyze', method: 'POST', body } )
				.then( ( r ) => {
					setProjectId( r.project_id );
					setRun( run + 1 );
					setNotice( null );
				} )
				.catch( ( e ) => setNotice( { status: 'error', text: errorText( e ) } ) )
				.finally( () => setBusy( false ) );
		};
		const generate = () => {
			setBusy( true );
			apiFetch( {
				path: '/aiscs/v1/design/image/' + projectId + '/generate',
				method: 'POST',
				data: {
					keep_text: form.keep_text,
					topic: form.topic,
					primary_keyword: form.primary_keyword,
					secondary_keywords: form.secondary.split( ',' ).map( ( s ) => s.trim() ).filter( Boolean ),
					audience: form.audience,
					intent: form.intent,
					location: form.location,
					tone: form.tone,
					notes: form.notes,
					title: form.title,
					profile_id: parseInt( form.profile_id, 10 ) || 0,
					placeholders: form.placeholders,
				},
			} )
				.then( () => setRun( run + 1 ) )
				.catch( ( e ) => setNotice( { status: 'error', text: errorText( e ) } ) )
				.finally( () => setBusy( false ) );
		};
		const reset = () => {
			setProjectId( 0 );
			setFile( null );
			setPreview( '' );
		};

		const job = state && state.job ? state.job : null;
		const running = job && ( 'queued' === job.status || 'running' === job.status );
		const design = state && state.design;

		const steps = running
			? el(
					'div',
					{ className: 'aiscs-studio__card' },
					el( 'h2', null, __( 'Working…', T ) ),
					el( 'ol', { className: 'aiscs-studio__steps' }, ( job.steps || [] ).map( ( s, i ) => el( 'li', { key: s.id, className: i < job.step_index ? 'is-done' : i === job.step_index ? 'is-current' : '' }, s.label, i === job.step_index ? el( Spinner ) : null ) ) ),
					el( 'p', { className: 'description' }, job.message )
			  )
			: null;

		const uploadForm = el(
			Fragment,
			null,
			el( 'p', { className: 'description' }, __( 'Upload a screenshot or design export of a web page (PNG, JPEG or WebP, up to 10 MB). An AI vision model reads its layout and colours; the page is rebuilt with native, editable Elementor widgets. Full-page screenshots are fine: they are read in slices.', T ) ),
			el( 'label', { className: 'aiscs-studio__file' }, el( 'span', null, __( 'Screenshot', T ) ), el( 'input', { type: 'file', accept: 'image/png,image/jpeg,image/webp', onChange: choose } ) ),
			el( TextControl, { label: __( 'Vision model (optional; leave empty to use your default text model)', T ), value: model, onChange: setModel, placeholder: 'gpt-4.1, claude-sonnet-4-5, gemini-2.5-flash', __nextHasNoMarginBottom: true } ),
			el( 'p', { className: 'description' }, __( 'Uses your default text provider (OpenAI, Anthropic, Gemini or xAI; custom endpoints cannot read images yet). The analysis is one AI request and is counted in Usage and Costs. The screenshot is deleted from the server right after it is read.', T ) ),
			el( 'div', { className: 'aiscs-studio__actions' }, el( Button, { variant: 'primary', isBusy: busy, disabled: busy || ! file, onClick: analyze }, __( 'Analyze screenshot', T ) ) )
		);

		const review = design
			? el(
					'div',
					{ className: 'aiscs-studio__result' },
					el( 'h2', null, __( 'Design structure found in the screenshot', T ) ),
					el( 'p', { className: 'description' }, sprintf( __( 'Read by %1$s (%2$s).', T ), design.provider, design.model ) ),
					( design.report.approximations || [] ).map( ( a ) => el( Notice, { key: a, status: 'visual_approximation' === a ? 'info' : 'warning', isDismissible: false }, VISION_NOTES[ a ] || a ) ),
					design.report.truncated ? el( Notice, { status: 'warning', isDismissible: false }, __( 'The screenshot is very tall; only its upper part was read.', T ) ) : null,
					el(
						'div',
						{ className: 'aiscs-studio__vision' },
						preview ? el( 'img', { src: preview, alt: __( 'Uploaded screenshot', T ), className: 'aiscs-studio__shot' } ) : null,
						el(
							'div',
							null,
							el( TokenSummary, { tokens: design.tokens } ),
							el(
								'table',
								{ className: 'widefat striped' },
								el( 'thead', null, el( 'tr', null, el( 'th', null, '#' ), el( 'th', null, __( 'Section', T ) ), el( 'th', null, __( 'Layout', T ) ), el( 'th', null, __( 'Elements', T ) ) ) ),
								el(
									'tbody',
									null,
									design.document.sections.map( ( s, i ) =>
										el(
											'tr',
											{ key: s.id },
											el( 'td', null, i + 1 ),
											el( 'td', null, SECTION_LABELS[ s.type ] || s.type ),
											el( 'td', null, s.layout.kind + ' ' + s.layout.columns.desktop + '/' + s.layout.columns.tablet + '/' + s.layout.columns.mobile ),
											el( 'td', null, s.elements.map( ( e ) => ( 'group' === e.type ? __( 'card', T ) + '(' + e.elements.map( ( g ) => g.type ).join( ', ' ) + ')' : e.type + ( e.text ? ': “' + e.text.slice( 0, 40 ) + '”' : '' ) ) ).join( ' · ' ) )
										)
									)
								)
							),
							( design.report.observations || [] ).length ? el( 'ul', { className: 'aiscs-studio__list-plain' }, design.report.observations.map( ( o, i ) => el( 'li', { key: i }, o ) ) ) : null
						)
					),
					state.result
						? null
						: el(
								Fragment,
								null,
								el( 'h2', null, __( 'Content', T ) ),
								el( CheckboxControl, { label: __( 'Keep the text from the screenshot (no content request)', T ), checked: form.keep_text, onChange: set( 'keep_text' ), __nextHasNoMarginBottom: true } ),
								form.keep_text
									? null
									: el(
											'div',
											{ className: 'aiscs-studio__fields' },
											el( TextControl, { label: __( 'Page topic (required)', T ), value: form.topic, onChange: set( 'topic' ), __nextHasNoMarginBottom: true } ),
											el( TextControl, { label: __( 'Primary keyword (required)', T ), value: form.primary_keyword, onChange: set( 'primary_keyword' ), __nextHasNoMarginBottom: true } ),
											el( TextControl, { label: __( 'Secondary keywords (comma separated)', T ), value: form.secondary, onChange: set( 'secondary' ), __nextHasNoMarginBottom: true } ),
											el( TextControl, { label: __( 'Target audience', T ), value: form.audience, onChange: set( 'audience' ), __nextHasNoMarginBottom: true } ),
											el( TextControl, { label: __( 'Target location', T ), value: form.location, onChange: set( 'location' ), __nextHasNoMarginBottom: true } ),
											el( TextControl, { label: __( 'Tone', T ), value: form.tone, onChange: set( 'tone' ), __nextHasNoMarginBottom: true } )
									  ),
								form.keep_text ? null : el( wp.components.TextareaControl, { label: __( 'Facts and notes for the AI (the AI does not invent facts)', T ), value: form.notes, onChange: set( 'notes' ), rows: 3 } ),
								el(
									'div',
									{ className: 'aiscs-studio__fields' },
									el( TextControl, { label: __( 'Page title (optional)', T ), value: form.title, onChange: set( 'title' ), __nextHasNoMarginBottom: true } ),
									el( SelectControl, {
										label: __( 'Styles', T ),
										value: form.profile_id,
										options: [ { label: __( 'Colours and shapes from the screenshot', T ), value: '0' } ].concat( profiles.map( ( p ) => ( { label: sprintf( __( 'Brand profile: %s', T ), p.name ), value: String( p.id ) } ) ) ),
										onChange: set( 'profile_id' ),
										__nextHasNoMarginBottom: true,
									} )
								),
								el( CheckboxControl, { label: __( 'Add placeholder images to the Media Library where the screenshot shows images', T ), checked: form.placeholders, onChange: set( 'placeholders' ), __nextHasNoMarginBottom: true } ),
								el(
									'div',
									{ className: 'aiscs-studio__actions' },
									el( Button, { variant: 'primary', isBusy: busy, disabled: busy || running || ( ! form.keep_text && ( ! form.topic.trim() || ! form.primary_keyword.trim() ) ), onClick: generate }, __( 'Create Elementor draft', T ) ),
									el( Button, { variant: 'tertiary', onClick: reset }, __( 'Start over', T ) )
								)
						  )
			  )
			: null;

		return el(
			Fragment,
			null,
			noticeView,
			! projectId ? uploadForm : null,
			state && state.error ? el( Notice, { status: 'error', isDismissible: false }, state.error ) : null,
			job && ( 'failed' === job.status || 'cancelled' === job.status ) ? el( Fragment, null, el( Notice, { status: 'error', isDismissible: false }, job.message || job.error ), el( Button, { variant: 'secondary', onClick: reset }, __( 'Start over', T ) ) ) : null,
			steps,
			state && state.result ? el( Fragment, null, el( DraftResult, { result: state.result } ), state.result.report && state.result.report.placeholders ? el( 'p', { className: 'description' }, sprintf( __( '%d placeholder images were added to the Media Library. Replace them in Elementor with your own images.', T ), state.result.report.placeholders ) ) : null, el( Button, { variant: 'secondary', onClick: reset }, __( 'Convert another screenshot', T ) ) ) : null,
			review
		);
	}

	/* ---------------------------------------------------------------------- Mount */

	const VIEWS = {
		dashboard: [ __( 'AI Website Studio', T ), Dashboard ],
		brand: [ __( 'Website Brand Analyzer', T ), BrandAnalyzer ],
		profiles: [ __( 'Brand Profiles', T ), Profiles ],
		clone: [ __( 'Clone Existing Page', T ), ClonePage ],
		pages: [ __( 'Generated Pages', T ), GeneratedPages ],
		html: [ __( 'HTML to Elementor', T ), HtmlToElementor ],
		image: [ __( 'Image to Design', T ), ImageToDesign ],
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
