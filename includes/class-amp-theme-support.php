<?php
/**
 * Class AMP_Theme_Support
 *
 * @package AMP
 */

/**
 * Class AMP_Theme_Support
 *
 * Callbacks for adding AMP-related things when theme support is added.
 */
class AMP_Theme_Support {

	/**
	 * Replaced with the necessary scripts depending on components used in output.
	 *
	 * @var string
	 */
	const SCRIPTS_PLACEHOLDER = '<!-- AMP:SCRIPTS_PLACEHOLDER -->';

	/**
	 * Response cache group name.
	 *
	 * @var string
	 */
	const RESPONSE_CACHE_GROUP = 'amp-reponse';

	/**
	 * Sanitizer classes.
	 *
	 * @var array
	 */
	protected static $sanitizer_classes = array();

	/**
	 * Embed handlers.
	 *
	 * @var AMP_Base_Embed_Handler[]
	 */
	protected static $embed_handlers = array();

	/**
	 * Template types.
	 *
	 * @var array
	 */
	protected static $template_types = array(
		'paged', // Deprecated.
		'index',
		'404',
		'archive',
		'author',
		'category',
		'tag',
		'taxonomy',
		'date',
		'home',
		'front_page',
		'page',
		'search',
		'single',
		'embed',
		'singular',
		'attachment',
	);

	/**
	 * AMP-specific query vars that were purged.
	 *
	 * @since 0.7
	 * @see AMP_Theme_Support::purge_amp_query_vars()
	 * @var string[]
	 */
	public static $purged_amp_query_vars = array();

	/**
	 * Start time when init was called.
	 *
	 * @since 1.0
	 * @var float
	 */
	public static $init_start_time;

	/**
	 * Whether output buffering has started.
	 *
	 * @since 0.7
	 * @var bool
	 */
	protected static $is_output_buffering = false;

	/**
	 * Initialize.
	 *
	 * @since 0.7
	 */
	public static function init() {
		self::apply_options();
		if ( ! current_theme_supports( 'amp' ) ) {
			return;
		}

		AMP_Validation_Manager::init( array(
			'should_locate_sources' => AMP_Validation_Manager::should_validate_response(),
		) );

		self::$init_start_time = microtime( true );

		self::purge_amp_query_vars();
		self::handle_xhr_request();

		require_once AMP__DIR__ . '/includes/amp-post-template-actions.php';

		// Validate theme support usage.
		$support = get_theme_support( 'amp' );
		if ( WP_DEBUG && is_array( $support ) ) {
			$args = array_shift( $support );
			if ( ! is_array( $args ) ) {
				trigger_error( esc_html__( 'Expected AMP theme support arg to be array.', 'amp' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
			} elseif ( count( array_diff( array_keys( $args ), array( 'template_dir', 'available_callback', 'comments_live_list', '__added_via_option' ) ) ) !== 0 ) {
				trigger_error( esc_html__( 'Expected AMP theme support to only have template_dir and/or available_callback.', 'amp' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
			}
		}

		add_action( 'widgets_init', array( __CLASS__, 'register_widgets' ) );

		/*
		 * Note that wp action is use instead of template_redirect because some themes/plugins output
		 * the response at this action and then short-circuit with exit. So this is why the the preceding
		 * action to template_redirect--the wp action--is used instead.
		 */
		add_action( 'wp', array( __CLASS__, 'finish_init' ), PHP_INT_MAX );
	}

	/**
	 * Apply options for whether theme support is enabled via admin and what sanitization is performed by default.
	 *
	 * @see AMP_Post_Type_Support::add_post_type_support() For where post type support is added, since it is irrespective of theme support.
	 */
	public static function apply_options() {
		if ( ! current_theme_supports( 'amp' ) ) {
			$theme_support_option = AMP_Options_Manager::get_option( 'theme_support' );
			if ( 'disabled' === $theme_support_option ) {
				return;
			}

			$args = array(
				'__added_via_option' => true,
			);
			if ( 'paired' === $theme_support_option ) {
				$args['template_dir'] = './';
			}
			add_theme_support( 'amp', $args );
		}
	}

	/**
	 * Finish initialization once query vars are set.
	 *
	 * @since 0.7
	 */
	public static function finish_init() {
		if ( ! is_amp_endpoint() ) {
			amp_add_frontend_actions();
			return;
		}

		self::ensure_proper_amp_location();

		if ( ! amp_is_canonical() ) {
			self::register_paired_hooks();
		}

		self::add_hooks();
		self::$sanitizer_classes = amp_get_content_sanitizers();
		self::$sanitizer_classes = AMP_Validation_Manager::filter_sanitizer_args( self::$sanitizer_classes );
		self::$embed_handlers    = self::register_content_embed_handlers();

		foreach ( self::$sanitizer_classes as $sanitizer_class => $args ) {
			if ( method_exists( $sanitizer_class, 'add_buffering_hooks' ) ) {
				call_user_func( array( $sanitizer_class, 'add_buffering_hooks' ), $args );
			}
		}
	}

	/**
	 * Ensure that the current AMP location is correct.
	 *
	 * @since 1.0
	 *
	 * @param bool $exit Whether to exit after redirecting.
	 * @return bool Whether redirection was done. Naturally this is irrelevant if $exit is true.
	 */
	public static function ensure_proper_amp_location( $exit = true ) {
		$has_query_var = false !== get_query_var( amp_get_slug(), false ); // May come from URL param or endpoint slug.
		$has_url_param = isset( $_GET[ amp_get_slug() ] ); // WPCS: CSRF OK.

		if ( amp_is_canonical() || is_singular( AMP_Story_Post_Type::POST_TYPE_SLUG ) ) {
			/*
			 * When AMP native/canonical, then when there is an /amp/ endpoint or ?amp URL param,
			 * then a redirect needs to be done to the URL without any AMP indicator in the URL.
			 */
			if ( $has_query_var || $has_url_param ) {
				return self::redirect_ampless_url( $exit );
			}
		} else {
			/*
			 * When in AMP paired mode *with* theme support, then the proper AMP URL has the 'amp' URL param
			 * and not the /amp/ endpoint. The URL param is now the exclusive way to mark AMP in paired mode
			 * when amp theme support present. This is important for plugins to be able to reliably call
			 * is_amp_endpoint() before the parse_query action.
			 */
			if ( $has_query_var && ! $has_url_param ) {
				$old_url = amp_get_current_url();
				$new_url = add_query_arg( amp_get_slug(), '', amp_remove_endpoint( $old_url ) );
				if ( $old_url !== $new_url ) {
					wp_safe_redirect( $new_url, 302 );
					if ( $exit ) {
						exit;
					}
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Redirect to non-AMP version of the current URL, such as because AMP is canonical or there are unaccepted validation errors.
	 *
	 * If the current URL is already AMP-less then do nothing.
	 *
	 * @since 0.7
	 * @since 1.0 Added $exit param.
	 * @since 1.0 Renamed from redirect_canonical_amp().
	 *
	 * @param bool $exit Whether to exit after redirecting.
	 * @return bool Whether redirection was done. Naturally this is irrelevant if $exit is true.
	 */
	public static function redirect_ampless_url( $exit = true ) {
		$current_url = amp_get_current_url();
		$ampless_url = amp_remove_endpoint( $current_url );
		if ( $ampless_url === $current_url ) {
			return false;
		}

		/*
		 * Temporary redirect because AMP URL may return when blocking validation errors
		 * occur or when a non-canonical AMP theme is used.
		 */
		wp_safe_redirect( $ampless_url, 302 );
		if ( $exit ) {
			exit;
		}
		return true;
	}

	/**
	 * Determines whether paired mode is available.
	 *
	 * When 'amp' theme support has not been added or canonical mode is enabled, then this returns false.
	 * Returns true when there is a template_dir defined in theme support, and if a defined available_callback
	 * returns true.
	 *
	 * @return bool Whether available.
	 */
	public static function is_paired_available() {
		$support = get_theme_support( 'amp' );
		if ( empty( $support ) || amp_is_canonical() ) {
			return false;
		}

		/**
		 * Queried object.
		 *
		 * @var WP_Post $queried_object
		 */
		$queried_object = get_queried_object();
		if ( is_singular() && ! post_supports_amp( $queried_object ) ) {
			return false;
		}

		$args = array_shift( $support );

		if ( isset( $args['available_callback'] ) && is_callable( $args['available_callback'] ) ) {
			return call_user_func( $args['available_callback'] );
		}
		return true;
	}

	/**
	 * Determine whether the user is in the Customizer preview iframe.
	 *
	 * @since 0.7
	 *
	 * @return bool Whether in Customizer preview iframe.
	 */
	public static function is_customize_preview_iframe() {
		global $wp_customize;
		return is_customize_preview() && $wp_customize->get_messenger_channel();
	}

	/**
	 * Register hooks for paired mode.
	 */
	public static function register_paired_hooks() {
		foreach ( self::$template_types as $template_type ) {
			add_filter( "{$template_type}_template_hierarchy", array( __CLASS__, 'filter_paired_template_hierarchy' ) );
		}
		add_filter( 'template_include', array( __CLASS__, 'filter_paired_template_include' ), 100 );
	}

	/**
	 * Register hooks.
	 */
	public static function add_hooks() {

		// Remove core actions which are invalid AMP.
		remove_action( 'wp_head', 'wp_post_preview_js', 1 );
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );

		// Prevent MediaElement.js scripts/styles from being enqueued.
		add_filter( 'wp_video_shortcode_library', function() {
			return 'amp';
		} );
		add_filter( 'wp_audio_shortcode_library', function() {
			return 'amp';
		} );

		/*
		 * Add additional markup required by AMP <https://www.ampproject.org/docs/reference/spec#required-markup>.
		 * Note that the meta[name=viewport] is not added here because a theme may want to define one with additional
		 * properties than included in the default configuration. If a theme doesn't include one, then the meta viewport
		 * will be added when output buffering is finished. Note that meta charset _is_ output here because the output
		 * buffer will need it to parse the document properly, and it must be exactly as is to be valid AMP. Nevertheless,
		 * in this case too we should defer to the theme as well to output the meta charset because it is possible the
		 * install is not on utf-8 and we may need to do a encoding conversion.
		 */
		add_action( 'wp_print_styles', array( __CLASS__, 'print_amp_styles' ), 0 ); // Print boilerplate before theme and plugin stylesheets.
		add_action( 'wp_head', 'amp_add_generator_metadata', 20 );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'dequeue_customize_preview_scripts' ), 1000 );
		add_filter( 'customize_partial_render', array( __CLASS__, 'filter_customize_partial_render' ) );

		add_action( 'wp_footer', 'amp_print_analytics' );

		/*
		 * Disable admin bar because admin-bar.css (28K) and Dashicons (48K) alone
		 * combine to surpass the 50K limit imposed for the amp-custom style.
		 */
		add_filter( 'show_admin_bar', '__return_false', 100 );

		/*
		 * Start output buffering at very low priority for sake of plugins and themes that use template_redirect
		 * instead of template_include.
		 */
		$priority = defined( 'PHP_INT_MIN' ) ? PHP_INT_MIN : ~PHP_INT_MAX; // phpcs:ignore PHPCompatibility.PHP.NewConstants.php_int_minFound
		add_action( 'template_redirect', array( __CLASS__, 'start_output_buffering' ), $priority );

		// Commenting hooks.
		add_filter( 'wp_list_comments_args', array( __CLASS__, 'set_comments_walker' ), PHP_INT_MAX );
		add_filter( 'comment_form_defaults', array( __CLASS__, 'filter_comment_form_defaults' ) );
		add_filter( 'comment_reply_link', array( __CLASS__, 'filter_comment_reply_link' ), 10, 4 );
		add_filter( 'cancel_comment_reply_link', array( __CLASS__, 'filter_cancel_comment_reply_link' ), 10, 3 );
		add_action( 'comment_form', array( __CLASS__, 'amend_comment_form' ), 100 );
		remove_action( 'comment_form', 'wp_comment_form_unfiltered_html_nonce' );
		add_filter( 'wp_kses_allowed_html', array( __CLASS__, 'whitelist_layout_in_wp_kses_allowed_html' ), 10 );
		add_filter( 'get_header_image_tag', array( __CLASS__, 'amend_header_image_with_video_header' ), PHP_INT_MAX );
		add_action( 'wp_print_footer_scripts', function() {
			wp_dequeue_script( 'wp-custom-header' );
		}, 0 );
		add_action( 'wp_enqueue_scripts', function() {
			wp_dequeue_script( 'comment-reply' ); // Handled largely by AMP_Comments_Sanitizer and *reply* methods in this class.
		} );

		// @todo Add character conversion.
	}

	/**
	 * Remove query vars that come in requests such as for amp-live-list.
	 *
	 * WordPress should generally not respond differently to requests when these parameters
	 * are present. In some cases, when a query param such as __amp_source_origin is present
	 * then it would normally get included into pagination links generated by get_pagenum_link().
	 * The whitelist sanitizer empties out links that contain this string as it matches the
	 * blacklisted_value_regex. So by preemptively scrubbing any reference to these query vars
	 * we can ensure that WordPress won't end up referencing them in any way.
	 *
	 * @since 0.7
	 */
	public static function purge_amp_query_vars() {
		$query_vars = array(
			'__amp_source_origin',
			'_wp_amp_action_xhr_converted',
			'amp_latest_update_time',
			'amp_last_check_time',
		);

		// Scrub input vars.
		foreach ( $query_vars as $query_var ) {
			if ( ! isset( $_GET[ $query_var ] ) ) { // phpcs:ignore
				continue;
			}
			self::$purged_amp_query_vars[ $query_var ] = wp_unslash( $_GET[ $query_var ] ); // phpcs:ignore
			unset( $_REQUEST[ $query_var ], $_GET[ $query_var ] );
			$scrubbed = true;
		}

		if ( isset( $scrubbed ) ) {
			$build_query = function( $query ) use ( $query_vars ) {
				$pattern = '/^(' . join( '|', $query_vars ) . ')(?==|$)/';
				$pairs   = array();
				foreach ( explode( '&', $query ) as $pair ) {
					if ( ! preg_match( $pattern, $pair ) ) {
						$pairs[] = $pair;
					}
				}
				return join( '&', $pairs );
			};

			// Scrub QUERY_STRING.
			if ( ! empty( $_SERVER['QUERY_STRING'] ) ) {
				$_SERVER['QUERY_STRING'] = $build_query( $_SERVER['QUERY_STRING'] );
			}

			// Scrub REQUEST_URI.
			if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
				list( $path, $query ) = explode( '?', $_SERVER['REQUEST_URI'], 2 );

				$pairs                  = $build_query( $query );
				$_SERVER['REQUEST_URI'] = $path;
				if ( ! empty( $pairs ) ) {
					$_SERVER['REQUEST_URI'] .= "?{$pairs}";
				}
			}
		}
	}

	/**
	 * Hook into a POST form submissions, such as the comment form or some other form submission.
	 *
	 * @since 0.7.0
	 */
	public static function handle_xhr_request() {
		$is_amp_xhr = (
			! empty( self::$purged_amp_query_vars['_wp_amp_action_xhr_converted'] )
			&&
			! empty( self::$purged_amp_query_vars['__amp_source_origin'] )
			&&
			( ! empty( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] )
		);
		if ( ! $is_amp_xhr ) {
			return;
		}

		// Send AMP response header.
		$origin = wp_validate_redirect( wp_sanitize_redirect( esc_url_raw( self::$purged_amp_query_vars['__amp_source_origin'] ) ) );
		if ( $origin ) {
			AMP_Response_Headers::send_header( 'AMP-Access-Control-Allow-Source-Origin', $origin, array( 'replace' => true ) );
		}

		// Intercept POST requests which redirect.
		add_filter( 'wp_redirect', array( __CLASS__, 'intercept_post_request_redirect' ), PHP_INT_MAX );

		// Add special handling for redirecting after comment submission.
		add_filter( 'comment_post_redirect', array( __CLASS__, 'filter_comment_post_redirect' ), PHP_INT_MAX, 2 );

		// Add die handler for AMP error display, most likely due to problem with comment.
		add_filter( 'wp_die_handler', function() {
			return array( __CLASS__, 'handle_wp_die' );
		} );

	}

	/**
	 * Strip tags that are not allowed in amp-mustache.
	 *
	 * @since 0.7.0
	 *
	 * @param string $text Text to sanitize.
	 * @return string Sanitized text.
	 */
	protected static function wp_kses_amp_mustache( $text ) {
		$amp_mustache_allowed_html_tags = array( 'strong', 'b', 'em', 'i', 'u', 's', 'small', 'mark', 'del', 'ins', 'sup', 'sub' );
		return wp_kses( $text, array_fill_keys( $amp_mustache_allowed_html_tags, array() ) );
	}

	/**
	 * Handle comment_post_redirect to ensure page reload is done when comments_live_list is not supported, while sending back a success message when it is.
	 *
	 * @since 0.7.0
	 *
	 * @param string     $url     Comment permalink to redirect to.
	 * @param WP_Comment $comment Posted comment.
	 * @return string|null URL if redirect to be done; otherwise function will exist.
	 */
	public static function filter_comment_post_redirect( $url, $comment ) {
		$theme_support = get_theme_support( 'amp' );

		// Cause a page refresh if amp-live-list is not implemented for comments via add_theme_support( 'amp', array( 'comments_live_list' => true ) ).
		if ( empty( $theme_support[0]['comments_live_list'] ) ) {
			/*
			 * Add the comment ID to the URL to force AMP to refresh the page.
			 * This is ideally a temporary workaround to deal with https://github.com/ampproject/amphtml/issues/14170
			 */
			$url = add_query_arg( 'comment', $comment->comment_ID, $url );

			// Pass URL along to wp_redirect().
			return $url;
		}

		// Create a success message to display to the user.
		if ( '1' === (string) $comment->comment_approved ) {
			$message = __( 'Your comment has been posted.', 'amp' );
		} else {
			$message = __( 'Your comment is awaiting moderation.', 'default' ); // Note core string re-use.
		}

		/**
		 * Filters the message when comment submitted success message when
		 *
		 * @since 0.7
		 */
		$message = apply_filters( 'amp_comment_posted_message', $message, $comment );

		// Message will be shown in template defined by AMP_Theme_Support::amend_comment_form().
		wp_send_json( array(
			'message' => self::wp_kses_amp_mustache( $message ),
		) );
		return null;
	}

	/**
	 * New error handler for AMP form submission.
	 *
	 * @since 0.7.0
	 * @see wp_die()
	 *
	 * @param WP_Error|string  $error The error to handle.
	 * @param string|int       $title Optional. Error title. If `$message` is a `WP_Error` object,
	 *                                error data with the key 'title' may be used to specify the title.
	 *                                If `$title` is an integer, then it is treated as the response
	 *                                code. Default empty.
	 * @param string|array|int $args {
	 *     Optional. Arguments to control behavior. If `$args` is an integer, then it is treated
	 *     as the response code. Default empty array.
	 *
	 *     @type int $response The HTTP response code. Default 200 for Ajax requests, 500 otherwise.
	 * }
	 */
	public static function handle_wp_die( $error, $title = '', $args = array() ) {
		if ( is_int( $title ) ) {
			$status_code = $title;
		} elseif ( is_int( $args ) ) {
			$status_code = $args;
		} elseif ( is_array( $args ) && isset( $args['response'] ) ) {
			$status_code = $args['response'];
		} else {
			$status_code = 500;
		}
		status_header( $status_code );

		if ( is_wp_error( $error ) ) {
			$error = $error->get_error_message();
		}

		// Message will be shown in template defined by AMP_Theme_Support::amend_comment_form().
		wp_send_json( array(
			'error' => self::wp_kses_amp_mustache( $error ),
		) );
	}

	/**
	 * Intercept the response to a POST request.
	 *
	 * @since 0.7.0
	 * @see wp_redirect()
	 *
	 * @param string $location The location to redirect to.
	 */
	public static function intercept_post_request_redirect( $location ) {

		// Make sure relative redirects get made absolute.
		$parsed_location = array_merge(
			array(
				'scheme' => 'https',
				'host'   => wp_parse_url( home_url(), PHP_URL_HOST ),
				'path'   => isset( $_SERVER['REQUEST_URI'] ) ? strtok( wp_unslash( $_SERVER['REQUEST_URI'] ), '?' ) : '/',
			),
			wp_parse_url( $location )
		);

		$absolute_location = '';
		if ( 'https' === $parsed_location['scheme'] ) {
			$absolute_location .= $parsed_location['scheme'] . ':';
		}
		$absolute_location .= '//' . $parsed_location['host'];
		if ( isset( $parsed_location['port'] ) ) {
			$absolute_location .= ':' . $parsed_location['port'];
		}
		$absolute_location .= $parsed_location['path'];
		if ( isset( $parsed_location['query'] ) ) {
			$absolute_location .= '?' . $parsed_location['query'];
		}
		if ( isset( $parsed_location['fragment'] ) ) {
			$absolute_location .= '#' . $parsed_location['fragment'];
		}

		AMP_Response_Headers::send_header( 'AMP-Redirect-To', $absolute_location );
		AMP_Response_Headers::send_header( 'Access-Control-Expose-Headers', 'AMP-Redirect-To' );

		wp_send_json_success();
	}

	/**
	 * Register/override widgets.
	 *
	 * @global WP_Widget_Factory
	 * @return void
	 */
	public static function register_widgets() {
		global $wp_widget_factory;
		foreach ( $wp_widget_factory->widgets as $registered_widget ) {
			$registered_widget_class_name = get_class( $registered_widget );
			if ( ! preg_match( '/^WP_Widget_(.+)$/', $registered_widget_class_name, $matches ) ) {
				continue;
			}
			$amp_class_name = 'AMP_Widget_' . $matches[1];
			if ( ! class_exists( $amp_class_name ) || is_a( $amp_class_name, $registered_widget_class_name ) ) {
				continue;
			}

			unregister_widget( $registered_widget_class_name );
			register_widget( $amp_class_name );
		}
	}

	/**
	 * Register content embed handlers.
	 *
	 * This was copied from `AMP_Content::register_embed_handlers()` due to being a private method
	 * and due to `AMP_Content` not being well suited for use in AMP canonical.
	 *
	 * @see AMP_Content::register_embed_handlers()
	 * @global int $content_width
	 * @return AMP_Base_Embed_Handler[] Handlers.
	 */
	public static function register_content_embed_handlers() {
		global $content_width;

		$embed_handlers = array();
		foreach ( amp_get_content_embed_handlers() as $embed_handler_class => $args ) {

			/**
			 * Embed handler.
			 *
			 * @type AMP_Base_Embed_Handler $embed_handler
			 */
			$embed_handler = new $embed_handler_class( array_merge(
				array(
					'content_max_width' => ! empty( $content_width ) ? $content_width : AMP_Post_Template::CONTENT_MAX_WIDTH, // Back-compat.
				),
				$args
			) );

			if ( ! is_subclass_of( $embed_handler, 'AMP_Base_Embed_Handler' ) ) {
				/* translators: %s is embed handler */
				_doing_it_wrong( __METHOD__, esc_html( sprintf( __( 'Embed Handler (%s) must extend `AMP_Embed_Handler`', 'amp' ), $embed_handler_class ) ), '0.1' );
				continue;
			}

			$embed_handler->register_embed();
			$embed_handlers[] = $embed_handler;
		}

		return $embed_handlers;
	}

	/**
	 * Add the comments template placeholder marker
	 *
	 * @param array $args the args for the comments list..
	 * @return array Args to return.
	 */
	public static function set_comments_walker( $args ) {
		$amp_walker     = new AMP_Comment_Walker();
		$args['walker'] = $amp_walker;
		return $args;
	}

	/**
	 * Adds the form submit success and fail templates.
	 */
	public static function amend_comment_form() {
		?>
		<?php if ( is_singular() && ! amp_is_canonical() ) : ?>
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( amp_get_permalink( get_the_ID() ) ); ?>">
		<?php endif; ?>

		<div submit-success>
			<template type="amp-mustache">
				<p>{{{message}}}</p>
			</template>
		</div>
		<div submit-error>
			<template type="amp-mustache">
				<p class="amp-comment-submit-error">{{{error}}}</p>
			</template>
		</div>
		<?php
	}

	/**
	 * Prepends template hierarchy with template_dir for AMP paired mode templates.
	 *
	 * @see get_query_template()
	 *
	 * @param array $templates Template hierarchy.
	 * @return array Templates.
	 */
	public static function filter_paired_template_hierarchy( $templates ) {
		$support = get_theme_support( 'amp' );
		$args    = array_shift( $support );
		if ( isset( $args['template_dir'] ) ) {
			$amp_templates = array();
			foreach ( $templates as $template ) {
				$amp_templates[] = $args['template_dir'] . '/' . $template;
			}
			$templates = $amp_templates;
		}
		return $templates;
	}

	/**
	 * Redirect to the non-canonical URL when the template to include is empty.
	 *
	 * This is a failsafe in case an index.php is not located in the AMP template_dir,
	 * and the available_callback fails to omit a given request from being available in AMP.
	 *
	 * @param string $template Template to include.
	 * @return string Template to include.
	 */
	public static function filter_paired_template_include( $template ) {
		if ( ! is_singular( AMP_Story_Post_Type::POST_TYPE_SLUG ) && ( empty( $template ) || ! self::is_paired_available() ) ) {
			wp_safe_redirect( self::get_current_canonical_url(), 302 ); // Temporary redirect because support may come later.
			exit;
		}
		return $template;
	}

	/**
	 * Get canonical URL for current request.
	 *
	 * @see rel_canonical()
	 * @global WP $wp
	 * @global WP_Rewrite $wp_rewrite
	 * @link https://www.ampproject.org/docs/reference/spec#canon.
	 * @link https://core.trac.wordpress.org/ticket/18660
	 *
	 * @return string Canonical non-AMP URL.
	 */
	public static function get_current_canonical_url() {
		global $wp, $wp_rewrite;

		$url = null;
		if ( is_singular() ) {
			$url = wp_get_canonical_url();
		}

		// For non-singular queries, make use of the request URI and public query vars to determine canonical URL.
		if ( empty( $url ) ) {
			$added_query_vars = $wp->query_vars;
			if ( ! $wp_rewrite->permalink_structure || empty( $wp->request ) ) {
				$url = home_url( '/' );
			} else {
				$url = home_url( user_trailingslashit( $wp->request ) );
				parse_str( $wp->matched_query, $matched_query_vars );
				foreach ( $wp->query_vars as $key => $value ) {

					// Remove query vars that were matched in the rewrite rules for the request.
					if ( isset( $matched_query_vars[ $key ] ) ) {
						unset( $added_query_vars[ $key ] );
					}
				}
			}
		}

		if ( ! empty( $added_query_vars ) ) {
			$url = add_query_arg( $added_query_vars, $url );
		}

		return amp_remove_endpoint( $url );
	}

	/**
	 * Get the ID for the amp-state.
	 *
	 * @since 0.7
	 *
	 * @param int $post_id Post ID.
	 * @return string ID for amp-state.
	 */
	public static function get_comment_form_state_id( $post_id ) {
		return sprintf( 'commentform_post_%d', $post_id );
	}

	/**
	 * Filter comment form args to an element with [text] AMP binding wrap the title reply.
	 *
	 * @since 0.7
	 * @see comment_form()
	 *
	 * @param array $args Comment form args.
	 * @return array Filtered comment form args.
	 */
	public static function filter_comment_form_defaults( $args ) {
		$state_id = self::get_comment_form_state_id( get_the_ID() );

		$text_binding = sprintf(
			'%s.replyToName ? %s : %s',
			$state_id,
			str_replace(
				'%s',
				sprintf( '" + %s.replyToName + "', $state_id ),
				wp_json_encode( $args['title_reply_to'] )
			),
			wp_json_encode( $args['title_reply'] )
		);

		$args['title_reply_before'] .= sprintf(
			'<span [text]="%s">',
			esc_attr( $text_binding )
		);
		$args['cancel_reply_before'] = '</span>' . $args['cancel_reply_before'];
		return $args;
	}

	/**
	 * Modify the comment reply link for AMP.
	 *
	 * @since 0.7
	 * @see get_comment_reply_link()
	 *
	 * @param string     $link    The HTML markup for the comment reply link.
	 * @param array      $args    An array of arguments overriding the defaults.
	 * @param WP_Comment $comment The object of the comment being replied.
	 * @return string Comment reply link.
	 */
	public static function filter_comment_reply_link( $link, $args, $comment ) {

		// Continue to show default link to wp-login when user is not logged-in.
		if ( get_option( 'comment_registration' ) && ! is_user_logged_in() ) {
			return $args['before'] . $link . $args['after'];
		}

		$state_id  = self::get_comment_form_state_id( get_the_ID() );
		$tap_state = array(
			$state_id => array(
				'replyToName' => $comment->comment_author,
				'values'      => array(
					'comment_parent' => (string) $comment->comment_ID,
				),
			),
		);

		// @todo Figure out how to support add_below. Instead of moving the form, what about letting the form get a fixed position?
		$link = sprintf(
			'<a rel="nofollow" class="comment-reply-link" href="%s" on="%s" aria-label="%s">%s</a>',
			esc_attr( '#' . $args['respond_id'] ),
			esc_attr( sprintf( 'tap:AMP.setState( %s )', wp_json_encode( $tap_state ) ) ),
			esc_attr( sprintf( $args['reply_to_text'], $comment->comment_author ) ),
			$args['reply_text']
		);
		return $args['before'] . $link . $args['after'];
	}

	/**
	 * Filters the cancel comment reply link HTML.
	 *
	 * @since 0.7
	 * @see get_cancel_comment_reply_link()
	 *
	 * @param string $formatted_link The HTML-formatted cancel comment reply link.
	 * @param string $link           Cancel comment reply link URL.
	 * @param string $text           Cancel comment reply link text.
	 * @return string Cancel reply link.
	 */
	public static function filter_cancel_comment_reply_link( $formatted_link, $link, $text ) {
		unset( $formatted_link, $link );
		if ( empty( $text ) ) {
			$text = __( 'Click here to cancel reply.', 'default' );
		}

		$state_id  = self::get_comment_form_state_id( get_the_ID() );
		$tap_state = array(
			$state_id => array(
				'replyToName' => '',
				'values'      => array(
					'comment_parent' => '0',
				),
			),
		);

		$respond_id = 'respond'; // Hard-coded in comment_form() and default value in get_comment_reply_link().
		return sprintf(
			'<a id="cancel-comment-reply-link" href="%s" %s [hidden]="%s" on="%s">%s</a>',
			esc_url( remove_query_arg( 'replytocom' ) . '#' . $respond_id ),
			isset( $_GET['replytocom'] ) ? '' : ' hidden', // phpcs:ignore
			esc_attr( sprintf( '%s.values.comment_parent == "0"', self::get_comment_form_state_id( get_the_ID() ) ) ),
			esc_attr( sprintf( 'tap:AMP.setState( %s )', wp_json_encode( $tap_state ) ) ),
			esc_html( $text )
		);
	}

	/**
	 * Print AMP boilerplate and custom styles.
	 */
	public static function print_amp_styles() {
		echo amp_get_boilerplate_code() . "\n"; // WPCS: XSS OK.
		echo "<style amp-custom></style>\n"; // This will by populated by AMP_Style_Sanitizer.
	}

	/**
	 * Ensure markup required by AMP <https://www.ampproject.org/docs/reference/spec#required-markup>.
	 *
	 * Ensure meta[charset], meta[name=viewport], and link[rel=canonical]; a the whitelist sanitizer
	 * may have removed an illegal meta[http-equiv] or meta[name=viewport]. Core only outputs a
	 * canonical URL by default if a singular post.
	 *
	 * @since 0.7
	 * @todo All of this might be better placed inside of a sanitizer.
	 *
	 * @param DOMDocument $dom Doc.
	 */
	public static function ensure_required_markup( DOMDocument $dom ) {
		/**
		 * Elements.
		 *
		 * @var DOMElement $meta
		 * @var DOMElement $script
		 * @var DOMElement $link
		 */
		$head = $dom->getElementsByTagName( 'head' )->item( 0 );
		if ( ! $head ) {
			$head = $dom->createElement( 'head' );
			$dom->documentElement->insertBefore( $head, $dom->documentElement->firstChild );
		}
		$meta_charset  = null;
		$meta_viewport = null;
		foreach ( $head->getElementsByTagName( 'meta' ) as $meta ) {
			if ( $meta->hasAttribute( 'charset' ) && 'utf-8' === strtolower( $meta->getAttribute( 'charset' ) ) ) { // @todo Also look for meta[http-equiv="Content-Type"]?
				$meta_charset = $meta;
			} elseif ( 'viewport' === $meta->getAttribute( 'name' ) ) {
				$meta_viewport = $meta;
			}
		}
		if ( ! $meta_charset ) {
			// Warning: This probably means the character encoding needs to be converted.
			$meta_charset = AMP_DOM_Utils::create_node( $dom, 'meta', array(
				'charset' => 'utf-8',
			) );
			$head->insertBefore( $meta_charset, $head->firstChild );
		}
		if ( ! $meta_viewport ) {
			$meta_viewport = AMP_DOM_Utils::create_node( $dom, 'meta', array(
				'name'    => 'viewport',
				'content' => 'width=device-width,minimum-scale=1',
			) );
			$head->insertBefore( $meta_viewport, $meta_charset->nextSibling );
		}
		// Prevent schema.org duplicates.
		$has_schema_org_metadata = false;
		foreach ( $head->getElementsByTagName( 'script' ) as $script ) {
			if ( 'application/ld+json' === $script->getAttribute( 'type' ) && false !== strpos( $script->nodeValue, 'schema.org' ) ) {
				$has_schema_org_metadata = true;
				break;
			}
		}
		if ( ! $has_schema_org_metadata ) {
			$script = $dom->createElement( 'script', wp_json_encode( amp_get_schemaorg_metadata() ) );
			$script->setAttribute( 'type', 'application/ld+json' );
			$head->appendChild( $script );
		}
		// Ensure rel=canonical link.
		$rel_canonical = null;
		foreach ( $head->getElementsByTagName( 'link' ) as $link ) {
			if ( 'canonical' === $link->getAttribute( 'rel' ) ) {
				$rel_canonical = $link;
				break;
			}
		}
		if ( ! $rel_canonical ) {
			$rel_canonical = AMP_DOM_Utils::create_node( $dom, 'link', array(
				'rel'  => 'canonical',
				'href' => self::get_current_canonical_url(),
			) );
			$head->appendChild( $rel_canonical );
		}
	}

	/**
	 * Dequeue Customizer assets which are not necessary outside the preview iframe.
	 *
	 * Prevent enqueueing customize-preview styles if not in customizer preview iframe.
	 * These are only needed for when there is live editing of content, such as selective refresh.
	 *
	 * @since 0.7
	 */
	public static function dequeue_customize_preview_scripts() {

		// Dequeue styles unnecessary unless in customizer preview iframe when editing (such as for edit shortcuts).
		if ( ! self::is_customize_preview_iframe() ) {
			wp_dequeue_style( 'customize-preview' );
			foreach ( wp_styles()->registered as $handle => $dependency ) {
				if ( in_array( 'customize-preview', $dependency->deps, true ) ) {
					wp_dequeue_style( $handle );
				}
			}
		}
	}

	/**
	 * Start output buffering.
	 *
	 * @since 0.7
	 * @see AMP_Theme_Support::finish_output_buffering()
	 */
	public static function start_output_buffering() {
		/*
		 * Disable the New Relic Browser agent on AMP responses.
		 * This prevents the New Relic from causing invalid AMP responses due the NREUM script it injects after the meta charset:
		 * https://docs.newrelic.com/docs/browser/new-relic-browser/troubleshooting/google-amp-validator-fails-due-3rd-party-script
		 * Sites with New Relic will need to specially configure New Relic for AMP:
		 * https://docs.newrelic.com/docs/browser/new-relic-browser/installation/monitor-amp-pages-new-relic-browser
		 */
		if ( function_exists( 'newrelic_disable_autorum' ) ) {
			newrelic_disable_autorum();
		}

		ob_start( array( __CLASS__, 'finish_output_buffering' ) );
		self::$is_output_buffering = true;
	}

	/**
	 * Determine whether output buffering has started.
	 *
	 * @since 0.7
	 * @see AMP_Theme_Support::start_output_buffering()
	 * @see AMP_Theme_Support::finish_output_buffering()
	 *
	 * @return bool Whether output buffering has started.
	 */
	public static function is_output_buffering() {
		return self::$is_output_buffering;
	}

	/**
	 * Finish output buffering.
	 *
	 * @since 0.7
	 * @see AMP_Theme_Support::start_output_buffering()
	 *
	 * @param string $response Buffered Response.
	 * @return string Processed Response.
	 */
	public static function finish_output_buffering( $response ) {
		self::$is_output_buffering = false;
		return self::prepare_response( $response );
	}

	/**
	 * Filter rendered partial to convert to AMP.
	 *
	 * @see WP_Customize_Partial::render()
	 *
	 * @param string|mixed $partial Rendered partial.
	 * @return string|mixed Filtered partial.
	 * @global int $content_width
	 */
	public static function filter_customize_partial_render( $partial ) {
		global $content_width;
		if ( is_string( $partial ) && preg_match( '/<\w/', $partial ) ) {
			$dom  = AMP_DOM_Utils::get_dom_from_content( $partial );
			$args = array(
				'content_max_width'    => ! empty( $content_width ) ? $content_width : AMP_Post_Template::CONTENT_MAX_WIDTH, // Back-compat.
				'use_document_element' => false,
				'allow_dirty_styles'   => true,
				'allow_dirty_scripts'  => false,
			);
			AMP_Content_Sanitizer::sanitize_document( $dom, self::$sanitizer_classes, $args ); // @todo Include script assets in response?
			$partial = AMP_DOM_Utils::get_content_from_dom( $dom );
		}
		return $partial;
	}

	/**
	 * Process response to ensure AMP validity.
	 *
	 * @since 0.7
	 *
	 * @param string $response HTML document response. By default it expects a complete document.
	 * @param array  $args     Args to send to the preprocessor/sanitizer.
	 * @return string AMP document response.
	 * @global int $content_width
	 */
	public static function prepare_response( $response, $args = array() ) {
		global $content_width;

		if ( isset( $args['validation_error_callback'] ) ) {
			_doing_it_wrong( __METHOD__, 'Do not supply validation_error_callback arg.', '1.0' );
			unset( $args['validation_error_callback'] );
		}

		/*
		 * Check if the response starts with HTML markup.
		 * Without this check, JSON responses will be erroneously corrupted,
		 * being wrapped in HTML documents.
		 */
		if ( '<' !== substr( ltrim( $response ), 0, 1 ) ) {
			return $response;
		}

		$args = array_merge(
			array(
				'content_max_width'       => ! empty( $content_width ) ? $content_width : AMP_Post_Template::CONTENT_MAX_WIDTH, // Back-compat.
				'use_document_element'    => true,
				'allow_dirty_styles'      => self::is_customize_preview_iframe(), // Dirty styles only needed when editing (e.g. for edit shortcodes).
				'allow_dirty_scripts'     => is_customize_preview(), // Scripts are always needed to inject changeset UUID.
				'enable_response_caching' => (
					! ( defined( 'WP_DEBUG' ) && WP_DEBUG )
					&&
					! AMP_Validation_Manager::should_validate_response()
				),
			),
			$args
		);

		// Return cache if enabled and found.
		$response_cache_key = null;
		if ( true === $args['enable_response_caching'] ) {
			// Set response cache hash, the data values dictates whether a new hash key should be generated or not.
			$response_cache_key = md5( wp_json_encode( array(
				$args,
				$response,
				self::$sanitizer_classes,
				self::$embed_handlers,
				AMP__VERSION,
			) ) );

			$response_cache = wp_cache_get( $response_cache_key, self::RESPONSE_CACHE_GROUP );

			// Make sure that all of the validation errors should be sanitized in the same way; if not, then the cached body should be discarded.
			if ( isset( $response_cache['validation_results'] ) ) {
				foreach ( $response_cache['validation_results'] as $validation_result ) {
					$should_sanitize = AMP_Validation_Error_Taxonomy::is_validation_error_sanitized( $validation_result['error'] );
					if ( $should_sanitize !== $validation_result['sanitized'] ) {
						unset( $response_cache['body'] );
						break;
					}
				}
			}

			// Short-circuit response with cached body.
			if ( isset( $response_cache['body'] ) ) {
				return $response_cache['body'];
			}
		}

		AMP_Response_Headers::send_server_timing( 'amp_output_buffer', -self::$init_start_time, 'AMP Output Buffer' );

		$dom_parse_start = microtime( true );

		/*
		 * Make sure that <meta charset> is present in output prior to parsing.
		 * Note that the meta charset is supposed to appear within the first 1024 bytes.
		 * See <https://www.w3.org/International/questions/qa-html-encoding-declarations>.
		 */
		if ( ! preg_match( '#<meta[^>]+charset=#i', substr( $response, 0, 1024 ) ) ) {
			$response = preg_replace(
				'/(<head[^>]*>)/i',
				'$1' . sprintf( '<meta charset="%s">', esc_attr( get_bloginfo( 'charset' ) ) ),
				$response,
				1
			);
		}

		$dom  = AMP_DOM_Utils::get_dom( $response );
		$head = $dom->getElementsByTagName( 'head' )->item( 0 );

		// Make sure scripts from the body get moved to the head.
		if ( isset( $head ) ) {
			$xpath = new DOMXPath( $dom );
			foreach ( $xpath->query( '//body//script[ @custom-element or @custom-template ]' ) as $script ) {
				$head->appendChild( $script );
			}
		}

		// Ensure the mandatory amp attribute is present on the html element, as otherwise it will be stripped entirely.
		if ( ! $dom->documentElement->hasAttribute( 'amp' ) && ! $dom->documentElement->hasAttribute( '⚡️' ) ) {
			$dom->documentElement->setAttribute( 'amp', '' );
		}

		AMP_Response_Headers::send_server_timing( 'amp_dom_parse', -$dom_parse_start, 'AMP DOM Parse' );

		$assets = AMP_Content_Sanitizer::sanitize_document( $dom, self::$sanitizer_classes, $args );

		$dom_serialize_start = microtime( true );
		self::ensure_required_markup( $dom );

		$blocking_error_count = AMP_Validation_Manager::count_blocking_validation_errors();
		if ( ! AMP_Validation_Manager::should_validate_response() && $blocking_error_count > 0 ) {

			// Note the canonical check will not currently ever be met because dirty AMP is not yet supported; all validation errors will forcibly be sanitized.
			if ( amp_is_canonical() ) {
				$dom->documentElement->removeAttribute( 'amp' );

				/*
				 * Make sure that document.write() is disabled to prevent dynamically-added content (such as added
				 * via amp-live-list) from wiping out the page by introducing any scripts that call this function.
				 */
				if ( $head ) {
					$script = $dom->createElement( 'script' );
					$script->appendChild( $dom->createTextNode( 'document.addEventListener( "DOMContentLoaded", function() { document.write = function( text ) { throw new Error( "[AMP-WP] Prevented document.write() call with: "  + text ); }; } );' ) );
					$head->appendChild( $script );
				}
			} else {
				$current_url = amp_get_current_url();
				$ampless_url = amp_remove_endpoint( $current_url );
				if ( AMP_Validation_Manager::has_cap() ) {
					$ampless_url = add_query_arg(
						AMP_Validation_Manager::VALIDATION_ERRORS_QUERY_VAR,
						$blocking_error_count,
						$ampless_url
					);
				}

				/*
				 * Temporary redirect because AMP URL may return when blocking validation errors
				 * occur or when a non-canonical AMP theme is used.
				 */
				wp_safe_redirect( $ampless_url, 302 );
				return esc_html__( 'Redirecting to non-AMP version.', 'amp' );
			}
		}

		// @todo If 'utf-8' is not the blog charset, then we'll need to do some character encoding conversation or "entityification".
		if ( 'utf-8' !== strtolower( get_bloginfo( 'charset' ) ) ) {
			/* translators: %s is the charset of the current site */
			trigger_error( esc_html( sprintf( __( 'The database has the %s encoding when it needs to be utf-8 to work with AMP.', 'amp' ), get_bloginfo( 'charset' ) ) ), E_USER_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
		}

		if ( AMP_Validation_Manager::should_validate_response() ) {
			AMP_Validation_Manager::finalize_validation( $dom, array(
				'remove_source_comments' => ! isset( $_GET['amp_preserve_source_comments'] ), // WPCS: CSRF.
			) );
		}

		$response  = "<!DOCTYPE html>\n";
		$response .= AMP_DOM_Utils::get_content_from_dom_node( $dom, $dom->documentElement );

		$amp_scripts = $assets['scripts'];
		foreach ( self::$embed_handlers as $embed_handler ) {
			$amp_scripts = array_merge(
				$amp_scripts,
				$embed_handler->get_scripts()
			);
		}

		// Allow for embed handlers to override the default extension version by defining a different URL.
		foreach ( $amp_scripts as $handle => $value ) {
			if ( is_string( $value ) && wp_script_is( $handle, 'registered' ) ) {
				wp_scripts()->registered[ $handle ]->src = $value;
			}
		}

		/*
		 * Inject additional AMP component scripts which have been discovered by the sanitizers into the head.
		 * This is adapted from wp_scripts()->do_items(), but it runs only the bare minimum required to output
		 * the missing scripts, without allowing other filters to apply which may cause an invalid AMP response.
		 */
		$script_tags = '';
		foreach ( array_diff( array_keys( $amp_scripts ), wp_scripts()->done ) as $handle ) {
			if ( ! wp_script_is( $handle, 'registered' ) ) {
				continue;
			}
			$script_dep   = wp_scripts()->registered[ $handle ];
			$script_tags .= amp_filter_script_loader_tag(
				sprintf(
					"<script type='text/javascript' src='%s'></script>\n", // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript
					esc_url( $script_dep->src )
				),
				$handle
			);
		}
		if ( ! empty( $script_tags ) ) {
			$response = preg_replace(
				'#(?=</head>)#',
				$script_tags,
				$response,
				1
			);
		}

		AMP_Response_Headers::send_server_timing( 'amp_dom_serialize', -$dom_serialize_start, 'AMP DOM Serialize' );

		// Cache response if enabled.
		if ( true === $args['enable_response_caching'] ) {
			$response_cache = array(
				'body'               => $response,
				'validation_results' => array_map(
					function( $result ) {
						unset( $result['error']['sources'] );
						return $result;
					},
					AMP_Validation_Manager::$validation_results
				),
			);
			wp_cache_set( $response_cache_key, $response_cache, self::RESPONSE_CACHE_GROUP, MONTH_IN_SECONDS );
		}

		return $response;
	}

	/**
	 * Adds 'data-amp-layout' to the allowed <img> attributes for wp_kses().
	 *
	 * @since 0.7
	 *
	 * @param array $context Allowed tags and their allowed attributes.
	 * @return array $context Filtered allowed tags and attributes.
	 */
	public static function whitelist_layout_in_wp_kses_allowed_html( $context ) {
		if ( ! empty( $context['img']['width'] ) && ! empty( $context['img']['height'] ) ) {
			$context['img']['data-amp-layout'] = true;
		}

		return $context;
	}

	/**
	 * Enqueue AMP assets if this is an AMP endpoint.
	 *
	 * @since 0.7
	 *
	 * @return void
	 */
	public static function enqueue_assets() {
		wp_enqueue_script( 'amp-runtime' );

		// Enqueue default styles expected by sanitizer.
		wp_enqueue_style( 'amp-default', amp_get_asset_url( 'css/amp-default.css' ), array(), AMP__VERSION );
	}

	/**
	 * Conditionally replace the header image markup with a header video or image.
	 *
	 * This is JS-driven in Core themes like Twenty Sixteen and Twenty Seventeen.
	 * So in order for the header video to display, this replaces the markup of the header image.
	 *
	 * @since 1.0
	 * @link https://github.com/WordPress/wordpress-develop/blob/d002fde80e5e3a083e5f950313163f566561517f/src/wp-includes/js/wp-custom-header.js#L54
	 * @link https://github.com/WordPress/wordpress-develop/blob/d002fde80e5e3a083e5f950313163f566561517f/src/wp-includes/js/wp-custom-header.js#L78
	 *
	 * @param string $image_markup The image markup to filter.
	 * @return string $html Filtered markup.
	 */
	public static function amend_header_image_with_video_header( $image_markup ) {

		// If there is no video, just pass the image through.
		if ( ! has_header_video() || ! is_header_video_active() ) {
			return $image_markup;
		};

		$video_settings   = get_header_video_settings();
		$parsed_url       = wp_parse_url( $video_settings['videoUrl'] );
		$query            = isset( $parsed_url['query'] ) ? wp_parse_args( $parsed_url['query'] ) : array();
		$video_attributes = array(
			'media'    => '(min-width: ' . $video_settings['minWidth'] . 'px)',
			'width'    => $video_settings['width'],
			'height'   => $video_settings['height'],
			'layout'   => 'responsive',
			'autoplay' => '',
			'id'       => 'wp-custom-header-video',
		);

		$youtube_id = null;
		if ( isset( $parsed_url['host'] ) && preg_match( '/(^|\.)(youtube\.com|youtu\.be)$/', $parsed_url['host'] ) ) {
			if ( 'youtu.be' === $parsed_url['host'] && ! empty( $parsed_url['path'] ) ) {
				$youtube_id = trim( $parsed_url['path'], '/' );
			} elseif ( isset( $query['v'] ) ) {
				$youtube_id = $query['v'];
			}
		}

		// If the video URL is for YouTube, return an <amp-youtube> element.
		if ( ! empty( $youtube_id ) ) {
			$video_markup = AMP_HTML_Utils::build_tag(
				'amp-youtube',
				array_merge(
					$video_attributes,
					array(
						'data-videoid'        => $youtube_id,
						'data-param-rel'      => '0', // Don't show related videos.
						'data-param-showinfo' => '0', // Don't show video title at the top.
						'data-param-controls' => '0', // Don't show video controls.
					)
				)
			);
		} else {
			$video_markup = AMP_HTML_Utils::build_tag(
				'amp-video',
				array_merge(
					$video_attributes,
					array(
						'src' => $video_settings['videoUrl'],
					)
				)
			);
		}

		return $image_markup . $video_markup;
	}
}
