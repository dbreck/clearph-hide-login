<?php
/**
 * Clear pH Hide Login — main plugin class.
 *
 * Hides the WordPress login URL behind a custom slug and 404s direct hits to
 * wp-login.php / wp-admin / wp-signup / wp-register / wp-activate while logged out.
 *
 * Slug resolution order:
 *   1. CPH_HIDE_LOGIN_SLUG constant (lets wp-config / dropins override the DB)
 *   2. cph_hide_login_slug filter
 *   3. cph_hide_login_slug site option
 *   4. cph_hide_login_slug network option (multisite default)
 *   5. Empty → plugin no-ops, default wp-login.php still works
 *
 * Compatible with the cph-hidden-login mu-plugin (same option key).
 */

defined( 'ABSPATH' ) || exit;

class CPH_Hide_Login {

	const OPTION_PREFIX = 'cph_hide_login_';

	/**
	 * @var CPH_Hide_Login
	 */
	private static $instance;

	/**
	 * Resolved login slug, or empty string when hiding is disabled.
	 *
	 * @var string
	 */
	private $slug = '';

	/**
	 * Set to true once we've decided this request is a hijacked wp-login.php hit
	 * that should render as a 404.
	 *
	 * @var bool
	 */
	private $is_blocked_request = false;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->slug = $this->resolve_slug();

		// Settings UI always loads so the admin can configure the plugin even when no slug is set.
		CPH_Hide_Login_Settings::get_instance();

		// If no slug is configured, the plugin no-ops — default WP login still works.
		if ( '' === $this->slug ) {
			add_action( 'admin_notices', array( $this, 'notice_no_slug' ) );
			add_action( 'network_admin_notices', array( $this, 'notice_no_slug' ) );
			return;
		}

		// URL rewriting needs to happen as early as possible after plugins load.
		add_action( 'plugins_loaded', array( $this, 'handle_request' ), 9999 );
		add_action( 'wp_loaded', array( $this, 'after_wp_loaded' ) );
		add_action( 'setup_theme', array( $this, 'block_customizer' ), 1 );

		// Strip every internally generated wp-login.php link.
		add_filter( 'site_url', array( $this, 'filter_site_url' ), 10, 4 );
		add_filter( 'network_site_url', array( $this, 'filter_network_site_url' ), 10, 3 );
		add_filter( 'wp_redirect', array( $this, 'filter_wp_redirect' ), 10, 2 );
		add_filter( 'login_url', array( $this, 'filter_login_url' ), 10, 3 );
		add_filter( 'site_option_welcome_email', array( $this, 'filter_welcome_email' ) );
		add_filter( 'user_request_action_email_content', array( $this, 'filter_user_request_email' ), 999, 2 );

		// Stop WP from helpfully redirecting /login, /admin, /dashboard back to wp-admin.
		remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 );

		// Optional: kill the lost-password flow entirely.
		if ( $this->option( 'disable_password_reset' ) ) {
			add_filter( 'allow_password_reset', '__return_false' );
			add_filter( 'show_password_fields', '__return_true' );
		}

		// Multisite "My Sites" admin bar links → rewrite dashboard URL if user isn't logged in there.
		if ( is_multisite() ) {
			add_action( 'wp_before_admin_bar_render', array( $this, 'modify_mysites_menu' ), 999 );
			add_filter( 'manage_sites_action_links', array( $this, 'manage_sites_action_links' ), 10, 3 );
		}
	}

	/**
	 * Activation: install the log table and seed defaults.
	 */
	public static function activate() {
		CPH_Hide_Login_Log::install();
	}

	// ---------------------------------------------------------------------
	// Slug + option helpers
	// ---------------------------------------------------------------------

	/**
	 * Get a plugin option, falling back to the network option then the supplied default.
	 *
	 * @param string $key Bare option name without prefix.
	 * @param mixed  $default
	 * @return mixed
	 */
	public static function option( $key, $default = false ) {
		$name = self::OPTION_PREFIX . $key;

		$site = get_option( $name, null );
		if ( null !== $site && '' !== $site ) {
			return $site;
		}

		if ( is_multisite() ) {
			$net = get_site_option( $name, null );
			if ( null !== $net && '' !== $net ) {
				return $net;
			}
		}

		return $default;
	}

	/**
	 * Resolve the active login slug for this request.
	 */
	private function resolve_slug() {
		if ( defined( 'CPH_HIDE_LOGIN_SLUG' ) && CPH_HIDE_LOGIN_SLUG ) {
			return self::sanitize_slug( CPH_HIDE_LOGIN_SLUG );
		}

		$filtered = apply_filters( 'cph_hide_login_slug', null );
		if ( $filtered ) {
			return self::sanitize_slug( $filtered );
		}

		$option = self::option( 'slug', '' );
		return self::sanitize_slug( $option );
	}

	public static function sanitize_slug( $value ) {
		$value = trim( (string) $value, "/ \t\n\r\0\x0B" );
		$value = sanitize_title_with_dashes( $value );
		return $value;
	}

	public function get_slug() {
		return $this->slug;
	}

	/**
	 * Slugs we must refuse — would collide with public WP query vars and could break the site.
	 */
	public static function forbidden_slugs() {
		$base = array( 'login', 'admin', 'dashboard', 'wp-login', 'wp-login.php', 'wp-admin' );
		if ( class_exists( 'WP' ) ) {
			$wp = new WP();
			return array_unique( array_merge( $wp->public_query_vars, $wp->private_query_vars, $base ) );
		}
		return $base;
	}

	// ---------------------------------------------------------------------
	// URL builders
	// ---------------------------------------------------------------------

	private function use_trailing_slashes() {
		return '/' === substr( get_option( 'permalink_structure' ), -1, 1 );
	}

	private function user_trailingslashit( $url ) {
		return $this->use_trailing_slashes() ? trailingslashit( $url ) : untrailingslashit( $url );
	}

	public function new_login_url( $scheme = null ) {
		$home = apply_filters( 'cph_hide_login_home_url', home_url( '/', $scheme ) );

		if ( get_option( 'permalink_structure' ) ) {
			return $this->user_trailingslashit( $home . $this->slug );
		}

		return $home . '?' . $this->slug;
	}

	// ---------------------------------------------------------------------
	// Request handling
	// ---------------------------------------------------------------------

	/**
	 * Inspect the incoming request and decide whether it should be 404'd or rewritten
	 * into the login flow.
	 *
	 * Runs late on plugins_loaded so we can mutate $_SERVER / $pagenow before WP boots.
	 */
	public function handle_request() {
		global $pagenow;

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? rawurldecode( (string) $_SERVER['REQUEST_URI'] ) : '';
		$request     = wp_parse_url( $request_uri );
		$path        = isset( $request['path'] ) ? $request['path'] : '';

		// 1. Direct hit on wp-login.php / wp-register.php / wp-signup.php / wp-activate.php
		//    while not in /wp-admin/. Hijack the request — render a 404.
		$is_login_php    = ( false !== strpos( $request_uri, 'wp-login.php' ) ) || ( $path && untrailingslashit( $path ) === site_url( 'wp-login', 'relative' ) );
		$is_register_php = false !== strpos( $request_uri, 'wp-register.php' );
		$is_signup_php   = ! is_multisite() && false !== strpos( $request_uri, 'wp-signup' );
		$is_activate_php = ! is_multisite() && false !== strpos( $request_uri, 'wp-activate' );

		if ( ( $is_login_php || $is_register_php || $is_signup_php || $is_activate_php ) && ! is_admin() ) {
			CPH_Hide_Login_Log::record( $is_login_php ? 'wp-login.php' : ( $is_register_php ? 'wp-register.php' : ( $is_signup_php ? 'wp-signup' : 'wp-activate' ) ) );

			$this->is_blocked_request = true;
			// Mangle the URI so WP can't match it to any real route.
			$_SERVER['REQUEST_URI'] = $this->user_trailingslashit( '/' . str_repeat( '-/', 10 ) );
			$pagenow                = 'index.php';
			return;
		}

		// 2. Hit on the custom slug → pretend this was wp-login.php.
		$slug_path_match = $path && untrailingslashit( $path ) === home_url( $this->slug, 'relative' );
		$slug_query_match = ! get_option( 'permalink_structure' )
			&& isset( $_GET[ $this->slug ] )
			&& '' === $_GET[ $this->slug ];

		if ( $slug_path_match || $slug_query_match ) {
			$_SERVER['SCRIPT_NAME'] = $this->slug;
			$pagenow                = 'wp-login.php';
		}
	}

	/**
	 * After WP has loaded: block /wp-admin/ for logged-out users, and route the request.
	 */
	public function after_wp_loaded() {
		global $pagenow;

		$request = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? rawurldecode( $_SERVER['REQUEST_URI'] ) : '' );
		$path    = isset( $request['path'] ) ? $request['path'] : '';

		// Postpass is the cookie-setting handshake for password-protected posts. Don't interfere.
		if ( isset( $_GET['action'] ) && 'postpass' === $_GET['action'] && isset( $_POST['post_password'] ) ) {
			return;
		}

		// Logged-out + wp-admin = 404. Allow admin-ajax, admin-post, options.php, cron, CLI.
		if (
			is_admin()
			&& ! is_user_logged_in()
			&& ! wp_doing_ajax()
			&& ! defined( 'WP_CLI' )
			&& ! defined( 'DOING_CRON' )
			&& 'admin-post.php' !== $pagenow
			&& '/wp-admin/options.php' !== $path
		) {
			CPH_Hide_Login_Log::record( 'wp-admin' );
			$this->serve_block();
		}

		// Catch a logged-out request that snuck through to options.php directly.
		if ( ! is_user_logged_in() && '/wp-admin/options.php' === $path ) {
			CPH_Hide_Login_Log::record( 'wp-admin/options.php' );
			$this->serve_block();
		}

		// Normalise trailing slash on the canonical login URL.
		if ( 'wp-login.php' === $pagenow && $path && $path !== $this->user_trailingslashit( $path ) && get_option( 'permalink_structure' ) ) {
			$qs = isset( $_SERVER['QUERY_STRING'] ) ? (string) $_SERVER['QUERY_STRING'] : '';
			wp_safe_redirect( $this->user_trailingslashit( $this->new_login_url() ) . ( '' !== $qs ? '?' . $qs : '' ) );
			exit;
		}

		// Render a 404 for the hijacked wp-login.php hit.
		if ( $this->is_blocked_request ) {
			$this->serve_block();
		}

		// Render the real login page when the slug matched.
		if ( 'wp-login.php' === $pagenow ) {
			global $error, $interim_login, $action, $user_login;

			if ( is_user_logged_in() && ! isset( $_REQUEST['action'] ) ) {
				$requested = isset( $_REQUEST['redirect_to'] ) ? $_REQUEST['redirect_to'] : '';
				$target    = apply_filters( 'cph_hide_login_logged_in_redirect', admin_url(), $requested, wp_get_current_user() );
				wp_safe_redirect( $target );
				exit;
			}

			@require_once ABSPATH . 'wp-login.php';
			exit;
		}
	}

	/**
	 * Render the blocked-request response: real 404 or redirect to a configured URL.
	 */
	private function serve_block() {
		$mode = self::option( 'block_mode', '404' );

		if ( 'redirect' === $mode ) {
			$target = trim( (string) self::option( 'redirect_url', '' ) );
			if ( '' !== $target ) {
				wp_safe_redirect( esc_url_raw( $target ) );
				exit;
			}
		}

		// Default: real 404 on the home template. No redirect — gives attackers nothing to chase.
		global $wp_query;
		status_header( 404 );
		nocache_headers();
		if ( $wp_query ) {
			$wp_query->set_404();
		}

		$template = function_exists( 'get_404_template' ) ? get_404_template() : '';
		if ( $template && file_exists( $template ) ) {
			include $template;
		} else {
			wp_die( 'Not Found', 'Not Found', array( 'response' => 404 ) );
		}
		exit;
	}

	/**
	 * Block customizer for logged-out users.
	 */
	public function block_customizer() {
		global $pagenow;
		if ( ! is_user_logged_in() && 'customize.php' === $pagenow ) {
			wp_die( 'Not Found', 'Not Found', array( 'response' => 404 ) );
		}
	}

	// ---------------------------------------------------------------------
	// URL filters — rewrite anything that mentions wp-login.php
	// ---------------------------------------------------------------------

	public function filter_site_url( $url, $path, $scheme, $blog_id ) {
		return $this->filter_wp_login_php( $url, $scheme );
	}

	public function filter_network_site_url( $url, $path, $scheme ) {
		return $this->filter_wp_login_php( $url, $scheme );
	}

	public function filter_wp_redirect( $location, $status ) {
		// WordPress.com SSO link — leave alone.
		if ( false !== strpos( $location, 'wordpress.com/wp-login.php' ) ) {
			return $location;
		}
		return $this->filter_wp_login_php( $location );
	}

	private function filter_wp_login_php( $url, $scheme = null ) {
		global $pagenow;

		$origin = $url;

		if ( false !== strpos( $url, 'wp-login.php?action=postpass' ) ) {
			return $url;
		}

		if ( is_multisite() && 'install.php' === $pagenow ) {
			return $url;
		}

		if ( false !== strpos( $url, 'wp-login.php' ) && false === strpos( wp_get_referer(), 'wp-login.php' ) ) {
			if ( is_ssl() ) {
				$scheme = 'https';
			}

			$parts = explode( '?', $url );
			if ( isset( $parts[1] ) ) {
				parse_str( $parts[1], $args );
				if ( isset( $args['login'] ) ) {
					$args['login'] = rawurlencode( $args['login'] );
				}
				$url = add_query_arg( $args, $this->new_login_url( $scheme ) );
			} else {
				$url = $this->new_login_url( $scheme );
			}
		}

		// Post-password authentication path — leave the original URL alone so the cookie flow works.
		if ( isset( $_POST['post_password'] ) && ! is_user_logged_in() ) {
			global $current_user;
			if ( is_wp_error( wp_authenticate_username_password( null, $current_user->user_login ?? '', $_POST['post_password'] ) ) ) {
				return $origin;
			}
		}

		return $url;
	}

	/**
	 * Update url redirect for options.php so plugins that link to it don't leak the real login URL.
	 */
	public function filter_login_url( $login_url, $redirect, $force_reauth ) {
		if ( false === $force_reauth || empty( $redirect ) ) {
			return $login_url;
		}

		$parts = explode( '?', $redirect );
		if ( $parts[0] === admin_url( 'options.php' ) ) {
			$login_url = admin_url();
		}
		return $login_url;
	}

	public function filter_welcome_email( $value ) {
		$slug = $this->slug ? $this->slug : 'wp-login.php';
		return str_replace( 'wp-login.php', trailingslashit( $slug ), $value );
	}

	public function filter_user_request_email( $email_text, $email_data ) {
		if ( empty( $email_data['confirm_url'] ) ) {
			return $email_text;
		}
		$new = str_replace( $this->slug . '/', 'wp-login.php', $email_data['confirm_url'] );
		return str_replace( '###CONFIRM_URL###', esc_url_raw( $new ), $email_text );
	}

	// ---------------------------------------------------------------------
	// Multisite admin bar / sites table tweaks
	// ---------------------------------------------------------------------

	public function modify_mysites_menu() {
		global $wp_admin_bar;
		$nodes = $wp_admin_bar->get_nodes();
		foreach ( $nodes as $node ) {
			if ( preg_match( '/^blog-(\d+)(.*)/', $node->id, $matches ) ) {
				$blog_id   = (int) $matches[1];
				$blog_slug = $this->slug_for_blog( $blog_id );
				if ( ! $blog_slug ) {
					continue;
				}
				if ( ! $matches[2] || '-d' === $matches[2] ) {
					$args       = $node;
					$old        = $args->href;
					$args->href = preg_replace( '/wp-admin\/$/', $blog_slug . '/', $old );
					if ( $old !== $args->href ) {
						$wp_admin_bar->add_node( $args );
					}
				} elseif ( false !== strpos( $node->href, '/wp-admin/' ) ) {
					$wp_admin_bar->remove_node( $node->id );
				}
			}
		}
	}

	private function slug_for_blog( $blog_id ) {
		$site = get_blog_option( $blog_id, self::OPTION_PREFIX . 'slug' );
		if ( $site ) {
			return self::sanitize_slug( $site );
		}
		if ( is_multisite() ) {
			$net = get_site_option( self::OPTION_PREFIX . 'slug' );
			if ( $net ) {
				return self::sanitize_slug( $net );
			}
		}
		return '';
	}

	public function manage_sites_action_links( $actions, $blog_id, $blogname ) {
		$slug = $this->slug_for_blog( $blog_id );
		if ( $slug ) {
			$actions['backend'] = sprintf(
				'<a href="%1$s" class="edit">%2$s</a>',
				esc_url( get_site_url( $blog_id, $slug ) ),
				'Dashboard'
			);
		}
		return $actions;
	}

	// ---------------------------------------------------------------------
	// Notices
	// ---------------------------------------------------------------------

	public function notice_no_slug() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings_url = is_network_admin()
			? network_admin_url( 'settings.php?page=cph-hide-login' )
			: admin_url( 'options-general.php?page=cph-hide-login' );

		printf(
			'<div class="notice notice-warning"><p><strong>Clear pH Hide Login</strong> is active but no login slug is set. Default <code>wp-login.php</code> is still exposed. <a href="%s">Configure the plugin →</a></p></div>',
			esc_url( $settings_url )
		);
	}
}
