<?php
/**
 * WP-CLI commands for Agentic Markdown Bridge.
 *
 * @package AgenticMarkdownBridge
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

final class Agentic_Markdown_CLI {
	/** Register command namespace. */
	public static function register() {
		WP_CLI::add_command( 'agentic-markdown', __CLASS__ );
	}

	/**
	 * Validate the current site configuration.
	 *
	 * ## EXAMPLES
	 *
	 *     wp agentic-markdown validate
	 */
	public function validate() {
		$settings = Agentic_Markdown_Bridge::get_settings();
		WP_CLI::log( 'Markdown output: ' . ( ! empty( $settings['enabled'] ) ? 'enabled' : 'disabled' ) );
		WP_CLI::log( 'DOM extension: ' . ( class_exists( 'DOMDocument' ) ? 'available' : 'missing' ) );
		WP_CLI::log( 'Homepage Markdown: ' . Agentic_Markdown_Bridge::get_front_markdown_url() );
		$llms = Agentic_Markdown_Bridge::get_llms_txt_url();
		WP_CLI::log( 'llms.txt: ' . ( $llms ? $llms : 'not configured' ) );
		if ( Agentic_Markdown_Bridge::physical_llms_is_symlink() ) {
			WP_CLI::warning( 'Physical llms.txt is a symlink; editing is blocked for safety.' );
		}
		WP_CLI::success( 'Validation complete.' );
	}

	/**
	 * Print the Markdown URL for a post.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Post ID.
	 */
	public function url( $args ) {
		$id = isset( $args[0] ) ? absint( $args[0] ) : 0;
		$post = get_post( $id );
		if ( ! $post ) {
			WP_CLI::error( 'Post not found.' );
		}
		if ( ! Agentic_Markdown_Bridge::is_post_enabled( $post ) ) {
			WP_CLI::warning( 'Markdown is disabled for this post.' );
		}
		WP_CLI::line( Agentic_Markdown_Bridge::get_markdown_url( $post ) );
	}

	/** Clear all generated Markdown cache entries. */
	public function clear_cache() {
		Agentic_Markdown_Bridge::clear_all_cache();
		WP_CLI::success( 'Agentic Markdown cache cleared.' );
	}

	/** Generate an llms.txt draft using the saved generator selection. */
	public function generate_llms() {
		$settings = Agentic_Markdown_Bridge::get_settings();
		$content  = Agentic_Markdown_Bridge::generate_llms_content( $settings );
		$settings['llms_content'] = $content;
		update_option( Agentic_Markdown_Bridge::SETTINGS_OPTION, $settings, false );
		Agentic_Markdown_Bridge::reset_settings_cache();
		update_option( 'agentic_markdown_bridge_llms_modified', time(), false );
		if ( Agentic_Markdown_Bridge::physical_llms_exists() ) {
			$result = Agentic_Markdown_Bridge::write_physical_llms_content( $content );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}
		}
		WP_CLI::success( 'llms.txt draft generated from the saved selection.' );
	}
}
