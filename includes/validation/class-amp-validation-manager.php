<?php
/**
 * Class AMP_Validation_Manager
 *
 * @package AMP
 */

/**
 * Class AMP_Validation_Manager
 *
 * @since 0.7
 */
class AMP_Validation_Manager {

	/**
	 * Query var that triggers validation.
	 *
	 * @var string
	 */
	const VALIDATE_QUERY_VAR = 'amp_validate';

	/**
	 * Query var that enables validation debug mode, to disable removal of invalid elements/attributes.
	 *
	 * @var string
	 */
	const DEBUG_QUERY_VAR = 'amp_debug';

	/**
	 * Query var for cache-busting.
	 *
	 * @var string
	 */
	const CACHE_BUST_QUERY_VAR = 'amp_cache_bust';

	/**
	 * Transient key to store validation errors when activating a plugin.
	 *
	 * @var string
	 */
	const PLUGIN_ACTIVATION_VALIDATION_ERRORS_TRANSIENT_KEY = 'amp_plugin_activation_validation_errors';

	/**
	 * The name of the REST API field with the AMP validation results.
	 *
	 * @var string
	 */
	const VALIDITY_REST_FIELD_NAME = 'amp_validity';

	/**
	 * The errors encountered when validating.
	 *
	 * @var array[][] {
	 *     @type array  $error     Error code.
	 *     @type bool   $sanitized Whether sanitized.
	 *     @type string $slug      Hash of the error.
	 * }
	 */
	public static $validation_results = array();

	/**
	 * Sources that enqueue each script.
	 *
	 * @var array
	 */
	public static $enqueued_script_sources = array();

	/**
	 * Sources that enqueue each style.
	 *
	 * @var array
	 */
	public static $enqueued_style_sources = array();

	/**
	 * Post IDs for posts that have been updated which need to be re-validated.
	 *
	 * Keys are post IDs and values are whether the post has been re-validated.
	 *
	 * @var bool[]
	 */
	public static $posts_pending_frontend_validation = array();

	/**
	 * Current sources gathered for a given hook currently being run.
	 *
	 * @see AMP_Validation_Manager::wrap_hook_callbacks()
	 * @see AMP_Validation_Manager::decorate_filter_source()
	 * @var array[]
	 */
	protected static $current_hook_source_stack = array();

	/**
	 * Index for where block appears in a post's content.
	 *
	 * @var int
	 */
	protected static $block_content_index = 0;

	/**
	 * Hook source stack.
	 *
	 * This has to be public for the sake of PHP 5.3.
	 *
	 * @since 0.7
	 * @var array[]
	 */
	public static $hook_source_stack = array();

	/**
	 * Whether validation error sources should be located.
	 *
	 * @todo Rename to should_locate_sources
	 * @var bool
	 */
	public static $locate_sources = false;

	/**
	 * Whether in debug mode.
	 *
	 * This means that sanitization will not be applied for validation errors, and any source comments will not be removed.
	 *
	 * @var bool
	 */
	public static $debug = false;

	/**
	 * Add the actions.
	 *
	 * @param array $args {
	 *     Args.
	 *
	 *     @type bool $debug Whether validation should be done in debug mode, where validation errors are not sanitized and source comments are not removed.
	 * }
	 * @return void
	 */
	public static function init( $args = array() ) {
		$args = array_merge(
			array(
				'debug'          => false,
				'locate_sources' => false,
			),
			$args
		);

		self::$debug          = $args['debug'];
		self::$locate_sources = $args['locate_sources'];

		add_action( 'init', array( 'AMP_Invalid_URL_Post_Type', 'register' ) );
		add_action( 'init', array( 'AMP_Validation_Error_Taxonomy', 'register' ) );

		add_action( 'save_post', array( __CLASS__, 'handle_save_post_prompting_validation' ), 10, 2 );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_block_validation' ) );

		add_action( 'edit_form_top', array( __CLASS__, 'print_edit_form_validation_status' ), 10, 2 );
		add_action( 'all_admin_notices', array( __CLASS__, 'plugin_notice' ) );

		add_action( 'rest_api_init', array( __CLASS__, 'add_rest_api_fields' ) );

		// Actions and filters involved in validation.
		add_action( 'activate_plugin', function() {
			if ( ! has_action( 'shutdown', array( __CLASS__, 'validate_after_plugin_activation' ) ) ) {
				add_action( 'shutdown', array( __CLASS__, 'validate_after_plugin_activation' ) ); // Shutdown so all plugins will have been activated.
			}
		} );

		if ( self::$locate_sources ) {
			self::add_validation_hooks();
		}
	}

	/**
	 * Add hooks for doing validation during preprocessing/sanitizing.
	 *
	 * @todo Rename to add_validation_error_source_tracing().
	 */
	public static function add_validation_hooks() {
		add_action( 'wp', array( __CLASS__, 'wrap_widget_callbacks' ) );

		add_action( 'all', array( __CLASS__, 'wrap_hook_callbacks' ) );
		$wrapped_filters = array( 'the_content', 'the_excerpt' );
		foreach ( $wrapped_filters as $wrapped_filter ) {
			add_filter( $wrapped_filter, array( __CLASS__, 'decorate_filter_source' ), PHP_INT_MAX );
		}

		add_filter( 'do_shortcode_tag', array( __CLASS__, 'decorate_shortcode_source' ), -1, 2 );

		$do_blocks_priority  = has_filter( 'the_content', 'do_blocks' );
		$is_gutenberg_active = (
			false !== $do_blocks_priority
			&&
			class_exists( 'WP_Block_Type_Registry' )
		);
		if ( $is_gutenberg_active ) {
			add_filter( 'the_content', array( __CLASS__, 'add_block_source_comments' ), $do_blocks_priority - 1 );
		}
	}

	/**
	 * Handle save_post action to queue re-validation of the post on the frontend.
	 *
	 * @see AMP_Validation_Manager::validate_queued_posts_on_frontend()
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public static function handle_save_post_prompting_validation( $post_id, $post ) {
		$should_validate_post = (
			is_post_type_viewable( $post->post_type )
			&&
			! wp_is_post_autosave( $post )
			&&
			! wp_is_post_revision( $post )
			&&
			! isset( self::$posts_pending_frontend_validation[ $post_id ] )
		);
		if ( $should_validate_post ) {
			self::$posts_pending_frontend_validation[ $post_id ] = true;

			// The reason for shutdown is to ensure that all postmeta changes have been saved, including whether AMP is enabled.
			if ( ! has_action( 'shutdown', array( __CLASS__, 'validate_queued_posts_on_frontend' ) ) ) {
				add_action( 'shutdown', array( __CLASS__, 'validate_queued_posts_on_frontend' ) );
			}
		}
	}

	/**
	 * Validate the posts pending frontend validation.
	 *
	 * @see AMP_Validation_Manager::handle_save_post_prompting_validation()
	 *
	 * @return array Mapping of post ID to the result of validating or storing the validation result.
	 */
	public static function validate_queued_posts_on_frontend() {
		$posts = array_filter(
			array_map( 'get_post', array_keys( array_filter( self::$posts_pending_frontend_validation ) ) ),
			function( $post ) {
				return $post && post_supports_amp( $post ) && 'trash' !== $post->post_status;
			}
		);

		$validation_posts = array();

		// @todo Only validate the first and then queue the rest in WP Cron?
		foreach ( $posts as $post ) {
			$url = amp_get_permalink( $post->ID );
			if ( ! $url ) {
				$validation_posts[ $post->ID ] = new WP_Error( 'no_amp_permalink' );
				continue;
			}

			// Prevent re-validating.
			self::$posts_pending_frontend_validation[ $post->ID ] = false;

			$validation_errors = self::validate_url( $url );
			if ( is_wp_error( $validation_errors ) ) {
				$validation_posts[ $post->ID ] = $validation_errors;
			} else {
				$validation_posts[ $post->ID ] = AMP_Invalid_URL_Post_Type::store_validation_errors( $validation_errors, $url );
			}
		}

		return $validation_posts;
	}

	/**
	 * Adds fields to the REST API responses, in order to display validation errors.
	 *
	 * @return void
	 */
	public static function add_rest_api_fields() {
		if ( amp_is_canonical() ) {
			$object_types = get_post_types_by_support( 'editor' );
		} else {
			$object_types = array_intersect(
				get_post_types_by_support( 'amp' ),
				get_post_types( array(
					'show_in_rest' => true,
				) )
			);
		}

		register_rest_field(
			$object_types,
			self::VALIDITY_REST_FIELD_NAME,
			array(
				'get_callback' => array( __CLASS__, 'get_amp_validity_rest_field' ),
				'schema'       => array(
					'description' => __( 'AMP validity status', 'amp' ),
					'type'        => 'object',
				),
			)
		);
	}

	/**
	 * Adds a field to the REST API responses to display the validation status.
	 *
	 * First, get existing errors for the post.
	 * If there are none, validate the post and return any errors.
	 *
	 * @param array           $post_data  Data for the post.
	 * @param string          $field_name The name of the field to add.
	 * @param WP_REST_Request $request    The name of the field to add.
	 * @return array|null $validation_data Validation data if it's available, or null.
	 */
	public static function get_amp_validity_rest_field( $post_data, $field_name, $request ) {
		unset( $field_name );
		if ( ! current_user_can( 'edit_post', $post_data['id'] ) ) {
			return null;
		}
		$post = get_post( $post_data['id'] );

		$validation_status_post = null;
		if ( in_array( $request->get_method(), array( 'PUT', 'POST' ), true ) ) {
			if ( ! isset( self::$posts_pending_frontend_validation[ $post->ID ] ) ) {
				self::$posts_pending_frontend_validation[ $post->ID ] = true;
			}
			$results = self::validate_queued_posts_on_frontend();
			if ( isset( $results[ $post->ID ] ) && is_int( $results[ $post->ID ] ) ) {
				$validation_status_post = get_post( $results[ $post->ID ] );
			}
		}

		if ( empty( $validation_status_post ) ) {
			// @todo Consider process_markup() if not post type is not viewable and if post type supports editor.
			$validation_status_post = AMP_Invalid_URL_Post_Type::get_invalid_url_post( amp_get_permalink( $post->ID ) );
		}

		$field = array(
			'errors'      => array(),
			'review_link' => null,
			'debug_link'  => self::get_debug_url( amp_get_permalink( $post_data['id'] ) ),
		);

		if ( $validation_status_post ) {
			$field = array_merge(
				$field,
				array(
					'review_link' => get_edit_post_link( $validation_status_post->ID, 'raw' ),
					'errors'      => wp_list_pluck(
						AMP_Invalid_URL_Post_Type::get_invalid_url_validation_errors( $validation_status_post, array( 'ignore_accepted' => true ) ),
						'data'
					),
				)
			);
		}

		return $field;
	}

	/**
	 * Processes markup, to determine AMP validity.
	 *
	 * Passes $markup through the AMP sanitizers.
	 * Also passes a 'validation_error_callback' to keep track of stripped attributes and nodes.
	 *
	 * @todo Eliminate since unused.
	 *
	 * @param string $markup The markup to process.
	 * @return string Sanitized markup.
	 */
	public static function process_markup( $markup ) {
		AMP_Theme_Support::register_content_embed_handlers();

		/** This filter is documented in wp-includes/post-template.php */
		$markup = apply_filters( 'the_content', $markup );
		$args   = array(
			'content_max_width'         => ! empty( $content_width ) ? $content_width : AMP_Post_Template::CONTENT_MAX_WIDTH,
			'validation_error_callback' => 'AMP_Validation_Manager::add_validation_error',
		);

		$results = AMP_Content_Sanitizer::sanitize( $markup, amp_get_content_sanitizers(), $args );
		return $results[0];
	}

	/**
	 * Whether the user has the required capability.
	 *
	 * Checks for permissions before validating.
	 *
	 * @return boolean $has_cap Whether the current user has the capability.
	 */
	public static function has_cap() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Add validation error.
	 *
	 * @param array $error {
	 *     Data.
	 *
	 *     @type string $code Error code.
	 *     @type DOMElement|DOMNode $node The removed node.
	 * }
	 * @param array $data Additional data, including the node.
	 *
	 * @return bool Whether the validation error should result in sanitization.
	 */
	public static function add_validation_error( array $error, array $data = array() ) {
		$node    = null;
		$matches = null;
		$sources = null;

		if ( isset( $data['node'] ) && $data['node'] instanceof DOMNode ) {
			$node = $data['node'];
		}

		if ( self::$locate_sources ) {
			if ( ! empty( $error['sources'] ) ) {
				$sources = $error['sources'];
			} elseif ( $node ) {
				$sources = self::locate_sources( $node );
			}
		}
		unset( $error['sources'] );

		if ( ! isset( $error['code'] ) ) {
			$error['code'] = 'unknown';
		}

		/**
		 * Filters the validation error array.
		 *
		 * This allows plugins to add amend additional properties which can help with
		 * more accurately identifying a validation error beyond the name of the parent
		 * node and the element's attributes. The $sources are also omitted because
		 * these are only available during an explicit validation request and so they
		 * are not suitable for plugins to vary sanitization by. If looking to force a
		 * validation error to be ignored, use the 'amp_validation_error_sanitized'
		 * filter instead of attempting to return an empty value with this filter (as
		 * that is not supported).
		 *
		 * @since 1.0
		 *
		 * @param array $error Validation error to be printed.
		 * @param array $context   {
		 *     Context data for validation error sanitization.
		 *
		 *     @type DOMNode $node Node for which the validation error is being reported. May be null.
		 * }
		 */
		$error = apply_filters( 'amp_validation_error', $error, compact( 'node' ) );

		// @todo Move this into a helper function.
		ksort( $error );
		$slug = md5( wp_json_encode( $error ) );
		$term = get_term_by( 'slug', $slug, AMP_Validation_Error_Taxonomy::TAXONOMY_SLUG );

		if ( ! self::$debug && ! empty( $term ) && AMP_Validation_Error_Taxonomy::VALIDATION_ERROR_ACCEPTED_STATUS === $term->term_group ) {
			$sanitized = true;
		} else {
			$sanitized = false;
		}

		/**
		 * Filters whether the validation error should be sanitized.
		 *
		 * Note that the $node is not passed here to ensure that the filter can be
		 * applied on validation errors that have been stored. Likewise, the $sources
		 * are also omitted because these are only available during an explicit
		 * validation request and so they are not suitable for plugins to vary
		 * sanitization by. Note that returning false this indicates that the
		 * validation error should not be considered a blocker to render AMP.
		 *
		 * @since 1.0
		 *
		 * @param bool  $sanitized Whether sanitized.
		 * @param array $context   {
		 *     Context data for validation error sanitization.
		 *
		 *     @type array $error Validation error being sanitized.
		 * }
		 */
		$sanitized = apply_filters( 'amp_validation_error_sanitized', $sanitized, compact( 'error' ) );

		// Add sources back into the $error for referencing later. @todo It may be cleaner to store sources separately to avoid having to re-remove later during storage.
		$error = array_merge( $error, compact( 'sources' ) );

		self::$validation_results[] = compact( 'error', 'sanitized' );
		return $sanitized;
	}

	/**
	 * Reset the stored removed nodes and attributes.
	 *
	 * After testing if the markup is valid,
	 * these static values will remain.
	 * So reset them in case another test is needed.
	 *
	 * @return void
	 */
	public static function reset_validation_results() {
		self::$validation_results      = array();
		self::$enqueued_style_sources  = array();
		self::$enqueued_script_sources = array();
	}

	/**
	 * Checks the AMP validity of the post content.
	 *
	 * If it's not valid AMP, it displays an error message above the 'Classic' editor.
	 *
	 * @param WP_Post $post The updated post.
	 * @return void
	 */
	public static function print_edit_form_validation_status( $post ) {
		if ( ! post_supports_amp( $post ) || ! self::has_cap() ) {
			return;
		}

		// Skip if the post type is not viewable on the frontend, since we need a permalink to validate.
		if ( ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}

		$amp_url          = amp_get_permalink( $post->ID );
		$invalid_url_post = AMP_Invalid_URL_Post_Type::get_invalid_url_post( $amp_url );
		if ( ! $invalid_url_post ) {
			return;
		}

		$validation_errors = wp_list_pluck(
			AMP_Invalid_URL_Post_Type::get_invalid_url_validation_errors( $invalid_url_post, array( 'ignore_accepted' => true ) ),
			'data'
		);

		// No validation errors so abort.
		if ( empty( $validation_errors ) ) {
			return;
		}

		echo '<div class="notice notice-warning">';
		echo '<p>';
		esc_html_e( 'There is content which fails AMP validation. Non-accepted validation errors prevent AMP from being served.', 'amp' );
		echo sprintf(
			' <a href="%s" target="_blank">%s</a>',
			esc_url( get_edit_post_link( $invalid_url_post ) ),
			esc_html__( 'Review issues', 'amp' )
		);
		echo '</p>';

		$results      = AMP_Validation_Error_Taxonomy::summarize_validation_errors( array_unique( $validation_errors, SORT_REGULAR ) );
		$removed_sets = array();
		if ( ! empty( $results[ AMP_Validation_Error_Taxonomy::REMOVED_ELEMENTS ] ) && is_array( $results[ AMP_Validation_Error_Taxonomy::REMOVED_ELEMENTS ] ) ) {
			$removed_sets[] = array(
				'label' => __( 'Invalid elements:', 'amp' ),
				'names' => array_map( 'sanitize_key', $results[ AMP_Validation_Error_Taxonomy::REMOVED_ELEMENTS ] ),
			);
		}
		if ( ! empty( $results[ AMP_Validation_Error_Taxonomy::REMOVED_ATTRIBUTES ] ) && is_array( $results[ AMP_Validation_Error_Taxonomy::REMOVED_ATTRIBUTES ] ) ) {
			$removed_sets[] = array(
				'label' => __( 'Invalid attributes:', 'amp' ),
				'names' => array_map( 'sanitize_key', $results[ AMP_Validation_Error_Taxonomy::REMOVED_ATTRIBUTES ] ),
			);
		}
		// @todo There are other kinds of errors other than REMOVED_ELEMENTS and REMOVED_ATTRIBUTES.
		foreach ( $removed_sets as $removed_set ) {
			printf( '<p>%s ', esc_html( $removed_set['label'] ) );
			self::output_removed_set( $removed_set['names'] );
			echo '</p>';
		}

		echo '</div>';
	}

	/**
	 * Get source start comment.
	 *
	 * @param array $source   Source data.
	 * @param bool  $is_start Whether the comment is the start or end.
	 * @return string HTML Comment.
	 */
	public static function get_source_comment( array $source, $is_start = true ) {
		unset( $source['reflection'] );
		return sprintf(
			'<!--%samp-source-stack %s-->',
			$is_start ? '' : '/',
			str_replace( '--', '', wp_json_encode( $source ) )
		);
	}

	/**
	 * Parse source comment.
	 *
	 * @param DOMComment $comment Comment.
	 * @return array|null Parsed source or null if not a source comment.
	 */
	public static function parse_source_comment( DOMComment $comment ) {
		if ( ! preg_match( '#^\s*(?P<closing>/)?amp-source-stack\s+(?P<args>{.+})\s*$#s', $comment->nodeValue, $matches ) ) {
			return null;
		}

		$source  = json_decode( $matches['args'], true );
		$closing = ! empty( $matches['closing'] );

		return compact( 'source', 'closing' );
	}

	/**
	 * Walk back tree to find the open sources.
	 *
	 * @param DOMNode $node Node to look for.
	 * @return array[][] {
	 *       The data of the removed sources (theme, plugin, or mu-plugin).
	 *
	 *       @type string $name The name of the source.
	 *       @type string $type The type of the source.
	 * }
	 */
	public static function locate_sources( DOMNode $node ) {
		$xpath    = new DOMXPath( $node->ownerDocument );
		$comments = $xpath->query( 'preceding::comment()[ starts-with( ., "amp-source-stack" ) or starts-with( ., "/amp-source-stack" ) ]', $node );
		$sources  = array();
		$matches  = array();

		foreach ( $comments as $comment ) {
			$parsed_comment = self::parse_source_comment( $comment );
			if ( ! $parsed_comment ) {
				continue;
			}
			if ( $parsed_comment['closing'] ) {
				array_pop( $sources );
			} else {
				$sources[] = $parsed_comment['source'];
			}
		}

		$is_enqueued_link = (
			$node instanceof DOMElement
			&&
			'link' === $node->nodeName
			&&
			preg_match( '/(?P<handle>.+)-css$/', (string) $node->getAttribute( 'id' ), $matches )
			&&
			isset( self::$enqueued_style_sources[ $matches['handle'] ] )
		);
		if ( $is_enqueued_link ) {
			$sources = array_merge(
				self::$enqueued_style_sources[ $matches['handle'] ],
				$sources
			);
		}

		/**
		 * Script dependency.
		 *
		 * @var _WP_Dependency $script_dependency
		 */
		if ( $node instanceof DOMElement && 'script' === $node->nodeName ) {
			$enqueued_script_handles = array_intersect( wp_scripts()->done, array_keys( self::$enqueued_script_sources ) );

			if ( $node->hasAttribute( 'src' ) ) {

				// External script.
				$src = $node->getAttribute( 'src' );
				foreach ( $enqueued_script_handles as $enqueued_script_handle ) {
					$script_dependency  = wp_scripts()->registered[ $enqueued_script_handle ];
					$is_matching_script = (
						$script_dependency
						&&
						$script_dependency->src
						&&
						// Script attribute is haystack because includes protocol and may include query args (like ver).
						false !== strpos( $src, preg_replace( '#^https?:(?=//)#', '', $script_dependency->src ) )
					);
					if ( $is_matching_script ) {
						$sources = array_merge(
							self::$enqueued_script_sources[ $enqueued_script_handle ],
							$sources
						);
						break;
					}
				}
			} elseif ( $node->firstChild ) {

				// Inline script.
				$text = $node->textContent;
				foreach ( $enqueued_script_handles as $enqueued_script_handle ) {
					$inline_scripts = array_filter( array_merge(
						(array) wp_scripts()->get_data( $enqueued_script_handle, 'data' ),
						(array) wp_scripts()->get_data( $enqueued_script_handle, 'before' ),
						(array) wp_scripts()->get_data( $enqueued_script_handle, 'after' )
					) );
					foreach ( $inline_scripts as $inline_script ) {
						/*
						 * Check to see if the inline script is inside (or the same) as the script in the document.
						 * Note that WordPress takes the registered inline script and will output it with newlines
						 * padding it, and sometimes with the script wrapped by CDATA blocks.
						 */
						if ( false !== strpos( $text, trim( $inline_script ) ) ) {
							$sources = array_merge(
								self::$enqueued_script_sources[ $enqueued_script_handle ],
								$sources
							);
							break;
						}
					}
				}
			}
		}

		return $sources;
	}

	/**
	 * Remove source comments.
	 *
	 * @param DOMDocument $dom Document.
	 */
	public static function remove_source_comments( $dom ) {
		$xpath    = new DOMXPath( $dom );
		$comments = array();
		foreach ( $xpath->query( '//comment()[ starts-with( ., "amp-source-stack" ) or starts-with( ., "/amp-source-stack" ) ]' ) as $comment ) {
			if ( self::parse_source_comment( $comment ) ) {
				$comments[] = $comment;
			}
		}
		foreach ( $comments as $comment ) {
			$comment->parentNode->removeChild( $comment );
		}
	}

	/**
	 * Add block source comments.
	 *
	 * @param string $content Content prior to blocks being processed.
	 * @return string Content with source comments added.
	 */
	public static function add_block_source_comments( $content ) {
		self::$block_content_index = 0;

		$start_block_pattern = implode( '', array(
			'#<!--\s+',
			'(?P<closing>/)?',
			'wp:(?P<name>\S+)',
			'(?:\s+(?P<attributes>\{.*?\}))?',
			'\s+(?P<self_closing>\/)?',
			'-->#s',
		) );

		return preg_replace_callback(
			$start_block_pattern,
			array( __CLASS__, 'handle_block_source_comment_replacement' ),
			$content
		);
	}

	/**
	 * Handle block source comment replacement.
	 *
	 * @see \AMP_Validation_Manager::add_block_source_comments()
	 *
	 * @param array $matches Matches.
	 *
	 * @return string Replaced.
	 */
	protected static function handle_block_source_comment_replacement( $matches ) {
		$replaced = $matches[0];

		// Obtain source information for block.
		$source = array(
			'block_name' => $matches['name'],
			'post_id'    => get_the_ID(),
		);

		if ( empty( $matches['closing'] ) ) {
			$source['block_content_index'] = self::$block_content_index;
			self::$block_content_index++;
		}

		// Make implicit core namespace explicit.
		$is_implicit_core_namespace = ( false === strpos( $source['block_name'], '/' ) );
		$source['block_name']       = $is_implicit_core_namespace ? 'core/' . $source['block_name'] : $source['block_name'];

		if ( ! empty( $matches['attributes'] ) ) {
			$source['block_attrs'] = json_decode( $matches['attributes'] );
		}
		$block_type = WP_Block_Type_Registry::get_instance()->get_registered( $source['block_name'] );
		if ( $block_type && $block_type->is_dynamic() ) {
			$callback_source = self::get_source( $block_type->render_callback );
			if ( $callback_source ) {
				$source = array_merge(
					$source,
					$callback_source
				);
			}
		}

		if ( ! empty( $matches['closing'] ) ) {
			$replaced .= self::get_source_comment( $source, false );
		} else {
			$replaced = self::get_source_comment( $source, true ) . $replaced;
			if ( ! empty( $matches['self_closing'] ) ) {
				unset( $source['block_content_index'] );
				$replaced .= self::get_source_comment( $source, false );
			}
		}
		return $replaced;
	}

	/**
	 * Wrap callbacks for registered widgets to keep track of queued assets and the source for anything printed for validation.
	 *
	 * @global array $wp_filter
	 * @return void
	 */
	public static function wrap_widget_callbacks() {
		global $wp_registered_widgets;
		foreach ( $wp_registered_widgets as $widget_id => &$registered_widget ) {
			$source = self::get_source( $registered_widget['callback'] );
			if ( ! $source ) {
				continue;
			}
			$source['widget_id'] = $widget_id;

			$function      = $registered_widget['callback'];
			$accepted_args = 2; // For the $instance and $args arguments.
			$callback      = compact( 'function', 'accepted_args', 'source' );

			$registered_widget['callback'] = self::wrapped_callback( $callback );
		}
	}

	/**
	 * Wrap filter/action callback functions for a given hook.
	 *
	 * Wrapped callback functions are reset to their original functions after invocation.
	 * This runs at the 'all' action. The shutdown hook is excluded.
	 *
	 * @global WP_Hook[] $wp_filter
	 * @param string $hook Hook name for action or filter.
	 * @return void
	 */
	public static function wrap_hook_callbacks( $hook ) {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook ] ) || 'shutdown' === $hook ) {
			return;
		}

		self::$current_hook_source_stack[ $hook ] = array();
		foreach ( $wp_filter[ $hook ]->callbacks as $priority => &$callbacks ) {
			foreach ( $callbacks as &$callback ) {
				$source = self::get_source( $callback['function'] );
				if ( ! $source ) {
					continue;
				}

				$reflection = $source['reflection'];
				unset( $source['reflection'] ); // Omit from stored source.

				// Add hook to stack for decorate_filter_source to read from.
				self::$current_hook_source_stack[ $hook ][] = $source;

				/*
				 * A current limitation with wrapping callbacks is that the wrapped function cannot have
				 * any parameters passed by reference. Without this the result is:
				 *
				 * > PHP Warning:  Parameter 1 to wp_default_styles() expected to be a reference, value given.
				 */
				if ( self::has_parameters_passed_by_reference( $reflection ) ) {
					continue;
				}

				$source['hook']    = $hook;
				$original_function = $callback['function'];
				$wrapped_callback  = self::wrapped_callback( array_merge(
					$callback,
					compact( 'priority', 'source', 'hook' )
				) );

				$callback['function'] = function() use ( &$callback, $wrapped_callback, $original_function ) {
					$callback['function'] = $original_function; // Restore original.
					return call_user_func_array( $wrapped_callback, func_get_args() );
				};
			}
		}
	}

	/**
	 * Determine whether the given reflection method/function has params passed by reference.
	 *
	 * @since 0.7
	 * @param ReflectionFunction|ReflectionMethod $reflection Reflection.
	 * @return bool Whether there are parameters passed by reference.
	 */
	protected static function has_parameters_passed_by_reference( $reflection ) {
		foreach ( $reflection->getParameters() as $parameter ) {
			if ( $parameter->isPassedByReference() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Filters the output created by a shortcode callback.
	 *
	 * @since 0.7
	 *
	 * @param string $output Shortcode output.
	 * @param string $tag    Shortcode name.
	 * @return string Output.
	 * @global array $shortcode_tags
	 */
	public static function decorate_shortcode_source( $output, $tag ) {
		global $shortcode_tags;
		if ( ! isset( $shortcode_tags[ $tag ] ) ) {
			return $output;
		}
		$source = self::get_source( $shortcode_tags[ $tag ] );
		if ( empty( $source ) ) {
			return $output;
		}
		$source['shortcode'] = $tag;

		$output = implode( '', array(
			self::get_source_comment( $source, true ),
			$output,
			self::get_source_comment( $source, false ),
		) );
		return $output;
	}

	/**
	 * Wraps output of a filter to add source stack comments.
	 *
	 * @todo Duplicate with AMP_Validation_Manager::wrap_buffer_with_source_comments()?
	 * @param string $value Value.
	 * @return string Value wrapped in source comments.
	 */
	public static function decorate_filter_source( $value ) {

		// Abort if the output is not a string and it doesn't contain any HTML tags.
		if ( ! is_string( $value ) || ! preg_match( '/<.+?>/s', $value ) ) {
			return $value;
		}

		$post   = get_post();
		$source = array(
			'hook'   => current_filter(),
			'filter' => true,
		);
		if ( $post ) {
			$source['post_id']   = $post->ID; // @todo This is causing duplicate validation errors to occur when only variance is post_id.
			$source['post_type'] = $post->post_type;
		}
		if ( isset( self::$current_hook_source_stack[ current_filter() ] ) ) {
			$sources = self::$current_hook_source_stack[ current_filter() ];
			array_pop( $sources ); // Remove self.
			$source['sources'] = $sources;
		}
		return implode( '', array(
			self::get_source_comment( $source, true ),
			$value,
			self::get_source_comment( $source, false ),
		) );
	}

	/**
	 * Gets the plugin or theme of the callback, if one exists.
	 *
	 * @param string|array $callback The callback for which to get the plugin.
	 * @return array|null {
	 *     The source data.
	 *
	 *     @type string $type Source type (core, plugin, mu-plugin, or theme).
	 *     @type string $name Source name.
	 *     @type string $function Normalized function name.
	 *     @type ReflectionMethod|ReflectionFunction $reflection
	 * }
	 */
	public static function get_source( $callback ) {
		$reflection = null;
		$class_name = null; // Because ReflectionMethod::getDeclaringClass() can return a parent class.
		try {
			if ( is_string( $callback ) && is_callable( $callback ) ) {
				// The $callback is a function or static method.
				$exploded_callback = explode( '::', $callback, 2 );
				if ( 2 === count( $exploded_callback ) ) {
					$class_name = $exploded_callback[0];
					$reflection = new ReflectionMethod( $exploded_callback[0], $exploded_callback[1] );
				} else {
					$reflection = new ReflectionFunction( $callback );
				}
			} elseif ( is_array( $callback ) && isset( $callback[0], $callback[1] ) && method_exists( $callback[0], $callback[1] ) ) {
				// The $callback is a method.
				if ( is_string( $callback[0] ) ) {
					$class_name = $callback[0];
				} elseif ( is_object( $callback[0] ) ) {
					$class_name = get_class( $callback[0] );
				}
				$reflection = new ReflectionMethod( $callback[0], $callback[1] );
			} elseif ( is_object( $callback ) && ( 'Closure' === get_class( $callback ) ) ) {
				$reflection = new ReflectionFunction( $callback );
			}
		} catch ( Exception $e ) {
			return null;
		}

		if ( ! $reflection ) {
			return null;
		}

		$source = compact( 'reflection' );

		$file = $reflection->getFileName();
		if ( $file ) {
			$file         = wp_normalize_path( $file );
			$slug_pattern = '([^/]+)';
			if ( preg_match( ':' . preg_quote( trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) ), ':' ) . $slug_pattern . ':s', $file, $matches ) ) {
				$source['type'] = 'plugin';
				$source['name'] = $matches[1];
			} elseif ( preg_match( ':' . preg_quote( trailingslashit( wp_normalize_path( get_theme_root() ) ), ':' ) . $slug_pattern . ':s', $file, $matches ) ) {
				$source['type'] = 'theme';
				$source['name'] = $matches[1];
			} elseif ( preg_match( ':' . preg_quote( trailingslashit( wp_normalize_path( WPMU_PLUGIN_DIR ) ), ':' ) . $slug_pattern . ':s', $file, $matches ) ) {
				$source['type'] = 'mu-plugin';
				$source['name'] = $matches[1];
			} elseif ( preg_match( ':' . preg_quote( trailingslashit( wp_normalize_path( ABSPATH ) ), ':' ) . '(wp-admin|wp-includes)/:s', $file, $matches ) ) {
				$source['type'] = 'core';
				$source['name'] = $matches[1];
			}
		}

		if ( $class_name ) {
			$source['function'] = $class_name . '::' . $reflection->getName();
		} else {
			$source['function'] = $reflection->getName();
		}

		return $source;
	}

	/**
	 * Check whether or not output buffering is currently possible.
	 *
	 * This is to guard against a fatal error: "ob_start(): Cannot use output buffering in output buffering display handlers".
	 *
	 * @return bool Whether output buffering is allowed.
	 */
	public static function can_output_buffer() {

		// Output buffering for validation can only be done while overall output buffering is being done for the response.
		if ( ! AMP_Theme_Support::is_output_buffering() ) {
			return false;
		}

		// Abort when in shutdown since output has finished, when we're likely in the overall output buffering display handler.
		if ( did_action( 'shutdown' ) ) {
			return false;
		}

		// Check if any functions in call stack are output buffering display handlers.
		$called_functions = array();
		if ( defined( 'DEBUG_BACKTRACE_IGNORE_ARGS' ) ) {
			$arg = DEBUG_BACKTRACE_IGNORE_ARGS; // phpcs:ignore PHPCompatibility.PHP.NewConstants.debug_backtrace_ignore_argsFound
		} else {
			$arg = false;
		}
		$backtrace = debug_backtrace( $arg ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Only way to find out if we are in a buffering display handler.
		foreach ( $backtrace as $call_stack ) {
			$called_functions[] = '{closure}' === $call_stack['function'] ? 'Closure::__invoke' : $call_stack['function'];
		}
		return 0 === count( array_intersect( ob_list_handlers(), $called_functions ) );
	}

	/**
	 * Wraps a callback in comments if it outputs markup.
	 *
	 * If the sanitizer removes markup,
	 * this indicates which plugin it was from.
	 * The call_user_func_array() logic is mainly copied from WP_Hook:apply_filters().
	 *
	 * @param array $callback {
	 *     The callback data.
	 *
	 *     @type callable $function
	 *     @type int      $accepted_args
	 *     @type array    $source
	 * }
	 * @return closure $wrapped_callback The callback, wrapped in comments.
	 */
	public static function wrapped_callback( $callback ) {
		return function() use ( $callback ) {
			global $wp_styles, $wp_scripts;

			$function      = $callback['function'];
			$accepted_args = $callback['accepted_args'];
			$args          = func_get_args();

			$before_styles_enqueued = array();
			if ( isset( $wp_styles ) && isset( $wp_styles->queue ) ) {
				$before_styles_enqueued = $wp_styles->queue;
			}
			$before_scripts_enqueued = array();
			if ( isset( $wp_scripts ) && isset( $wp_scripts->queue ) ) {
				$before_scripts_enqueued = $wp_scripts->queue;
			}

			// Wrap the markup output of (action) hooks in source comments.
			AMP_Validation_Manager::$hook_source_stack[] = $callback['source'];
			$has_buffer_started                          = false;
			if ( AMP_Validation_Manager::can_output_buffer() ) {
				$has_buffer_started = ob_start( array( __CLASS__, 'wrap_buffer_with_source_comments' ) );
			}
			$result = call_user_func_array( $function, array_slice( $args, 0, intval( $accepted_args ) ) );
			if ( $has_buffer_started ) {
				ob_end_flush();
			}
			array_pop( AMP_Validation_Manager::$hook_source_stack );

			// Keep track of which source enqueued the styles.
			if ( isset( $wp_styles ) && isset( $wp_styles->queue ) ) {
				foreach ( array_diff( $wp_styles->queue, $before_styles_enqueued ) as $handle ) {
					AMP_Validation_Manager::$enqueued_style_sources[ $handle ][] = array_merge( $callback['source'], compact( 'handle' ) );
				}
			}

			// Keep track of which source enqueued the scripts, and immediately report validity.
			if ( isset( $wp_scripts ) && isset( $wp_scripts->queue ) ) {
				foreach ( array_diff( $wp_scripts->queue, $before_scripts_enqueued ) as $queued_handle ) {
					$handles = array( $queued_handle );

					// Account for case where registered script is a placeholder for a set of scripts (e.g. jquery).
					if ( isset( $wp_scripts->registered[ $queued_handle ] ) && false === $wp_scripts->registered[ $queued_handle ]->src ) {
						$handles = array_merge( $handles, $wp_scripts->registered[ $queued_handle ]->deps );
					}

					foreach ( $handles as $handle ) {
						AMP_Validation_Manager::$enqueued_script_sources[ $handle ][] = array_merge( $callback['source'], compact( 'handle' ) );
					}
				}
			}

			return $result;
		};
	}

	/**
	 * Wrap output buffer with source comments.
	 *
	 * A key reason for why this is a method and not a closure is so that
	 * the can_output_buffer method will be able to identify it by name.
	 *
	 * @since 0.7
	 * @todo Is duplicate of \AMP_Validation_Manager::decorate_filter_source()?
	 *
	 * @param string $output Output buffer.
	 * @return string Output buffer conditionally wrapped with source comments.
	 */
	public static function wrap_buffer_with_source_comments( $output ) {
		if ( empty( self::$hook_source_stack ) ) {
			return $output;
		}

		$source = self::$hook_source_stack[ count( self::$hook_source_stack ) - 1 ];

		// Wrap output that contains HTML tags (as opposed to actions that trigger in HTML attributes).
		if ( ! empty( $output ) && preg_match( '/<.+?>/s', $output ) ) {
			$output = implode( '', array(
				self::get_source_comment( $source, true ),
				$output,
				self::get_source_comment( $source, false ),
			) );
		}
		return $output;
	}

	/**
	 * Output a removed set, each wrapped in <code></code>.
	 *
	 * @param array[][] $set {
	 *     The removed elements to output.
	 *
	 *     @type string $name  The name of the source.
	 *     @type string $count The number that were invalid.
	 * }
	 * @return void
	 */
	protected static function output_removed_set( $set ) {
		$items = array();
		foreach ( $set as $name => $count ) {
			if ( 1 === intval( $count ) ) {
				$items[] = sprintf( '<code>%s</code>', esc_html( $name ) );
			} else {
				$items[] = sprintf( '<code>%s</code> (%d)', esc_html( $name ), $count );
			}
		}
		echo implode( ', ', $items ); // WPCS: XSS OK.
	}

	/**
	 * Whether to validate the front end response.
	 *
	 * @return boolean Whether to validate.
	 */
	public static function should_validate_response() {
		return self::has_cap() && isset( $_GET[ self::VALIDATE_QUERY_VAR ] ); // WPCS: CSRF ok.
	}

	/**
	 * Determine if there are any validation errors which have not been ignored.
	 *
	 * @return bool Whether AMP is blocked.
	 */
	public static function has_blocking_validation_errors() {
		foreach ( self::$validation_results as $result ) {
			if ( false === $result['sanitized'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Finalize validation.
	 *
	 * @param DOMDocument $dom Document.
	 * @param array       $args {
	 *     Args.
	 *
	 *     @type bool $remove_source_comments           Whether source comments should be removed. Defaults to true.
	 *     @type bool $append_validation_status_comment Whether the validation errors should be appended as an HTML comment. Defaults to true.
	 * }
	 */
	public static function finalize_validation( DOMDocument $dom, $args = array() ) {
		$args = array_merge(
			array(
				'remove_source_comments'           => ! self::$debug,
				'append_validation_status_comment' => true,
			),
			$args
		);

		if ( $args['remove_source_comments'] ) {
			self::remove_source_comments( $dom );
		}

		if ( $args['append_validation_status_comment'] ) {
			$errors  = wp_list_pluck( self::$validation_results, 'error' );
			$encoded = wp_json_encode( $errors, 128 /* JSON_PRETTY_PRINT */ );
			$encoded = str_replace( '--', '\u002d\u002d', $encoded ); // Prevent "--" in strings from breaking out of HTML comments.
			$comment = $dom->createComment( 'AMP_VALIDATION_ERRORS:' . $encoded . "\n" ); // @todo Rename to AMP_VALIDATION_RESULTS and then include sanitized.
			$dom->documentElement->appendChild( $comment );
		}
	}

	/**
	 * Adds the validation callback if front-end validation is needed.
	 *
	 * @param array $sanitizers The AMP sanitizers.
	 * @return array $sanitizers The filtered AMP sanitizers.
	 */
	public static function filter_sanitizer_args( $sanitizers ) {
		foreach ( $sanitizers as $sanitizer => &$args ) {
			$args['validation_error_callback'] = __CLASS__ . '::add_validation_error';
		}

		// @todo Pass this into all sanitizers?
		if ( isset( $sanitizers['AMP_Style_Sanitizer'] ) ) {
			$sanitizers['AMP_Style_Sanitizer']['locate_sources'] = self::$locate_sources;
		}

		return $sanitizers;
	}

	/**
	 * Validates the latest published post.
	 *
	 * @return array|WP_Error The validation errors, or WP_Error.
	 */
	public static function validate_after_plugin_activation() {
		$url = amp_admin_get_preview_permalink();
		if ( ! $url ) {
			return new WP_Error( 'no_published_post_url_available' );
		}
		$validation_errors = self::validate_url( $url );
		if ( is_array( $validation_errors ) && count( $validation_errors ) > 0 ) {
			AMP_Invalid_URL_Post_Type::store_validation_errors( $validation_errors, $url );
			set_transient( self::PLUGIN_ACTIVATION_VALIDATION_ERRORS_TRANSIENT_KEY, $validation_errors, 60 );
		} else {
			delete_transient( self::PLUGIN_ACTIVATION_VALIDATION_ERRORS_TRANSIENT_KEY );
		}
		return $validation_errors;
	}

	/**
	 * Validates a given URL.
	 *
	 * The validation errors will be stored in the validation status custom post type,
	 * as well as in a transient.
	 *
	 * @param string $url The URL to validate.
	 * @return array|WP_Error The validation errors, or WP_Error on error.
	 */
	public static function validate_url( $url ) {
		$validation_url = add_query_arg(
			array(
				self::VALIDATE_QUERY_VAR   => 1,
				self::CACHE_BUST_QUERY_VAR => wp_rand(),
			),
			$url
		);

		$r = wp_remote_get( $validation_url, array(
			'cookies'   => wp_unslash( $_COOKIE ), // @todo Passing-along the credentials of the currently-authenticated user prevents this from working in cron.
			'sslverify' => false,
			'headers'   => array(
				'Cache-Control' => 'no-cache',
			),
		) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( wp_remote_retrieve_response_code( $r ) >= 400 ) {
			return new WP_Error(
				wp_remote_retrieve_response_code( $r ),
				wp_remote_retrieve_response_message( $r )
			);
		}
		$response = wp_remote_retrieve_body( $r );
		if ( ! preg_match( '#</body>.*?<!--\s*AMP_VALIDATION_ERRORS\s*:\s*(\[.*?\])\s*-->#s', $response, $matches ) ) {
			return new WP_Error( 'response_comment_absent' );
		}
		$validation_errors = json_decode( $matches[1], true );
		if ( ! is_array( $validation_errors ) ) {
			return new WP_Error( 'malformed_json_validation_errors' );
		}

		return $validation_errors;
	}

	/**
	 * On activating a plugin, display a notice if a plugin causes an AMP validation error.
	 *
	 * @return void
	 */
	public static function plugin_notice() {
		global $pagenow;
		if ( ( 'plugins.php' === $pagenow ) && ( ! empty( $_GET['activate'] ) || ! empty( $_GET['activate-multi'] ) ) ) { // WPCS: CSRF ok.
			$validation_errors = get_transient( self::PLUGIN_ACTIVATION_VALIDATION_ERRORS_TRANSIENT_KEY );
			if ( empty( $validation_errors ) || ! is_array( $validation_errors ) ) {
				return;
			}
			delete_transient( self::PLUGIN_ACTIVATION_VALIDATION_ERRORS_TRANSIENT_KEY );
			$errors          = AMP_Validation_Error_Taxonomy::summarize_validation_errors( $validation_errors );
			$invalid_plugins = isset( $errors[ AMP_Validation_Error_Taxonomy::SOURCES_INVALID_OUTPUT ]['plugin'] ) ? array_unique( $errors[ AMP_Validation_Error_Taxonomy::SOURCES_INVALID_OUTPUT ]['plugin'] ) : null;
			if ( isset( $invalid_plugins ) ) {
				$reported_plugins = array();
				foreach ( $invalid_plugins as $plugin ) {
					$reported_plugins[] = sprintf( '<code>%s</code>', esc_html( $plugin ) );
				}

				$more_details_link = sprintf(
					'<a href="%s">%s</a>',
					esc_url( add_query_arg(
						'post_type',
						AMP_Invalid_URL_Post_Type::POST_TYPE_SLUG,
						admin_url( 'edit.php' )
					) ),
					__( 'More details', 'amp' )
				);
				printf(
					'<div class="notice notice-warning is-dismissible"><p>%s %s %s</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">%s</span></button></div>',
					esc_html( _n( 'Warning: The following plugin may be incompatible with AMP:', 'Warning: The following plugins may be incompatible with AMP:', count( $invalid_plugins ), 'amp' ) ),
					implode( ', ', $reported_plugins ),
					$more_details_link,
					esc_html__( 'Dismiss this notice.', 'amp' )
				); // WPCS: XSS ok.
			}
		}
	}

	/**
	 * Get validation debug UR:.
	 *
	 * @param string $url URL to to validate and debug.
	 * @return string Debug URL.
	 */
	public static function get_debug_url( $url ) {
		return add_query_arg(
			array(
				self::VALIDATE_QUERY_VAR => '',
				self::DEBUG_QUERY_VAR    => '',
			),
			$url
		) . '#development=1';
	}

	/**
	 * Enqueues the block validation script.
	 *
	 * @return void
	 */
	public static function enqueue_block_validation() {
		$slug = 'amp-block-validation';

		wp_enqueue_script(
			$slug,
			amp_get_asset_url( "js/{$slug}.js" ),
			array( 'underscore' ),
			AMP__VERSION,
			true
		);

		$data = wp_json_encode( array(
			'i18n'                 => gutenberg_get_jed_locale_data( 'amp' ), // @todo POT file.
			'ampValidityRestField' => self::VALIDITY_REST_FIELD_NAME,
		) );
		wp_add_inline_script( $slug, sprintf( 'ampBlockValidation.boot( %s );', $data ) );
	}
}