<?php
/**
 * Uninstall cleanup for Agentic Markdown Bridge.
 *
 * @package AgenticMarkdownBridge
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/** Clean one site's data. */
function agentic_mb_uninstall_site() {
	delete_option( 'agentic_markdown_bridge_settings' );
	delete_option( 'agentic_markdown_bridge_rewrite_version' );
	delete_option( 'agentic_markdown_bridge_llms_views' );
	delete_option( 'agentic_markdown_bridge_llms_modified' );
	delete_option( 'agentic_markdown_bridge_cache_epoch' );

	$cache_keys = get_option( 'agentic_markdown_bridge_cache_keys', array() );
	if ( is_array( $cache_keys ) ) {
		foreach ( $cache_keys as $key ) {
			if ( is_string( $key ) && 0 === strpos( $key, 'agentic_mb_cache_' ) ) {
				delete_transient( $key );
			}
		}
	}
	delete_option( 'agentic_markdown_bridge_cache_keys' );

	delete_post_meta_by_key( '_agentic_mb_title_mode' );
	delete_post_meta_by_key( '_agentic_mb_pre_h1_mode' );
	delete_post_meta_by_key( '_agentic_mb_enabled_mode' );
	delete_post_meta_by_key( '_agentic_mb_custom_mode' );
	delete_post_meta_by_key( '_agentic_mb_custom_markdown' );
	delete_post_meta_by_key( '_agentic_mb_view_count' );
	delete_post_meta_by_key( '_agentic_mb_cache_epoch' );
}

if ( is_multisite() ) {
	$agentic_mb_site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
	foreach ( $agentic_mb_site_ids as $agentic_mb_site_id ) {
		switch_to_blog( (int) $agentic_mb_site_id );
		agentic_mb_uninstall_site();
		restore_current_blog();
	}
} else {
	agentic_mb_uninstall_site();
}
