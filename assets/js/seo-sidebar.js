/* global wp, forwpSeoSidebar */

( function ( wp, settings ) {
	console.log( '[forwp-seo] seo-sidebar.js loaded', settings );
	if ( ! settings ) {
		return;
	}

	const el = wp.element.createElement;
	const { registerPlugin } = wp.plugins;
	const editorApi = wp.editor || wp.editPost;
	if ( ! editorApi ) {
		console.warn( '[forwp-seo] editor API missing' );
		return;
	}

	const { PluginSidebar, PluginSidebarMoreMenuItem } = editorApi;
	const { createBlock } = wp.blocks || {};
	const { useSelect } = wp.data;
	const { useState, useEffect, Fragment } = wp.element;
	const { PanelBody, Button, Spinner, Notice } = wp.components;
	const { __ } = wp.i18n;
	const chartBar = wp.icons ? wp.icons.chartBar : null;
	const apiFetch = wp.apiFetch;

	const flattenBlocks = ( blocks ) => {
		let flat = [];
		blocks.forEach( ( block ) => {
			flat.push( block );
			if ( Array.isArray( block.innerBlocks ) && block.innerBlocks.length ) {
				flat = flat.concat( flattenBlocks( block.innerBlocks ) );
			}
		} );
		return flat;
	};

	const buildLlmsPreview = ( title, url, summary, steps, codeSamples, tags ) => {
		const lines = [];
		lines.push( '# TechArticle: ' + title );
		lines.push( '' );
		lines.push( '## URL' );
		lines.push( url );
		lines.push( '' );
		lines.push( '## Description' );
		lines.push( summary || '' );
		lines.push( '' );
		lines.push( '## Steps' );
		if ( steps.length ) {
			steps.forEach( ( step, index ) => {
				lines.push( ( index + 1 ) + '. ' + step );
			} );
		}
		lines.push( '' );
		lines.push( '## Code Samples' );
		if ( codeSamples.length ) {
			codeSamples.forEach( ( code, index ) => {
				lines.push( index + 1 + '. ' + code );
			} );
		}
		lines.push( '' );
		if ( tags.length ) {
			lines.push( '## Tags' );
			tags.forEach( ( tag ) => {
				lines.push( '- ' + tag );
			} );
			lines.push( '' );
		}
		lines.push( '## Updated' );
		lines.push( new Date().toISOString().replace( 'T', ' ' ).replace( 'Z', ' UTC' ) );

		return lines.join( '\n' );
	};

	const SeoSidebar = () => {
		const postId = useSelect( ( select ) => select( 'core/editor' ).getCurrentPostId(), [] );
		const post = useSelect( ( select ) => select( 'core/editor' ).getCurrentPost(), [] );
		const postTitle = useSelect(
			( select ) => select( 'core/editor' ).getEditedPostAttribute( 'title' ) || '',
			[]
		);
		const postExcerpt = useSelect(
			( select ) => select( 'core/editor' ).getEditedPostAttribute( 'excerpt' ) || '',
			[]
		);
		const postUrl = post?.link || '';
		const authorId = useSelect(
			( select ) => select( 'core/editor' ).getEditedPostAttribute( 'author' ),
			[]
		);
		const author = useSelect(
			( select ) =>
				authorId ? select( 'core' ).getEntityRecord( 'root', 'user', authorId ) : null,
			[ authorId ]
		);
		const tagIds = useSelect(
			( select ) => select( 'core/editor' ).getEditedPostAttribute( 'tags' ) || [],
			[]
		);
		const tags = useSelect(
			( select ) => {
				if ( ! tagIds.length ) {
					return [];
				}
				const records = select( 'core' ).getEntityRecords( 'taxonomy', 'post_tag', {
					include: tagIds,
					per_page: tagIds.length,
				} );
				if ( ! records ) {
					return [];
				}
				return records.map( ( record ) => record.name );
			},
			[ tagIds ]
		);

		const { hasCode, hasSteps } = useSelect( ( select ) => {
			const blocks = select( 'core/block-editor' ).getBlocks();
			const flat = flattenBlocks( blocks );
			let foundCode = false;
			let foundSteps = false;

			flat.forEach( ( block ) => {
				if ( block.name === 'core/code' ) {
					foundCode = true;
				}
				if ( block.name === 'forwp-seo/techarticle-steps' || block.name === 'forwp-seo/techarticle-step' ) {
					foundSteps = true;
				}
			} );

			return {
				hasCode: foundCode,
				hasSteps: foundSteps,
			};
		}, [] );

		const invalidBlocks = useSelect( ( select ) => {
			const blocks = select( 'core/block-editor' ).getBlocks();
			const flat = flattenBlocks( blocks );
			return flat.filter( ( block ) => {
				if ( block.isValid !== false ) {
					return false;
				}
				return block.name === 'core/comments' || block.name === 'core/separator';
			} );
		}, [] );

		const { steps, codeSamples, summary } = useSelect( ( select ) => {
			const blocks = select( 'core/block-editor' ).getBlocks();
			const flat = flattenBlocks( blocks );
			const extractedSteps = [];
			const extractedCodes = [];
			let extractedSummary = '';

			flat.forEach( ( block ) => {
				if ( block.name === 'core/paragraph' && ! extractedSummary ) {
					const text = block?.attributes?.content?.replace( /<[^>]*>/g, '' ) || '';
					if ( text.trim() ) {
						extractedSummary = text.trim();
					}
				}
				if ( block.name === 'core/code' ) {
					const code = block?.attributes?.content || '';
					if ( code.trim() ) {
						extractedCodes.push( code.trim() );
					}
				}
				if ( block.name === 'forwp-seo/techarticle-steps' && block?.attributes?.steps ) {
					const blockSteps = block?.attributes?.steps || [];
					blockSteps.forEach( ( step ) => {
						const text = step?.text?.replace( /<[^>]*>/g, '' ) || '';
						if ( text.trim() ) {
							extractedSteps.push( text.trim() );
						}
					} );
				}

				if ( block.name === 'forwp-seo/techarticle-step' ) {
					const parts = [];
					const walk = ( innerBlocks ) => {
						innerBlocks.forEach( ( inner ) => {
							const html = inner?.attributes?.content || inner?.innerHTML || '';
							const text = html.replace( /<[^>]*>/g, '' ).trim();
							if ( text ) {
								parts.push( text );
							}
							if ( inner.innerBlocks?.length ) {
								walk( inner.innerBlocks );
							}
						} );
					};
					walk( block.innerBlocks || [] );
					if ( parts.length ) {
						extractedSteps.push( parts.join( '\n' ) );
					}
				}
			} );

			if ( ! extractedSummary && postExcerpt ) {
				extractedSummary = postExcerpt.replace( /<[^>]*>/g, '' ).trim();
			}

			return {
				steps: extractedSteps,
				codeSamples: extractedCodes,
				summary: extractedSummary,
			};
		}, [ postExcerpt ] );

		const [ cross, setCross ] = useState( null );
		const [ crossLoading, setCrossLoading ] = useState( false );
		const [ crossError, setCrossError ] = useState( '' );
		const [ notifyBusy, setNotifyBusy ] = useState( false );
		const [ notifyNote, setNotifyNote ] = useState( '' );
		const [ serverTechArticle, setServerTechArticle ] = useState( null );
		const [ gscStatus, setGscStatus ] = useState( null );
		const [ gscLoading, setGscLoading ] = useState( true );
		const [ gscBusy, setGscBusy ] = useState( false );
		const postType = post?.type || '';
		const postStatus = post?.status || '';

		const gscHeaders = {
			'X-WP-Nonce': settings.nonce,
		};

		const loadGscStatus = async ( inspect ) => {
			if ( ! postId || ! apiFetch ) {
				return;
			}
			setGscLoading( true );
			try {
				const response = await apiFetch( {
					path:
						'/forwp-seo/v1/gsc/post-index?post_id=' +
						postId +
						( inspect ? '&inspect=1' : '' ),
					headers: gscHeaders,
				} );
				setGscStatus( response );
			} catch ( err ) {
				setGscStatus( {
					ok: false,
					message: err?.message || __( 'Could not load Search Console status.', '4wp-seo-helper' ),
				} );
			} finally {
				setGscLoading( false );
			}
		};

		const requestGscIndex = async ( event ) => {
			if ( event && event.preventDefault ) {
				event.preventDefault();
			}
			if ( ! postId || ! apiFetch || ! gscStatus?.ready ) {
				return;
			}
			setGscBusy( true );
			try {
				const response = await apiFetch( {
					path: '/forwp-seo/v1/gsc/request-index',
					method: 'POST',
					headers: gscHeaders,
					data: { post_id: postId },
				} );
				setGscStatus( response );
				const inspectUrl = response?.gscInspectUrl || response?.inspect?.inspectLink || '';
				if ( inspectUrl ) {
					window.open( inspectUrl, '_blank', 'noopener,noreferrer' );
				}
			} catch ( err ) {
				setGscStatus( {
					ok: false,
					message: err?.message || __( 'Could not refresh Search Console status.', '4wp-seo-helper' ),
					gscInspectUrl: gscStatus?.gscInspectUrl || '',
				} );
			} finally {
				setGscBusy( false );
			}
		};

		useEffect( () => {
			if ( ! postId ) {
				setGscLoading( false );
				return;
			}
			loadGscStatus( false );
		}, [ postId, postStatus ] );

		useEffect( () => {
			if ( ! postId || postType !== 'practice_case' || ! apiFetch ) {
				setServerTechArticle( null );
				return undefined;
			}

			let cancelled = false;

			apiFetch( {
				path: '/forwp-seo/v1/techarticle?post_id=' + postId,
				headers: {
					'X-WP-Nonce': settings.nonce,
				},
			} )
				.then( ( response ) => {
					if ( ! cancelled ) {
						setServerTechArticle( response );
					}
				} )
				.catch( () => {
					if ( ! cancelled ) {
						setServerTechArticle( null );
					}
				} );

			return () => {
				cancelled = true;
			};
		}, [ postId, postType ] );

		const sendTelegram = async () => {
			if ( ! postId || notifyBusy || ! apiFetch ) {
				return;
			}
			setNotifyBusy( true );
			setNotifyNote( '' );
			try {
				await apiFetch( {
					path: '/forwp-seo/v1/crosspost/notify',
					method: 'POST',
					data: { post_id: postId },
					headers: {
						'X-WP-Nonce': settings.nonce,
					},
				} );
				setNotifyNote( __( 'Sent to Telegram.', '4wp-seo-helper' ) );
			} catch ( err ) {
				setNotifyNote( err?.message || __( 'Could not send the Telegram message.', '4wp-seo-helper' ) );
			} finally {
				setNotifyBusy( false );
			}
		};

		const loadCrossposting = async ( fresh = false ) => {
			if ( ! postId || ! settings.crosspostingEnabled || ! apiFetch ) {
				return;
			}
			setCrossLoading( true );
			setCrossError( '' );
			try {
				const response = await apiFetch( {
					path: '/forwp-seo/v1/crosspost?post_id=' + postId + ( fresh === true ? '&fresh=1' : '' ),
					headers: {
						'X-WP-Nonce': settings.nonce,
					},
				} );
				setCross( response );
			} catch ( err ) {
				setCross( null );
				setCrossError( err?.message || __( 'Could not load cross posting status.', '4wp-seo-helper' ) );
			} finally {
				setCrossLoading( false );
			}
		};

		useEffect( () => {
			loadCrossposting();
		}, [ postId, postStatus ] );

		// Steps required for JSON-LD; Code (`core/code`) is optional enrichment — not TechArticle Context.
		const isValid = serverTechArticle?.valid || hasSteps;
		const jsonPreview = serverTechArticle?.schema && Object.keys( serverTechArticle.schema ).length
			? serverTechArticle.schema
			: {
			'@context': 'https://schema.org',
			'@type': 'TechArticle',
			headline: postTitle || '...',
			author: author?.name
				? {
					'@type': 'Person',
					name: author.name,
				}
				: undefined,
			hasPart: [
				...codeSamples.map( ( code ) => ( {
					'@type': 'SoftwareSourceCode',
					codeSampleType: 'full',
					programmingLanguage: 'auto',
					text: code,
				} ) ),
				{
					'@type': 'HowTo',
					step: steps.map( ( step ) => ( {
						'@type': 'HowToStep',
						text: step,
					} ) ),
				},
			],
			about: tags.map( ( tag ) => ( {
				'@type': 'Thing',
				name: tag,
			} ) ),
		};

		const llmsPreview = buildLlmsPreview(
			postTitle || '...',
			postUrl || '',
			summary || '',
			steps,
			codeSamples,
			tags
		);

		const repairInvalidBlocks = () => {
			if ( ! createBlock ) {
				console.warn( '[forwp-seo] wp.blocks.createBlock missing' );
				return;
			}
			const dispatch = wp.data.dispatch( 'core/block-editor' );
			if ( ! dispatch || ! dispatch.replaceBlock ) {
				console.warn( '[forwp-seo] block editor dispatch missing' );
				return;
			}
			invalidBlocks.forEach( ( block ) => {
				const next = createBlock( block.name, block.attributes || {} );
				dispatch.replaceBlock( block.clientId, next );
			} );
		};

		const statusNotice = isValid
			? el(
				Notice,
				{ status: 'success', isDismissible: false },
				serverTechArticle?.source === 'practice_case_sections'
					? __( 'TechArticle is ready from practice case section meta. JSON-LD will be added to the page.', '4wp-seo-helper' )
					: __( 'TechArticle is ready. JSON-LD will be added to the page.', '4wp-seo-helper' )
			)
			: el(
				Notice,
				{ status: 'warning', isDismissible: false },
				postType === 'practice_case'
					? __( 'Missing steps/commands in practice case section meta. JSON-LD will not be added.', '4wp-seo-helper' )
					: __( 'Missing required blocks. JSON-LD will not be added.', '4wp-seo-helper' )
			);

		const statusList = postType === 'practice_case'
			? el(
				'ul',
				{ style: { margin: 0, paddingLeft: '18px' } },
				el(
					'li',
					null,
					( serverTechArticle?.valid ? '✅ ' : '⚠️ ' ) +
						__( 'Practice case section meta (steps + commands)', '4wp-seo-helper' )
				)
			)
			: el(
				Fragment,
				null,
				el(
					'ul',
					{ style: { margin: 0, paddingLeft: '18px' } },
					el(
						'li',
						null,
						( hasSteps ? '✅ ' : '⚠️ ' ) + __( 'TechArticle Steps block (required)', '4wp-seo-helper' )
					),
					el(
						'li',
						null,
						( hasCode ? '✅ ' : 'ℹ️ ' ) +
							__( 'Code sample — WordPress Code block /code (optional)', '4wp-seo-helper' )
					)
				),
				el(
					'p',
					{
						style: {
							margin: '8px 0 0',
							fontSize: '12px',
							color: '#646970',
						},
					},
					__(
						'Code is not TechArticle Context. Insert the core Code block (usually inside Steps) to add SoftwareSourceCode to JSON-LD.',
						'4wp-seo-helper'
					)
				)
			);

		const repairBlock = invalidBlocks.length
			? el(
				'div',
				{ style: { marginTop: '12px' } },
				el(
					Notice,
					{ status: 'warning', isDismissible: false },
					__( 'Invalid blocks detected. Click to repair.', '4wp-seo-helper' )
				),
				el(
					Button,
					{ variant: 'secondary', onClick: repairInvalidBlocks },
					__( 'Repair invalid blocks', '4wp-seo-helper' )
				)
			)
			: null;

		const jsonPreviewBlock = el(
			'pre',
			{
				style: {
					background: '#f0f0f1',
					padding: '10px',
					borderRadius: '4px',
					fontSize: '12px',
					overflow: 'auto',
					maxHeight: '240px',
				},
			},
			JSON.stringify( jsonPreview, null, 2 )
		);

		const validationButtons = el(
			'div',
			{ style: { display: 'flex', flexDirection: 'column', gap: '8px' } },
			el(
				Button,
				{
					variant: 'secondary',
					href: postUrl
						? 'https://validator.schema.org/#url=' + encodeURIComponent( postUrl )
						: 'https://validator.schema.org/',
					target: '_blank',
					rel: 'noopener noreferrer',
				},
				__( 'Validate Schema.org', '4wp-seo-helper' )
			),
			el(
				Button,
				{
					variant: 'secondary',
					href: postUrl
						? 'https://search.google.com/test/rich-results?url=' + encodeURIComponent( postUrl )
						: 'https://search.google.com/test/rich-results',
					target: '_blank',
					rel: 'noopener noreferrer',
				},
				__( 'Google Rich Results Test', '4wp-seo-helper' )
			)
		);

		const llmsPreviewBlock = el(
			'pre',
			{
				style: {
					background: '#f0f0f1',
					padding: '10px',
					borderRadius: '4px',
					fontSize: '12px',
					overflow: 'auto',
					maxHeight: '240px',
				},
			},
			llmsPreview
		);

		const crossVariantLabel = ( variant ) => {
			const labels = {
				ready: __( 'Variant ready', '4wp-seo-helper' ),
				draft: __( 'Variant in progress', '4wp-seo-helper' ),
				empty: __( 'Variant file is empty', '4wp-seo-helper' ),
				missing: __( 'Variant file missing', '4wp-seo-helper' ),
				skipped: __( 'Skipped', '4wp-seo-helper' ),
				none: __( 'No variant', '4wp-seo-helper' ),
			};
			return labels[ variant ] || variant || '';
		};

		const crossPlatformRow = ( item ) =>
			el(
				'li',
				{
					key: item.key,
					style: {
						padding: '6px 0',
						borderBottom: '1px solid #f0f0f1',
						margin: 0,
					},
				},
				el(
					'div',
					{ style: { display: 'flex', justifyContent: 'space-between', gap: '8px' } },
					el(
						'span',
						null,
						el( 'strong', null, item.label ),
						el(
							'span',
							{ style: { marginLeft: '6px', color: '#646970', fontSize: '11px' } },
							item.mode === 'api' ? __( 'API', '4wp-seo-helper' ) : __( 'Manual', '4wp-seo-helper' )
						),
						item.open_url
							? el(
								'a',
								{
									href: item.open_url,
									target: '_blank',
									rel: 'noopener',
									'data-forwp-crosspost-post': String( postId ),
									'data-forwp-crosspost-platform': item.key,
									title: __( 'Open', '4wp-seo-helper' ) + ' ' + item.label,
									'aria-label': __( 'Open', '4wp-seo-helper' ) + ' ' + item.label,
									style: { marginLeft: '4px', textDecoration: 'none', verticalAlign: 'middle' },
								},
								el( 'span', { className: 'dashicons dashicons-external', style: { fontSize: '16px', width: '16px', height: '16px' } } )
							)
							: null
					),
					item.posted
						? el(
							'a',
							{ href: item.url || undefined, target: '_blank', rel: 'noopener noreferrer' },
							__( 'Posted', '4wp-seo-helper' )
						)
						: el( 'span', { style: { color: '#646970' } }, __( 'Not posted', '4wp-seo-helper' ) )
				),
				el(
					'div',
					{ style: { color: '#646970', fontSize: '11px' } },
					crossVariantLabel( item.variant )
				)
			);

		let crosspostingBody;
		if ( ! settings.crosspostingEnabled ) {
			crosspostingBody = [
				el(
					'p',
					{ key: 'off' },
					__( 'Cross posting module is disabled. Enable it in 4wp SEO settings.', '4wp-seo-helper' )
				),
			];
		} else if ( crossLoading && ! cross ) {
			crosspostingBody = [ el( Spinner, { key: 'spin' } ) ];
		} else if ( crossError ) {
			crosspostingBody = [
				el( Notice, { key: 'err', status: 'error', isDismissible: false }, crossError ),
			];
		} else if ( ! cross || ! cross.package ) {
			crosspostingBody = [
				el(
					'p',
					{ key: 'none' },
					__( 'No meta.json package for this post. Import it from 4WP Drive to cross-post.', '4wp-seo-helper' )
				),
			];
		} else {
			const original = cross.original || {};
			crosspostingBody = [
				el(
					'p',
					{ key: 'original' },
					__( 'Original:', '4wp-seo-helper' ) + ' ',
					original.url
						? el( 'a', { href: original.url, target: '_blank', rel: 'noopener noreferrer' }, original.status || '—' )
						: original.status || '—'
				),
				el(
					'p',
					{ key: 'delay' },
					cross.republish_at
						? __( 'Cross-post from:', '4wp-seo-helper' ) +
							' ' +
							new Date( cross.republish_at ).toLocaleString()
						: __( 'Cross-post delay:', '4wp-seo-helper' ) +
							' ' +
							cross.delay_hours +
							' ' +
							__( 'h after publishing', '4wp-seo-helper' )
				),
				cross.sync_error
					? el( Notice, { key: 'sync', status: 'warning', isDismissible: false }, cross.sync_error )
					: null,
				cross.platforms && cross.platforms.length
					? el(
						'ul',
						{ key: 'list', style: { margin: 0, padding: 0, listStyle: 'none' } },
						...cross.platforms.map( crossPlatformRow )
					)
					: el(
						'p',
						{ key: 'empty' },
						__( 'No enabled platforms in this package.', '4wp-seo-helper' ),
						settings.platformsUrl
							? el( 'span', null, ' ', el( 'a', { href: settings.platformsUrl }, __( 'Manage platforms', '4wp-seo-helper' ) ) )
							: null
					),
				el(
					Button,
					{
						key: 'notify',
						variant: 'primary',
						isBusy: notifyBusy,
						disabled: notifyBusy,
						onClick: sendTelegram,
						style: { marginTop: '8px', marginRight: '8px' },
					},
					__( 'Send to Telegram', '4wp-seo-helper' )
				),
				notifyNote
					? el( 'p', { key: 'notify-note', style: { margin: '8px 0 0' } }, notifyNote )
					: null,
				el(
					Button,
					{ key: 'refresh', variant: 'secondary', isBusy: crossLoading, disabled: crossLoading, onClick: () => loadCrossposting( true ), style: { marginTop: '8px' } },
					__( 'Refresh', '4wp-seo-helper' )
				),
			].filter( Boolean );
		}

		const formatRequestedAt = ( ts ) => {
			const n = parseInt( ts, 10 );
			if ( ! n ) {
				return '';
			}
			return new Date( n * 1000 ).toLocaleString( undefined, {
				day: '2-digit',
				month: '2-digit',
				year: 'numeric',
				hour: '2-digit',
				minute: '2-digit',
			} );
		};

		const formatCrawl = ( iso ) => {
			if ( ! iso ) {
				return '';
			}
			const date = new Date( iso );
			if ( Number.isNaN( date.getTime() ) ) {
				return iso;
			}
			return date.toLocaleString( undefined, {
				day: '2-digit',
				month: '2-digit',
				year: 'numeric',
				hour: '2-digit',
				minute: '2-digit',
			} );
		};

		const scoreTone = ( value, noFocus ) => {
			if ( noFocus || value === null || typeof value === 'undefined' || value === '' ) {
				return 'na';
			}
			const n = parseInt( value, 10 );
			if ( n >= 71 ) {
				return 'good';
			}
			if ( n >= 41 ) {
				return 'ok';
			}
			return 'bad';
		};

		const coverageTone = ( coverage, verdict ) => {
			const text = ( coverage || '' ) + ' ' + ( verdict || '' );
			if ( /pass|indexed/i.test( text ) && ! /not indexed|excluded|error/i.test( text ) ) {
				return 'good';
			}
			if ( /fail|error|excluded|not indexed/i.test( text ) ) {
				return 'bad';
			}
			return 'ok';
		};

		const shortenUrl = ( url ) => {
			if ( ! url ) {
				return '';
			}
			try {
				const parsed = new URL( url );
				return parsed.host.replace( /^www\./, '' ) + parsed.pathname.replace( /\/$/, '' );
			} catch ( err ) {
				return url;
			}
		};

		const seo = gscStatus?.seo || null;
		const gscInspect = gscStatus?.inspect || {};
		const yoastPanelBody = [];
		if ( seo && seo.adapter && seo.adapter !== 'none' ) {
			const seoTone = scoreTone( seo.seo, seo.noFocus );
			const readTone = scoreTone( seo.readability, false );
			yoastPanelBody.push(
				el(
					'div',
					{ key: 'scores', className: 'forwp-seo-side__scores' },
					el(
						'div',
						{ className: 'forwp-seo-side__score forwp-seo-side__score--' + seoTone },
						el(
							'span',
							{ className: 'forwp-seo-side__score-value' },
							seo.noFocus || seo.seo === null ? '—' : String( seo.seo )
						),
						el(
							'span',
							{ className: 'forwp-seo-side__score-label' },
							__( 'SEO', '4wp-seo-helper' )
						)
					),
					el(
						'div',
						{ className: 'forwp-seo-side__score forwp-seo-side__score--' + readTone },
						el(
							'span',
							{ className: 'forwp-seo-side__score-value' },
							seo.readability === null ? '—' : String( seo.readability )
						),
						el(
							'span',
							{ className: 'forwp-seo-side__score-label' },
							__( 'Readability', '4wp-seo-helper' )
						)
					)
				)
			);
			if ( seo.label ) {
				yoastPanelBody.push(
					el( 'p', { key: 'label', className: 'forwp-seo-side__meta' }, seo.label )
				);
			}
			yoastPanelBody.push(
				el(
					'p',
					{ key: 'kw', className: 'forwp-seo-side__meta' },
					seo.focusKeyword
						? __( 'Focus keyphrase: ', '4wp-seo-helper' ) + seo.focusKeyword
						: __( 'No focus keyphrase', '4wp-seo-helper' )
				)
			);
		} else if ( gscStatus ) {
			yoastPanelBody.push(
				el(
					'p',
					{ key: 'none', className: 'forwp-seo-side__meta' },
					__( 'Yoast SEO scores are not available for this post.', '4wp-seo-helper' )
				)
			);
		} else if ( gscLoading ) {
			yoastPanelBody.push( el( Spinner, { key: 'spin' } ) );
		}

		const gscPanelBody = [];
		if ( postStatus !== 'publish' ) {
			gscPanelBody.push(
				el(
					'p',
					{ key: 'draft', className: 'forwp-seo-side__hint' },
					__( 'Publish the post before requesting indexing.', '4wp-seo-helper' )
				)
			);
		} else if ( gscLoading && ! gscStatus ) {
			gscPanelBody.push( el( Spinner, { key: 'spin' } ) );
		} else {
			if ( gscStatus?.message ) {
				gscPanelBody.push(
					el(
						'p',
						{
							key: 'msg',
							className: 'forwp-seo-side__hint',
							style: gscStatus.ok ? null : { color: '#b32d2e' },
						},
						gscStatus.message
					)
				);
			}
			if ( gscInspect.coverage || gscInspect.verdict ) {
				gscPanelBody.push(
					el(
						'div',
						{ key: 'pills', className: 'forwp-seo-side__pills' },
						gscInspect.coverage
							? el(
								'span',
								{
									className:
										'forwp-seo-side__pill forwp-seo-side__pill--' +
										coverageTone( gscInspect.coverage, gscInspect.verdict ),
								},
								gscInspect.coverage
							)
							: null,
						gscInspect.verdict
							? el(
								'span',
								{
									className:
										'forwp-seo-side__pill forwp-seo-side__pill--' +
										coverageTone( '', gscInspect.verdict ),
								},
								gscInspect.verdict
							)
							: null
					)
				);
			}
			const rows = [];
			if ( gscInspect.lastCrawl ) {
				rows.push(
					el(
						'div',
						{ key: 'crawl', className: 'forwp-seo-side__row' },
						el( 'dt', null, __( 'Last crawl', '4wp-seo-helper' ) ),
						el( 'dd', null, formatCrawl( gscInspect.lastCrawl ) )
					)
				);
			}
			if ( gscInspect.googleCanonical || gscStatus?.inspectionUrl ) {
				const canon = gscInspect.googleCanonical || gscStatus.inspectionUrl;
				rows.push(
					el(
						'div',
						{ key: 'canon', className: 'forwp-seo-side__row' },
						el( 'dt', null, __( 'Google canonical', '4wp-seo-helper' ) ),
						el(
							'dd',
							null,
							el(
								'a',
								{ href: canon, target: '_blank', rel: 'noopener noreferrer' },
								shortenUrl( canon )
							)
						)
					)
				);
			}
			if ( gscStatus?.requestedAt ) {
				rows.push(
					el(
						'div',
						{ key: 'req', className: 'forwp-seo-side__row' },
						el( 'dt', null, __( 'Last indexing request', '4wp-seo-helper' ) ),
						el( 'dd', null, formatRequestedAt( gscStatus.requestedAt ) )
					)
				);
			}
			if ( rows.length ) {
				gscPanelBody.push( el( 'dl', { key: 'rows', className: 'forwp-seo-side__rows' }, rows ) );
			}
			if ( gscInspect.error ) {
				gscPanelBody.push(
					el( 'p', { key: 'err', className: 'forwp-seo-side__hint', style: { color: '#b32d2e' } }, gscInspect.error )
				);
			}
			gscPanelBody.push(
				el(
					'p',
					{ key: 'hint', className: 'forwp-seo-side__hint' },
					__( 'Opens Google Search Console on this article URL. Click Request indexing there — the API cannot queue that action.', '4wp-seo-helper' )
				)
			);
			gscPanelBody.push(
				el(
					'div',
					{ key: 'actions', className: 'forwp-seo-side__actions' },
					el(
						Button,
						{
							variant: 'primary',
							onClick: requestGscIndex,
							disabled: ! gscStatus?.ready || gscBusy || gscLoading || postStatus !== 'publish',
							isBusy: gscBusy,
						},
						__( 'Request indexing', '4wp-seo-helper' )
					),
					el(
						Button,
						{
							variant: 'secondary',
							onClick: () => loadGscStatus( true ),
							disabled: ! gscStatus?.ready || gscBusy || gscLoading || postStatus !== 'publish',
						},
						__( 'Refresh status', '4wp-seo-helper' )
					)
				)
			);
		}

		const sidebarPanels = [];
		if ( yoastPanelBody.length ) {
			sidebarPanels.push(
				el(
					PanelBody,
					{
						key: 'yoast',
						title: seo?.adapterLabel || __( 'Yoast SEO', '4wp-seo-helper' ),
						initialOpen: true,
					},
					el( 'div', { className: 'forwp-seo-side' }, yoastPanelBody )
				)
			);
		}
		if ( settings.gscEnabled ) {
			sidebarPanels.push(
				el(
					PanelBody,
					{ key: 'gsc', title: __( 'Search Console', '4wp-seo-helper' ), initialOpen: true },
					el( 'div', { className: 'forwp-seo-side' }, gscPanelBody )
				)
			);
		}
		if ( settings.techarticleEnabled ) {
			sidebarPanels.push(
				el(
					PanelBody,
					{ key: 'schema', title: __( 'Schema.org (TechArticle)', '4wp-seo-helper' ), initialOpen: ! settings.gscEnabled },
					statusNotice,
					statusList,
					repairBlock
				),
				el(
					PanelBody,
					{ key: 'json', title: __( 'JSON-LD Preview', '4wp-seo-helper' ), initialOpen: false },
					jsonPreviewBlock
				),
				el(
					PanelBody,
					{ key: 'valid', title: __( 'Validation Tools', '4wp-seo-helper' ), initialOpen: false },
					validationButtons
				),
				el(
					PanelBody,
					{ key: 'llms', title: __( 'LLMS.txt Preview', '4wp-seo-helper' ), initialOpen: false },
					llmsPreviewBlock
				)
			);
		}
		if ( settings.crosspostingPublic ) {
			sidebarPanels.push(
				el(
					PanelBody,
					{ key: 'cross', title: __( 'Cross posting', '4wp-seo-helper' ), initialOpen: false },
					...crosspostingBody
				)
			);
		}

		return el(
			wp.element.Fragment,
			null,
			el(
				PluginSidebarMoreMenuItem,
				{ target: 'forwp-seo-sidebar', icon: chartBar || undefined },
				__( '4WP SEO Helper', '4wp-seo-helper' )
			),
			el(
				PluginSidebar,
				{ name: 'forwp-seo-sidebar', title: __( '4WP SEO Helper', '4wp-seo-helper' ), icon: chartBar || undefined },
				...sidebarPanels
			)
		);
	};

	if ( ! PluginSidebar || ! PluginSidebarMoreMenuItem ) {
		console.warn( '[forwp-seo] sidebar components missing' );
		return;
	}

	registerPlugin( 'forwp-seo-sidebar', {
		render: SeoSidebar,
	} );
	console.log( '[forwp-seo] seo-sidebar plugin registered' );
} )( window.wp, window.forwpSeoSidebar );

