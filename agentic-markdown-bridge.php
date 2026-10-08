<?php
/**
 * Plugin Name:       Agentic Markdown Bridge
 * Description:       Adds clean Markdown representations, llms.txt management, discovery, caching, diagnostics, and content controls for WordPress.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Bipixels
 * Author URI:        https://bipixels.com/
 * Text Domain:       agentic-markdown-bridge
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package AgenticMarkdownBridge
 */

defined( 'ABSPATH' ) || exit;

define( 'AGENTIC_MB_VERSION', '1.0.0' );
define( 'AGENTIC_MB_PLUGIN_FILE', __FILE__ );
define( 'AGENTIC_MB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once AGENTIC_MB_PLUGIN_DIR . 'includes/class-agentic-markdown-bridge.php';

register_activation_hook( __FILE__, array( 'Agentic_Markdown_Bridge', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Agentic_Markdown_Bridge', 'deactivate' ) );

Agentic_Markdown_Bridge::init();

// Admin-only code is not parsed on normal front-end requests.
if ( is_admin() ) {
	require_once AGENTIC_MB_PLUGIN_DIR . 'includes/class-agentic-markdown-admin.php';
	Agentic_Markdown_Admin::init();
}

// CLI code is loaded only inside WP-CLI.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once AGENTIC_MB_PLUGIN_DIR . 'includes/class-agentic-markdown-cli.php';
	Agentic_Markdown_CLI::register();
}
