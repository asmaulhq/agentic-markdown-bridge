<?php
/**
 * WordPress admin UI for Agentic Markdown Bridge.
 *
 * @package AgenticMarkdownBridge
 */

defined( 'ABSPATH' ) || exit;

final class Agentic_Markdown_Admin {
	/** Bootstrap admin hooks. */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_admin_pages' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post', array( __CLASS__, 'save_meta_boxes' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( AGENTIC_MB_PLUGIN_FILE ), array( __CLASS__, 'add_settings_link' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'add_post_row_action' ), 10, 2 );
		add_filter( 'page_row_actions', array( __CLASS__, 'add_post_row_action' ), 10, 2 );
		add_action( 'admin_post_agentic_mb_bulk_update', array( __CLASS__, 'handle_bulk_update' ) );
		add_action( 'admin_post_agentic_mb_clear_cache', array( __CLASS__, 'handle_clear_cache' ) );
		add_action( 'admin_post_agentic_mb_reset_analytics', array( __CLASS__, 'handle_reset_analytics' ) );
		add_action( 'wp_ajax_agentic_mb_generate_llms_preview', array( __CLASS__, 'ajax_generate_llms_preview' ) );
	}

	/** Register plugin setting and fields. */
	public static function register_settings() {
		register_setting(
			'agentic_markdown_bridge',
			Agentic_Markdown_Bridge::SETTINGS_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => Agentic_Markdown_Bridge::get_default_settings(),
			)
		);

		add_settings_section( 'amb_general', __( 'Markdown representations', 'agentic-markdown-bridge' ), array( __CLASS__, 'section_general' ), 'agentic-markdown-bridge' );
		self::add_checkbox( 'enabled', __( 'Markdown output', 'agentic-markdown-bridge' ), __( 'Enable Markdown representations for eligible public content.', 'agentic-markdown-bridge' ), 'amb_general' );
		add_settings_field( 'post_types', __( 'Content types', 'agentic-markdown-bridge' ), array( __CLASS__, 'render_post_types' ), 'agentic-markdown-bridge', 'amb_general' );
		add_settings_field( 'title_mode', __( 'Post title in Markdown', 'agentic-markdown-bridge' ), array( __CLASS__, 'render_title_mode' ), 'agentic-markdown-bridge', 'amb_general' );
		add_settings_field( 'pre_h1_mode', __( 'Content before first H1', 'agentic-markdown-bridge' ), array( __CLASS__, 'render_pre_h1_mode' ), 'agentic-markdown-bridge', 'amb_general' );
		add_settings_field( 'exclude_classes', __( 'Exclude sections', 'agentic-markdown-bridge' ), array( __CLASS__, 'render_exclusion_classes' ), 'agentic-markdown-bridge', 'amb_general' );

		add_settings_section( 'amb_output', __( 'Discovery, metadata & performance', 'agentic-markdown-bridge' ), array( __CLASS__, 'section_output' ), 'agentic-markdown-bridge' );
		self::add_checkbox( 'alternate_link', __( 'Markdown discovery', 'agentic-markdown-bridge' ), __( 'Add rel="alternate" type="text/markdown" to eligible HTML pages.', 'agentic-markdown-bridge' ), 'amb_output' );
		self::add_checkbox( 'content_negotiation', __( 'Content negotiation', 'agentic-markdown-bridge' ), __( 'Serve Markdown from the canonical URL when a client explicitly prefers Accept: text/markdown.', 'agentic-markdown-bridge' ), 'amb_output' );
		self::add_checkbox( 'front_matter', __( 'YAML front matter', 'agentic-markdown-bridge' ), __( 'Prepend title, canonical URL, author, dates and language to generated Markdown.', 'agentic-markdown-bridge' ), 'amb_output' );
		self::add_checkbox( 'cache_enabled', __( 'Markdown cache', 'agentic-markdown-bridge' ), __( 'Cache generated Markdown in WordPress transients.', 'agentic-markdown-bridge' ), 'amb_output' );
		add_settings_field( 'cache_ttl', __( 'Generation cache lifetime', 'agentic-markdown-bridge' ), array( __CLASS__, 'render_number' ), 'agentic-markdown-bridge', 'amb_output', array( 'key' => 'cache_ttl', 'min' => 60, 'max' => 604800, 'suffix' => __( 'seconds', 'agentic-markdown-bridge' ) ) );
		add_settings_field( 'http_cache_seconds', __( 'Browser/crawler cache', 'agentic-markdown-bridge' ), array( __CLASS__, 'render_number' ), 'agentic-markdown-bridge', 'amb_output', array( 'key' => 'http_cache_seconds', 'min' => 0, 'max' => 86400, 'suffix' => __( 'seconds', 'agentic-markdown-bridge' ) ) );
		add_settings_field( 'x_robots', __( 'Markdown search indexing', 'agentic-markdown-bridge' ), array( __CLASS__, 'render_x_robots' ), 'agentic-markdown-bridge', 'amb_output' );
		self::add_checkbox( 'analytics', __( 'Local view counters', 'agentic-markdown-bridge' ), __( 'Count successful Markdown and managed llms.txt GET requests locally. No IP addresses, user agents or external analytics are stored.', 'agentic-markdown-bridge' ), 'amb_output' );

		add_settings_section( 'amb_frontend', __( 'Front-end actions', 'agentic-markdown-bridge' ), array( __CLASS__, 'section_frontend' ), 'agentic-markdown-bridge' );
		self::add_checkbox( 'frontend_button', __( 'Front-end actions', 'agentic-markdown-bridge' ), __( 'Show fixed Markdown actions on eligible pages.', 'agentic-markdown-bridge' ), 'amb_frontend' );
		add_settings_field( 'button_label', __( 'View Markdown label', 'agentic-markdown-bridge' ), array( __CLASS__, 'render_text' ), 'agentic-markdown-bridge', 'amb_frontend', array( 'key' => 'button_label', 'placeholder' => __( 'View Markdown', 'agentic-markdown-bridge' ) ) );
		self::add_checkbox( 'button_copy', __( 'Copy Markdown action', 'agentic-markdown-bridge' ), __( 'Add a button that fetches and copies the current Markdown representation.', 'agentic-markdown-bridge' ), 'amb_frontend' );
		add_settings_field( 'copy_label', __( 'Copy label', 'agentic-markdown-bridge' ), array( __CLASS__, 'render_text' ), 'agentic-markdown-bridge', 'amb_frontend', array( 'key' => 'copy_label', 'placeholder' => __( 'Copy Markdown', 'agentic-markdown-bridge' ) ) );
		self::add_checkbox( 'button_llms', __( 'llms.txt action', 'agentic-markdown-bridge' ), __( 'Add a front-end link to the discovered llms.txt resource.', 'agentic-markdown-bridge' ), 'amb_frontend' );
		add_settings_field( 'llms_button_label', __( 'llms.txt label', 'agentic-markdown-bridge' ), array( __CLASS__, 'render_text' ), 'agentic-markdown-bridge', 'amb_frontend', array( 'key' => 'llms_button_label', 'placeholder' => __( 'View llms.txt', 'agentic-markdown-bridge' ) ) );
		add_settings_field( 'button_position', __( 'Position', 'agentic-markdown-bridge' ), array( __CLASS__, 'render_select' ), 'agentic-markdown-bridge', 'amb_frontend', array( 'key' => 'button_position', 'options' => array( 'bottom-left' => __( 'Bottom left', 'agentic-markdown-bridge' ), 'bottom-center' => __( 'Bottom center', 'agentic-markdown-bridge' ), 'bottom-right' => __( 'Bottom right', 'agentic-markdown-bridge' ) ) ) );
		add_settings_field( 'button_style', __( 'Style', 'agentic-markdown-bridge' ), array( __CLASS__, 'render_select' ), 'agentic-markdown-bridge', 'amb_frontend', array( 'key' => 'button_style', 'options' => array( 'dark' => __( 'Dark', 'agentic-markdown-bridge' ), 'light' => __( 'Light', 'agentic-markdown-bridge' ), 'auto' => __( 'Automatic light/dark', 'agentic-markdown-bridge' ) ) ) );
		add_settings_field( 'button_shape', __( 'Shape', 'agentic-markdown-bridge' ), array( __CLASS__, 'render_select' ), 'agentic-markdown-bridge', 'amb_frontend', array( 'key' => 'button_shape', 'options' => array( 'pill' => __( 'Pill', 'agentic-markdown-bridge' ), 'rounded' => __( 'Rounded', 'agentic-markdown-bridge' ), 'square' => __( 'Square', 'agentic-markdown-bridge' ) ) ) );

		add_settings_section( 'amb_llms', __( 'llms.txt manager', 'agentic-markdown-bridge' ), array( __CLASS__, 'section_llms' ), 'agentic-markdown-bridge' );
		self::add_checkbox( 'manage_llms', __( 'Managed llms.txt', 'agentic-markdown-bridge' ), __( 'Create and serve /llms.txt from WordPress when there is no physical root file.', 'agentic-markdown-bridge' ), 'amb_llms' );
		add_settings_field( 'llms_content', __( 'llms.txt content', 'agentic-markdown-bridge' ), array( __CLASS__, 'render_llms_content' ), 'agentic-markdown-bridge', 'amb_llms' );
		self::add_checkbox( 'describedby_link', __( 'External llms.txt discovery', 'agentic-markdown-bridge' ), __( 'Advertise an externally managed llms.txt when the built-in manager is off.', 'agentic-markdown-bridge' ), 'amb_llms' );
		add_settings_field( 'llms_url', __( 'External llms.txt URL', 'agentic-markdown-bridge' ), array( __CLASS__, 'render_text' ), 'agentic-markdown-bridge', 'amb_llms', array( 'key' => 'llms_url', 'placeholder' => '/llms.txt', 'class' => 'regular-text code' ) );
		add_settings_field( 'llms_generator', __( 'llms.txt generator', 'agentic-markdown-bridge' ), array( __CLASS__, 'render_llms_generator' ), 'agentic-markdown-bridge', 'amb_llms' );
	}

	/** Helper: checkbox field registration. */
	private static function add_checkbox( $key, $title, $label, $section ) {
		add_settings_field( 'amb_' . $key, $title, array( __CLASS__, 'render_checkbox' ), 'agentic-markdown-bridge', $section, array( 'key' => $key, 'label' => $label ) );
	}

	/** Sanitize all settings. */
	public static function sanitize_settings( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = Agentic_Markdown_Bridge::get_default_settings();
		$current  = Agentic_Markdown_Bridge::get_settings();
		$types    = array_keys( Agentic_Markdown_Bridge::get_available_post_types() );
		$post_types = isset( $input['post_types'] ) && is_array( $input['post_types'] ) ? array_values( array_intersect( array_map( 'sanitize_key', $input['post_types'] ), $types ) ) : array();

		$out = $defaults;
		foreach ( array( 'enabled', 'alternate_link', 'content_negotiation', 'front_matter', 'cache_enabled', 'analytics', 'frontend_button', 'button_copy', 'button_llms', 'manage_llms', 'describedby_link', 'llms_generator_use_markdown' ) as $key ) {
			$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}
		$out['post_types'] = $post_types;

		$title_mode = isset( $input['title_mode'] ) ? sanitize_key( $input['title_mode'] ) : 'auto';
		$out['title_mode'] = in_array( $title_mode, array( 'auto', 'always', 'never' ), true ) ? $title_mode : 'auto';
		$pre_h1_mode = isset( $input['pre_h1_mode'] ) ? sanitize_key( $input['pre_h1_mode'] ) : 'ignore';
		$out['pre_h1_mode'] = in_array( $pre_h1_mode, array( 'ignore', 'include' ), true ) ? $pre_h1_mode : 'ignore';

		$exclude_raw     = isset( $input['exclude_classes'] ) ? sanitize_text_field( wp_unslash( $input['exclude_classes'] ) ) : '';
		$exclude_classes = array();
		foreach ( preg_split( '/[\s,]+/', $exclude_raw, -1, PREG_SPLIT_NO_EMPTY ) as $token ) {
			$token = ltrim( trim( (string) $token ), '.' );
			$token = preg_replace( '/[^A-Za-z0-9_-]/', '', $token );
			if ( '' !== $token ) {
				$exclude_classes[] = $token;
			}
		}
		$exclude_classes       = array_values( array_unique( array_slice( $exclude_classes, 0, 50 ) ) );
		$out['exclude_classes'] = implode( ', ', array_map( static function ( $class ) { return '.' . $class; }, $exclude_classes ) );
		$out['cache_ttl'] = min( 604800, max( 60, isset( $input['cache_ttl'] ) ? absint( $input['cache_ttl'] ) : 43200 ) );
		$out['http_cache_seconds'] = min( 86400, max( 0, isset( $input['http_cache_seconds'] ) ? absint( $input['http_cache_seconds'] ) : 300 ) );
		$x_robots = isset( $input['x_robots'] ) ? sanitize_key( $input['x_robots'] ) : 'none';
		$out['x_robots'] = in_array( $x_robots, array( 'none', 'noindex', 'index' ), true ) ? $x_robots : 'none';

		foreach ( array( 'button_label' => __( 'View Markdown', 'agentic-markdown-bridge' ), 'copy_label' => __( 'Copy Markdown', 'agentic-markdown-bridge' ), 'llms_button_label' => __( 'View llms.txt', 'agentic-markdown-bridge' ) ) as $key => $fallback ) {
			$value = isset( $input[ $key ] ) ? sanitize_text_field( wp_unslash( $input[ $key ] ) ) : $fallback;
			$out[ $key ] = trim( $value ) ? $value : $fallback;
		}
		$position = isset( $input['button_position'] ) ? sanitize_key( $input['button_position'] ) : 'bottom-center';
		$out['button_position'] = in_array( $position, array( 'bottom-left', 'bottom-center', 'bottom-right' ), true ) ? $position : 'bottom-center';
		$style = isset( $input['button_style'] ) ? sanitize_key( $input['button_style'] ) : 'dark';
		$out['button_style'] = in_array( $style, array( 'dark', 'light', 'auto' ), true ) ? $style : 'dark';
		$shape = isset( $input['button_shape'] ) ? sanitize_key( $input['button_shape'] ) : 'pill';
		$out['button_shape'] = in_array( $shape, array( 'pill', 'rounded', 'square' ), true ) ? $shape : 'pill';

		$llms_url = isset( $input['llms_url'] ) ? trim( sanitize_text_field( wp_unslash( $input['llms_url'] ) ) ) : '/llms.txt';
		if ( $llms_url && 0 !== strpos( $llms_url, '/' ) ) {
			$llms_url = esc_url_raw( $llms_url, array( 'http', 'https' ) );
		}
		$out['llms_url'] = $llms_url ? $llms_url : '/llms.txt';
		$out['llms_generator_summary'] = isset( $input['llms_generator_summary'] ) ? sanitize_textarea_field( wp_unslash( $input['llms_generator_summary'] ) ) : '';
		$out['llms_generator_ids'] = isset( $input['llms_generator_ids'] ) && is_array( $input['llms_generator_ids'] ) ? array_values( array_filter( array_unique( array_map( 'absint', $input['llms_generator_ids'] ) ) ) ) : array();

		$llms_content = isset( $input['llms_content'] ) ? Agentic_Markdown_Bridge::sanitize_markdown_text( wp_unslash( $input['llms_content'] ) ) : (string) $current['llms_content'];
		if ( $out['manage_llms'] && '' === trim( $llms_content ) ) {
			$llms_content = Agentic_Markdown_Bridge::get_default_llms_content();
		}
		if ( ( $out['manage_llms'] || Agentic_Markdown_Bridge::physical_llms_exists() ) && ! preg_match( '/^(?:\xEF\xBB\xBF)?\s*#\s+\S+/u', $llms_content ) ) {
			add_settings_error( Agentic_Markdown_Bridge::SETTINGS_OPTION, 'amb_llms_h1', __( 'llms.txt should begin with an H1 heading (# Site Name).', 'agentic-markdown-bridge' ), 'warning' );
		}

		if ( Agentic_Markdown_Bridge::physical_llms_exists() ) {
			$physical = Agentic_Markdown_Bridge::read_physical_llms_content();
			if ( false !== $physical && $physical !== $llms_content ) {
				$result = Agentic_Markdown_Bridge::write_physical_llms_content( $llms_content );
				if ( is_wp_error( $result ) ) {
					add_settings_error( Agentic_Markdown_Bridge::SETTINGS_OPTION, 'amb_llms_write', $result->get_error_message(), 'error' );
					$llms_content = $physical;
				} else {
					add_settings_error( Agentic_Markdown_Bridge::SETTINGS_OPTION, 'amb_llms_updated', __( 'The physical llms.txt file was updated safely.', 'agentic-markdown-bridge' ), 'success' );
				}
			}
		}
		$out['llms_content'] = $llms_content;
		if ( (string) $current['llms_content'] !== (string) $llms_content ) {
			update_option( 'agentic_markdown_bridge_llms_modified', time(), false );
		}
		Agentic_Markdown_Bridge::reset_settings_cache();
		return $out;
	}

	/** Admin menus. */
	public static function add_admin_pages() {
		add_options_page( __( 'Agentic Markdown Bridge', 'agentic-markdown-bridge' ), __( 'Agentic Markdown', 'agentic-markdown-bridge' ), 'manage_options', 'agentic-markdown-bridge', array( __CLASS__, 'render_settings_page' ) );
		add_management_page( __( 'Agentic Markdown Content', 'agentic-markdown-bridge' ), __( 'Markdown Content', 'agentic-markdown-bridge' ), 'edit_posts', 'agentic-markdown-content', array( __CLASS__, 'render_content_page' ) );
		add_management_page( __( 'Agentic Markdown Diagnostics', 'agentic-markdown-bridge' ), __( 'Markdown Diagnostics', 'agentic-markdown-bridge' ), 'manage_options', 'agentic-markdown-diagnostics', array( __CLASS__, 'render_diagnostics_page' ) );
	}

	/** Plugin Settings action link. */
	public static function add_settings_link( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=agentic-markdown-bridge' ) ) . '">' . esc_html__( 'Settings', 'agentic-markdown-bridge' ) . '</a>' );
		return $links;
	}

	/** Post list row action. */
	public static function add_post_row_action( $actions, $post ) {
		if ( Agentic_Markdown_Bridge::is_post_enabled( $post ) ) {
			$actions['agentic_markdown'] = '<a href="' . esc_url( Agentic_Markdown_Bridge::get_markdown_url( $post ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View Markdown', 'agentic-markdown-bridge' ) . '</a>';
		}
		return $actions;
	}

	/** Settings page. */
	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Agentic Markdown Bridge', 'agentic-markdown-bridge' ); ?></h1>
			<p><?php esc_html_e( 'Clean Markdown representations, discovery, llms.txt management and practical controls for machine-readable WordPress content.', 'agentic-markdown-bridge' ); ?></p>
			<p><a class="button" href="<?php echo esc_url( admin_url( 'tools.php?page=agentic-markdown-content' ) ); ?>"><?php esc_html_e( 'Manage content', 'agentic-markdown-bridge' ); ?></a> <a class="button" href="<?php echo esc_url( admin_url( 'tools.php?page=agentic-markdown-diagnostics' ) ); ?>"><?php esc_html_e( 'Diagnostics', 'agentic-markdown-bridge' ); ?></a></p>
			<form action="options.php" method="post">
				<?php settings_fields( 'agentic_markdown_bridge' ); do_settings_sections( 'agentic-markdown-bridge' ); submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public static function section_general() {
		echo '<p>' . esc_html__( 'Markdown is generated from rendered WordPress content. Individual posts can override availability, title handling, and the generated Markdown.', 'agentic-markdown-bridge' ) . '</p>';
	}
	public static function section_output() {
		echo '<p>' . esc_html__( 'Control discovery, optional content negotiation, metadata, caching and search-engine headers.', 'agentic-markdown-bridge' ) . '</p>';
	}
	public static function section_frontend() {
		echo '<p>' . esc_html__( 'Optional visitor-facing actions. These are off by default and do not affect machine discovery.', 'agentic-markdown-bridge' ) . '</p>';
	}
	public static function section_llms() {
		$physical = Agentic_Markdown_Bridge::physical_llms_exists();
		echo '<p>' . esc_html__( 'Create, edit or discover llms.txt without requiring FTP or cPanel.', 'agentic-markdown-bridge' ) . '</p>';
		if ( $physical ) {
			if ( Agentic_Markdown_Bridge::physical_llms_is_symlink() ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'A physical llms.txt symbolic link exists. It can be previewed, but the plugin will not edit symlinks for safety.', 'agentic-markdown-bridge' ) . '</p></div>';
			} elseif ( Agentic_Markdown_Bridge::physical_llms_is_writable() ) {
				echo '<div class="notice notice-info inline"><p>' . esc_html__( 'A physical root llms.txt file exists. Its content is loaded below, and saving updates it using a temporary backup and atomic replacement.', 'agentic-markdown-bridge' ) . '</p></div>';
			} else {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'A physical root llms.txt file exists but WordPress cannot safely write to it.', 'agentic-markdown-bridge' ) . '</p></div>';
			}
		}
		$url = Agentic_Markdown_Bridge::physical_llms_exists() ? home_url( '/llms.txt' ) : Agentic_Markdown_Bridge::get_llms_txt_url();
		if ( $url ) {
			echo '<p><a class="button" target="_blank" rel="noopener noreferrer" href="' . esc_url( $url ) . '">' . esc_html__( 'View public llms.txt', 'agentic-markdown-bridge' ) . '</a></p>';
		}
	}

	/** Generic checkbox renderer. */
	public static function render_checkbox( $args ) {
		$s = Agentic_Markdown_Bridge::get_settings(); $key = sanitize_key( $args['key'] );
		?><label><input type="checkbox" name="<?php echo esc_attr( Agentic_Markdown_Bridge::SETTINGS_OPTION ); ?>[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $s[ $key ] ) ); ?>> <?php echo esc_html( $args['label'] ); ?></label><?php
	}

	/** Public post type checkboxes. */
	public static function render_post_types() {
		$s = Agentic_Markdown_Bridge::get_settings(); $selected = (array) $s['post_types'];
		foreach ( Agentic_Markdown_Bridge::get_available_post_types() as $name => $object ) {
			echo '<label style="display:block;margin:0 0 5px"><input type="checkbox" name="' . esc_attr( Agentic_Markdown_Bridge::SETTINGS_OPTION ) . '[post_types][]" value="' . esc_attr( $name ) . '" ' . checked( in_array( $name, $selected, true ), true, false ) . '> ' . esc_html( $object->labels->name ) . ' <code>' . esc_html( $name ) . '</code></label>';
		}
	}

	public static function render_title_mode() {
		self::render_select( array( 'key' => 'title_mode', 'options' => array( 'auto' => __( 'Auto — add only when content has no H1', 'agentic-markdown-bridge' ), 'always' => __( 'Always include WordPress title', 'agentic-markdown-bridge' ), 'never' => __( 'Never include WordPress title', 'agentic-markdown-bridge' ) ) ) );
	}
	public static function render_pre_h1_mode() {
		self::render_select( array( 'key' => 'pre_h1_mode', 'options' => array( 'ignore' => __( 'Ignore content before first H1 — Recommended', 'agentic-markdown-bridge' ), 'include' => __( 'Include content before first H1', 'agentic-markdown-bridge' ) ) ) );
		echo '<p class="description">' . esc_html__( 'Applies to automatically generated Markdown only. If no H1 exists, no content is removed.', 'agentic-markdown-bridge' ) . '</p>';
	}
	public static function render_exclusion_classes() {
		$s     = Agentic_Markdown_Bridge::get_settings();
		$value = isset( $s['exclude_classes'] ) ? (string) $s['exclude_classes'] : '';
		echo '<input type="text" class="regular-text code" name="' . esc_attr( Agentic_Markdown_Bridge::SETTINGS_OPTION ) . '[exclude_classes]" value="' . esc_attr( $value ) . '" placeholder=".exclude-mrk, .no-markdown">';
		echo '<p class="description">' . esc_html__( 'Optional additional CSS classes to remove, including their entire contents, from automatically generated Markdown. Separate classes with commas.', 'agentic-markdown-bridge' ) . '</p>';
		echo '<p><code>agentic-markdown-exclude</code> — ' . esc_html__( 'built-in class that always excludes a Gutenberg block or HTML element.', 'agentic-markdown-bridge' ) . '</p><p><code>data-agentic-markdown="exclude"</code> — ' . esc_html__( 'developer-friendly equivalent for custom markup.', 'agentic-markdown-bridge' ) . '</p>';
	}
	public static function render_x_robots() {
		self::render_select( array( 'key' => 'x_robots', 'options' => array( 'none' => __( 'No X-Robots-Tag (recommended default)', 'agentic-markdown-bridge' ), 'noindex' => __( 'noindex, follow', 'agentic-markdown-bridge' ), 'index' => __( 'index, follow', 'agentic-markdown-bridge' ) ) ) );
	}
	public static function render_text( $args ) {
		$s = Agentic_Markdown_Bridge::get_settings(); $key = sanitize_key( $args['key'] ); $class = isset( $args['class'] ) ? $args['class'] : 'regular-text';
		echo '<input type="text" class="' . esc_attr( $class ) . '" name="' . esc_attr( Agentic_Markdown_Bridge::SETTINGS_OPTION ) . '[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $s[ $key ] ) . '" placeholder="' . esc_attr( isset( $args['placeholder'] ) ? $args['placeholder'] : '' ) . '">';
	}
	public static function render_number( $args ) {
		$s = Agentic_Markdown_Bridge::get_settings(); $key = sanitize_key( $args['key'] );
		echo '<input type="number" class="small-text" name="' . esc_attr( Agentic_Markdown_Bridge::SETTINGS_OPTION ) . '[' . esc_attr( $key ) . ']" value="' . esc_attr( (int) $s[ $key ] ) . '" min="' . esc_attr( (int) $args['min'] ) . '" max="' . esc_attr( (int) $args['max'] ) . '"> ' . esc_html( $args['suffix'] );
	}
	public static function render_select( $args ) {
		$s = Agentic_Markdown_Bridge::get_settings(); $key = sanitize_key( $args['key'] ); $value = (string) $s[ $key ];
		echo '<select name="' . esc_attr( Agentic_Markdown_Bridge::SETTINGS_OPTION ) . '[' . esc_attr( $key ) . ']">';
		foreach ( $args['options'] as $option => $label ) {
			echo '<option value="' . esc_attr( $option ) . '" ' . selected( $value, $option, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	/** llms editor. */
	public static function render_llms_content() {
		$s = Agentic_Markdown_Bridge::get_settings(); $value = (string) $s['llms_content']; $physical = Agentic_Markdown_Bridge::read_physical_llms_content();
		if ( false !== $physical ) { $value = $physical; } elseif ( '' === trim( $value ) ) { $value = Agentic_Markdown_Bridge::get_default_llms_content(); }
		echo '<textarea class="large-text code" rows="22" spellcheck="false" name="' . esc_attr( Agentic_Markdown_Bridge::SETTINGS_OPTION ) . '[llms_content]">' . esc_textarea( $value ) . '</textarea>';
		echo '<p class="description"><strong>' . esc_html__( 'Source:', 'agentic-markdown-bridge' ) . '</strong> ' . esc_html( false !== $physical ? __( 'physical /llms.txt file', 'agentic-markdown-bridge' ) : __( 'WordPress-managed content', 'agentic-markdown-bridge' ) ) . '</p>';
	}

	/** llms generator controls. */
	public static function render_llms_generator() {
		$s          = Agentic_Markdown_Bridge::get_settings();
		$selected   = array_map( 'absint', (array) $s['llms_generator_ids'] );
		$candidates = get_posts(
			array(
				'post_type'   => array_keys( Agentic_Markdown_Bridge::get_available_post_types() ),
				'post_status' => 'publish',
				'numberposts' => 200,
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);
		$home_url   = home_url( '/' );
		$llms_url   = home_url( '/llms.txt' );
		$ai_provider_urls = apply_filters(
			'agentic_markdown_bridge_ai_provider_urls',
			array(
				'chatgpt'    => 'https://chatgpt.com/?q={prompt}',
				'claude'     => 'https://claude.ai/new',
				'gemini'     => 'https://gemini.google.com/app',
				'perplexity' => 'https://www.perplexity.ai/search?q={prompt}',
			)
		);
		?>
		<p><label><strong><?php esc_html_e( 'Site summary', 'agentic-markdown-bridge' ); ?></strong><br><textarea class="large-text" rows="3" name="<?php echo esc_attr( Agentic_Markdown_Bridge::SETTINGS_OPTION ); ?>[llms_generator_summary]" placeholder="<?php esc_attr_e( 'Short description used below the H1.', 'agentic-markdown-bridge' ); ?>"><?php echo esc_textarea( (string) $s['llms_generator_summary'] ); ?></textarea></label></p>
		<p><label><input type="checkbox" name="<?php echo esc_attr( Agentic_Markdown_Bridge::SETTINGS_OPTION ); ?>[llms_generator_use_markdown]" value="1" <?php checked( ! empty( $s['llms_generator_use_markdown'] ) ); ?>> <?php esc_html_e( 'Link to Markdown representations when available.', 'agentic-markdown-bridge' ); ?></label></p>
		<p><label for="amb-generator-filter"><strong><?php esc_html_e( 'Choose important content', 'agentic-markdown-bridge' ); ?></strong></label><br><input id="amb-generator-filter" type="search" class="regular-text" placeholder="<?php esc_attr_e( 'Filter titles…', 'agentic-markdown-bridge' ); ?>"></p>
		<select id="amb-generator-posts" multiple size="12" style="width:100%;max-width:760px" name="<?php echo esc_attr( Agentic_Markdown_Bridge::SETTINGS_OPTION ); ?>[llms_generator_ids][]">
		<?php foreach ( $candidates as $post ) :
			$pto          = get_post_type_object( $post->post_type );
			$canonical    = get_permalink( $post );
			$markdown_url = Agentic_Markdown_Bridge::is_post_enabled( $post ) ? Agentic_Markdown_Bridge::get_markdown_url( $post ) : '';
			?>
			<option value="<?php echo esc_attr( $post->ID ); ?>" data-title="<?php echo esc_attr( get_the_title( $post ) ); ?>" data-url="<?php echo esc_url( $canonical ); ?>" data-markdown-url="<?php echo esc_url( $markdown_url ); ?>" <?php selected( in_array( (int) $post->ID, $selected, true ) ); ?>><?php echo esc_html( get_the_title( $post ) . ' — ' . ( $pto ? $pto->labels->singular_name : $post->post_type ) ); ?></option>
		<?php endforeach; ?>
		</select>
		<p><button type="button" id="amb-generate-llms" class="button button-secondary"><?php esc_html_e( 'Generate editable llms.txt draft', 'agentic-markdown-bridge' ); ?></button> <span id="amb-generate-llms-status" class="description"></span></p>
		<p class="description"><?php esc_html_e( 'Generation never publishes automatically. The generated draft is placed in the llms.txt editor above; review it, edit it, then click Save Changes when ready.', 'agentic-markdown-bridge' ); ?></p>

		<style>
			#amb-ai-generator {
				max-width: 900px;
				margin-top: 34px;
				padding-top: 28px;
				border-top: 1px solid #dcdcde;
			}
			#amb-ai-generator h3 {
				margin: 0 0 10px;
				font-size: 16px;
				line-height: 1.4;
			}
			#amb-ai-generator .amb-ai-intro,
			#amb-ai-generator .amb-ai-note {
				max-width: 760px;
				margin: 0;
			}
			#amb-ai-generator .amb-ai-intro {
				margin-bottom: 20px;
			}
			#amb-ai-provider-buttons {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
				gap: 12px;
				max-width: 760px;
				margin: 0 0 18px;
			}
			#amb-ai-provider-buttons .amb-ai-provider-card {
				display: flex;
				flex-direction: column;
				gap: 7px;
				min-width: 0;
			}
			#amb-ai-provider-buttons .button {
				margin: 0;
				min-height: 40px;
				width: 100%;
				padding-left: 16px;
				padding-right: 16px;
			}
			#amb-ai-provider-buttons .amb-ai-provider-help {
				min-height: 34px;
				font-size: 12px;
				line-height: 1.4;
				color: #646970;
			}
			#amb-ai-provider-status {
				display: block;
				min-height: 20px;
				margin: 0 0 16px;
			}
			#amb-ai-generator .amb-ai-note {
				margin-bottom: 22px;
			}
			#amb-ai-generator details {
				max-width: 760px;
				margin-top: 0;
				padding-top: 2px;
			}
			#amb-ai-generator details summary {
				cursor: pointer;
				font-weight: 500;
			}
			#amb-ai-generator details[open] summary {
				margin-bottom: 12px;
			}
			#amb-ai-generator #amb-ai-prompt-preview {
				margin-top: 4px;
			}
		</style>
		<div id="amb-ai-generator">
			<h3><?php esc_html_e( 'Generate llms.txt with your AI', 'agentic-markdown-bridge' ); ?></h3>
			<p class="description amb-ai-intro"><?php esc_html_e( 'Use your own ChatGPT, Claude, Gemini, or Perplexity account. The plugin prepares a production-ready prompt using the public website URL, current llms.txt URL, and optional site summary. No API key is required and no server-side AI request is made.', 'agentic-markdown-bridge' ); ?></p>
			<div id="amb-ai-provider-buttons">
				<div class="amb-ai-provider-card">
					<button type="button" class="button button-secondary amb-ai-provider" data-provider="chatgpt"><?php esc_html_e( 'ChatGPT', 'agentic-markdown-bridge' ); ?></button>
					<span class="amb-ai-provider-help"><?php esc_html_e( 'Opens with the prompt prefilled.', 'agentic-markdown-bridge' ); ?></span>
				</div>
				<div class="amb-ai-provider-card">
					<button type="button" class="button button-secondary amb-ai-provider" data-provider="claude"><?php esc_html_e( 'Claude', 'agentic-markdown-bridge' ); ?></button>
					<span class="amb-ai-provider-help"><?php esc_html_e( 'Copies the prompt and opens Claude.', 'agentic-markdown-bridge' ); ?></span>
				</div>
				<div class="amb-ai-provider-card">
					<button type="button" class="button button-secondary amb-ai-provider" data-provider="gemini"><?php esc_html_e( 'Gemini', 'agentic-markdown-bridge' ); ?></button>
					<span class="amb-ai-provider-help"><?php esc_html_e( 'Copies the prompt and opens Gemini.', 'agentic-markdown-bridge' ); ?></span>
				</div>
				<div class="amb-ai-provider-card">
					<button type="button" class="button button-secondary amb-ai-provider" data-provider="perplexity"><?php esc_html_e( 'Perplexity', 'agentic-markdown-bridge' ); ?></button>
					<span class="amb-ai-provider-help"><?php esc_html_e( 'Opens with the prompt prefilled.', 'agentic-markdown-bridge' ); ?></span>
				</div>
			</div>
			<span id="amb-ai-provider-status" class="description" aria-live="polite"></span>
			<p class="description amb-ai-note"><?php esc_html_e( 'Claude and Gemini use a clipboard-first workflow because their clean new-chat pages do not reliably support safe prompt prefilling. Paste the copied prompt and submit it when ready.', 'agentic-markdown-bridge' ); ?></p>
			<details>
				<summary><?php esc_html_e( 'Preview or copy the AI prompt', 'agentic-markdown-bridge' ); ?></summary>
				<p><textarea id="amb-ai-prompt-preview" class="large-text code" rows="18" readonly></textarea></p>
				<p><button type="button" id="amb-copy-ai-prompt" class="button"><?php esc_html_e( 'Copy AI prompt', 'agentic-markdown-bridge' ); ?></button> <span id="amb-copy-ai-prompt-status" class="description" aria-live="polite"></span></p>
			</details>
		</div>

		<script>
		(function(){
			var f=document.getElementById('amb-generator-filter'),s=document.getElementById('amb-generator-posts'),b=document.getElementById('amb-generate-llms'),status=document.getElementById('amb-generate-llms-status');
			var summary=document.querySelector('textarea[name="<?php echo esc_js( Agentic_Markdown_Bridge::SETTINGS_OPTION ); ?>[llms_generator_summary]"]');
			var useMd=document.querySelector('input[name="<?php echo esc_js( Agentic_Markdown_Bridge::SETTINGS_OPTION ); ?>[llms_generator_use_markdown]"]');
			var preview=document.getElementById('amb-ai-prompt-preview'),copy=document.getElementById('amb-copy-ai-prompt'),copyStatus=document.getElementById('amb-copy-ai-prompt-status'),aiStatus=document.getElementById('amb-ai-provider-status');
			var homeUrl=<?php echo wp_json_encode( $home_url ); ?>,llmsUrl=<?php echo wp_json_encode( $llms_url ); ?>,providerUrls=<?php echo wp_json_encode( $ai_provider_urls ); ?>;

			function buildAiPrompt(){
				var siteSummary=(summary&&summary.value.trim())?summary.value.trim():'Not provided';
				var lines=[];
				lines.push('Act as a **Technical SEO + GEO/AEO + AI Discoverability Specialist**.');
				lines.push('');
				lines.push('Create or improve a production-ready `llms.txt` for:');
				lines.push('');
				lines.push('Website: '+homeUrl);
				lines.push('Existing llms.txt: '+llmsUrl);
				lines.push('Summary: '+siteSummary);
				lines.push('');
				lines.push('Instructions:');
				lines.push('');
				lines.push('- Inspect the website and existing `llms.txt` first.');
				lines.push('- Use only verified facts and working public URLs.');
				lines.push('- Do not invent claims, services, people, clients, results, or pages.');
				lines.push('- Treat `llms.txt` as a **curated high-value resource map**, not a sitemap.');
				lines.push('- Prioritize the pages that best explain the entity, core offerings, expertise, authoritative work/case studies, key resources, people, and contact.');
				lines.push('- Prefer unique, authoritative pages over overlapping or repetitive ones.');
				lines.push('- Use exactly one H1, an optional short blockquote, and only relevant H2 sections.');
				lines.push('- Format links as:');
				lines.push('  `- [Page Name](URL): Short factual description.`');
				lines.push('- Exclude duplicate, thin, legal, utility, archive, login, search, pagination, and low-value pages.');
				lines.push('- Prefer canonical URLs, or intentional Markdown endpoints specifically provided for AI/LLM consumption.');
				lines.push('- Keep the file concise, scannable, and useful for retrieval.');
				lines.push('');
				lines.push('Before output, verify that every included link adds meaningful information and that no important core page is missing.');
				lines.push('');
				lines.push('Return only the final `llms.txt` Markdown. No code fence, commentary, citations, or explanation.');
				return lines.join('\n');
			}

			function refreshPreview(){if(preview){preview.value=buildAiPrompt();}}
			if(f&&s){f.addEventListener('input',function(){var q=f.value.toLowerCase();Array.prototype.forEach.call(s.options,function(o){o.hidden=q&&o.text.toLowerCase().indexOf(q)===-1;});});s.addEventListener('change',refreshPreview);}
			if(summary){summary.addEventListener('input',refreshPreview);}
			if(useMd){useMd.addEventListener('change',refreshPreview);}
			refreshPreview();

			if(b&&s){b.addEventListener('click',function(){var editor=document.querySelector('textarea[name="<?php echo esc_js( Agentic_Markdown_Bridge::SETTINGS_OPTION ); ?>[llms_content]"]'),fd=new FormData();fd.append('action','agentic_mb_generate_llms_preview');fd.append('_ajax_nonce',<?php echo wp_json_encode( wp_create_nonce( 'agentic_mb_generate_llms_preview' ) ); ?>);fd.append('summary',summary?summary.value:'');fd.append('use_markdown',useMd&&useMd.checked?'1':'0');Array.prototype.forEach.call(s.selectedOptions,function(o){fd.append('ids[]',o.value);});b.disabled=true;status.textContent=<?php echo wp_json_encode( __( 'Generating…', 'agentic-markdown-bridge' ) ); ?>;fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();}).then(function(r){if(!r.success)throw new Error((r.data&&r.data.message)||'Failed');if(editor){editor.value=r.data.content;editor.scrollIntoView({behavior:'smooth',block:'center'});}status.textContent=<?php echo wp_json_encode( __( 'Draft generated. Review it, then Save Changes.', 'agentic-markdown-bridge' ) ); ?>;}).catch(function(e){status.textContent=e.message;}).finally(function(){b.disabled=false;});});}

			function copyText(text){
				if(navigator.clipboard&&window.isSecureContext){return navigator.clipboard.writeText(text);}
				return new Promise(function(resolve,reject){var t=document.createElement('textarea');t.value=text;t.setAttribute('readonly','');t.style.position='fixed';t.style.opacity='0';document.body.appendChild(t);t.select();try{document.execCommand('copy')?resolve():reject(new Error('Copy failed'));}catch(e){reject(e);}document.body.removeChild(t);});
			}

			if(copy){copy.addEventListener('click',function(){var prompt=buildAiPrompt();refreshPreview();copyText(prompt).then(function(){copyStatus.textContent=<?php echo wp_json_encode( __( 'Prompt copied.', 'agentic-markdown-bridge' ) ); ?>;}).catch(function(){copyStatus.textContent=<?php echo wp_json_encode( __( 'Copy failed. Select the prompt above and copy it manually.', 'agentic-markdown-bridge' ) ); ?>;});});}

			Array.prototype.forEach.call(document.querySelectorAll('.amb-ai-provider'),function(button){button.addEventListener('click',function(){var provider=button.dataset.provider,prompt=buildAiPrompt(),encoded=encodeURIComponent(prompt),url='';refreshPreview();if(provider==='gemini'||provider==='claude'){url=providerUrls[provider]||(provider==='claude'?'https://claude.ai/new':'https://gemini.google.com/app');window.open(url,'_blank','noopener,noreferrer');copyText(prompt).then(function(){aiStatus.textContent=provider==='claude'?<?php echo wp_json_encode( __( 'Claude opened. The prompt was copied — paste it into Claude and submit.', 'agentic-markdown-bridge' ) ); ?>:<?php echo wp_json_encode( __( 'Gemini opened. The prompt was copied — paste it into Gemini and submit.', 'agentic-markdown-bridge' ) ); ?>;}).catch(function(){aiStatus.textContent=provider==='claude'?<?php echo wp_json_encode( __( 'Claude opened. Copy the prompt from the preview below and paste it into Claude.', 'agentic-markdown-bridge' ) ); ?>:<?php echo wp_json_encode( __( 'Gemini opened. Copy the prompt from the preview below and paste it into Gemini.', 'agentic-markdown-bridge' ) ); ?>;});return;}url=(providerUrls[provider]||'').replace('{prompt}',encoded);if(url){window.open(url,'_blank','noopener,noreferrer');aiStatus.textContent=<?php echo wp_json_encode( __( 'Opened in a new tab. Review the AI output before saving it as llms.txt.', 'agentic-markdown-bridge' ) ); ?>;}});});
		}());
		</script>
		<?php
	}

	/** Return an llms.txt draft without saving it. */
	public static function ajax_generate_llms_preview() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to generate llms.txt.', 'agentic-markdown-bridge' ) ), 403 );
		}
		check_ajax_referer( 'agentic_mb_generate_llms_preview' );
		$settings = Agentic_Markdown_Bridge::get_settings();
		$settings['llms_generator_summary'] = isset( $_POST['summary'] ) ? sanitize_textarea_field( wp_unslash( $_POST['summary'] ) ) : '';
		$settings['llms_generator_use_markdown'] = empty( $_POST['use_markdown'] ) ? 0 : 1;
		$settings['llms_generator_ids'] = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_values( array_filter( array_map( 'absint', $_POST['ids'] ) ) ) : array();
		wp_send_json_success( array( 'content' => Agentic_Markdown_Bridge::generate_llms_content( $settings ) ) );
	}

	/** Add editor meta boxes. */
	public static function add_meta_boxes() {
		foreach ( Agentic_Markdown_Bridge::get_available_post_types() as $type => $object ) {
			add_meta_box( 'agentic-markdown-bridge-options', __( 'Agentic Markdown', 'agentic-markdown-bridge' ), array( __CLASS__, 'render_options_meta_box' ), $type, 'side', 'default', array( '__block_editor_compatible_meta_box' => true ) );
			add_meta_box( 'agentic-markdown-bridge-custom', __( 'Custom Markdown Override', 'agentic-markdown-bridge' ), array( __CLASS__, 'render_custom_meta_box' ), $type, 'normal', 'default', array( '__block_editor_compatible_meta_box' => true ) );
		}
	}

	/** Editor side controls. */
	public static function render_options_meta_box( $post ) {
		wp_nonce_field( 'agentic_mb_save_post_options', 'agentic_mb_post_options_nonce' );
		$enabled = Agentic_Markdown_Bridge::get_post_enabled_mode( $post->ID );
		$title = get_post_meta( $post->ID, Agentic_Markdown_Bridge::TITLE_MODE_META, true ); if ( ! in_array( $title, array( 'auto','include','exclude' ), true ) ) { $title = 'inherit'; }
		$pre_h1 = get_post_meta( $post->ID, Agentic_Markdown_Bridge::PRE_H1_MODE_META, true ); if ( ! in_array( $pre_h1, array( 'ignore','include' ), true ) ) { $pre_h1 = 'inherit'; }
		?>
		<p><label><strong><?php esc_html_e( 'Markdown representation', 'agentic-markdown-bridge' ); ?></strong><br><select name="agentic_mb_enabled_mode" style="width:100%"><option value="inherit" <?php selected( $enabled, 'inherit' ); ?>><?php esc_html_e( 'Use global/content-type setting', 'agentic-markdown-bridge' ); ?></option><option value="enable" <?php selected( $enabled, 'enable' ); ?>><?php esc_html_e( 'Enable', 'agentic-markdown-bridge' ); ?></option><option value="disable" <?php selected( $enabled, 'disable' ); ?>><?php esc_html_e( 'Disable', 'agentic-markdown-bridge' ); ?></option></select></label></p>
		<p><label><strong><?php esc_html_e( 'Markdown title', 'agentic-markdown-bridge' ); ?></strong><br><select name="agentic_mb_title_mode" style="width:100%"><option value="inherit" <?php selected( $title, 'inherit' ); ?>><?php esc_html_e( 'Use global setting', 'agentic-markdown-bridge' ); ?></option><option value="auto" <?php selected( $title, 'auto' ); ?>><?php esc_html_e( 'Auto-detect H1', 'agentic-markdown-bridge' ); ?></option><option value="include" <?php selected( $title, 'include' ); ?>><?php esc_html_e( 'Include WordPress title', 'agentic-markdown-bridge' ); ?></option><option value="exclude" <?php selected( $title, 'exclude' ); ?>><?php esc_html_e( 'Exclude WordPress title', 'agentic-markdown-bridge' ); ?></option></select></label></p>
		<p><label><strong><?php esc_html_e( 'Content before first H1', 'agentic-markdown-bridge' ); ?></strong><br><select name="agentic_mb_pre_h1_mode" style="width:100%"><option value="inherit" <?php selected( $pre_h1, 'inherit' ); ?>><?php esc_html_e( 'Use global setting', 'agentic-markdown-bridge' ); ?></option><option value="ignore" <?php selected( $pre_h1, 'ignore' ); ?>><?php esc_html_e( 'Ignore before first H1', 'agentic-markdown-bridge' ); ?></option><option value="include" <?php selected( $pre_h1, 'include' ); ?>><?php esc_html_e( 'Include before first H1', 'agentic-markdown-bridge' ); ?></option></select></label></p>
		<?php if ( Agentic_Markdown_Bridge::is_post_enabled( $post ) ) : ?><p><a class="button" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( Agentic_Markdown_Bridge::get_markdown_url( $post ) ); ?>"><?php esc_html_e( 'View Markdown', 'agentic-markdown-bridge' ); ?></a></p><?php endif; ?>
		<?php
	}

	/** Custom Markdown source and textarea. */
	public static function render_custom_meta_box( $post ) {
		wp_nonce_field( 'agentic_mb_save_custom_markdown', 'agentic_mb_custom_markdown_nonce' );
		$value       = (string) get_post_meta( $post->ID, Agentic_Markdown_Bridge::CUSTOM_MARKDOWN_META, true );
		$custom_mode = get_post_meta( $post->ID, Agentic_Markdown_Bridge::CUSTOM_MODE_META, true );
		$custom_mode = 'custom' === $custom_mode ? 'custom' : 'generated';
		?>
		<p><label for="agentic-mb-custom-mode"><strong><?php esc_html_e( 'Content source', 'agentic-markdown-bridge' ); ?></strong></label><br>
		<select id="agentic-mb-custom-mode" name="agentic_mb_custom_mode" style="width:100%" aria-controls="agentic-mb-custom-wrap" aria-expanded="<?php echo 'custom' === $custom_mode ? 'true' : 'false'; ?>">
			<option value="generated" <?php selected( $custom_mode, 'generated' ); ?>><?php esc_html_e( 'Generate from page content', 'agentic-markdown-bridge' ); ?></option>
			<option value="custom" <?php selected( $custom_mode, 'custom' ); ?>><?php esc_html_e( 'Use custom Markdown below', 'agentic-markdown-bridge' ); ?></option>
		</select></p>

		<div id="agentic-mb-custom-wrap"<?php echo 'custom' === $custom_mode ? '' : ' hidden'; ?>>
			<p id="agentic-mb-custom-help"><?php esc_html_e( 'This Markdown replaces the automatically generated version for this page. YAML front matter can still be added globally.', 'agentic-markdown-bridge' ); ?></p>
			<textarea id="agentic-mb-custom-markdown" class="widefat code" rows="16" spellcheck="false" aria-describedby="agentic-mb-custom-help" name="agentic_mb_custom_markdown"><?php echo esc_textarea( $value ); ?></textarea>
		</div>

		<script>
		(function () {
			'use strict';
			var source = document.getElementById('agentic-mb-custom-mode');
			var wrapper = document.getElementById('agentic-mb-custom-wrap');
			var editor = document.getElementById('agentic-mb-custom-markdown');
			if (!source || !wrapper || !editor) { return; }

			function syncCustomEditor(shouldFocus) {
				var isCustom = 'custom' === source.value;
				wrapper.hidden = !isCustom;
				source.setAttribute('aria-expanded', isCustom ? 'true' : 'false');

				if (!isCustom || !shouldFocus) { return; }
				window.requestAnimationFrame(function () {
					editor.focus();
					var end = editor.value.length;
					if (typeof editor.setSelectionRange === 'function') {
						editor.setSelectionRange(end, end);
					}
				});
			}

			source.addEventListener('change', function () {
				syncCustomEditor(true);
			});
		}());
		</script>
		<?php
	}

	/** Save per-post controls without touching fields that were not submitted. */
	public static function save_meta_boxes( $post_id ) {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$changed = false;
		$options_nonce_valid = isset( $_POST['agentic_mb_post_options_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['agentic_mb_post_options_nonce'] ) ), 'agentic_mb_save_post_options' );
		$custom_nonce_valid  = isset( $_POST['agentic_mb_custom_markdown_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['agentic_mb_custom_markdown_nonce'] ) ), 'agentic_mb_save_custom_markdown' );

		if ( $options_nonce_valid ) {
			if ( isset( $_POST['agentic_mb_enabled_mode'] ) ) {
				$enabled = sanitize_key( wp_unslash( $_POST['agentic_mb_enabled_mode'] ) );
				$changed = self::save_enum_meta( $post_id, Agentic_Markdown_Bridge::ENABLED_MODE_META, $enabled, array( 'enable', 'disable' ) ) || $changed;
			}
			if ( isset( $_POST['agentic_mb_title_mode'] ) ) {
				$title   = sanitize_key( wp_unslash( $_POST['agentic_mb_title_mode'] ) );
				$changed = self::save_enum_meta( $post_id, Agentic_Markdown_Bridge::TITLE_MODE_META, $title, array( 'auto', 'include', 'exclude' ) ) || $changed;
			}
			if ( isset( $_POST['agentic_mb_pre_h1_mode'] ) ) {
				$pre_h1  = sanitize_key( wp_unslash( $_POST['agentic_mb_pre_h1_mode'] ) );
				$changed = self::save_enum_meta( $post_id, Agentic_Markdown_Bridge::PRE_H1_MODE_META, $pre_h1, array( 'ignore', 'include' ) ) || $changed;
			}
		}

		if ( $custom_nonce_valid ) {
			if ( isset( $_POST['agentic_mb_custom_mode'] ) ) {
				$custom_mode = sanitize_key( wp_unslash( $_POST['agentic_mb_custom_mode'] ) );
				$changed     = self::save_enum_meta( $post_id, Agentic_Markdown_Bridge::CUSTOM_MODE_META, $custom_mode, array( 'custom' ) ) || $changed;
			}

			if ( isset( $_POST['agentic_mb_custom_markdown'] ) ) {
				// Markdown intentionally allows syntax that generic WordPress text sanitizers would strip.
				$raw_custom_markdown = wp_unslash( $_POST['agentic_mb_custom_markdown'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized immediately below by the plugin's Markdown-specific sanitizer.
				$custom              = Agentic_Markdown_Bridge::sanitize_markdown_text( $raw_custom_markdown );
				$existing            = (string) get_post_meta( $post_id, Agentic_Markdown_Bridge::CUSTOM_MARKDOWN_META, true );
				if ( '' === trim( $custom ) ) {
					if ( '' !== $existing ) {
						delete_post_meta( $post_id, Agentic_Markdown_Bridge::CUSTOM_MARKDOWN_META );
						$changed = true;
					}
				} elseif ( $existing !== $custom ) {
					update_post_meta( $post_id, Agentic_Markdown_Bridge::CUSTOM_MARKDOWN_META, $custom );
					$changed = true;
				}
			}
		}

		if ( $changed ) {
			Agentic_Markdown_Bridge::clear_post_cache( $post_id );
		}
	}

	/** Save or delete enum-like post meta and report whether it changed. */
	private static function save_enum_meta( $post_id, $key, $value, $allowed ) {
		$existing = (string) get_post_meta( $post_id, $key, true );
		$new      = in_array( $value, $allowed, true ) ? $value : '';
		if ( $existing === $new ) {
			return false;
		}
		if ( '' === $new ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $new );
		}
		return true;
	}

	/** Bulk content management screen. */
	public static function render_content_page() {
		if ( ! current_user_can( 'edit_posts' ) ) { return; }
		$paged = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$query = new WP_Query( array( 'post_type' => array_keys( Agentic_Markdown_Bridge::get_available_post_types() ), 'post_status' => 'publish', 'posts_per_page' => 30, 'paged' => $paged, 's' => $search, 'orderby' => 'modified', 'order' => 'DESC' ) );
		?>
		<div class="wrap"><h1><?php esc_html_e( 'Agentic Markdown Content', 'agentic-markdown-bridge' ); ?></h1>
		<form method="get"><input type="hidden" name="page" value="agentic-markdown-content"><p class="search-box"><input type="search" name="s" value="<?php echo esc_attr( $search ); ?>"><input type="submit" class="button" value="<?php esc_attr_e( 'Search content', 'agentic-markdown-bridge' ); ?>"></p></form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="agentic_mb_bulk_update"><?php wp_nonce_field( 'agentic_mb_bulk_update' ); ?>
		<div class="tablenav top"><div class="alignleft actions"><select name="bulk_mode"><option value=""><?php esc_html_e( 'Bulk actions', 'agentic-markdown-bridge' ); ?></option><option value="inherit"><?php esc_html_e( 'Use global setting', 'agentic-markdown-bridge' ); ?></option><option value="enable"><?php esc_html_e( 'Enable Markdown', 'agentic-markdown-bridge' ); ?></option><option value="disable"><?php esc_html_e( 'Disable Markdown', 'agentic-markdown-bridge' ); ?></option></select> <button class="button action"><?php esc_html_e( 'Apply', 'agentic-markdown-bridge' ); ?></button></div></div>
		<table class="widefat striped"><thead><tr><td class="check-column"><input type="checkbox" onclick="document.querySelectorAll('.amb-row-check').forEach(function(c){c.checked=this.checked}.bind(this))"></td><th><?php esc_html_e( 'Content', 'agentic-markdown-bridge' ); ?></th><th><?php esc_html_e( 'Type', 'agentic-markdown-bridge' ); ?></th><th><?php esc_html_e( 'Markdown', 'agentic-markdown-bridge' ); ?></th><th><?php esc_html_e( 'Title', 'agentic-markdown-bridge' ); ?></th><th><?php esc_html_e( 'Source', 'agentic-markdown-bridge' ); ?></th><th><?php esc_html_e( 'Views', 'agentic-markdown-bridge' ); ?></th><th><?php esc_html_e( 'Actions', 'agentic-markdown-bridge' ); ?></th></tr></thead><tbody>
		<?php foreach ( $query->posts as $post ) : $mode=Agentic_Markdown_Bridge::get_post_enabled_mode($post->ID); $title=get_post_meta($post->ID,Agentic_Markdown_Bridge::TITLE_MODE_META,true); $source='custom'===get_post_meta($post->ID,Agentic_Markdown_Bridge::CUSTOM_MODE_META,true)?__('Custom','agentic-markdown-bridge'):__('Generated','agentic-markdown-bridge'); ?>
		<tr><th class="check-column"><input class="amb-row-check" type="checkbox" name="post_ids[]" value="<?php echo esc_attr( $post->ID ); ?>"></th><td><strong><a href="<?php echo esc_url( get_edit_post_link( $post ) ); ?>"><?php echo esc_html( get_the_title( $post ) ); ?></a></strong></td><td><?php echo esc_html( $post->post_type ); ?></td><td><?php echo esc_html( 'inherit' === $mode ? ( Agentic_Markdown_Bridge::is_post_enabled($post) ? __('Enabled (global)','agentic-markdown-bridge') : __('Disabled (global)','agentic-markdown-bridge') ) : ucfirst($mode) ); ?></td><td><?php echo esc_html( $title ? $title : __('Global','agentic-markdown-bridge') ); ?></td><td><?php echo esc_html( $source ); ?></td><td><?php echo esc_html( number_format_i18n( (int) get_post_meta($post->ID,Agentic_Markdown_Bridge::VIEW_COUNT_META,true) ) ); ?></td><td><?php if ( Agentic_Markdown_Bridge::is_post_enabled($post) ) : ?><a target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( Agentic_Markdown_Bridge::get_markdown_url($post) ); ?>"><?php esc_html_e( 'View Markdown', 'agentic-markdown-bridge' ); ?></a><?php endif; ?></td></tr>
		<?php endforeach; ?>
		</tbody></table></form>
		<?php $base = add_query_arg( array( 'page'=>'agentic-markdown-content','s'=>$search ), admin_url('tools.php') ); echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base'=>add_query_arg('paged','%#%',$base),'format'=>'','current'=>$paged,'total'=>max(1,(int)$query->max_num_pages) ) ) ) . '</div></div>'; ?>
		</div><?php
	}

	/** Handle bulk enable/disable. */
	public static function handle_bulk_update() {
		if ( ! current_user_can( 'edit_posts' ) ) { wp_die( esc_html__( 'You are not allowed to do that.', 'agentic-markdown-bridge' ) ); }
		check_admin_referer( 'agentic_mb_bulk_update' );
		$mode = isset( $_POST['bulk_mode'] ) ? sanitize_key( wp_unslash( $_POST['bulk_mode'] ) ) : '';
		$ids = isset( $_POST['post_ids'] ) && is_array( $_POST['post_ids'] ) ? array_map( 'absint', $_POST['post_ids'] ) : array();
		if ( in_array( $mode, array( 'inherit','enable','disable' ), true ) ) {
			foreach ( $ids as $id ) {
				if ( current_user_can( 'edit_post', $id ) ) { if ( 'inherit' === $mode ) { delete_post_meta($id,Agentic_Markdown_Bridge::ENABLED_MODE_META); } else { update_post_meta($id,Agentic_Markdown_Bridge::ENABLED_MODE_META,$mode); } }
			}
		}
		wp_safe_redirect( admin_url( 'tools.php?page=agentic-markdown-content' ) ); exit;
	}

	/** Diagnostics page. */
	public static function render_diagnostics_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$s = Agentic_Markdown_Bridge::get_settings(); $rules = get_option( 'rewrite_rules', array() );
		$index_rule_ok = empty( $s['enabled'] ); $path_rule_ok = empty( $s['enabled'] ); $llms_rule_ok = ! empty( $s['manage_llms'] ) ? false : true;
		$foreign_markdown_rules = 0; $foreign_llms_rules = 0;
		foreach ( (array) $rules as $regex => $target ) {
			if ( '^index\.md/?$' === $regex && false !== strpos($target,Agentic_Markdown_Bridge::QUERY_VAR) ) { $index_rule_ok=true; }
			if ( '^(.+?)/index\.md/?$' === $regex && false !== strpos($target,Agentic_Markdown_Bridge::QUERY_VAR) ) { $path_rule_ok=true; }
			if ( '^llms\.txt/?$' === $regex && false !== strpos($target,Agentic_Markdown_Bridge::QUERY_LLMS_VAR) ) { $llms_rule_ok=true; }
			if ( false !== strpos( $regex, 'index\.md' ) && false === strpos( $target, Agentic_Markdown_Bridge::QUERY_VAR ) ) { ++$foreign_markdown_rules; }
			if ( false !== strpos( $regex, 'llms' ) && false === strpos( $target, Agentic_Markdown_Bridge::QUERY_LLMS_VAR ) ) { ++$foreign_llms_rules; }
		}
		$llms_content = Agentic_Markdown_Bridge::physical_llms_exists() ? Agentic_Markdown_Bridge::read_physical_llms_content() : $s['llms_content'];

		/* translators: %d is the number of competing Markdown rewrite rules detected. */
		$markdown_conflict_message = sprintf( _n( '%d competing Markdown rewrite rule detected', '%d competing Markdown rewrite rules detected', $foreign_markdown_rules, 'agentic-markdown-bridge' ), $foreign_markdown_rules );
		/* translators: %d is the number of competing llms.txt rewrite rules detected. */
		$llms_conflict_message = sprintf( _n( '%d competing llms.txt rewrite rule detected', '%d competing llms.txt rewrite rules detected', $foreign_llms_rules, 'agentic-markdown-bridge' ), $foreign_llms_rules );

		$checks = array(
			array( !empty($s['enabled']), __('Markdown output','agentic-markdown-bridge'), !empty($s['enabled'])?__('Enabled','agentic-markdown-bridge'):__('Disabled','agentic-markdown-bridge') ),
			array( class_exists('DOMDocument'), __('PHP DOM extension','agentic-markdown-bridge'), class_exists('DOMDocument')?__('Available','agentic-markdown-bridge'):__('Missing — fallback text conversion will be used','agentic-markdown-bridge') ),
			array( $index_rule_ok && $path_rule_ok, __('Markdown rewrite rules','agentic-markdown-bridge'), $index_rule_ok&&$path_rule_ok?__('Registered','agentic-markdown-bridge'):__('Missing — save Permalinks or reactivate the plugin','agentic-markdown-bridge') ),
			array( 0 === $foreign_markdown_rules, __('Markdown route conflicts','agentic-markdown-bridge'), 0 === $foreign_markdown_rules ? __('No competing index.md rewrite rules detected','agentic-markdown-bridge') : $markdown_conflict_message ),
			array( 0 === $foreign_llms_rules, __('llms.txt rewrite conflicts','agentic-markdown-bridge'), 0 === $foreign_llms_rules ? __('No competing llms.txt rewrite rules detected','agentic-markdown-bridge') : $llms_conflict_message ),
			array( $llms_rule_ok || Agentic_Markdown_Bridge::physical_llms_exists(), __('llms.txt route/source','agentic-markdown-bridge'), Agentic_Markdown_Bridge::physical_llms_exists()?__('Physical root file','agentic-markdown-bridge'):( !empty($s['manage_llms'])?__('Managed virtual route','agentic-markdown-bridge'):__('External/disabled','agentic-markdown-bridge') ) ),
			array( !Agentic_Markdown_Bridge::physical_llms_is_symlink(), __('Physical llms.txt safety','agentic-markdown-bridge'), Agentic_Markdown_Bridge::physical_llms_is_symlink()?__('Symlink detected — editing blocked','agentic-markdown-bridge'):__('OK','agentic-markdown-bridge') ),
			array( !$llms_content || preg_match('/^(?:\xEF\xBB\xBF)?\s*#\s+\S+/u',(string)$llms_content), __('llms.txt H1','agentic-markdown-bridge'), !$llms_content?__('No local content configured','agentic-markdown-bridge'):(preg_match('/^(?:\xEF\xBB\xBF)?\s*#\s+\S+/u',(string)$llms_content)?__('Present','agentic-markdown-bridge'):__('Missing','agentic-markdown-bridge')) ),
		);
		?>
		<div class="wrap"><h1><?php esc_html_e('Agentic Markdown Diagnostics','agentic-markdown-bridge'); ?></h1>
		<table class="widefat striped" style="max-width:1000px"><thead><tr><th><?php esc_html_e('Status','agentic-markdown-bridge'); ?></th><th><?php esc_html_e('Check','agentic-markdown-bridge'); ?></th><th><?php esc_html_e('Result','agentic-markdown-bridge'); ?></th></tr></thead><tbody><?php foreach($checks as $c): ?><tr><td style="font-size:20px"><?php echo $c[0]?'✓':'!'; ?></td><td><?php echo esc_html($c[1]); ?></td><td><?php echo esc_html($c[2]); ?></td></tr><?php endforeach; ?></tbody></table>
		<h2><?php esc_html_e('Live endpoint check','agentic-markdown-bridge'); ?></h2><p><?php esc_html_e('This runs in your browser against your own site. No data is sent to Bipixels or any external service.','agentic-markdown-bridge'); ?></p><button id="amb-live-test" class="button button-primary"><?php esc_html_e('Run live checks','agentic-markdown-bridge'); ?></button><pre id="amb-live-results" style="max-width:1000px;white-space:pre-wrap;background:#fff;border:1px solid #ccd0d4;padding:14px"></pre>
		<h2><?php esc_html_e('Maintenance','agentic-markdown-bridge'); ?></h2><p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=agentic_mb_clear_cache'),'agentic_mb_clear_cache')); ?>"><?php esc_html_e('Clear Markdown cache','agentic-markdown-bridge'); ?></a> <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=agentic_mb_reset_analytics'),'agentic_mb_reset_analytics')); ?>"><?php esc_html_e('Reset local counters','agentic-markdown-bridge'); ?></a></p>
		<p><?php echo esc_html__('llms.txt views: ','agentic-markdown-bridge') . esc_html(number_format_i18n((int)get_option(Agentic_Markdown_Bridge::LLMS_VIEWS_OPTION,0))); ?></p>
		<script>(function(){var b=document.getElementById('amb-live-test'),o=document.getElementById('amb-live-results');if(!b||!o)return;b.addEventListener('click',function(){b.disabled=true;o.textContent='Running…';var tests=[['Homepage Markdown',<?php echo wp_json_encode(Agentic_Markdown_Bridge::get_front_markdown_url()); ?>],['llms.txt',<?php echo wp_json_encode(Agentic_Markdown_Bridge::get_llms_txt_url()); ?>]].filter(function(t){return t[1];});Promise.all(tests.map(function(t){return fetch(t[1],{method:'HEAD',credentials:'same-origin',headers:{'Accept':'text/markdown'}}).then(function(r){return t[0]+': HTTP '+r.status+' | '+(r.headers.get('content-type')||'no content-type')+' | Link: '+(r.headers.get('link')||'—');}).catch(function(e){return t[0]+': FAILED '+e.message;});})).then(function(lines){o.textContent=lines.join('\n');b.disabled=false;});});}());</script>
		</div><?php
	}

	public static function handle_clear_cache() { if(!current_user_can('manage_options')){wp_die('');} check_admin_referer('agentic_mb_clear_cache'); Agentic_Markdown_Bridge::clear_all_cache(); wp_safe_redirect(admin_url('tools.php?page=agentic-markdown-diagnostics')); exit; }
	public static function handle_reset_analytics() { if(!current_user_can('manage_options')){wp_die('');} check_admin_referer('agentic_mb_reset_analytics'); delete_option(Agentic_Markdown_Bridge::LLMS_VIEWS_OPTION); delete_post_meta_by_key( Agentic_Markdown_Bridge::VIEW_COUNT_META );
		wp_safe_redirect(admin_url('tools.php?page=agentic-markdown-diagnostics')); exit; }
}
