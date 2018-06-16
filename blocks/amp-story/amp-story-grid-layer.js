const { __ } = wp.i18n;
const {
	registerBlockType
} = wp.blocks;
const {
	InspectorControls,
	InnerBlocks
} = wp.editor;
const {
	SelectControl
} = wp.components;

/**
 * Register block.
 */
export default registerBlockType(
	'amp/amp-story-grid-layer',
	{
		title: __( 'AMP Story Grid Layer' ),
		category: 'layout',
		icon: 'grid-view',

		attributes: {
			template: {
				type: 'string',
				source: 'attribute',
				selector: 'amp-story-grid-layer',
				attribute: 'template',
				default: 'fill'
			}
		},

		/*
		 * <amp-story-grid-layer>:
		 *   mandatory_ancestor: "AMP-STORY-PAGE"
		 *   descendant_tag_list: "amp-story-grid-layer-allowed-descendants"
		 *
		 * https://github.com/ampproject/amphtml/blob/87fe1d02f902be97b596b36ec3421592c83d241e/extensions/amp-story/validator-amp-story.protoascii#L172-L188
		 */

		edit( props ) {
			const { isSelected, setAttributes } = props;
			return [
				isSelected && (
					<InspectorControls key='inspector'>
						<SelectControl
							key="template"
							label={ __( 'Template', 'amp' ) }
							value={ props.attributes.template }
							options={ [
								{
									value: 'fill',
									label: __( 'Fill', 'amp' )
								},
								{
									value: 'horizontal',
									label: __( 'Horizontal', 'amp' )
								},
								{
									value: 'thirds',
									label: __( 'Thirds', 'amp' )
								},
								{
									value: 'vertical',
									label: __( 'Vertical', 'amp' )
								}
							] }
							onChange={ value => ( setAttributes( { template: value } ) ) }
						/>
					</InspectorControls>
				),
				<InnerBlocks key='contents' />
			];
		},

		save( { attributes } ) {
			return (
				<amp-story-grid-layer template={ attributes.template }>
					<InnerBlocks.Content />
				</amp-story-grid-layer>
			);
		}
	}
);
