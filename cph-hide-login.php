<?php
/**
 * Plugin Name: Clear pH Hide Login
 * Description: Hide the WordPress login URL behind a custom slug and 404 direct hits to wp-login.php and wp-admin while logged out. Optional password-reset lockdown and blocked-attempt logging. No wp-config edits required.
 * Author:      Clear pH Design
 * Author URI:  https://clearphdesign.com
 * Version:     1.0.0
 * Requires at least: 5.5
 * Requires PHP: 7.4
 * Network:     true
 * License:     GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'CPH_HIDE_LOGIN_VERSION', '1.0.0' );
define( 'CPH_HIDE_LOGIN_FILE', __FILE__ );
define( 'CPH_HIDE_LOGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CPH_HIDE_LOGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CPH_HIDE_LOGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once CPH_HIDE_LOGIN_DIR . 'includes/class-cph-hide-login-log.php';
require_once CPH_HIDE_LOGIN_DIR . 'includes/class-cph-hide-login-settings.php';
require_once CPH_HIDE_LOGIN_DIR . 'includes/class-cph-hide-login.php';

// GitHub-driven self-update. Tag a release on github.com/dbreck/clearph-hide-login
// with the same version as the Version: header above and WP will offer the update.
if ( file_exists( CPH_HIDE_LOGIN_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php' ) ) {
	require_once CPH_HIDE_LOGIN_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';

	$cph_hide_login_updater = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/dbreck/clearph-hide-login/',
		__FILE__,
		'cph-hide-login'
	);
	$cph_hide_login_updater->setBranch( 'main' );
}

register_activation_hook( __FILE__, array( 'CPH_Hide_Login', 'activate' ) );

add_action( 'plugins_loaded', array( 'CPH_Hide_Login', 'get_instance' ), 0 );
