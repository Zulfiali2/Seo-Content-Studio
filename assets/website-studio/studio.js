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
		features: __( 'Features / benefits', T ),
		about: __( 'About', T ),
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
			[ __( 'AI Page Generator, section images and internal links', T ), __( 'Available', T ), boot.urls.generate ],
			[ __( 'Page tools: preview, section regeneration, accessibility', T ), __( 'Available', T ), boot.urls.tools ],
			[ __( 'Design Consistency Checker', T ), __( 'Available', T ), boot.urls.consistency ],
			[ __( 'Design Library', T ), __( 'Available', T ), boot.urls.library ],
			[ __( 'Revisions and History', T ), __( 'Available', T ), boot.urls.revisions ],
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
											el( 'td', null, el( 'a', { href: p.elementor_url, target: '_blank' }, __( 'Open in Elementor', T ) ), ' · ', el( 'a', { href: p.preview_url, target: '_blank' }, __( 'Preview', T ) ), ' · ', el( 'a', { href: boot.urls.tools + '&page_id=' + p.page_id }, __( 'Page tools', T ) ) )
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

	/* ---------------------------------------------------------------------- AI Page Generator */

	const LAYOUT_CHANGES = {
		columns_1: __( 'One column', T ),
		columns_2: __( 'Two columns', T ),
		columns_3: __( 'Three columns', T ),
		columns_4: __( 'Four columns', T ),
		columns_5: __( 'Five columns', T ),
		columns_6: __( 'Six columns', T ),
		long_cards_fewer_columns: __( 'Long card text: fewer columns so it stays readable', T ),
		split_to_grid: __( 'Side-by-side layout changed to a grid (it did not have exactly two parts)', T ),
		faq_single_column: __( 'FAQ shown in one column', T ),
		stack_without_groups: __( 'Single column (no cards)', T ),
		extra_button_removed: __( 'More than two buttons: extra buttons removed', T ),
		long_heading_smaller: __( 'Long main heading: smaller font size', T ),
		h1_added: __( 'The first heading became the page H1', T ),
	};

	function PageGenerator() {
		const [ profiles, setProfiles ] = useState( [] );
		const [ form, setForm ] = useState( { topic: '', primary_keyword: '', secondary: '', audience: '', intent: '', location: '', tone: '', notes: '', length: 'medium', images: '3', cta_url: '', links: true, title: '', profile_id: '0', sections: [] } );
		const [ projectId, setProjectId ] = useState( 0 );
		const [ run, setRun ] = useState( 0 );
		const [ busy, setBusy ] = useState( false );
		const [ approved, setApproved ] = useState( {} );
		const [ picked, setPicked ] = useState( {} );
		const [ prompts, setPrompts ] = useState( {} );
		const [ noticeView, setNotice ] = useNotice();
		const state = useJob( projectId, run );

		useEffect( () => {
			apiFetch( { path: '/aiscs/v1/website/brand-profiles?page=1' } )
				.then( ( r ) => setProfiles( r.items || [] ) )
				.catch( () => setProfiles( [] ) );
		}, [] );
		const set = ( key ) => ( v ) => setForm( Object.assign( {}, form, { [ key ]: v } ) );
		const fail = ( e ) => setNotice( { status: 'error', text: errorText( e ) } );
		const after = () => {
			setBusy( false );
		};

		const start = () => {
			setBusy( true );
			apiFetch( {
				path: '/aiscs/v1/design/generate',
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
					length: form.length,
					sections: form.sections,
					images: parseInt( form.images, 10 ) || 0,
					cta_url: form.cta_url.trim(),
					links: form.links,
					title: form.title,
					profile_id: parseInt( form.profile_id, 10 ) || 0,
				},
			} )
				.then( ( r ) => {
					setProjectId( r.project_id );
					setApproved( {} );
					setRun( run + 1 );
					setNotice( null );
				} )
				.catch( fail )
				.finally( after );
		};
		const build = () => {
			setBusy( true );
			apiFetch( {
				path: '/aiscs/v1/design/generate/' + projectId + '/build',
				method: 'POST',
				data: { approved_links: Object.keys( approved ).filter( ( k ) => approved[ k ] ) },
			} )
				.then( () => setRun( run + 1 ) )
				.catch( fail )
				.finally( after );
		};
		const makeImages = ( ids ) => {
			const data = { slots: ids, prompts: {} };
			ids.forEach( ( id ) => {
				if ( prompts[ id ] !== undefined ) {
					data.prompts[ id ] = prompts[ id ];
				}
			} );
			setBusy( true );
			apiFetch( { path: '/aiscs/v1/design/generate/' + projectId + '/images', method: 'POST', data } )
				.then( () => {
					setPicked( {} );
					setRun( run + 1 );
				} )
				.catch( fail )
				.finally( after );
		};
		const applyImages = ( ids ) => {
			setBusy( true );
			apiFetch( { path: '/aiscs/v1/design/generate/' + projectId + '/images/apply', method: 'POST', data: { slots: ids } } )
				.then( ( r ) => {
					setNotice(
						r.missing && r.missing.length
							? { status: 'warning', text: sprintf( __( '%1$d image(s) placed in the draft. %2$d could not be placed because the placeholder was removed or replaced in Elementor.', T ), r.applied.length, r.missing.length ) }
							: { status: 'success', text: sprintf( __( '%d image(s) placed in the draft.', T ), r.applied.length ) }
					);
					setRun( run + 1 );
				} )
				.catch( fail )
				.finally( after );
		};
		const discard = ( id ) => {
			setBusy( true );
			apiFetch( { path: '/aiscs/v1/design/generate/' + projectId + '/images/' + id + '/discard', method: 'POST' } )
				.then( () => setRun( run + 1 ) )
				.catch( fail )
				.finally( after );
		};
		const reset = () => {
			setProjectId( 0 );
			setPrompts( {} );
			setPicked( {} );
		};

		const job = state && state.job ? state.job : null;
		const running = job && ( 'queued' === job.status || 'running' === job.status );
		const design = state && state.design;
		const gen = state && state.generator;
		const result = state && state.result;

		const sectionChoices = [ 'hero', 'features', 'cards', 'about', 'faq', 'cta', 'contact', 'content' ];
		const toggleSection = ( type, on ) => setForm( Object.assign( {}, form, { sections: on ? form.sections.concat( [ type ] ) : form.sections.filter( ( s ) => s !== type ) } ) );

		const briefForm = el(
			Fragment,
			null,
			el( 'p', { className: 'description' }, __( 'Describe the page; the AI writes the copy, plans the sections and suggests images and internal links. You review everything before a draft is created, and the draft is built from native, editable Elementor widgets. Nothing is published.', T ) ),
			el(
				'div',
				{ className: 'aiscs-studio__fields' },
				el( TextControl, { label: __( 'Page topic (required)', T ), value: form.topic, onChange: set( 'topic' ), __nextHasNoMarginBottom: true } ),
				el( TextControl, { label: __( 'Primary keyword (required)', T ), value: form.primary_keyword, onChange: set( 'primary_keyword' ), __nextHasNoMarginBottom: true } ),
				el( TextControl, { label: __( 'Secondary keywords (comma separated)', T ), value: form.secondary, onChange: set( 'secondary' ), __nextHasNoMarginBottom: true } ),
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
				el( TextControl, { label: __( 'Target audience', T ), value: form.audience, onChange: set( 'audience' ), __nextHasNoMarginBottom: true } ),
				el( TextControl, { label: __( 'Target location', T ), value: form.location, onChange: set( 'location' ), __nextHasNoMarginBottom: true } ),
				el( TextControl, { label: __( 'Tone', T ), value: form.tone, onChange: set( 'tone' ), __nextHasNoMarginBottom: true } ),
				el( SelectControl, {
					label: __( 'Length', T ),
					value: form.length,
					options: [
						{ label: __( 'Short (about 4 sections)', T ), value: 'short' },
						{ label: __( 'Medium (about 6 sections)', T ), value: 'medium' },
						{ label: __( 'Long (about 8 sections)', T ), value: 'long' },
					],
					onChange: set( 'length' ),
					__nextHasNoMarginBottom: true,
				} ),
				el( SelectControl, {
					label: __( 'Images', T ),
					value: form.images,
					options: [ '0', '1', '2', '3', '4', '5', '6', '8' ].map( ( n ) => ( { label: '0' === n ? __( 'No images', T ) : sprintf( __( 'Up to %s', T ), n ), value: n } ) ),
					onChange: set( 'images' ),
					__nextHasNoMarginBottom: true,
				} ),
				el( TextControl, { label: __( 'Button link (optional: /contact/, #form or https://…)', T ), value: form.cta_url, onChange: set( 'cta_url' ), __nextHasNoMarginBottom: true } ),
				el( TextControl, { label: __( 'Page title (optional)', T ), value: form.title, onChange: set( 'title' ), __nextHasNoMarginBottom: true } ),
				el( SelectControl, {
					label: __( 'Styles', T ),
					value: form.profile_id,
					options: [ { label: __( 'Elementor site colours', T ), value: '0' } ].concat( profiles.map( ( p ) => ( { label: sprintf( __( 'Brand profile: %s', T ), p.name ), value: String( p.id ) } ) ) ),
					onChange: set( 'profile_id' ),
					__nextHasNoMarginBottom: true,
				} )
			),
			el( 'p', null, el( 'strong', null, __( 'Sections (optional; leave empty to let the AI choose)', T ) ) ),
			el( 'div', { className: 'aiscs-studio__checks' }, sectionChoices.map( ( type ) => el( CheckboxControl, { key: type, label: SECTION_LABELS[ type ] || type, checked: form.sections.includes( type ), onChange: ( on ) => toggleSection( type, on ), __nextHasNoMarginBottom: true } ) ) ),
			el( wp.components.TextareaControl, { label: __( 'Facts and notes for the AI: services, prices, guarantees, real testimonials… The AI uses only these facts and never invents others.', T ), value: form.notes, onChange: set( 'notes' ), rows: 4 } ),
			el( CheckboxControl, { label: __( 'Suggest internal links to my published pages (you approve each one)', T ), checked: form.links, onChange: set( 'links' ), __nextHasNoMarginBottom: true } ),
			el( 'p', { className: 'description' }, __( 'One request to your default text provider, counted in Usage and Costs. AI images are only generated later, when you ask for them.', T ) ),
			el( 'div', { className: 'aiscs-studio__actions' }, el( Button, { variant: 'primary', isBusy: busy, disabled: busy || ! form.topic.trim() || ! form.primary_keyword.trim(), onClick: start }, __( 'Generate page', T ) ) )
		);

		const steps = running
			? el(
					'div',
					{ className: 'aiscs-studio__card' },
					el( 'h2', null, __( 'Working…', T ) ),
					el( 'ol', { className: 'aiscs-studio__steps' }, ( job.steps || [] ).map( ( s, i ) => el( 'li', { key: s.id, className: i < job.step_index ? 'is-done' : i === job.step_index ? 'is-current' : '' }, s.label, i === job.step_index ? el( Spinner ) : null ) ) ),
					el( 'p', { className: 'description' }, job.message )
			  )
			: null;

		const elementSummary = ( e ) => {
			if ( 'group' === e.type ) {
				return __( 'card', T ) + ' (' + e.elements.map( elementSummary ).join( ', ' ) + ')';
			}
			if ( 'heading' === e.type || 'button' === e.type ) {
				return e.type + ': “' + e.text.slice( 0, 60 ) + '”';
			}
			if ( 'image' === e.type ) {
				return __( 'image', T ) + ( e.alt ? ': ' + e.alt.slice( 0, 50 ) : '' );
			}
			return e.type;
		};

		const review =
			design && gen && ! result
				? el(
						'div',
						{ className: 'aiscs-studio__result' },
						el( 'h2', null, __( 'Review the generated page', T ) ),
						el( 'p', { className: 'description' }, sprintf( __( 'Written by %1$s (%2$s). Nothing has been saved to your pages yet.', T ), design.provider, design.model ) ),
						gen.facts.numbers.length || gen.facts.placeholders.length || gen.report.assumptions.length
							? el(
									Notice,
									{ status: 'warning', isDismissible: false },
									el( 'strong', null, __( 'Check these facts before publishing', T ) ),
									el(
										'ul',
										{ className: 'aiscs-studio__list-plain' },
										gen.facts.numbers.map( ( f, i ) => el( 'li', { key: 'n' + i }, sprintf( __( 'Section %1$d: “%2$s” (contains a number)', T ), f.section + 1, f.text ) ) ),
										gen.facts.placeholders.map( ( f, i ) => el( 'li', { key: 'p' + i }, sprintf( __( 'Section %1$d: fill in %2$s', T ), f.section + 1, f.text ) ) ),
										gen.report.assumptions.map( ( a, i ) => el( 'li', { key: 'a' + i }, a ) )
									)
							  )
							: null,
						el(
							'table',
							{ className: 'widefat striped aiscs-tokens__table' },
							el(
								'tbody',
								null,
								el( 'tr', null, el( 'th', null, __( 'Page title', T ) ), el( 'td', null, design.document.page.title ) ),
								el( 'tr', null, el( 'th', null, __( 'SEO title', T ) ), el( 'td', null, gen.seo.meta_title, ' ', el( 'small', null, '(' + gen.seo.meta_title.length + ')' ) ) ),
								el( 'tr', null, el( 'th', null, __( 'Meta description', T ) ), el( 'td', null, gen.seo.meta_description, ' ', el( 'small', null, '(' + gen.seo.meta_description.length + ')' ) ) ),
								el( 'tr', null, el( 'th', null, __( 'Slug', T ) ), el( 'td', null, design.document.page.slug || '—' ) )
							)
						),
						el( 'h3', null, __( 'Sections', T ) ),
						el(
							'table',
							{ className: 'widefat striped' },
							el( 'thead', null, el( 'tr', null, el( 'th', null, '#' ), el( 'th', null, __( 'Section', T ) ), el( 'th', null, __( 'Layout (desktop/tablet/mobile)', T ) ), el( 'th', null, __( 'Content', T ) ) ) ),
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
										el( 'td', null, s.elements.map( elementSummary ).join( ' · ' ) )
									)
								)
							)
						),
						gen.report.layout_changes.length
							? el(
									'details',
									{ className: 'aiscs-studio__analysis' },
									el( 'summary', null, sprintf( __( 'Layout adjusted to the content (%d)', T ), gen.report.layout_changes.length ) ),
									el( 'ul', { className: 'aiscs-studio__list-plain' }, gen.report.layout_changes.map( ( c, i ) => el( 'li', { key: i }, sprintf( __( 'Section %1$d: %2$s', T ), c.section + 1, LAYOUT_CHANGES[ c.change ] || c.change ) ) ) )
							  )
							: null,
						el( 'h3', null, __( 'Internal link suggestions', T ) ),
						gen.links.length
							? el(
									Fragment,
									null,
									el( 'p', { className: 'description' }, __( 'Only the links you tick are added. Each target is checked again when the draft is created.', T ) ),
									gen.links.map( ( l ) =>
										el( CheckboxControl, {
											key: l.id,
											checked: !! approved[ l.id ],
											onChange: ( on ) => setApproved( Object.assign( {}, approved, { [ l.id ]: on } ) ),
											label: sprintf( __( '“%1$s” → %2$s (section %3$d)', T ), l.anchor, l.title, l.section + 1 ) + ( l.reason ? ' — ' + l.reason : '' ),
											__nextHasNoMarginBottom: true,
										} )
									)
							  )
							: el( 'p', { className: 'description' }, __( 'No suitable internal links were suggested.', T ) ),
						el( 'h3', null, __( 'Images', T ) ),
						gen.images.length
							? el(
									Fragment,
									null,
									el( 'p', { className: 'description' }, __( 'The draft gets placeholder images first. After it is created you can generate AI images from these prompts, review them and choose which ones to use.', T ) ),
									el( 'ul', { className: 'aiscs-studio__list-plain' }, gen.images.map( ( img ) => el( 'li', { key: img.id }, el( 'strong', null, img.alt || img.id ), ' (' + img.aspect + ', ' + ( SECTION_LABELS[ img.section_type ] || img.section_type ) + '): ' + img.prompt ) ) )
							  )
							: el( 'p', { className: 'description' }, __( 'No images.', T ) ),
						el(
							'div',
							{ className: 'aiscs-studio__actions' },
							el( Button, { variant: 'primary', isBusy: busy, disabled: busy || running, onClick: build }, __( 'Create Elementor draft', T ) ),
							el( Button, { variant: 'secondary', disabled: busy || running, onClick: start }, __( 'Generate again', T ) ),
							el( Button, { variant: 'tertiary', onClick: reset }, __( 'Change the brief', T ) )
						)
				  )
				: null;

		const slots = state && state.images ? state.images.slots : [];
		const canGenerate = state && state.images && state.images.can_generate;
		const chosenIds = Object.keys( picked ).filter( ( k ) => picked[ k ] );
		const imageReview =
			result && slots.length
				? el(
						'div',
						{ className: 'aiscs-studio__result' },
						el( 'h2', null, __( 'Section images', T ) ),
						canGenerate ? null : el( Notice, { status: 'warning', isDismissible: false }, __( 'To generate AI images, choose a default image provider under AI Providers (and make sure you may upload files). You can also replace the placeholders in Elementor with your own images.', T ) ),
						el( 'p', { className: 'description' }, __( 'Each image is generated as a suggestion in the Media Library. It is only put into the draft when you click “Use this image”.', T ) ),
						el(
							'div',
							{ className: 'aiscs-studio__images' },
							slots.map( ( s ) =>
								el(
									'div',
									{ key: s.id, className: 'aiscs-studio__card' },
									el( CheckboxControl, { label: el( 'strong', null, s.alt || s.id ), checked: !! picked[ s.id ], disabled: ! canGenerate, onChange: ( on ) => setPicked( Object.assign( {}, picked, { [ s.id ]: on } ) ), __nextHasNoMarginBottom: true } ),
									el( 'p', { className: 'description' }, sprintf( __( 'Section %1$d · %2$s · %3$s', T ), s.section + 1, s.aspect, 'applied' === s.status ? __( 'AI image in the draft', T ) : __( 'placeholder in the draft', T ) ) ),
									el( wp.components.TextareaControl, { label: __( 'Prompt', T ), value: prompts[ s.id ] !== undefined ? prompts[ s.id ] : s.prompt, onChange: ( v ) => setPrompts( Object.assign( {}, prompts, { [ s.id ]: v } ) ), rows: 3 } ),
									s.error ? el( Notice, { status: 'error', isDismissible: false }, s.error ) : null,
									s.candidate
										? el(
												Fragment,
												null,
												el( 'img', { src: s.candidate.url, alt: s.alt, className: 'aiscs-studio__candidate' } ),
												el( 'p', { className: 'description' }, sprintf( __( 'Made by %1$s (%2$s).', T ), s.candidate.provider, s.candidate.model ) ),
												el(
													'div',
													{ className: 'aiscs-studio__actions' },
													el( Button, { variant: 'primary', disabled: busy || running, onClick: () => applyImages( [ s.id ] ) }, __( 'Use this image', T ) ),
													el( Button, { variant: 'tertiary', isDestructive: true, disabled: busy || running, onClick: () => discard( s.id ) }, __( 'Discard', T ) )
												)
										  )
										: null
								)
							)
						),
						el(
							'div',
							{ className: 'aiscs-studio__actions' },
							el( Button, { variant: 'secondary', isBusy: busy, disabled: busy || running || ! canGenerate || ! chosenIds.length, onClick: () => makeImages( chosenIds ) }, sprintf( __( 'Generate %d selected image(s)', T ), chosenIds.length ) ),
							el( 'span', { className: 'description' }, __( 'One image request per image, counted in Usage and Costs.', T ) )
						)
				  )
				: null;

		return el(
			Fragment,
			null,
			noticeView,
			! projectId ? briefForm : null,
			state && state.error ? el( Notice, { status: 'error', isDismissible: false }, state.error ) : null,
			job && ( 'failed' === job.status || 'cancelled' === job.status ) ? el( Fragment, null, el( Notice, { status: 'error', isDismissible: false }, job.message || job.error ), result ? null : el( Button, { variant: 'secondary', onClick: reset }, __( 'Start over', T ) ) ) : null,
			steps,
			result
				? el(
						Fragment,
						null,
						el( DraftResult, { result } ),
						result.report && result.report.links_applied && result.report.links_applied.length ? el( 'p', { className: 'description' }, sprintf( __( '%d internal link(s) added.', T ), result.report.links_applied.length ) ) : null,
						result.report && result.report.links_skipped && result.report.links_skipped.length ? el( Notice, { status: 'warning', isDismissible: false }, sprintf( __( '%d approved link(s) were not added because the target page is no longer published or the anchor text was not found.', T ), result.report.links_skipped.length ) ) : null
				  )
				: null,
			imageReview,
			review,
			result ? el( Button, { variant: 'secondary', onClick: reset }, __( 'Generate another page', T ) ) : null
		);
	}

	/* ---------------------------------------------------------------------- Page tools (phase 7) */

	const DEVICES_PX = { desktop: 1280, tablet: 768, mobile: 390 };
	const STATUS_LABELS = { pass: __( 'Pass', T ), warning: __( 'Warning', T ), fail: __( 'Fail', T ), not_checked: __( 'Not in the reference', T ) };
	const CATEGORY_LABELS = {
		colors: __( 'Brand colours', T ),
		typography: __( 'Typography', T ),
		buttons: __( 'Button styling', T ),
		cards: __( 'Card styling', T ),
		spacing: __( 'Section spacing', T ),
		widths: __( 'Container widths', T ),
		images: __( 'Image treatment', T ),
		headings: __( 'Heading hierarchy', T ),
		patterns: __( 'Visual patterns', T ),
		responsive: __( 'Responsive layout', T ),
		links: __( 'Links and buttons', T ),
		contrast: __( 'Colour contrast', T ),
		readability: __( 'Readability', T ),
		forms: __( 'Form labels', T ),
		keyboard: __( 'Keyboard', T ),
		media: __( 'Video and audio', T ),
	};
	const SEVERITY_LABELS = { error: __( 'Error', T ), warning: __( 'Warning', T ), recommendation: __( 'Recommendation', T ) };
	const ACTION_LABELS = {
		rewrite: __( 'Rewrite content (AI)', T ),
		seo: __( 'Improve SEO (AI)', T ),
		redesign: __( 'Redesign section (AI)', T ),
		image: __( 'Regenerate images (AI)', T ),
		colors: __( 'Change colours', T ),
		typography: __( 'Change typography', T ),
		layout: __( 'Change layout', T ),
		spacing: __( 'Adjust spacing', T ),
		responsive: __( 'Improve responsive design', T ),
	};
	const REVISION_ACTIONS = { create: __( 'Created', T ), update: __( 'Updated', T ), regenerate: __( 'Section regenerated', T ), fix: __( 'Fixes applied', T ), images: __( 'Images placed', T ), insert: __( 'Library item inserted', T ), restore: __( 'Restored', T ), external: __( 'Edited in Elementor', T ) };

	/** Device-sized preview of a URL; scrolls to an element when asked. */
	function DevicePreview( { url, scrollTo } ) {
		const [ device, setDevice ] = useState( 'desktop' );
		const [ frame, setFrame ] = useState( null );
		const scroll = () => {
			if ( ! frame || ! scrollTo ) {
				return;
			}
			try {
				const target = frame.contentDocument && frame.contentDocument.querySelector( '.elementor-element-' + scrollTo );
				if ( target ) {
					target.scrollIntoView( { block: 'start' } );
				}
			} catch ( e ) {
				// Cross-origin preview (different admin domain): the preview stays at the top.
			}
		};
		useEffect( scroll, [ frame, scrollTo, device ] );
		if ( ! url ) {
			return null;
		}
		return el(
			'div',
			{ className: 'aiscs-studio__device' },
			el(
				'div',
				{ className: 'aiscs-studio__actions' },
				Object.keys( DEVICES_PX ).map( ( d ) => el( Button, { key: d, variant: d === device ? 'primary' : 'secondary', onClick: () => setDevice( d ) }, { desktop: __( 'Desktop', T ), tablet: __( 'Tablet', T ), mobile: __( 'Mobile', T ) }[ d ] + ' (' + DEVICES_PX[ d ] + 'px)' ) ),
				el( Button, { variant: 'tertiary', href: url, target: '_blank' }, __( 'Open full page', T ) )
			),
			el( 'div', { className: 'aiscs-studio__frame-wrap' }, el( 'iframe', { ref: setFrame, src: url, title: __( 'Page preview', T ), className: 'aiscs-studio__frame', style: { width: DEVICES_PX[ device ] + 'px' }, onLoad: scroll } ) )
		);
	}

	/** Findings with checkboxes for the ones that can be fixed. */
	function FindingList( { findings, chosen, setChosen, values, setValues } ) {
		if ( ! findings.length ) {
			return el( Notice, { status: 'success', isDismissible: false }, __( 'No problems found.', T ) );
		}
		return el(
			'table',
			{ className: 'widefat striped' },
			el( 'thead', null, el( 'tr', null, el( 'th', { className: 'check-column' } ), el( 'th', null, __( 'Severity', T ) ), el( 'th', null, __( 'Area', T ) ), el( 'th', null, __( 'Problem', T ) ), el( 'th', null, __( 'Suggested fix', T ) ) ) ),
			el(
				'tbody',
				null,
				findings.map( ( f ) =>
					el(
						'tr',
						{ key: f.id },
						el( 'th', { className: 'check-column' }, f.fix ? el( CheckboxControl, { checked: !! chosen[ f.id ], onChange: ( on ) => setChosen( Object.assign( {}, chosen, { [ f.id ]: on } ) ), label: '', 'aria-label': f.fix.label, __nextHasNoMarginBottom: true } ) : null ),
						el( 'td', null, el( 'span', { className: 'aiscs-studio__badge is-' + f.severity }, SEVERITY_LABELS[ f.severity ] || f.severity ) ),
						el( 'td', null, CATEGORY_LABELS[ f.category ] || f.category ),
						el( 'td', null, f.message ),
						el(
							'td',
							null,
							f.fix ? f.fix.label : el( 'em', null, __( 'Fix manually in Elementor', T ) ),
							f.fix && f.fix.needs_value && setValues
								? el( TextControl, { label: __( 'Text to use', T ), value: values[ f.id ] !== undefined ? values[ f.id ] : f.fix.suggestion || '', onChange: ( v ) => setValues( Object.assign( {}, values, { [ f.id ]: v } ) ), __nextHasNoMarginBottom: true } )
								: null
						)
					)
				)
			)
		);
	}

	function ConsistencyTab( { page, writable, onChanged } ) {
		const [ profiles, setProfiles ] = useState( [] );
		const [ reference, setReference ] = useState( '' );
		const [ report, setReport ] = useState( null );
		const [ chosen, setChosen ] = useState( {} );
		const [ busy, setBusy ] = useState( false );
		const [ noticeView, setNotice ] = useNotice();
		useEffect( () => {
			apiFetch( { path: '/aiscs/v1/website/brand-profiles?page=1' } )
				.then( ( r ) => setProfiles( r.items || [] ) )
				.catch( () => setProfiles( [] ) );
		}, [] );
		const body = () => {
			const data = { profile_id: 0, reference_page_ids: [] };
			if ( reference.startsWith( 'p' ) ) {
				data.profile_id = parseInt( reference.slice( 1 ), 10 );
			} else if ( reference.trim() ) {
				data.reference_page_ids = reference.split( ',' ).map( ( s ) => parseInt( s, 10 ) ).filter( ( n ) => n > 0 ).slice( 0, 5 );
			}
			return data;
		};
		const run = () => {
			setBusy( true );
			apiFetch( { path: '/aiscs/v1/design/pages/' + page + '/consistency-check', method: 'POST', data: body() } )
				.then( ( r ) => {
					setReport( r );
					setChosen( {} );
				} )
				.catch( ( e ) => setNotice( { status: 'error', text: errorText( e ) } ) )
				.finally( () => setBusy( false ) );
		};
		const apply = () => {
			setBusy( true );
			apiFetch( { path: '/aiscs/v1/design/pages/' + page + '/consistency/apply', method: 'POST', data: Object.assign( body(), { fix_ids: Object.keys( chosen ).filter( ( k ) => chosen[ k ] ) } ) } )
				.then( ( r ) => {
					setNotice( { status: r.skipped.length ? 'warning' : 'success', text: sprintf( __( '%1$d fixes applied, %2$d skipped (the page changed since the check). A revision was saved.', T ), r.applied.length, r.skipped.length ) } );
					onChanged();
					run();
				} )
				.catch( ( e ) => setNotice( { status: 'error', text: errorText( e ) } ) )
				.finally( () => setBusy( false ) );
		};
		const picked = Object.keys( chosen ).filter( ( k ) => chosen[ k ] ).length;
		return el(
			Fragment,
			null,
			noticeView,
			el( 'p', { className: 'description' }, __( 'Compares this page with a brand profile, with reference pages, or with the styles the page was built with (else the Elementor Site Settings). Each recommendation can be applied on its own; nothing else on the page changes.', T ) ),
			el(
				'div',
				{ className: 'aiscs-studio__filters' },
				el( SelectControl, {
					label: __( 'Compare with', T ),
					value: reference.startsWith( 'p' ) || '' === reference ? reference : 'pages',
					options: [ { label: __( 'Styles the page was built with / Site Settings', T ), value: '' } ]
						.concat( profiles.map( ( p ) => ( { label: sprintf( __( 'Brand profile: %s', T ), p.name ), value: 'p' + p.id } ) ) )
						.concat( [ { label: __( 'Reference pages (IDs)…', T ), value: 'pages' } ] ),
					onChange: ( v ) => setReference( 'pages' === v ? ' ' : v ),
					__nextHasNoMarginBottom: true,
				} ),
				! reference.startsWith( 'p' ) && '' !== reference ? el( TextControl, { label: __( 'Reference page IDs (comma separated, up to 5)', T ), value: reference.trim(), onChange: ( v ) => setReference( v || ' ' ), __nextHasNoMarginBottom: true } ) : null,
				el( Button, { variant: 'primary', isBusy: busy, disabled: busy, onClick: run }, __( 'Check consistency', T ) )
			),
			report
				? el(
						Fragment,
						null,
						el( 'h3', null, sprintf( __( 'Design Consistency Report — score %d/100', T ), report.score ) ),
						el(
							'table',
							{ className: 'widefat striped aiscs-tokens__table' },
							el( 'tbody', null, Object.keys( report.categories ).map( ( c ) => el( 'tr', { key: c }, el( 'th', null, CATEGORY_LABELS[ c ] || c ), el( 'td', null, el( 'span', { className: 'aiscs-studio__badge is-' + report.categories[ c ].status }, STATUS_LABELS[ report.categories[ c ].status ] ) ) ) ) )
						),
						el( 'h3', null, __( 'Recommendations', T ) ),
						el( FindingList, { findings: report.findings, chosen, setChosen } ),
						report.findings.some( ( f ) => f.fix )
							? el(
									'div',
									{ className: 'aiscs-studio__actions' },
									el( Button, { variant: 'primary', isBusy: busy, disabled: busy || ! picked || ! writable, onClick: apply }, sprintf( __( 'Apply %d selected recommendations', T ), picked ) ),
									writable ? null : el( 'span', { className: 'description' }, __( 'Fixes can only be applied to drafts made by AI Website Studio.', T ) )
							  )
							: null
				  )
				: null
		);
	}

	function AccessibilityTab( { page, writable, onChanged } ) {
		const [ report, setReport ] = useState( null );
		const [ chosen, setChosen ] = useState( {} );
		const [ values, setValues ] = useState( {} );
		const [ busy, setBusy ] = useState( false );
		const [ noticeView, setNotice ] = useNotice();
		const run = () => {
			setBusy( true );
			apiFetch( { path: '/aiscs/v1/design/pages/' + page + '/accessibility-check', method: 'POST' } )
				.then( ( r ) => {
					setReport( r );
					setChosen( {} );
					setValues( {} );
				} )
				.catch( ( e ) => setNotice( { status: 'error', text: errorText( e ) } ) )
				.finally( () => setBusy( false ) );
		};
		const apply = () => {
			const ids = Object.keys( chosen ).filter( ( k ) => chosen[ k ] );
			const data = { fix_ids: ids, values: {} };
			report.findings.forEach( ( f ) => {
				if ( ids.includes( f.id ) && f.fix && f.fix.needs_value ) {
					data.values[ f.id ] = values[ f.id ] !== undefined ? values[ f.id ] : f.fix.suggestion || '';
				}
			} );
			setBusy( true );
			apiFetch( { path: '/aiscs/v1/design/pages/' + page + '/accessibility/apply', method: 'POST', data } )
				.then( ( r ) => {
					setNotice( { status: r.skipped.length ? 'warning' : 'success', text: sprintf( __( '%1$d fixes applied, %2$d skipped (fixes that need text are skipped when the text is empty).', T ), r.applied.length, r.skipped.length ) } );
					onChanged();
					run();
				} )
				.catch( ( e ) => setNotice( { status: 'error', text: errorText( e ) } ) )
				.finally( () => setBusy( false ) );
		};
		const picked = Object.keys( chosen ).filter( ( k ) => chosen[ k ] ).length;
		const order = { error: 0, warning: 1, recommendation: 2 };
		return el(
			Fragment,
			null,
			noticeView,
			el( 'p', { className: 'description' }, __( 'Checks common problems against WCAG 2.2 AA: heading order, image alt text, link and button text, colour contrast, text size, form labels and markup that needs a keyboard check.', T ) ),
			el( 'div', { className: 'aiscs-studio__actions' }, el( Button, { variant: 'primary', isBusy: busy, disabled: busy, onClick: run }, __( 'Check accessibility', T ) ) ),
			report
				? el(
						Fragment,
						null,
						el( Notice, { status: 'info', isDismissible: false }, report.notice ),
						el( FindingList, { findings: report.findings.slice().sort( ( a, b ) => order[ a.severity ] - order[ b.severity ] ), chosen, setChosen, values, setValues } ),
						report.findings.some( ( f ) => f.fix )
							? el( 'div', { className: 'aiscs-studio__actions' }, el( Button, { variant: 'primary', isBusy: busy, disabled: busy || ! picked || ! writable, onClick: apply }, sprintf( __( 'Apply %d selected fixes', T ), picked ) ) )
							: null
				  )
				: null
		);
	}

	/** Poll a regeneration project until its proposal exists. */
	function useProposal( projectId, run ) {
		const [ state, setState ] = useState( null );
		useEffect( () => {
			if ( ! projectId ) {
				setState( null );
				return undefined;
			}
			let stop = false;
			let timer = null;
			const poll = () => {
				apiFetch( { path: '/aiscs/v1/design/proposals/' + projectId } )
					.then( ( r ) => {
						if ( stop ) {
							return;
						}
						setState( r );
						const status = r.job ? r.job.status : '';
						if ( ! r.proposal && ( 'queued' === status || 'running' === status ) ) {
							timer = setTimeout( poll, 3000 );
						}
					} )
					.catch( ( e ) => ! stop && setState( { error: errorText( e ) } ) );
			};
			poll();
			return () => {
				stop = true;
				clearTimeout( timer );
			};
		}, [ projectId, run ] );
		return state;
	}

	function SectionsTab( { page, overview, onChanged } ) {
		const [ section, setSection ] = useState( '' );
		const [ form, setForm ] = useState( { action: 'rewrite', instruction: '', primary_keyword: '', background: 'surface', scale: 'smaller', font: '', kind: 'grid', columns: '3', spacing: 'normal', prompt: '' } );
		const [ projectId, setProjectId ] = useState( 0 );
		const [ run, setRun ] = useState( 0 );
		const [ preview, setPreview ] = useState( null );
		const [ busy, setBusy ] = useState( false );
		const [ save, setSave ] = useState( { name: '', category: 'other' } );
		const [ noticeView, setNotice ] = useNotice();
		const state = useProposal( projectId, run );
		const set = ( key ) => ( v ) => setForm( Object.assign( {}, form, { [ key ]: v } ) );
		const fail = ( e ) => setNotice( { status: 'error', text: errorText( e ) } );

		if ( ! overview.regenerable ) {
			return el( Notice, { status: 'info', isDismissible: false }, __( 'Sections can be regenerated on pages built by the AI Website Studio design engine (AI Page Generator, HTML to Elementor, Image to Design). You can still check, preview and restore this page, and save its sections to the design library.', T ) );
		}
		const regenerate = () => {
			setBusy( true );
			setPreview( null );
			apiFetch( {
				path: '/aiscs/v1/design/pages/' + page + '/sections/' + section + '/regenerate',
				method: 'POST',
				data: Object.assign( {}, form, { columns: parseInt( form.columns, 10 ) || 0 } ),
			} )
				.then( ( r ) => {
					setProjectId( r.project_id );
					setRun( run + 1 );
					setNotice( null );
				} )
				.catch( fail )
				.finally( () => setBusy( false ) );
		};
		const act = ( what ) => {
			setBusy( true );
			apiFetch( { path: '/aiscs/v1/design/proposals/' + projectId + '/' + what, method: 'POST' } )
				.then( ( r ) => {
					if ( 'preview' === what ) {
						setPreview( r );
						return;
					}
					setNotice( { status: 'success', text: 'approve' === what ? __( 'The section was updated in the draft and a revision was saved. Only this section changed.', T ) : __( 'The proposal was discarded. The page did not change.', T ) } );
					setProjectId( 0 );
					setPreview( null );
					onChanged();
				} )
				.catch( fail )
				.finally( () => setBusy( false ) );
		};
		const saveToLibrary = ( elementId ) => {
			setBusy( true );
			apiFetch( { path: '/aiscs/v1/design/library', method: 'POST', data: { page_id: page, element_id: elementId, name: save.name, category: save.category } } )
				.then( ( r ) => setNotice( { status: 'success', text: sprintf( __( '"%s" was saved to the design library.', T ), r.name ) } ) )
				.catch( fail )
				.finally( () => setBusy( false ) );
		};

		const job = state && state.job;
		const running = job && ! ( state && state.proposal ) && ( 'queued' === job.status || 'running' === job.status );
		const current = overview.sections.find( ( s ) => s.section_id === section );
		const options = {
			rewrite: [ 'instruction', 'primary_keyword' ],
			seo: [ 'instruction', 'primary_keyword' ],
			redesign: [ 'instruction' ],
			image: [ 'prompt' ],
			colors: [ 'background' ],
			typography: [ 'scale', 'font' ],
			layout: [ 'kind', 'columns' ],
			spacing: [ 'spacing' ],
			responsive: [],
		}[ form.action ];
		const field = ( key ) =>
			( {
				instruction: el( wp.components.TextareaControl, { key, label: __( 'Instruction (optional)', T ), value: form.instruction, onChange: set( 'instruction' ), rows: 2 } ),
				primary_keyword: el( TextControl, { key, label: __( 'Primary keyword (optional; defaults to the page brief)', T ), value: form.primary_keyword, onChange: set( 'primary_keyword' ), __nextHasNoMarginBottom: true } ),
				prompt: el( wp.components.TextareaControl, { key, label: __( 'Image prompt (optional; defaults to the alt text)', T ), value: form.prompt, onChange: set( 'prompt' ), rows: 2 } ),
				background: el( SelectControl, { key, label: __( 'Background', T ), value: form.background, options: [ { label: __( 'Page background', T ), value: '' }, { label: __( 'Light (surface)', T ), value: 'surface' }, { label: __( 'Primary', T ), value: 'primary' }, { label: __( 'Secondary', T ), value: 'secondary' }, { label: __( 'Dark', T ), value: 'dark' } ], onChange: set( 'background' ), __nextHasNoMarginBottom: true } ),
				scale: el( SelectControl, { key, label: __( 'Heading size', T ), value: form.scale, options: [ { label: __( 'Smaller', T ), value: 'smaller' }, { label: __( 'Larger', T ), value: 'larger' }, { label: __( 'Brand size', T ), value: 'default' } ], onChange: set( 'scale' ), __nextHasNoMarginBottom: true } ),
				font: el( TextControl, { key, label: __( 'Heading font (optional)', T ), value: form.font, onChange: set( 'font' ), __nextHasNoMarginBottom: true } ),
				kind: el( SelectControl, { key, label: __( 'Layout', T ), value: form.kind, options: [ { label: __( 'One column', T ), value: 'stack' }, { label: __( 'Two halves', T ), value: 'split' }, { label: __( 'Grid of cards', T ), value: 'grid' } ], onChange: set( 'kind' ), __nextHasNoMarginBottom: true } ),
				columns: el( SelectControl, { key, label: __( 'Columns on desktop', T ), value: form.columns, options: [ '0', '1', '2', '3', '4', '5', '6' ].map( ( n ) => ( { label: '0' === n ? __( 'Automatic', T ) : n, value: n } ) ), onChange: set( 'columns' ), __nextHasNoMarginBottom: true } ),
				spacing: el( SelectControl, { key, label: __( 'Spacing', T ), value: form.spacing, options: [ { label: __( 'Compact', T ), value: 'compact' }, { label: __( 'Normal', T ), value: 'normal' }, { label: __( 'Spacious', T ), value: 'spacious' } ], onChange: set( 'spacing' ), __nextHasNoMarginBottom: true } ),
			} )[ key ];

		return el(
			Fragment,
			null,
			noticeView,
			el(
				'table',
				{ className: 'widefat striped' },
				el( 'thead', null, el( 'tr', null, el( 'th', null, '#' ), el( 'th', null, __( 'Section', T ) ), el( 'th', null, __( 'Content', T ) ), el( 'th', null ) ) ),
				el(
					'tbody',
					null,
					overview.sections.map( ( s ) =>
						el(
							'tr',
							{ key: s.section_id, className: s.section_id === section ? 'is-selected' : '' },
							el( 'td', null, s.index + 1 ),
							el( 'td', null, SECTION_LABELS[ s.type ] || s.type ),
							el( 'td', null, s.present ? s.label : el( 'em', null, __( 'Removed in Elementor', T ) ) ),
							el( 'td', null, s.present ? el( Button, { variant: s.section_id === section ? 'primary' : 'secondary', onClick: () => setSection( s.section_id ) }, __( 'Select', T ) ) : null )
						)
					)
				)
			),
			current
				? el(
						'div',
						{ className: 'aiscs-studio__card aiscs-studio__result' },
						el( 'h3', null, sprintf( __( 'Section %1$d: %2$s', T ), current.index + 1, current.label ) ),
						el( 'p', { className: 'description' }, __( 'Only this section is regenerated; the rest of the page stays as it is. You preview the result before anything changes.', T ) ),
						el(
							'div',
							{ className: 'aiscs-studio__fields' },
							el( SelectControl, { label: __( 'What to do', T ), value: form.action, options: Object.keys( ACTION_LABELS ).filter( ( a ) => 'image' !== a || overview.image_ready ).map( ( a ) => ( { label: ACTION_LABELS[ a ], value: a } ) ), onChange: set( 'action' ), __nextHasNoMarginBottom: true } ),
							options.map( field )
						),
						el(
							'div',
							{ className: 'aiscs-studio__actions' },
							el( Button, { variant: 'primary', isBusy: busy || running, disabled: busy || running, onClick: regenerate }, __( 'Regenerate', T ) ),
							overview.image_ready ? null : el( 'span', { className: 'description' }, __( 'AI images need a default image provider (AI Providers).', T ) )
						),
						el( 'details', { className: 'aiscs-studio__analysis' }, el( 'summary', null, __( 'Save this section to the design library', T ) ), el( 'div', { className: 'aiscs-studio__filters' }, el( TextControl, { label: __( 'Name', T ), value: save.name, onChange: ( v ) => setSave( Object.assign( {}, save, { name: v } ) ), __nextHasNoMarginBottom: true } ), el( SelectControl, { label: __( 'Category', T ), value: save.category, options: LIBRARY_SECTIONS.map( ( c ) => ( { label: LIBRARY_LABELS[ c ] || c, value: c } ) ), onChange: ( v ) => setSave( Object.assign( {}, save, { category: v } ) ), __nextHasNoMarginBottom: true } ), el( Button, { variant: 'secondary', disabled: busy, onClick: () => saveToLibrary( current.element_id ) }, __( 'Save to library', T ) ) ) )
				  )
				: el( 'p', { className: 'description' }, __( 'Select a section to regenerate it.', T ) ),
			running ? el( 'p', null, el( Spinner ), ' ', job.step_label || __( 'Working…', T ) ) : null,
			job && 'failed' === job.status && ! ( state && state.proposal ) ? el( Notice, { status: 'error', isDismissible: false }, job.message || job.error ) : null,
			state && state.proposal && 'pending' === state.proposal.status
				? el(
						'div',
						{ className: 'aiscs-studio__card aiscs-studio__result' },
						el( 'h3', null, __( 'Proposal', T ) ),
						el( 'p', null, state.proposal.summary, state.proposal.model ? ' — ' + sprintf( __( 'by %1$s (%2$s)', T ), state.proposal.provider, state.proposal.model ) : '' ),
						state.proposal.report && state.proposal.report.images_dropped ? el( Notice, { status: 'warning', isDismissible: false }, sprintf( __( '%d image slots were dropped: images are never invented, only the section’s existing images are reused.', T ), state.proposal.report.images_dropped ) ) : null,
						state.proposal.texts.length ? el( 'details', null, el( 'summary', null, __( 'Texts in the new section', T ) ), el( 'ul', { className: 'aiscs-studio__list-plain' }, state.proposal.texts.map( ( t, i ) => el( 'li', { key: i }, t ) ) ) ) : null,
						el(
							'div',
							{ className: 'aiscs-studio__actions' },
							el( Button, { variant: 'secondary', disabled: busy, onClick: () => act( 'preview' ) }, __( 'Preview', T ) ),
							el( Button, { variant: 'primary', disabled: busy, onClick: () => act( 'approve' ) }, __( 'Approve', T ) ),
							el( Button, { variant: 'secondary', disabled: busy, onClick: regenerate }, __( 'Regenerate', T ) ),
							el( Button, { variant: 'tertiary', isDestructive: true, disabled: busy, onClick: () => act( 'discard' ) }, __( 'Discard', T ) )
						),
						preview ? el( DevicePreview, { url: preview.preview_url, scrollTo: preview.element_id } ) : null
				  )
				: null
		);
	}

	function RevisionsTab( { page, writable, onChanged, version } ) {
		const [ data, setData ] = useState( null );
		const [ diff, setDiff ] = useState( null );
		const [ busy, setBusy ] = useState( false );
		const [ noticeView, setNotice ] = useNotice();
		const load = useCallback( () => {
			apiFetch( { path: '/aiscs/v1/design/pages/' + page + '/revisions' } )
				.then( setData )
				.catch( ( e ) => setNotice( { status: 'error', text: errorText( e ) } ) );
		}, [ page ] );
		useEffect( load, [ load, version ] );
		const compare = ( rev ) => {
			apiFetch( { path: '/aiscs/v1/design/pages/' + page + '/revisions/compare?from=' + rev + '&to=0' } )
				.then( ( r ) => setDiff( r ) )
				.catch( ( e ) => setNotice( { status: 'error', text: errorText( e ) } ) );
		};
		const restore = ( path, text ) => {
			if ( ! window.confirm( text ) ) {
				return;
			}
			setBusy( true );
			apiFetch( { path: '/aiscs/v1/design/pages/' + page + '/revisions/' + path, method: 'POST' } )
				.then( () => {
					setNotice( { status: 'success', text: __( 'Restored. The previous state was kept as a revision, so this can be undone.', T ) } );
					setDiff( null );
					load();
					onChanged();
				} )
				.catch( ( e ) => setNotice( { status: 'error', text: errorText( e ) } ) )
				.finally( () => setBusy( false ) );
		};
		if ( ! data ) {
			return el( Fragment, null, noticeView, el( Spinner ) );
		}
		const list = ( items, verb ) => ( items.length ? el( 'li', null, verb + ': ' + items.map( ( i ) => '“' + i.label + '”' ).join( ', ' ) ) : null );
		return el(
			Fragment,
			null,
			noticeView,
			data.edited_since ? el( Notice, { status: 'info', isDismissible: false }, __( 'The page was edited in Elementor after the newest revision. Its current state is saved as a revision before any restore.', T ) ) : null,
			data.items.length
				? el(
						'table',
						{ className: 'widefat striped' },
						el( 'thead', null, el( 'tr', null, el( 'th', null, '#' ), el( 'th', null, __( 'Change', T ) ), el( 'th', null, __( 'Summary', T ) ), el( 'th', null, __( 'Date', T ) ), el( 'th', null ) ) ),
						el(
							'tbody',
							null,
							data.items.map( ( r ) =>
								el(
									'tr',
									{ key: r.id },
									el( 'td', null, r.revision ),
									el( 'td', null, REVISION_ACTIONS[ r.action ] || r.action ),
									el( 'td', null, r.summary, r.meta && r.meta.model ? el( 'small', null, ' (' + r.meta.model + ')' ) : null ),
									el( 'td', null, new Date( r.created_at ).toLocaleString() ),
									el(
										'td',
										null,
										r.restorable ? el( Button, { variant: 'link', onClick: () => compare( r.revision ) }, __( 'Compare with now', T ) ) : el( 'em', null, __( 'Too large to restore', T ) ),
										r.restorable && writable ? [ ' · ', el( Button, { key: 'r', variant: 'link', disabled: busy, onClick: () => restore( r.revision + '/restore', sprintf( __( 'Restore the whole page to revision %d?', T ), r.revision ) ) }, __( 'Restore', T ) ) ] : null
									)
								)
							)
						)
				  )
				: el( 'p', null, __( 'No revisions yet. The studio stores one each time it changes the page.', T ) ),
			diff
				? el(
						'div',
						{ className: 'aiscs-studio__card aiscs-studio__result' },
						el( 'h3', null, sprintf( __( 'Revision %d compared with the page now', T ), diff.from ) ),
						el(
							'ul',
							{ className: 'aiscs-studio__list-plain' },
							list( diff.diff.added, __( 'Added since', T ) ),
							list( diff.diff.removed, __( 'Removed since', T ) ),
							diff.diff.moved.length ? el( 'li', null, sprintf( __( '%d sections moved', T ), diff.diff.moved.length ) ) : null,
							el( 'li', null, sprintf( __( '%d sections unchanged', T ), diff.diff.unchanged ) )
						),
						diff.diff.changed.map( ( c ) =>
							el(
								'div',
								{ key: c.id, className: 'aiscs-studio__analysis' },
								el( 'strong', null, c.label ),
								c.style_only ? el( 'p', { className: 'description' }, __( 'Style or layout changed; texts are the same.', T ) ) : null,
								c.text_removed.length ? el( 'p', null, el( 'del', null, c.text_removed.join( ' · ' ) ) ) : null,
								c.text_added.length ? el( 'p', null, el( 'ins', null, c.text_added.join( ' · ' ) ) ) : null,
								writable ? el( Button, { variant: 'secondary', disabled: busy, onClick: () => restore( diff.from + '/sections/' + c.id + '/restore', __( 'Put this section back as it was in that revision? Other sections stay as they are.', T ) ) }, __( 'Undo this section', T ) ) : null
							)
						),
						diff.diff.removed.map( ( c ) => ( writable ? el( 'p', { key: 'rm' + c.id }, el( Button, { variant: 'secondary', disabled: busy, onClick: () => restore( diff.from + '/sections/' + c.id + '/restore', __( 'Put this section back?', T ) ) }, sprintf( __( 'Restore “%s”', T ), c.label ) ) ) : null ) )
				  )
				: null
		);
	}

	const LIBRARY_SECTIONS = [ 'hero', 'services', 'features', 'about', 'cta', 'testimonials', 'faq', 'contact', 'footer', 'content', 'other' ];
	const LIBRARY_COMPONENTS = [ 'button', 'card', 'image_grid', 'feature_box', 'statistics', 'pricing', 'other' ];
	const LIBRARY_LABELS = { hero: __( 'Hero', T ), services: __( 'Services', T ), features: __( 'Features', T ), about: __( 'About', T ), cta: __( 'Call to action', T ), testimonials: __( 'Testimonials', T ), faq: __( 'FAQ', T ), contact: __( 'Contact', T ), footer: __( 'Footer', T ), content: __( 'Content', T ), other: __( 'Other', T ), button: __( 'Button', T ), card: __( 'Card', T ), image_grid: __( 'Image grid', T ), feature_box: __( 'Feature box', T ), statistics: __( 'Statistics', T ), pricing: __( 'Pricing card', T ) };

	/** Studio pages to choose from (and ?page_id= from the URL). */
	function usePagePicker() {
		const fromUrl = parseInt( new URLSearchParams( window.location.search ).get( 'page_id' ) || '0', 10 ) || 0;
		const [ pages, setPages ] = useState( null );
		const [ page, setPage ] = useState( fromUrl );
		useEffect( () => {
			apiFetch( { path: '/aiscs/v1/design/pages?page=1' } )
				.then( ( r ) => setPages( r.items || [] ) )
				.catch( () => setPages( [] ) );
		}, [] );
		const picker = el( SelectControl, {
			label: __( 'Page', T ),
			value: String( page ),
			options: [ { label: __( 'Choose a page created by the studio', T ), value: '0' } ].concat( ( pages || [] ).map( ( p ) => ( { label: p.title + ' (#' + p.page_id + ', ' + p.status + ')', value: String( p.page_id ) } ) ) ).concat( fromUrl && pages && ! pages.some( ( p ) => p.page_id === fromUrl ) ? [ { label: '#' + fromUrl, value: String( fromUrl ) } ] : [] ),
			onChange: ( v ) => setPage( parseInt( v, 10 ) || 0 ),
			__nextHasNoMarginBottom: true,
		} );
		return [ page, picker, pages ];
	}

	function PageTools( { tab: initial } ) {
		const [ page, picker ] = usePagePicker();
		const [ tab, setTab ] = useState( initial || 'preview' );
		const [ overview, setOverview ] = useState( null );
		const [ version, setVersion ] = useState( 0 );
		const [ noticeView, setNotice ] = useNotice();
		useEffect( () => {
			setOverview( null );
			if ( ! page ) {
				return;
			}
			apiFetch( { path: '/aiscs/v1/design/pages/' + page + '/overview' } )
				.then( setOverview )
				.catch( ( e ) => setNotice( { status: 'error', text: errorText( e ) } ) );
		}, [ page, version ] );
		const changed = () => setVersion( version + 1 );
		const tabs = { preview: __( 'Preview', T ), sections: __( 'Sections', T ), consistency: __( 'Design consistency', T ), accessibility: __( 'Accessibility', T ), revisions: __( 'Revisions', T ) };
		return el(
			Fragment,
			null,
			noticeView,
			el( 'div', { className: 'aiscs-studio__filters' }, picker ),
			overview
				? el(
						Fragment,
						null,
						el(
							'div',
							{ className: 'aiscs-studio__actions' },
							el( 'strong', null, overview.page.title ),
							el( 'span', { className: 'description' }, overview.page.status ),
							el( Button, { variant: 'secondary', href: overview.page.elementor_url, target: '_blank' }, __( 'Open in Elementor', T ) )
						),
						overview.page.writable ? null : el( Notice, { status: 'warning', isDismissible: false }, overview.page.not_writable ),
						el( 'div', { className: 'aiscs-studio__tabs', role: 'tablist' }, Object.keys( tabs ).map( ( t ) => el( Button, { key: t, role: 'tab', 'aria-selected': t === tab, variant: t === tab ? 'primary' : 'secondary', onClick: () => setTab( t ) }, tabs[ t ] ) ) ),
						'preview' === tab
							? el(
									Fragment,
									null,
									el( 'p', { className: 'description' }, __( 'The draft as Elementor renders it. Only users who may edit the page can open this preview. Choose a section to jump to it.', T ) ),
									el( PagePreview, { overview, version } )
							  )
							: null,
						'sections' === tab ? el( SectionsTab, { page, overview, onChanged: changed } ) : null,
						'consistency' === tab ? el( ConsistencyTab, { page, writable: overview.page.writable, onChanged: changed } ) : null,
						'accessibility' === tab ? el( AccessibilityTab, { page, writable: overview.page.writable, onChanged: changed } ) : null,
						'revisions' === tab ? el( RevisionsTab, { page, writable: overview.page.writable, onChanged: changed, version } ) : null
				  )
				: page
				? el( Spinner )
				: null
		);
	}

	function PagePreview( { overview, version } ) {
		const [ target, setTarget ] = useState( '' );
		const url = overview.page.preview_url ? overview.page.preview_url + ( overview.page.preview_url.includes( '?' ) ? '&' : '?' ) + 'aiscs_v=' + version : '';
		return el(
			Fragment,
			null,
			overview.outline.length
				? el( SelectControl, { label: __( 'Section preview', T ), value: target, options: [ { label: __( 'Full page', T ), value: '' } ].concat( overview.outline.map( ( o, i ) => ( { label: i + 1 + '. ' + o.label, value: o.id } ) ) ), onChange: setTarget, __nextHasNoMarginBottom: true } )
				: null,
			el( DevicePreview, { url, scrollTo: target } )
		);
	}

	function DesignLibraryView() {
		const [ kind, setKind ] = useState( '' );
		const [ items, setItems ] = useState( null );
		const [ target, setTarget ] = useState( '0' );
		const [ position, setPosition ] = useState( '-1' );
		const [ preview, setPreview ] = useState( null );
		const [ editing, setEditing ] = useState( null );
		const [ busy, setBusy ] = useState( false );
		const [ , , pages ] = usePagePicker();
		const [ noticeView, setNotice ] = useNotice();
		const fail = ( e ) => setNotice( { status: 'error', text: errorText( e ) } );
		const load = useCallback( () => {
			apiFetch( { path: '/aiscs/v1/design/library?kind=' + kind } )
				.then( ( r ) => setItems( r.items ) )
				.catch( fail );
		}, [ kind ] );
		useEffect( load, [ load ] );
		const call = ( path, method, data, done ) => {
			setBusy( true );
			apiFetch( { path: '/aiscs/v1/design/library/' + path, method, data } )
				.then( ( r ) => {
					done( r );
					load();
				} )
				.catch( fail )
				.finally( () => setBusy( false ) );
		};
		return el(
			Fragment,
			null,
			noticeView,
			el( 'p', { className: 'description' }, __( 'Reusable sections and components saved from your pages (Page tools → Sections → Save to library). Items keep their Elementor structure, styles and responsive settings, and get new element IDs when inserted.', T ) ),
			el(
				'div',
				{ className: 'aiscs-studio__filters' },
				el( SelectControl, { label: __( 'Show', T ), value: kind, options: [ { label: __( 'Everything', T ), value: '' }, { label: __( 'Sections', T ), value: 'section' }, { label: __( 'Components', T ), value: 'component' } ], onChange: setKind, __nextHasNoMarginBottom: true } ),
				el( SelectControl, { label: __( 'Insert into', T ), value: target, options: [ { label: __( 'Choose a studio draft', T ), value: '0' } ].concat( ( pages || [] ).filter( ( p ) => 'publish' !== p.status ).map( ( p ) => ( { label: p.title + ' (#' + p.page_id + ')', value: String( p.page_id ) } ) ) ), onChange: setTarget, __nextHasNoMarginBottom: true } ),
				el( SelectControl, { label: __( 'Position', T ), value: position, options: [ { label: __( 'At the end', T ), value: '-1' }, { label: __( 'At the top', T ), value: '0' } ], onChange: setPosition, __nextHasNoMarginBottom: true } )
			),
			null === items
				? el( Spinner )
				: items.length
				? el(
						'div',
						{ className: 'aiscs-studio__cards' },
						items.map( ( item ) =>
							el(
								'div',
								{ key: item.id, className: 'aiscs-studio__card' },
								editing && editing.id === item.id
									? el(
											Fragment,
											null,
											el( TextControl, { label: __( 'Name', T ), value: editing.name, onChange: ( v ) => setEditing( Object.assign( {}, editing, { name: v } ) ), __nextHasNoMarginBottom: true } ),
											el( SelectControl, { label: __( 'Category', T ), value: editing.category, options: ( 'component' === item.kind ? LIBRARY_COMPONENTS : LIBRARY_SECTIONS ).map( ( c ) => ( { label: LIBRARY_LABELS[ c ] || c, value: c } ) ), onChange: ( v ) => setEditing( Object.assign( {}, editing, { category: v } ) ), __nextHasNoMarginBottom: true } ),
											el( 'div', { className: 'aiscs-studio__actions' }, el( Button, { variant: 'primary', disabled: busy || ! editing.name.trim(), onClick: () => call( item.id, 'PUT', { name: editing.name, category: editing.category }, () => setEditing( null ) ) }, __( 'Save', T ) ), el( Button, { variant: 'tertiary', onClick: () => setEditing( null ) }, __( 'Cancel', T ) ) )
									  )
									: el( 'h2', null, item.name ),
								el( 'p', { className: 'description' }, ( 'component' === item.kind ? __( 'Component', T ) : __( 'Section', T ) ) + ' · ' + ( LIBRARY_LABELS[ item.category ] || item.category ) + ' · ' + new Date( item.updated_at ).toLocaleDateString() ),
								el( 'p', null, ( item.summary.texts || [] ).join( ' · ' ).slice( 0, 160 ) ),
								el( 'p', { className: 'description' }, Object.keys( item.summary.widgets || {} ).map( ( w ) => w + ' ×' + item.summary.widgets[ w ] ).join( ', ' ) ),
								el(
									'div',
									{ className: 'aiscs-studio__actions' },
									el( Button, { variant: 'secondary', disabled: busy, onClick: () => call( item.id + '/preview', 'POST', {}, ( r ) => setPreview( r.preview_url ) ) }, __( 'Preview', T ) ),
									el( Button, { variant: 'primary', disabled: busy || '0' === target, onClick: () => call( item.id + '/insert', 'POST', { page_id: parseInt( target, 10 ), position: parseInt( position, 10 ) }, () => setNotice( { status: 'success', text: __( 'Inserted into the draft. A revision was saved.', T ) } ) ) }, __( 'Insert', T ) ),
									el( Button, { variant: 'tertiary', onClick: () => setEditing( { id: item.id, name: item.name, category: item.category } ) }, __( 'Edit', T ) ),
									el( Button, { variant: 'tertiary', disabled: busy, onClick: () => call( item.id + '/duplicate', 'POST', {}, () => {} ) }, __( 'Duplicate', T ) ),
									el( Button, { variant: 'tertiary', isDestructive: true, disabled: busy, onClick: () => window.confirm( __( 'Delete this library item? Pages that use it are not changed.', T ) ) && call( item.id, 'DELETE', undefined, () => {} ) }, __( 'Delete', T ) )
								)
							)
						)
				  )
				: el( 'p', null, __( 'The library is empty. Save a section from Page tools → Sections.', T ) ),
			preview ? el( 'div', { className: 'aiscs-studio__result' }, el( 'h2', null, __( 'Preview (temporary page, deleted after a day)', T ) ), el( DevicePreview, { url: preview, scrollTo: '' } ) ) : null
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
		generate: [ __( 'AI Page Generator', T ), PageGenerator ],
		consistency: [ __( 'Design Consistency Checker', T ), () => el( PageTools, { tab: 'consistency' } ) ],
		library: [ __( 'Design Library', T ), DesignLibraryView ],
		revisions: [ __( 'Revisions and History', T ), () => el( PageTools, { tab: 'revisions' } ) ],
		tools: [ __( 'Page Tools', T ), () => el( PageTools, { tab: 'preview' } ) ],
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
