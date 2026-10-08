<?php
/**
 * Core plugin class for Agentic Markdown Bridge.
 *
 * @package AgenticMarkdownBridge
 */

defined( 'ABSPATH' ) || exit;

final class Agentic_Markdown_Bridge {
	/** Request-local normalized settings cache. */
	private static $settings_cache = null;
	const VERSION             = AGENTIC_MB_VERSION;
	const QUERY_VAR           = 'agentic_mb_markdown_path';
	const QUERY_ID_VAR        = 'agentic_mb_markdown_id';
	const QUERY_LLMS_VAR      = 'agentic_mb_llms_txt';
	const REWRITE_VERSION     = '5';
	const REWRITE_OPTION      = 'agentic_markdown_bridge_rewrite_version';
	const SETTINGS_OPTION     = 'agentic_markdown_bridge_settings';
	const TITLE_MODE_META     = '_agentic_mb_title_mode';
	const PRE_H1_MODE_META    = '_agentic_mb_pre_h1_mode';
	const ENABLED_MODE_META   = '_agentic_mb_enabled_mode';
	const CUSTOM_MODE_META    = '_agentic_mb_custom_mode';
	const CUSTOM_MARKDOWN_META = '_agentic_mb_custom_markdown';
	const VIEW_COUNT_META     = '_agentic_mb_view_count';
	const LLMS_VIEWS_OPTION   = 'agentic_markdown_bridge_llms_views';
	const CACHE_PREFIX        = 'agentic_mb_cache_';
	const CACHE_EPOCH_OPTION  = 'agentic_markdown_bridge_cache_epoch';
	const LEGACY_CACHE_KEYS_OPTION = 'agentic_markdown_bridge_cache_keys';
	const CACHE_EPOCH_META    = '_agentic_mb_cache_epoch';

	/** Bootstrap only the hooks required by the current configuration. */
	public static function init() {
		$settings         = self::get_settings();
		$markdown_enabled = ! empty( $settings['enabled'] );
		$managed_llms     = ! empty( $settings['manage_llms'] );

		// Rewrite/query hooks are unnecessary when both virtual resources are disabled.
		if ( $markdown_enabled || $managed_llms ) {
			add_action( 'init', array( __CLASS__, 'register_rewrites' ) );
			add_filter( 'query_vars', array( __CLASS__, 'register_query_vars' ) );
		}

		if ( $managed_llms ) {
			add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_llms_txt' ), -5 );
		}

		if ( $markdown_enabled ) {
			add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_markdown' ), -4 );
			if ( ! empty( $settings['content_negotiation'] ) ) {
				add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_negotiated_markdown' ), -3 );
			}
			if ( ! empty( $settings['frontend_button'] ) ) {
				add_action( 'wp_footer', array( __CLASS__, 'print_frontend_markdown_actions' ), 100 );
			}
		}

		if ( ( $markdown_enabled && ! empty( $settings['alternate_link'] ) ) || ! empty( $settings['describedby_link'] ) || $managed_llms ) {
			add_action( 'wp_head', array( __CLASS__, 'print_discovery_links' ), 2 );
		}

		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'maybe_flush_rewrites' ) );
		}
		add_action( 'update_option_' . self::SETTINGS_OPTION, array( __CLASS__, 'maybe_flush_rewrites_after_settings_update' ), 10, 2 );

		if ( is_multisite() ) {
			add_action( 'switch_blog', array( __CLASS__, 'reset_settings_cache' ), 10, 0 );
		}
	}

	/** Activate plugin, including network activation. */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			$sites = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
			foreach ( $sites as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::activate_site();
				restore_current_blog();
			}
			return;
		}
		self::activate_site();
	}

	/** Activate for current site. */
	private static function activate_site() {
		self::$settings_cache = null;
		if ( false === get_option( self::SETTINGS_OPTION, false ) ) {
			add_option( self::SETTINGS_OPTION, self::get_default_settings(), '', false );
		}
		self::register_rewrites();
		flush_rewrite_rules( false );
		update_option( self::REWRITE_OPTION, self::REWRITE_VERSION, false );
	}

	/** Deactivate plugin. */
	public static function deactivate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			$sites = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
			foreach ( $sites as $site_id ) {
				switch_to_blog( (int) $site_id );
				flush_rewrite_rules( false );
				restore_current_blog();
			}
			return;
		}
		flush_rewrite_rules( false );
	}

	/** Register only the virtual routes currently enabled by the site. */
	public static function register_rewrites() {
		$settings = self::get_settings();
		if ( ! empty( $settings['manage_llms'] ) ) {
			add_rewrite_rule( '^llms\.txt/?$', 'index.php?' . self::QUERY_LLMS_VAR . '=1', 'top' );
		}
		if ( ! empty( $settings['enabled'] ) ) {
			add_rewrite_rule( '^index\.md/?$', 'index.php?' . self::QUERY_VAR . '=__front__', 'top' );
			add_rewrite_rule( '^(.+?)/index\.md/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
		}
	}

	/** Register custom query vars. */
	public static function register_query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		$vars[] = self::QUERY_ID_VAR;
		$vars[] = self::QUERY_LLMS_VAR;
		return $vars;
	}

	/** Flush rewrite rules once after rewrite changes. */
	public static function maybe_flush_rewrites() {
		if ( ! current_user_can( 'manage_options' ) || self::REWRITE_VERSION === get_option( self::REWRITE_OPTION ) ) {
			return;
		}
		self::register_rewrites();
		flush_rewrite_rules( false );
		update_option( self::REWRITE_OPTION, self::REWRITE_VERSION, false );
	}

	/** Refresh rewrites only when virtual endpoint availability changes. */
	public static function maybe_flush_rewrites_after_settings_update( $old_value, $new_value ) {
		self::$settings_cache = null;
		$old_value = is_array( $old_value ) ? $old_value : array();
		$new_value = is_array( $new_value ) ? $new_value : array();

		$markdown_changed = ! empty( $old_value['enabled'] ) !== ! empty( $new_value['enabled'] );
		$llms_changed     = ! empty( $old_value['manage_llms'] ) !== ! empty( $new_value['manage_llms'] );
		if ( ! $markdown_changed && ! $llms_changed ) {
			return;
		}

		self::register_rewrites();
		flush_rewrite_rules( false );
	}

	/** Default plugin settings. */
	public static function get_default_settings() {
		return array(
			'enabled'                    => 1,
			'post_types'                 => array( 'post', 'page' ),
			'title_mode'                 => 'auto',
			'pre_h1_mode'                => 'ignore',
			'exclude_classes'             => '',
			'alternate_link'             => 1,
			'content_negotiation'        => 0,
			'front_matter'               => 0,
			'cache_enabled'              => 1,
			'cache_ttl'                  => 43200,
			'http_cache_seconds'         => 300,
			'x_robots'                   => 'none',
			'analytics'                  => 0,
			'frontend_button'            => 0,
			'button_label'               => __( 'View Markdown', 'agentic-markdown-bridge' ),
			'button_copy'                => 0,
			'copy_label'                 => __( 'Copy Markdown', 'agentic-markdown-bridge' ),
			'button_llms'                => 0,
			'llms_button_label'          => __( 'View llms.txt', 'agentic-markdown-bridge' ),
			'button_position'            => 'bottom-center',
			'button_style'               => 'dark',
			'button_shape'               => 'pill',
			'describedby_link'           => 0,
			'llms_url'                   => '/llms.txt',
			'manage_llms'                => 0,
			'llms_content'               => '',
			'llms_generator_summary'     => '',
			'llms_generator_ids'         => array(),
			'llms_generator_use_markdown'=> 1,
		);
	}

	/** Get normalized settings once per request. */
	public static function get_settings() {
		if ( null !== self::$settings_cache ) {
			return self::$settings_cache;
		}
		$settings = get_option( self::SETTINGS_OPTION, array() );
		self::$settings_cache = wp_parse_args( is_array( $settings ) ? $settings : array(), self::get_default_settings() );
		return self::$settings_cache;
	}

	/** Reset request-local settings after an in-request update. */
	public static function reset_settings_cache() {
		self::$settings_cache = null;
	}

	/** Public post types available to the plugin. */
	public static function get_available_post_types() {
		$objects = get_post_types( array( 'public' => true ), 'objects' );
		unset( $objects['attachment'] );
		foreach ( $objects as $name => $object ) {
			if ( ! is_post_type_viewable( $object ) ) {
				unset( $objects[ $name ] );
			}
		}
		return $objects;
	}

	/** Return expected physical root llms.txt path. */
	public static function get_physical_llms_path() {
		return wp_normalize_path( trailingslashit( ABSPATH ) . 'llms.txt' );
	}

	/** Whether physical llms.txt exists. */
	public static function physical_llms_exists() {
		return is_file( self::get_physical_llms_path() );
	}

	/** Whether physical llms.txt is a symlink. */
	public static function physical_llms_is_symlink() {
		return is_link( self::get_physical_llms_path() );
	}

	/** Local filesystem wrapper. */
	private static function get_local_filesystem() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		return new WP_Filesystem_Direct( null );
	}

	/** Read physical llms.txt. */
	public static function read_physical_llms_content() {
		if ( ! self::physical_llms_exists() || self::physical_llms_is_symlink() ) {
			return false;
		}
		try {
			$filesystem = self::get_local_filesystem();
			$path       = self::get_physical_llms_path();
			return $filesystem->is_readable( $path ) ? $filesystem->get_contents( $path ) : false;
		} catch ( Throwable $error ) {
			return false;
		}
	}

	/**
	 * Normalize user-authored Markdown without stripping Markdown syntax.
	 *
	 * The public representation is served as text/markdown with nosniff; this
	 * sanitizer therefore preserves Markdown and code samples while removing
	 * invalid UTF-8, NUL bytes, and unsafe ASCII control characters.
	 *
	 * @param mixed $value     Raw value.
	 * @param int   $max_bytes Maximum accepted byte length.
	 * @return string
	 */
	public static function sanitize_markdown_text( $value, $max_bytes = 2097152 ) {
		$value = is_string( $value ) ? $value : '';
		$value = wp_check_invalid_utf8( $value );
		$value = str_replace( array( "\r\n", "\r", "\0" ), array( "\n", "\n", '' ), $value );
		$value = preg_replace( '/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value );
		$value = is_string( $value ) ? $value : '';

		$max_bytes = max( 1024, absint( $max_bytes ) );
		if ( strlen( $value ) > $max_bytes ) {
			$value = substr( $value, 0, $max_bytes );
			$value = wp_check_invalid_utf8( $value );
		}

		return $value;
	}

	/** Return physical llms.txt modification time when available. */
	public static function get_physical_llms_modified_timestamp() {
		if ( ! self::physical_llms_exists() || self::physical_llms_is_symlink() ) {
			return 0;
		}
		try {
			$filesystem = self::get_local_filesystem();
			$modified   = $filesystem->mtime( self::get_physical_llms_path() );
			return $modified ? (int) $modified : 0;
		} catch ( Throwable $error ) {
			return 0;
		}
	}

	/** Physical llms.txt writable status. */
	public static function physical_llms_is_writable() {
		if ( ! self::physical_llms_exists() || self::physical_llms_is_symlink() ) {
			return false;
		}
		try {
			$filesystem = self::get_local_filesystem();
			return $filesystem->is_writable( self::get_physical_llms_path() );
		} catch ( Throwable $error ) {
			return false;
		}
	}

	/**
	 * Safely replace a physical llms.txt using a same-directory temp file and
	 * temporary backup. Symlinks are deliberately rejected.
	 *
	 * @param string $content Content to write.
	 * @return true|WP_Error
	 */
	public static function write_physical_llms_content( $content ) {
		$path = self::get_physical_llms_path();
		if ( ! self::physical_llms_exists() ) {
			return new WP_Error( 'agentic_mb_llms_missing', __( 'The physical llms.txt file no longer exists.', 'agentic-markdown-bridge' ) );
		}
		if ( self::physical_llms_is_symlink() ) {
			return new WP_Error( 'agentic_mb_llms_symlink', __( 'For safety, Agentic Markdown Bridge will not edit a symbolic-link llms.txt file.', 'agentic-markdown-bridge' ) );
		}
		if ( strlen( (string) $content ) > 2 * MB_IN_BYTES ) {
			return new WP_Error( 'agentic_mb_llms_too_large', __( 'llms.txt is larger than the 2 MB safety limit.', 'agentic-markdown-bridge' ) );
		}
		$root = wp_normalize_path( trailingslashit( ABSPATH ) );
		if ( 0 !== strpos( $path, $root ) || $path !== $root . 'llms.txt' ) {
			return new WP_Error( 'agentic_mb_llms_path', __( 'The llms.txt path failed the safety check.', 'agentic-markdown-bridge' ) );
		}

		try {
			$filesystem = self::get_local_filesystem();
			if ( ! $filesystem->is_writable( $path ) ) {
				return new WP_Error( 'agentic_mb_llms_not_writable', __( 'The physical llms.txt file is not writable by WordPress.', 'agentic-markdown-bridge' ) );
			}
			$chmod = $filesystem->getchmod( $path );
			$mode  = $chmod ? octdec( $chmod ) : 0644;
			$token = strtolower( wp_generate_password( 10, false, false ) );
			$tmp   = $path . '.amb-' . $token . '.tmp';
			$bak   = $path . '.amb-' . $token . '.bak';

			if ( ! $filesystem->put_contents( $tmp, (string) $content, $mode ) ) {
				return new WP_Error( 'agentic_mb_llms_temp_write', __( 'WordPress could not create the temporary llms.txt file.', 'agentic-markdown-bridge' ) );
			}
			if ( ! $filesystem->copy( $path, $bak, true, $mode ) ) {
				$filesystem->delete( $tmp, false, 'f' );
				return new WP_Error( 'agentic_mb_llms_backup', __( 'WordPress could not create a temporary backup before updating llms.txt.', 'agentic-markdown-bridge' ) );
			}
			if ( ! $filesystem->move( $tmp, $path, true ) ) {
				$filesystem->move( $bak, $path, true );
				$filesystem->delete( $tmp, false, 'f' );
				return new WP_Error( 'agentic_mb_llms_replace', __( 'WordPress could not replace llms.txt. The original file was restored.', 'agentic-markdown-bridge' ) );
			}
			$filesystem->chmod( $path, $mode );
			$filesystem->delete( $bak, false, 'f' );
		} catch ( Throwable $error ) {
			return new WP_Error( 'agentic_mb_llms_filesystem_error', __( 'WordPress could not update llms.txt because of a filesystem error.', 'agentic-markdown-bridge' ) );
		}
		return true;
	}

	/** Default starter llms.txt. */
	public static function get_default_llms_content() {
		$name        = trim( wp_strip_all_tags( (string) get_bloginfo( 'name' ) ) );
		$description = trim( wp_strip_all_tags( (string) get_bloginfo( 'description' ) ) );
		$name        = $name ? $name : 'Website';
		$content     = '# ' . $name . "\n";
		if ( $description ) {
			$content .= "\n> " . $description . "\n";
		}
		$content .= "\n## Important Pages\n\n- [Home](" . home_url( '/' ) . "): Main website.\n";
		return $content;
	}

	/** Generate an editable llms.txt draft from selected posts. */
	public static function generate_llms_content( $settings ) {
		$settings = wp_parse_args( is_array( $settings ) ? $settings : array(), self::get_settings() );
		$name     = trim( wp_strip_all_tags( (string) get_bloginfo( 'name' ) ) );
		$name     = $name ? $name : 'Website';
		$summary  = trim( (string) $settings['llms_generator_summary'] );
		if ( ! $summary ) {
			$summary = trim( wp_strip_all_tags( (string) get_bloginfo( 'description' ) ) );
		}
		$content = '# ' . $name . "\n";
		if ( $summary ) {
			$content .= "\n> " . preg_replace( '/\s+/u', ' ', $summary ) . "\n";
		}

		$ids = isset( $settings['llms_generator_ids'] ) && is_array( $settings['llms_generator_ids'] ) ? array_map( 'absint', $settings['llms_generator_ids'] ) : array();
		$ids = array_values( array_filter( array_unique( $ids ) ) );
		if ( ! $ids ) {
			$front = (int) get_option( 'page_on_front' );
			if ( $front ) {
				$ids[] = $front;
			}
			$pages = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 20, 'orderby' => array( 'menu_order' => 'ASC', 'date' => 'DESC' ), 'fields' => 'ids' ) );
			$ids   = array_values( array_unique( array_merge( $ids, $pages ) ) );
		}

		$groups = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || 'publish' !== $post->post_status || ! is_post_type_viewable( $post->post_type ) ) {
				continue;
			}
			$object = get_post_type_object( $post->post_type );
			$label  = $object && ! empty( $object->labels->name ) ? $object->labels->name : ucfirst( $post->post_type );
			$groups[ $label ][] = $post;
		}

		foreach ( $groups as $label => $posts ) {
			$content .= "\n## " . wp_strip_all_tags( $label ) . "\n\n";
			foreach ( $posts as $post ) {
				$url = ! empty( $settings['llms_generator_use_markdown'] ) && self::is_post_enabled( $post ) ? self::get_markdown_url( $post ) : get_permalink( $post );
				$description = trim( wp_strip_all_tags( get_the_excerpt( $post ) ) );
				if ( ! $description ) {
					$description = wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 28, '…' );
				}
				$title = wp_strip_all_tags( get_the_title( $post ) );
				$content .= '- [' . str_replace( array( '[', ']' ), '', $title ) . '](' . esc_url_raw( $url ) . ')';
				if ( $description ) {
					$content .= ': ' . preg_replace( '/\s+/u', ' ', $description );
				}
				$content .= "\n";
			}
		}
		return rtrim( $content ) . "\n";
	}

	/** llms.txt discovery URL. */
	public static function get_llms_txt_url() {
		$settings = self::get_settings();
		if ( ! empty( $settings['manage_llms'] ) ) {
			return (string) apply_filters( 'agentic_markdown_bridge_llms_txt_url', home_url( '/llms.txt' ) );
		}
		if ( empty( $settings['describedby_link'] ) ) {
			return '';
		}
		$raw = trim( (string) $settings['llms_url'] );
		if ( ! $raw ) {
			return '';
		}
		$url = 0 === strpos( $raw, '/' ) ? home_url( $raw ) : esc_url_raw( $raw );
		return (string) apply_filters( 'agentic_markdown_bridge_llms_txt_url', $url );
	}

	/** Get per-post Markdown enable mode. */
	public static function get_post_enabled_mode( $post_id ) {
		$mode = get_post_meta( (int) $post_id, self::ENABLED_MODE_META, true );
		return in_array( $mode, array( 'enable', 'disable' ), true ) ? $mode : 'inherit';
	}

	/** Determine whether a post exposes Markdown. */
	public static function is_post_enabled( $post ) {
		$settings = self::get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return false;
		}
		$post = get_post( $post );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! empty( $post->post_password ) || 'attachment' === $post->post_type ) {
			return false;
		}
		$post_type = get_post_type_object( $post->post_type );
		if ( ! $post_type || ! is_post_type_viewable( $post_type ) ) {
			return false;
		}
		$mode = self::get_post_enabled_mode( $post->ID );
		if ( 'disable' === $mode ) {
			$enabled = false;
		} elseif ( 'enable' === $mode ) {
			$enabled = true;
		} else {
			$allowed = isset( $settings['post_types'] ) && is_array( $settings['post_types'] ) ? array_map( 'sanitize_key', $settings['post_types'] ) : array();
			$allowed = (array) apply_filters( 'agentic_markdown_bridge_allowed_post_types', $allowed );
			$enabled = in_array( $post->post_type, $allowed, true );
		}
		return (bool) apply_filters( 'agentic_markdown_bridge_is_enabled_for_post', $enabled, $post );
	}

	/** Markdown URL for a post. */
	public static function get_markdown_url( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return '';
		}
		$permalink = get_permalink( $post );
		if ( ! $permalink ) {
			return '';
		}
		if ( ! get_option( 'permalink_structure' ) ) {
			$url = add_query_arg( self::QUERY_ID_VAR, (int) $post->ID, home_url( '/' ) );
		} else {
			$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
			$post_path = (string) wp_parse_url( $permalink, PHP_URL_PATH );
			$home_norm = trailingslashit( '/' . trim( $home_path, '/' ) );
			$post_norm = trailingslashit( '/' . trim( $post_path, '/' ) );
			$url       = $home_norm === $post_norm ? home_url( '/index.md' ) : trailingslashit( $permalink ) . 'index.md';
		}
		return (string) apply_filters( 'agentic_markdown_bridge_markdown_url', $url, $post );
	}

	/** Root homepage Markdown URL. */
	public static function get_front_markdown_url() {
		if ( ! get_option( 'permalink_structure' ) ) {
			return add_query_arg( self::QUERY_VAR, '__front__', home_url( '/' ) );
		}
		return home_url( '/index.md' );
	}

	/** Resolve path route to post. */
	private static function resolve_requested_post( $requested_path ) {
		$requested_path = (string) $requested_path;
		if ( '__front__' === $requested_path ) {
			$front_id = (int) get_option( 'page_on_front' );
			return $front_id ? get_post( $front_id ) : null;
		}
		$path = trim( rawurldecode( $requested_path ), '/' );
		if ( '' === $path || false !== strpos( $path, '..' ) ) {
			return null;
		}
		foreach ( array_unique( array( home_url( '/' . $path . '/' ), home_url( '/' . $path ) ) ) as $candidate_url ) {
			$post_id = url_to_postid( $candidate_url );
			if ( $post_id ) {
				return get_post( $post_id );
			}
		}
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $post_type ) {
			if ( 'attachment' === $post_type ) {
				continue;
			}
			$post = get_page_by_path( $path, OBJECT, $post_type );
			if ( $post ) {
				return $post;
			}
		}
		return null;
	}

	/** Determine effective post-title mode. */
	private static function should_include_post_title( $post, $content_html, $converted_markdown = '' ) {
		$settings = self::get_settings();
		$global   = sanitize_key( (string) $settings['title_mode'] );
		$override = get_post_meta( $post->ID, self::TITLE_MODE_META, true );
		if ( 'include' === $override ) {
			$mode = 'always';
		} elseif ( 'exclude' === $override ) {
			$mode = 'never';
		} elseif ( 'auto' === $override ) {
			$mode = 'auto';
		} else {
			$mode = in_array( $global, array( 'auto', 'always', 'never' ), true ) ? $global : 'auto';
		}
		$has_h1 = '' !== $converted_markdown ? (bool) preg_match( '/^#\s+\S+/m', (string) $converted_markdown ) : (bool) preg_match( '/<h1\b[^>]*>/i', (string) $content_html );
		$include = 'always' === $mode ? true : ( 'never' === $mode ? false : ! $has_h1 );
		return (bool) apply_filters( 'agentic_markdown_bridge_include_post_title', $include, $post, $content_html, $mode, $converted_markdown );
	}


	/** Determine whether generated Markdown should begin at the first H1. */
	private static function should_ignore_before_first_h1( $post ) {
		$settings = self::get_settings();
		$global   = isset( $settings['pre_h1_mode'] ) ? sanitize_key( (string) $settings['pre_h1_mode'] ) : 'ignore';
		$override = get_post_meta( $post->ID, self::PRE_H1_MODE_META, true );

		if ( in_array( $override, array( 'ignore', 'include' ), true ) ) {
			$mode = $override;
		} else {
			$mode = in_array( $global, array( 'ignore', 'include' ), true ) ? $global : 'ignore';
		}

		$ignore = 'ignore' === $mode;
		return (bool) apply_filters( 'agentic_markdown_bridge_ignore_before_first_h1', $ignore, $post, $mode );
	}

	/**
	 * Remove generated Markdown before the first top-level H1.
	 *
	 * Fenced code blocks are tracked so a literal "# Example" inside code is not
	 * mistaken for the document heading. If no H1 exists, content is unchanged.
	 */
	private static function trim_before_first_h1( $markdown ) {
		$markdown = trim( (string) $markdown );
		if ( '' === $markdown ) {
			return '';
		}

		$lines       = preg_split( '/\r\n|\r|\n/', $markdown );
		$in_fence    = false;
		$fence_char  = '';
		$fence_width = 0;

		foreach ( $lines as $index => $line ) {
			$left_trimmed = ltrim( $line );
			if ( preg_match( '/^(`{3,}|~{3,})/', $left_trimmed, $match ) ) {
				$marker = $match[1];
				$char   = substr( $marker, 0, 1 );
				$width  = strlen( $marker );

				if ( ! $in_fence ) {
					$in_fence    = true;
					$fence_char  = $char;
					$fence_width = $width;
				} elseif ( $char === $fence_char && $width >= $fence_width ) {
					$in_fence    = false;
					$fence_char  = '';
					$fence_width = 0;
				}
				continue;
			}

			if ( ! $in_fence && preg_match( '/^#(?!#)\s+\S/u', $left_trimmed ) ) {
				return trim( implode( "\n", array_slice( $lines, $index ) ) );
			}
		}

		return $markdown;
	}

	/** Load the converter only when a Markdown representation is actually requested. */
	private static function ensure_converter_loaded() {
		if ( ! class_exists( 'Agentic_Markdown_Converter', false ) ) {
			require_once AGENTIC_MB_PLUGIN_DIR . 'includes/class-agentic-markdown-converter.php';
		}
	}

	/**
	 * Custom CSS classes that should be omitted from generated Markdown.
	 *
	 * The built-in agentic-markdown-exclude class is handled by the converter
	 * independently and is always available. This method returns only the
	 * administrator-defined additional classes.
	 *
	 * @return string[]
	 */
	public static function get_exclusion_classes() {
		$settings = self::get_settings();
		$raw      = isset( $settings['exclude_classes'] ) ? (string) $settings['exclude_classes'] : '';
		$classes  = array();

		foreach ( preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY ) as $token ) {
			$token = ltrim( trim( (string) $token ), '.' );
			$token = preg_replace( '/[^A-Za-z0-9_-]/', '', $token );
			if ( '' !== $token ) {
				$classes[] = $token;
			}
		}

		$classes = array_values( array_unique( array_slice( $classes, 0, 50 ) ) );
		$classes = (array) apply_filters( 'agentic_markdown_bridge_exclusion_classes', $classes );

		$clean = array();
		foreach ( $classes as $class ) {
			$class = preg_replace( '/[^A-Za-z0-9_-]/', '', ltrim( trim( (string) $class ), '.' ) );
			if ( '' !== $class ) {
				$clean[] = $class;
			}
		}

		return array_values( array_unique( array_slice( $clean, 0, 50 ) ) );
	}

	/** Build a Markdown document. */
	public static function build_markdown( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return '';
		}
		$settings = self::get_settings();
		$language = self::get_language_for_post( $post );
		$cache_key = self::get_cache_key( $post, $language );
		$use_cache = ! empty( $settings['cache_enabled'] ) && ! is_user_logged_in();
		if ( $use_cache ) {
			$cached = get_transient( $cache_key );
			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}

		$custom_mode = get_post_meta( $post->ID, self::CUSTOM_MODE_META, true );
		$custom      = (string) get_post_meta( $post->ID, self::CUSTOM_MARKDOWN_META, true );
		if ( 'custom' === $custom_mode ) {
			$body = trim( $custom );
		} else {
			$title        = wp_strip_all_tags( get_the_title( $post ) );
			$content_html = apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Intentional use of WordPress core's the_content filter to render post content consistently.
			$content_html = (string) apply_filters( 'agentic_markdown_bridge_rendered_html', $content_html, $post );
			self::ensure_converter_loaded();
			$converted    = trim( Agentic_Markdown_Converter::convert( $content_html, get_permalink( $post ), self::get_exclusion_classes() ) );
			if ( $converted && self::should_ignore_before_first_h1( $post ) ) {
				$converted = self::trim_before_first_h1( $converted );
			}
			$parts        = array();
			if ( $title && self::should_include_post_title( $post, $content_html, $converted ) ) {
				$parts[] = '# ' . self::escape_heading_text( $title );
			}
			if ( $converted ) {
				$parts[] = $converted;
			}
			$body = trim( implode( "\n\n", $parts ) );
		}

		$markdown = self::maybe_add_front_matter( $body, $post, $language );
		$markdown = trim( $markdown ) . "\n";
		$markdown = (string) apply_filters( 'agentic_markdown_bridge_markdown', $markdown, $post );

		if ( $use_cache ) {
			$ttl = max( 60, absint( $settings['cache_ttl'] ) );
			set_transient( $cache_key, $markdown, $ttl );
		}
		return $markdown;
	}

	/** Build Markdown for a posts-index homepage. */
	private static function build_blog_home_markdown() {
		$name        = wp_strip_all_tags( (string) get_bloginfo( 'name' ) );
		$description = wp_strip_all_tags( (string) get_bloginfo( 'description' ) );
		$parts       = array( '# ' . ( $name ? self::escape_heading_text( $name ) : 'Website' ) );
		if ( $description ) {
			$parts[] = $description;
		}
		$posts = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 20 ) );
		if ( $posts ) {
			$lines = array( '## Recent Posts' );
			foreach ( $posts as $post ) {
				$url = self::is_post_enabled( $post ) ? self::get_markdown_url( $post ) : get_permalink( $post );
				$lines[] = '- [' . str_replace( array( '[', ']' ), '', wp_strip_all_tags( get_the_title( $post ) ) ) . '](' . esc_url_raw( $url ) . ')';
			}
			$parts[] = implode( "\n", $lines );
		}
		return trim( implode( "\n\n", $parts ) ) . "\n";
	}

	/** Optional YAML front matter. */
	private static function maybe_add_front_matter( $body, $post, $language ) {
		$settings = self::get_settings();
		if ( empty( $settings['front_matter'] ) || preg_match( '/^---\s*\n/', ltrim( $body ) ) ) {
			return $body;
		}
		$author = get_the_author_meta( 'display_name', (int) $post->post_author );
		$lines  = array(
			'---',
			'title: ' . wp_json_encode( wp_strip_all_tags( get_the_title( $post ) ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			'canonical: ' . wp_json_encode( get_permalink( $post ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
		);
		if ( $author ) {
			$lines[] = 'author: ' . wp_json_encode( $author, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}
		$lines[] = 'date_published: ' . wp_json_encode( get_post_time( DATE_W3C, true, $post ), JSON_UNESCAPED_SLASHES );
		$lines[] = 'date_modified: ' . wp_json_encode( get_post_modified_time( DATE_W3C, true, $post ), JSON_UNESCAPED_SLASHES );
		if ( $language ) {
			$lines[] = 'language: ' . wp_json_encode( $language, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}
		$lines[] = '---';
		return implode( "\n", $lines ) . "\n\n" . $body;
	}

	/** Get language code with common multilingual integrations. */
	public static function get_language_for_post( $post ) {
		$post = get_post( $post );
		$language = '';
		if ( $post && function_exists( 'pll_get_post_language' ) ) {
			$language = (string) pll_get_post_language( $post->ID, 'locale' );
		}
		if ( ! $language && $post && has_filter( 'wpml_post_language_details' ) ) {
			$details = apply_filters( 'wpml_post_language_details', null, $post->ID ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Intentional WPML integration hook; third-party hook names cannot be prefixed by this plugin.
			if ( is_array( $details ) ) {
				$language = ! empty( $details['locale'] ) ? (string) $details['locale'] : ( ! empty( $details['language_code'] ) ? (string) $details['language_code'] : '' );
			}
		}
		if ( ! $language && isset( $GLOBALS['TRP_LANGUAGE'] ) ) {
			$language = sanitize_text_field( (string) $GLOBALS['TRP_LANGUAGE'] );
		}
		if ( ! $language ) {
			$language = determine_locale();
		}
		$language = str_replace( '_', '-', $language );
		return (string) apply_filters( 'agentic_markdown_bridge_content_language', $language, $post );
	}

	/** Cache key for Markdown. */
	private static function get_cache_key( $post, $language ) {
		$settings = self::get_settings();
		$relevant = array(
			'title_mode'   => $settings['title_mode'],
			'pre_h1_mode'    => isset( $settings['pre_h1_mode'] ) ? $settings['pre_h1_mode'] : 'ignore',
			'exclude_classes' => self::get_exclusion_classes(),
			'front_matter'   => $settings['front_matter'],
		);
		$meta = array(
			get_post_meta( $post->ID, self::TITLE_MODE_META, true ),
			get_post_meta( $post->ID, self::PRE_H1_MODE_META, true ),
			get_post_meta( $post->ID, self::CUSTOM_MODE_META, true ),
			get_post_meta( $post->ID, self::CUSTOM_MARKDOWN_META, true ),
		);
		$global_epoch = max( 1, (int) get_option( self::CACHE_EPOCH_OPTION, 1 ) );
		$post_epoch   = max( 0, (int) get_post_meta( $post->ID, self::CACHE_EPOCH_META, true ) );
		$hash = md5( self::VERSION . '|' . $global_epoch . '|' . $post_epoch . '|' . $post->ID . '|' . $post->post_modified_gmt . '|' . $language . '|' . wp_json_encode( $relevant ) . '|' . wp_json_encode( $meta ) );
		return self::CACHE_PREFIX . $hash;
	}

	/** Invalidate one post without scanning or deleting transient rows. */
	public static function clear_post_cache( $post_id = 0 ) {
		$post_id = absint( $post_id );
		if ( $post_id ) {
			update_post_meta( $post_id, self::CACHE_EPOCH_META, time() );
		}
	}

	/**
	 * Invalidate all generated Markdown in O(1) time.
	 *
	 * Old transients are intentionally allowed to expire naturally; new requests
	 * use a new generation key immediately. Legacy indexed transients from older
	 * releases are cleaned once when this method is first used after upgrade.
	 */
	public static function clear_all_cache() {
		$current = max( 1, (int) get_option( self::CACHE_EPOCH_OPTION, 1 ) );
		update_option( self::CACHE_EPOCH_OPTION, $current + 1, false );

		$legacy_keys = get_option( self::LEGACY_CACHE_KEYS_OPTION, false );
		if ( is_array( $legacy_keys ) ) {
			foreach ( $legacy_keys as $key ) {
				if ( is_string( $key ) && 0 === strpos( $key, self::CACHE_PREFIX ) ) {
					delete_transient( $key );
				}
			}
			delete_option( self::LEGACY_CACHE_KEYS_OPTION );
		}
	}

	/** Print HTML discovery links. */
	public static function print_discovery_links() {
		if ( is_admin() || is_feed() || is_404() ) {
			return;
		}
		$llms_url = self::get_llms_txt_url();
		if ( $llms_url ) {
			printf( '<link rel="describedby" href="%s">' . "\n", esc_url( $llms_url ) );
		}
		$settings = self::get_settings();
		if ( empty( $settings['alternate_link'] ) ) {
			return;
		}
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( self::is_post_enabled( $post ) ) {
				printf( '<link rel="alternate" type="text/markdown" href="%s">' . "\n", esc_url( self::get_markdown_url( $post ) ) );
			}
		} elseif ( is_front_page() && 'posts' === get_option( 'show_on_front' ) && ! empty( $settings['enabled'] ) ) {
			printf( '<link rel="alternate" type="text/markdown" href="%s">' . "\n", esc_url( self::get_front_markdown_url() ) );
		}
	}

	/** Optional front-end Markdown actions. */
	public static function print_frontend_markdown_actions() {
		if ( is_admin() || is_feed() || is_404() ) {
			return;
		}
		$settings = self::get_settings();
		if ( empty( $settings['frontend_button'] ) ) {
			return;
		}
		$post = is_singular() ? get_queried_object() : null;
		if ( $post && ! self::is_post_enabled( $post ) ) {
			return;
		}
		if ( ! $post && ! ( is_front_page() && 'posts' === get_option( 'show_on_front' ) && ! empty( $settings['enabled'] ) ) ) {
			return;
		}
		$markdown_url = $post ? self::get_markdown_url( $post ) : self::get_front_markdown_url();
		$llms_url     = self::get_llms_txt_url();
		$position     = in_array( $settings['button_position'], array( 'bottom-left', 'bottom-center', 'bottom-right' ), true ) ? $settings['button_position'] : 'bottom-center';
		$style        = in_array( $settings['button_style'], array( 'dark', 'light', 'auto' ), true ) ? $settings['button_style'] : 'dark';
		$shape        = in_array( $settings['button_shape'], array( 'pill', 'rounded', 'square' ), true ) ? $settings['button_shape'] : 'pill';
		$classes      = 'agentic-mb-actions agentic-mb-' . $position . ' agentic-mb-style-' . $style . ' agentic-mb-shape-' . $shape;
		?>
		<style id="agentic-markdown-bridge-actions-css">
		.agentic-mb-actions{position:fixed;bottom:calc(24px + env(safe-area-inset-bottom,0px));z-index:99999;display:flex;gap:8px;align-items:center;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}.agentic-mb-bottom-center{left:50%;transform:translateX(-50%)}.agentic-mb-bottom-left{left:24px}.agentic-mb-bottom-right{right:24px}.agentic-mb-actions a,.agentic-mb-actions button{appearance:none;display:inline-flex;align-items:center;justify-content:center;gap:.45em;box-sizing:border-box;padding:11px 17px;border:1px solid rgba(127,127,127,.24);font-family:inherit;font-size:14px;font-weight:600;line-height:1.2;text-decoration:none!important;cursor:pointer;box-shadow:0 4px 14px rgba(0,0,0,.14);transition:transform .15s ease,box-shadow .15s ease}.agentic-mb-shape-pill a,.agentic-mb-shape-pill button{border-radius:999px}.agentic-mb-shape-rounded a,.agentic-mb-shape-rounded button{border-radius:10px}.agentic-mb-shape-square a,.agentic-mb-shape-square button{border-radius:0}.agentic-mb-style-dark a,.agentic-mb-style-dark button{background:#111;color:#fff!important}.agentic-mb-style-light a,.agentic-mb-style-light button{background:#fff;color:#111!important}.agentic-mb-actions a:hover,.agentic-mb-actions button:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(0,0,0,.2)}.agentic-mb-actions a:focus-visible,.agentic-mb-actions button:focus-visible{outline:3px solid currentColor;outline-offset:3px}@media(prefers-color-scheme:dark){.agentic-mb-style-auto a,.agentic-mb-style-auto button{background:#111;color:#fff!important}}@media(prefers-color-scheme:light){.agentic-mb-style-auto a,.agentic-mb-style-auto button{background:#fff;color:#111!important}}@media(max-width:600px){.agentic-mb-actions{bottom:calc(14px + env(safe-area-inset-bottom,0px));max-width:calc(100vw - 24px);flex-wrap:wrap;justify-content:center}.agentic-mb-bottom-left{left:12px}.agentic-mb-bottom-right{right:12px}.agentic-mb-actions a,.agentic-mb-actions button{padding:10px 14px;font-size:13px}}
		</style>
		<div class="<?php echo esc_attr( $classes ); ?>" data-markdown-url="<?php echo esc_url( $markdown_url ); ?>">
			<a href="<?php echo esc_url( $markdown_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( (string) $settings['button_label'] ); ?> ↗</a>
			<?php if ( ! empty( $settings['button_copy'] ) ) : ?>
				<button type="button" class="agentic-mb-copy"><?php echo esc_html( (string) $settings['copy_label'] ); ?></button>
			<?php endif; ?>
			<?php if ( ! empty( $settings['button_llms'] ) && $llms_url ) : ?>
				<a href="<?php echo esc_url( $llms_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( (string) $settings['llms_button_label'] ); ?> ↗</a>
			<?php endif; ?>
		</div>
		<?php if ( ! empty( $settings['button_copy'] ) ) : ?>
		<script id="agentic-markdown-bridge-copy-js">(function(){'use strict';var b=document.querySelector('.agentic-mb-copy'),w=document.querySelector('.agentic-mb-actions');if(!b||!w)return;var original=b.textContent;b.addEventListener('click',function(){fetch(w.getAttribute('data-markdown-url'),{credentials:'same-origin',headers:{'Accept':'text/markdown'}}).then(function(r){if(!r.ok)throw new Error('HTTP '+r.status);return r.text();}).then(function(t){return navigator.clipboard.writeText(t);}).then(function(){b.textContent=<?php echo wp_json_encode( __( 'Copied!', 'agentic-markdown-bridge' ) ); ?>;setTimeout(function(){b.textContent=original;},1600);}).catch(function(){b.textContent=<?php echo wp_json_encode( __( 'Copy failed', 'agentic-markdown-bridge' ) ); ?>;setTimeout(function(){b.textContent=original;},1600);});});}());</script>
		<?php endif; ?>
		<?php
	}

	/** Serve managed virtual llms.txt. */
	public static function maybe_serve_llms_txt() {
		$requested = get_query_var( self::QUERY_LLMS_VAR, null );
		if ( null === $requested || '' === $requested ) {
			return;
		}
		$settings = self::get_settings();
		if ( empty( $settings['manage_llms'] ) ) {
			self::serve_404();
		}
		$method = self::request_method();
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			status_header( 405 ); header( 'Allow: GET, HEAD' ); exit;
		}
		$physical = self::read_physical_llms_content();
		$content  = false !== $physical ? trim( (string) $physical ) : trim( (string) $settings['llms_content'] );
		$content  = $content ? $content : self::get_default_llms_content();
		$content  = rtrim( (string) apply_filters( 'agentic_markdown_bridge_llms_txt_content', $content ) ) . "\n";
		$modified = false !== $physical ? self::get_physical_llms_modified_timestamp() : 0;
		if ( $modified <= 0 ) {
			$modified = (int) get_option( 'agentic_markdown_bridge_llms_modified', time() );
		}
		self::send_common_text_headers( $content, 'text/plain', $modified, '' );
		header( 'Content-Disposition: inline; filename="llms.txt"' );
		if ( ! empty( $settings['analytics'] ) && 'GET' === $method && ! is_user_logged_in() ) {
			update_option( self::LLMS_VIEWS_OPTION, (int) get_option( self::LLMS_VIEWS_OPTION, 0 ) + 1, false );
		}
		if ( 'HEAD' !== $method ) {
			echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		exit;
	}

	/** Serve explicit Markdown route. */
	public static function maybe_serve_markdown() {
		$requested_path = get_query_var( self::QUERY_VAR, null );
		$requested_id   = absint( get_query_var( self::QUERY_ID_VAR, 0 ) );
		if ( ( null === $requested_path || '' === $requested_path ) && ! $requested_id ) {
			return;
		}
		$method = self::request_method();
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			status_header( 405 ); header( 'Allow: GET, HEAD' ); exit;
		}
		if ( '__front__' === $requested_path && ! (int) get_option( 'page_on_front' ) && 'posts' === get_option( 'show_on_front' ) ) {
			if ( empty( self::get_settings()['enabled'] ) ) {
				self::serve_404();
			}
			$markdown = self::build_blog_home_markdown();
			self::send_markdown_response( $markdown, null, home_url( '/' ), self::latest_content_modified_timestamp(), $method );
		}
		$post = $requested_id ? get_post( $requested_id ) : self::resolve_requested_post( $requested_path );
		if ( ! self::is_post_enabled( $post ) ) {
			self::serve_404();
		}
		self::send_markdown_response( self::build_markdown( $post ), $post, get_permalink( $post ), get_post_modified_time( 'U', true, $post ), $method );
	}

	/** Serve Markdown when Accept: text/markdown is preferred. */
	public static function maybe_serve_negotiated_markdown() {
		$settings = self::get_settings();
		if ( empty( $settings['content_negotiation'] ) || is_admin() || is_feed() || is_404() || ! self::accept_prefers_markdown() ) {
			return;
		}
		$method = self::request_method();
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return;
		}
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( self::is_post_enabled( $post ) ) {
				self::send_markdown_response( self::build_markdown( $post ), $post, get_permalink( $post ), get_post_modified_time( 'U', true, $post ), $method );
			}
		} elseif ( is_front_page() && 'posts' === get_option( 'show_on_front' ) && ! empty( $settings['enabled'] ) ) {
			self::send_markdown_response( self::build_blog_home_markdown(), null, home_url( '/' ), self::latest_content_modified_timestamp(), $method );
		}
	}

	/** Send Markdown HTTP response with caching metadata. */
	private static function send_markdown_response( $markdown, $post, $canonical, $modified, $method ) {
		$settings = self::get_settings();
		$language = $post ? self::get_language_for_post( $post ) : str_replace( '_', '-', determine_locale() );
		self::send_common_text_headers( $markdown, 'text/markdown', (int) $modified, $language );
		header( 'Content-Disposition: inline' );
		$links = array();
		if ( $canonical ) {
			$links[] = '<' . esc_url_raw( $canonical ) . '>; rel="canonical"';
		}
		$llms_url = self::get_llms_txt_url();
		if ( $llms_url ) {
			$links[] = '<' . esc_url_raw( $llms_url ) . '>; rel="describedby"';
		}
		if ( $links ) {
			header( 'Link: ' . implode( ', ', $links ) );
		}
		if ( ! empty( $settings['analytics'] ) && 'GET' === $method && $post && ! is_user_logged_in() ) {
			update_post_meta( $post->ID, self::VIEW_COUNT_META, (int) get_post_meta( $post->ID, self::VIEW_COUNT_META, true ) + 1 );
		}
		do_action( 'agentic_markdown_bridge_before_markdown_response', $post );
		if ( 'HEAD' !== $method ) {
			echo $markdown; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		exit;
	}

	/** Common text response headers + ETag/Last-Modified 304 handling. */
	private static function send_common_text_headers( $content, $mime, $modified, $language ) {
		$settings = self::get_settings();
		$etag = '"amb-' . md5( (string) $content ) . '"';
		$modified = $modified > 0 ? $modified : time();
		status_header( 200 );
		header( 'Content-Type: ' . $mime . '; charset=' . get_option( 'blog_charset', 'UTF-8' ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'ETag: ' . $etag );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $modified ) . ' GMT' );
		header( 'Cache-Control: public, max-age=' . max( 0, absint( $settings['http_cache_seconds'] ) ) );
		if ( ! empty( $settings['content_negotiation'] ) ) {
			header( 'Vary: Accept', false );
		}
		if ( $language ) {
			header( 'Content-Language: ' . preg_replace( '/[^A-Za-z0-9-]/', '', $language ) );
		}
		if ( 'noindex' === $settings['x_robots'] ) {
			header( 'X-Robots-Tag: noindex, follow', true );
		} elseif ( 'index' === $settings['x_robots'] ) {
			header( 'X-Robots-Tag: index, follow', true );
		}
		$if_none_match = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) ) : '';

		// RFC 9110: If-None-Match takes precedence over If-Modified-Since when both exist.
		if ( '' !== $if_none_match ) {
			$matched = '*' === $if_none_match;
			if ( ! $matched ) {
				$expected = preg_replace( '/^W\//i', '', $etag );
				foreach ( explode( ',', $if_none_match ) as $candidate ) {
					$candidate = preg_replace( '/^W\//i', '', trim( $candidate ) );
					if ( hash_equals( (string) $expected, (string) $candidate ) ) {
						$matched = true;
						break;
					}
				}
			}
			if ( $matched ) {
				status_header( 304 );
				exit;
			}
		} else {
			$if_modified = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? strtotime( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) ) : false;
			if ( $if_modified && $if_modified >= $modified ) {
				status_header( 304 );
				exit;
			}
		}
	}

	/** Parse Accept header and only negotiate when Markdown is at least as preferred as HTML. */
	private static function accept_prefers_markdown() {
		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) ) : '';
		if ( false === strpos( $accept, 'text/markdown' ) ) {
			return false;
		}
		$qualities = array( 'text/markdown' => 0.0, 'text/html' => 0.0 );
		foreach ( explode( ',', $accept ) as $part ) {
			$bits = array_map( 'trim', explode( ';', $part ) );
			$type = array_shift( $bits );
			$q = 1.0;
			foreach ( $bits as $bit ) {
				if ( 0 === strpos( $bit, 'q=' ) ) {
					$q = (float) substr( $bit, 2 );
				}
			}
			if ( isset( $qualities[ $type ] ) ) {
				$qualities[ $type ] = $q;
			}
		}
		return $qualities['text/markdown'] > 0 && $qualities['text/markdown'] >= $qualities['text/html'];
	}

	/** Current HTTP method. */
	private static function request_method() {
		return isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	}

	/** Latest post modification timestamp for synthetic blog home. */
	private static function latest_content_modified_timestamp() {
		$modified = get_lastpostmodified( 'GMT' );
		$timestamp = $modified ? strtotime( $modified . ' GMT' ) : time();
		return $timestamp ? $timestamp : time();
	}

	/** 404 helper. */
	private static function serve_404() {
		global $wp_query;
		if ( $wp_query ) {
			$wp_query->set_404();
		}
		status_header( 404 );
		nocache_headers();
		exit;
	}

	/** Escape Markdown heading text. */
	private static function escape_heading_text( $text ) {
		$text = str_replace( array( "\r", "\n" ), ' ', (string) $text );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}
}
