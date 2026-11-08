<?php
/**
 * Removes the plugin settings, queue and markers. Compressed files stay as they are.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'wsvo_run_queue' );
delete_option( 'wsvo_settings' );
delete_option( 'wsvo_lock' );
foreach ( array( '_wsvo_status', '_wsvo_saved', '_wsvo_error', '_wsvo_tmp' ) as $key ) {
	delete_post_meta_by_key( $key );
}
