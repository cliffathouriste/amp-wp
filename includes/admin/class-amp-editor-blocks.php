<?php
/**
 * AMP Editor Blocks extending.
 *
 * @package AMP
 * @since 1.0
 */

/**
 * Class AMP_Editor_Blocks
 */
class AMP_Editor_Blocks {

	/**
	 * List of AMP scripts that need to be printed when AMP components are used in non-AMP document context ("dirty AMP").
	 *
	 * @var array
	 */
	public $content_required_amp_scripts = array();

	/**
	 * AMP components that have blocks.
	 *
	 * @var array
	 */
	public $amp_blocks = array(
		'amp-mathml',
		'amp-timeago',
		'amp-o2-player',
		'amp-ooyala-player',
		'amp-reach-player',
		'amp-springboard-player',
		'amp-jwplayer',
		'amp-brid-player',
		'amp-ima-video',
		'amp-fit-text',
	);

	/**
	 * Init.
	 */
	public function init() {
		if ( function_exists( 'gutenberg_init' ) ) {
			add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
			add_filter( 'wp_kses_allowed_html', array( $this, 'whitelist_block_atts_in_wp_kses_allowed_html' ), 10, 2 );
		}
	}

	/**
	 * Whitelist elements and attributes used for AMP.
	 *
	 * This prevents AMP markup from being deleted in
	 *
	 * @param array  $tags    Array of allowed post tags.
	 * @param string $context Context.
	 * @return mixed Modified array.
	 */
	public function whitelist_block_atts_in_wp_kses_allowed_html( $tags, $context ) {
		if ( 'post' !== $context ) {
			return $tags;
		}

		foreach ( $tags as &$tag ) {
			$tag['data-amp-layout']              = true;
			$tag['data-amp-noloading']           = true;
			$tag['data-amp-lightbox']            = true;
			$tag['data-close-button-aria-label'] = true;
		}

		foreach ( $this->amp_blocks as $amp_block ) {
			if ( ! isset( $tags[ $amp_block ] ) ) {
				$tags[ $amp_block ] = array();
			}

			// @todo The global attributes included here should be matched up with what is actually used by each block.
			$tags[ $amp_block ] = array_merge(
				array_fill_keys(
					array(
						'layout',
						'width',
						'height',
						'class',
					),
					true
				),
				$tags[ $amp_block ]
			);

			$amp_tag_specs = AMP_Allowed_Tags_Generated::get_allowed_tag( $amp_block );
			foreach ( $amp_tag_specs as $amp_tag_spec ) {
				if ( ! isset( $amp_tag_spec[ AMP_Rule_Spec::ATTR_SPEC_LIST ] ) ) {
					continue;
				}
				$tags[ $amp_block ] = array_merge(
					$tags[ $amp_block ],
					array_fill_keys( array_keys( $amp_tag_spec[ AMP_Rule_Spec::ATTR_SPEC_LIST ] ), true )
				);
			}
		}

		return $tags;
	}

	/**
	 * Enqueue filters for extending core blocks attributes.
	 * Has to be loaded before registering the blocks in registerCoreBlocks.
	 */
	public function enqueue_block_editor_assets() {

		// Enqueue script and style for AMP-specific blocks.
		if ( amp_is_canonical() && AMP_Story_Post_Type::POST_TYPE_SLUG !== get_current_screen()->post_type ) {
			wp_enqueue_style(
				'amp-editor-blocks-style',
				amp_get_asset_url( 'css/amp-editor-blocks.css' ),
				array(),
				AMP__VERSION
			);

			wp_enqueue_script(
				'amp-editor-blocks-build',
				amp_get_asset_url( 'js/amp-blocks-compiled.js' ),
				array( 'wp-blocks', 'lodash', 'wp-i18n', 'wp-element', 'wp-components' ),
				AMP__VERSION
			);

			wp_add_inline_script(
				'amp-editor-blocks-build',
				'wp.i18n.setLocaleData( ' . wp_json_encode( gutenberg_get_jed_locale_data( 'amp' ) ) . ', "amp" );',
				'before'
			);
		}

		wp_enqueue_script(
			'amp-editor-blocks',
			amp_get_asset_url( 'js/amp-editor-blocks.js' ),
			array( 'underscore', 'wp-hooks', 'wp-i18n', 'wp-components' ),
			AMP__VERSION,
			true
		);

		wp_add_inline_script(
			'amp-editor-blocks',
			sprintf( 'ampEditorBlocks.boot( %s );', wp_json_encode( array(
				'hasThemeSupport' => current_theme_supports( 'amp' ),
			) ) )
		);
	}
}
