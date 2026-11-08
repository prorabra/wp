<?php
/**
 * Removes the plugin settings and its markers. Optimized files stay as they are.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'wsio_settings' );
delete_post_meta_by_key( '_wsio_status' );
delete_post_meta_by_key( '_wsio_saved' );
