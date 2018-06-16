<?php
/**
 * Class AMP_Story_Post_Type
 *
 * @package AMP
 */

/**
 * Class AMP_Story_Post_Type
 */
class AMP_Story_Post_Type {

	/**
	 * The slug of the post type to store URLs that have AMP errors.
	 *
	 * @var string
	 */
	const POST_TYPE_SLUG = 'amp_story';

	/**
	 * Registers the post type to store URLs with validation errors.
	 *
	 * @return void
	 */
	public static function register() {
		register_post_type(
			self::POST_TYPE_SLUG,
			array(
				'labels'       => array(
					'name'               => _x( 'AMP Stories', 'post type general name', 'amp' ),
					'singular_name'      => _x( 'AMP Story', 'post type singular name', 'amp' ),
					'menu_name'          => _x( 'AMP Stories', 'admin menu', 'amp' ),
					'name_admin_bar'     => _x( 'AMP Story', 'add new on admin bar', 'amp' ),
					'add_new'            => _x( 'Add New', 'amp_story', 'amp' ),
					'add_new_item'       => __( 'Add New AMP Story', 'amp' ),
					'new_item'           => __( 'New AMP Story', 'amp' ),
					'edit_item'          => __( 'Edit AMP Story', 'amp' ),
					'view_item'          => __( 'View AMP Story', 'amp' ),
					'all_items'          => __( 'All AMP Stories', 'amp' ),
					'not_found'          => __( 'No AMP Stories found.', 'amp' ),
					'not_found_in_trash' => __( 'No AMP Stories found in Trash.', 'amp' ),
				),
				'supports'     => array(
					'title', // Used for amp-story[title[.
					'editor',
					'thumbnail', // Used for poster images.
					'amp',
					'revisions', // Without this, the REST API will return 404 for an autosave request.
				),
				'rewrite'      => array(
					'slug' => 'stories',
				),
				'public'       => true,
				'show_ui'      => true,
				'show_in_rest' => true,
				'template'     => array(
					array(
						'amp/amp-story-page',
						array(),
						array(
							array(
								'amp/amp-story-grid-layer',
								array(),
								array(
									array(
										'core/paragraph',
										array(
											'placeholder' => __( 'This is the story\'s first page\'s first layer.', 'amp' ),
										),
									),
								),
							),
						),
					),
				),
			)
		);

		add_filter( 'wp_kses_allowed_html', array( __CLASS__, 'filter_kses_allowed_html' ), 10, 2 );

		// Forcibly sanitize all validation errors.
		add_action(
			'wp', function() {
				if ( is_singular( AMP_Story_Post_Type::POST_TYPE_SLUG ) ) {
					add_filter( 'amp_validation_error_sanitized', '__return_true' );
				}
			}
		);

		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_block_editor_assets' ) );

		// Used for amp-story[publisher-logo-src]: The publisher's logo in square format (1x1 aspect ratio). This will be supplied by the custom logo or else site icon.
		add_image_size( 'amp-publisher-logo', 100, 100, true );

		// Used for amp-story[poster-portrait-src]: The story poster in portrait format (3x4 aspect ratio).
		add_image_size( 'amp-story-poster-portrait', 300, 400, true );

		// Used for amp-story[poster-square-src]: The story poster in square format (1x1 aspect ratio).
		add_image_size( 'amp-story-poster-square', 100, 100, true );

		// Used for amp-story[poster-landscape-src]: The story poster in square format (1x1 aspect ratio).
		add_image_size( 'amp-story-poster-landscape', 400, 300, true );

		add_filter( 'template_include', array( __CLASS__, 'filter_template_include' ) );
	}

	/**
	 * Filter the allowed tags for Kses to allow for amp-story children.
	 *
	 * @param array $allowed_tags Allowed tags.
	 * @return array Allowed tags.
	 */
	public static function filter_kses_allowed_html( $allowed_tags ) {
		$story_components = array(
			'amp-story-page',
			'amp-story-grid-layer',
		);
		foreach ( $story_components as $story_component ) {
			$attributes = array_fill_keys( array_keys( AMP_Allowed_Tags_Generated::get_allowed_attributes() ), true );
			$rule_specs = AMP_Allowed_Tags_Generated::get_allowed_tag( $story_component );
			foreach ( $rule_specs as $rule_spec ) {
				$attributes = array_merge( $attributes, array_fill_keys( array_keys( $rule_spec[ AMP_Rule_Spec::ATTR_SPEC_LIST ] ), true ) );
			}
			$allowed_tags[ $story_component ] = $attributes;
		}

		// @todo This perhaps should not be allowed if user does not have capability.
		foreach ( $allowed_tags as &$allowed_tag ) {
			$allowed_tag['grid-area'] = true;
		}

		return $allowed_tags;
	}

	/**
	 * Enqueue block editor assets.
	 */
	public static function enqueue_block_editor_assets() {
		if ( self::POST_TYPE_SLUG !== get_current_screen()->post_type ) {
			return;
		}

		wp_enqueue_style(
			'amp-editor-story-blocks-style',
			amp_get_asset_url( 'css/amp-editor-story-blocks.css' ),
			array(),
			AMP__VERSION
		);

		wp_enqueue_script(
			'amp-editor-story-blocks-build',
			amp_get_asset_url( 'js/amp-story-blocks-compiled.js' ),
			array( 'wp-blocks', 'lodash', 'wp-i18n', 'wp-element', 'wp-components' ),
			AMP__VERSION
		);

		wp_add_inline_script(
			'amp-editor-story-blocks-build',
			'wp.i18n.setLocaleData( ' . wp_json_encode( gutenberg_get_jed_locale_data( 'amp' ) ) . ', "amp" );',
			'before'
		);
	}

	/**
	 * Set template for amp_story post type.
	 *
	 * @param string $template Template.
	 * @return string Template.
	 */
	public static function filter_template_include( $template ) {
		if ( is_singular( self::POST_TYPE_SLUG ) ) {
			$template = AMP__DIR__ . '/includes/templates/single-amp_story.php';
		}
		return $template;
	}
}
