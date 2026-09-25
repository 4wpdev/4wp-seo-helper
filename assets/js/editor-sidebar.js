/* global wp */

( function ( wp ) {
	const { registerPlugin } = wp.plugins;
	const { PluginDocumentSettingPanel } = wp.editPost;
	const { useSelect } = wp.data;
	const { __ } = wp.i18n;
	const { Fragment } = wp.element;

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

	const StatusPanel = () => {
		const { hasCode, hasSteps } = useSelect( ( select ) => {
			const blocks = select( 'core/block-editor' ).getBlocks();
			const flat = flattenBlocks( blocks );
			let foundCode = false;
			let foundSteps = false;

			flat.forEach( ( block ) => {
				if ( block.name === 'core/code' ) {
					foundCode = true;
				}
				if (
					block.name === 'forwp-seo/techarticle-steps' ||
					block.name === 'forwp-seo/techarticle-step'
				) {
					foundSteps = true;
				}
			} );

			return {
				hasCode: foundCode,
				hasSteps: foundSteps,
			};
		}, [] );

		return (
			<PluginDocumentSettingPanel
				name="forwp-seo-status"
				title={ __( '4wp SEO Status', '4wp-seo-helper' ) }
				className="forwp-seo-status-panel"
			>
				<ul style={ { margin: 0, paddingLeft: '18px' } }>
					<li>
						{ hasSteps ? '✅ ' : '⚠️ ' }
						{ __( 'TechArticle Steps block (required)', '4wp-seo-helper' ) }
					</li>
					<li>
						{ hasCode ? '✅ ' : 'ℹ️ ' }
						{ __( 'Code sample — WordPress Code block /code (optional)', '4wp-seo-helper' ) }
					</li>
				</ul>
				<p style={ { margin: '8px 0 0', fontSize: '12px', color: '#646970' } }>
					{ __(
						'Code is not TechArticle Context. Insert the core Code block (usually inside Steps) to add SoftwareSourceCode to JSON-LD.',
						'4wp-seo-helper'
					) }
				</p>
			</PluginDocumentSettingPanel>
		);
	};

	registerPlugin( 'forwp-seo-status-panel', {
		render: () => (
			<Fragment>
				<StatusPanel />
			</Fragment>
		),
	} );
} )( window.wp );


