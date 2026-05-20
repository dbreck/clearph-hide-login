<?php
/**
 * Fired when the plugin is uninstalled. Removes all options and drops the log table on every blog.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$options = array(
	'cph_hide_login_slug',
	'cph_hide_login_block_mode',
	'cph_hide_login_redirect_url',
	'cph_hide_login_disable_password_reset',
	'cph_hide_login_logging',
	'cph_hide_login_log_retention_days',
);

$cph_hide_login_drop_table = static function () {
	global $wpdb;
	$table = $wpdb->prefix . 'cph_hide_login_log';
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
};

if ( is_multisite() ) {
	foreach ( $options as $opt ) {
		delete_site_option( $opt );
	}

	$blogs = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
	foreach ( $blogs as $blog_id ) {
		switch_to_blog( $blog_id );
		foreach ( $options as $opt ) {
			delete_option( $opt );
		}
		$cph_hide_login_drop_table();
		restore_current_blog();
	}
} else {
	foreach ( $options as $opt ) {
		delete_option( $opt );
	}
	$cph_hide_login_drop_table();
}
