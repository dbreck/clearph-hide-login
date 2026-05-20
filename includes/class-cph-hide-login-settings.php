<?php
/**
 * Admin UI for Clear pH Hide Login.
 *
 * Adds:
 *  - Settings → Clear pH Hide Login (per-site)
 *  - Network Admin → Settings → Clear pH Hide Login (multisite default)
 *  - "View Access Log" tab on the same page
 */

defined( 'ABSPATH' ) || exit;

class CPH_Hide_Login_Settings {

	const MENU_SLUG       = 'cph-hide-login';
	const SETTINGS_GROUP  = 'cph_hide_login_settings';
	const NONCE_NETWORK   = 'cph_hide_login_network_save';
	const NONCE_CLEAR_LOG = 'cph_hide_login_clear_log';

	/**
	 * @var CPH_Hide_Login_Settings
	 */
	private static $instance;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'network_admin_menu', array( $this, 'register_network_menu' ) );
		add_action( 'admin_post_cph_hide_login_save_network', array( $this, 'handle_network_save' ) );
		add_action( 'admin_post_cph_hide_login_clear_log', array( $this, 'handle_clear_log' ) );

		add_filter( 'plugin_action_links_' . CPH_HIDE_LOGIN_BASENAME, array( $this, 'plugin_action_links' ) );
		add_filter( 'network_admin_plugin_action_links_' . CPH_HIDE_LOGIN_BASENAME, array( $this, 'network_plugin_action_links' ) );
	}

	/**
	 * Register options + sanitizers.
	 */
	public function register_settings() {
		register_setting( self::SETTINGS_GROUP, 'cph_hide_login_slug', array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_slug' ),
			'default'           => '',
		) );
		register_setting( self::SETTINGS_GROUP, 'cph_hide_login_block_mode', array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_block_mode' ),
			'default'           => '404',
		) );
		register_setting( self::SETTINGS_GROUP, 'cph_hide_login_redirect_url', array(
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'default'           => '',
		) );
		register_setting( self::SETTINGS_GROUP, 'cph_hide_login_disable_password_reset', array(
			'type'              => 'boolean',
			'sanitize_callback' => array( $this, 'sanitize_bool' ),
			'default'           => 0,
		) );
		register_setting( self::SETTINGS_GROUP, 'cph_hide_login_logging', array(
			'type'              => 'boolean',
			'sanitize_callback' => array( $this, 'sanitize_bool' ),
			'default'           => 1,
		) );
		register_setting( self::SETTINGS_GROUP, 'cph_hide_login_log_retention_days', array(
			'type'              => 'integer',
			'sanitize_callback' => array( $this, 'sanitize_retention' ),
			'default'           => 30,
		) );
	}

	public function sanitize_slug( $value ) {
		$slug = CPH_Hide_Login::sanitize_slug( $value );
		if ( '' === $slug ) {
			return '';
		}
		if ( in_array( $slug, CPH_Hide_Login::forbidden_slugs(), true ) || false !== strpos( $slug, 'wp-login' ) ) {
			add_settings_error( 'cph_hide_login_slug', 'forbidden', sprintf( 'The slug "%s" is reserved by WordPress and cannot be used.', esc_html( $slug ) ) );
			return get_option( 'cph_hide_login_slug', '' );
		}
		return $slug;
	}

	public function sanitize_block_mode( $value ) {
		return ( 'redirect' === $value ) ? 'redirect' : '404';
	}

	public function sanitize_bool( $value ) {
		return $value ? 1 : 0;
	}

	public function sanitize_retention( $value ) {
		$value = (int) $value;
		if ( $value < 1 ) {
			return 30;
		}
		return min( $value, 365 );
	}

	// ---------------------------------------------------------------------
	// Menus
	// ---------------------------------------------------------------------

	public function register_menu() {
		add_options_page(
			'Clear pH Hide Login',
			'Clear pH Hide Login',
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function register_network_menu() {
		add_submenu_page(
			'settings.php',
			'Clear pH Hide Login',
			'Clear pH Hide Login',
			'manage_network_options',
			self::MENU_SLUG,
			array( $this, 'render_network_page' )
		);
	}

	public function plugin_action_links( $links ) {
		array_unshift( $links, '<a href="' . admin_url( 'options-general.php?page=' . self::MENU_SLUG ) . '">Settings</a>' );
		return $links;
	}

	public function network_plugin_action_links( $links ) {
		array_unshift( $links, '<a href="' . network_admin_url( 'settings.php?page=' . self::MENU_SLUG ) . '">Settings</a>' );
		return $links;
	}

	// ---------------------------------------------------------------------
	// Per-site settings page
	// ---------------------------------------------------------------------

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		$tab = isset( $_GET['tab'] ) && 'log' === $_GET['tab'] ? 'log' : 'settings';
		?>
		<div class="wrap">
			<h1>Clear pH Hide Login</h1>

			<h2 class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . self::MENU_SLUG ) ); ?>" class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>">Settings</a>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . self::MENU_SLUG . '&tab=log' ) ); ?>" class="nav-tab <?php echo 'log' === $tab ? 'nav-tab-active' : ''; ?>">Access Log</a>
			</h2>

			<?php
			if ( 'log' === $tab ) {
				$this->render_log_tab( admin_url( 'options-general.php?page=' . self::MENU_SLUG . '&tab=log' ) );
			} else {
				$this->render_settings_tab();
			}
			?>
		</div>
		<?php
	}

	private function render_settings_tab() {
		settings_errors();
		$slug                   = get_option( 'cph_hide_login_slug', '' );
		$block_mode             = get_option( 'cph_hide_login_block_mode', '404' );
		$redirect_url           = get_option( 'cph_hide_login_redirect_url', '' );
		$disable_password_reset = (bool) get_option( 'cph_hide_login_disable_password_reset', 0 );
		$logging                = (bool) get_option( 'cph_hide_login_logging', 1 );
		$retention              = (int) get_option( 'cph_hide_login_log_retention_days', 30 );

		$network_slug = is_multisite() ? get_site_option( 'cph_hide_login_slug', '' ) : '';
		$effective    = $slug ? $slug : $network_slug;
		?>
		<?php if ( $effective ) : ?>
			<div class="notice notice-success inline" style="margin-top:16px;">
				<p>Login URL: <strong><a href="<?php echo esc_url( home_url( '/' . $effective ) ); ?>"><?php echo esc_html( home_url( '/' . $effective ) ); ?></a></strong> &mdash; bookmark this page.</p>
			</div>
		<?php else : ?>
			<div class="notice notice-warning inline" style="margin-top:16px;">
				<p>No login slug set. The default <code>wp-login.php</code> is still exposed until you choose one.</p>
			</div>
		<?php endif; ?>

		<form action="options.php" method="post">
			<?php settings_fields( self::SETTINGS_GROUP ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="cph_hide_login_slug">Login URL slug</label></th>
					<td>
						<code><?php echo esc_html( trailingslashit( home_url() ) ); ?></code>
						<input type="text" id="cph_hide_login_slug" name="cph_hide_login_slug" value="<?php echo esc_attr( $slug ); ?>" class="regular-text" placeholder="<?php echo esc_attr( $network_slug ? $network_slug . ' (network default)' : 'e.g. cph-portal-7g3xq' ); ?>">
						<p class="description">Lowercase letters, numbers and dashes. Pick something a bot won't guess. Leave blank to fall back to the network default<?php echo $network_slug ? ' (<code>' . esc_html( $network_slug ) . '</code>)' : ''; ?> or disable hiding entirely.</p>
					</td>
				</tr>

				<tr>
					<th scope="row">Blocked access response</th>
					<td>
						<fieldset>
							<label><input type="radio" name="cph_hide_login_block_mode" value="404" <?php checked( $block_mode, '404' ); ?>> Serve a real 404 (recommended)</label><br>
							<label><input type="radio" name="cph_hide_login_block_mode" value="redirect" <?php checked( $block_mode, 'redirect' ); ?>> Redirect to a URL:</label>
							<input type="url" name="cph_hide_login_redirect_url" value="<?php echo esc_attr( $redirect_url ); ?>" class="regular-text" placeholder="https://example.com/">
						</fieldset>
						<p class="description">A real 404 gives attackers no signal. Redirects can leak the existence of the site or a different "blocked" page.</p>
					</td>
				</tr>

				<tr>
					<th scope="row">Password reset</th>
					<td>
						<label>
							<input type="checkbox" name="cph_hide_login_disable_password_reset" value="1" <?php checked( $disable_password_reset ); ?>>
							Disable the "Lost your password?" flow entirely
						</label>
						<p class="description">Stops the password-reset emails at the source. Admins can still reset passwords from the Users screen.</p>
					</td>
				</tr>

				<tr>
					<th scope="row">Access logging</th>
					<td>
						<label>
							<input type="checkbox" name="cph_hide_login_logging" value="1" <?php checked( $logging ); ?>>
							Log blocked attempts to <code><?php echo esc_html( CPH_Hide_Login_Log::table_name() ); ?></code>
						</label>
						<br><br>
						<label>
							Keep logs for
							<input type="number" name="cph_hide_login_log_retention_days" value="<?php echo esc_attr( $retention ); ?>" min="1" max="365" class="small-text"> days
						</label>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	private function render_log_tab( $base_url ) {
		$per_page = 50;
		$page     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$data     = CPH_Hide_Login_Log::fetch( $per_page, $page );

		$total       = (int) $data['total'];
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		?>
		<div style="margin-top:16px;display:flex;align-items:center;gap:12px;">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Clear all access log entries?');">
				<?php wp_nonce_field( self::NONCE_CLEAR_LOG ); ?>
				<input type="hidden" name="action" value="cph_hide_login_clear_log">
				<button type="submit" class="button">Clear log</button>
			</form>
			<span class="description"><?php echo (int) $total; ?> total entries</span>
		</div>

		<table class="wp-list-table widefat fixed striped" style="margin-top:16px;">
			<thead>
				<tr>
					<th style="width:160px;">When</th>
					<th style="width:140px;">IP</th>
					<th style="width:140px;">Reason</th>
					<th>Request URI</th>
					<th>User Agent</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $data['rows'] ) ) : ?>
					<tr><td colspan="5">No blocked attempts recorded yet.</td></tr>
				<?php else : ?>
					<?php foreach ( $data['rows'] as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['created_at'] ); ?></td>
							<td><code><?php echo esc_html( $row['ip'] ); ?></code></td>
							<td><?php echo esc_html( $row['reason'] ); ?></td>
							<td><code><?php echo esc_html( $row['request_uri'] ); ?></code></td>
							<td><?php echo esc_html( $row['user_agent'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="tablenav bottom"><div class="tablenav-pages">
				<?php
				echo paginate_links( array(
					'base'      => add_query_arg( 'paged', '%#%', $base_url ),
					'format'    => '',
					'current'   => $page,
					'total'     => $total_pages,
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
				) );
				?>
			</div></div>
		<?php endif; ?>
		<?php
	}

	public function handle_clear_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( self::NONCE_CLEAR_LOG );

		CPH_Hide_Login_Log::clear();

		wp_safe_redirect( add_query_arg(
			array( 'page' => self::MENU_SLUG, 'tab' => 'log', 'cleared' => '1' ),
			admin_url( 'options-general.php' )
		) );
		exit;
	}

	// ---------------------------------------------------------------------
	// Network settings page
	// ---------------------------------------------------------------------

	public function render_network_page() {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		$saved = isset( $_GET['updated'] ) && 'true' === $_GET['updated'];
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';

		$slug                   = get_site_option( 'cph_hide_login_slug', '' );
		$block_mode             = get_site_option( 'cph_hide_login_block_mode', '404' );
		$redirect_url           = get_site_option( 'cph_hide_login_redirect_url', '' );
		$disable_password_reset = (bool) get_site_option( 'cph_hide_login_disable_password_reset', 0 );
		$logging                = (bool) get_site_option( 'cph_hide_login_logging', 1 );
		$retention              = (int) get_site_option( 'cph_hide_login_log_retention_days', 30 );
		?>
		<div class="wrap">
			<h1>Clear pH Hide Login &mdash; Network defaults</h1>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p>Network defaults saved.</p></div>
			<?php endif; ?>
			<?php if ( '' !== $error ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>

			<p>These values act as the default for every subsite. Individual sites can override them in <em>Settings → Clear pH Hide Login</em>.</p>

			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<?php wp_nonce_field( self::NONCE_NETWORK ); ?>
				<input type="hidden" name="action" value="cph_hide_login_save_network">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cph_hide_login_slug">Default login slug</label></th>
						<td><input type="text" id="cph_hide_login_slug" name="cph_hide_login_slug" value="<?php echo esc_attr( $slug ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row">Blocked access response</th>
						<td>
							<fieldset>
								<label><input type="radio" name="cph_hide_login_block_mode" value="404" <?php checked( $block_mode, '404' ); ?>> 404</label>
								<label style="margin-left:12px;"><input type="radio" name="cph_hide_login_block_mode" value="redirect" <?php checked( $block_mode, 'redirect' ); ?>> Redirect:</label>
								<input type="url" name="cph_hide_login_redirect_url" value="<?php echo esc_attr( $redirect_url ); ?>" class="regular-text">
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row">Password reset</th>
						<td>
							<label><input type="checkbox" name="cph_hide_login_disable_password_reset" value="1" <?php checked( $disable_password_reset ); ?>> Disabled by default network-wide</label>
						</td>
					</tr>
					<tr>
						<th scope="row">Access logging</th>
						<td>
							<label><input type="checkbox" name="cph_hide_login_logging" value="1" <?php checked( $logging ); ?>> Enabled by default</label>
							<br><br>
							<label>Keep logs for <input type="number" name="cph_hide_login_log_retention_days" value="<?php echo esc_attr( $retention ); ?>" min="1" max="365" class="small-text"> days</label>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Save network defaults' ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_network_save() {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( self::NONCE_NETWORK );

		$slug_raw = isset( $_POST['cph_hide_login_slug'] ) ? wp_unslash( $_POST['cph_hide_login_slug'] ) : '';
		$slug     = CPH_Hide_Login::sanitize_slug( $slug_raw );
		if ( '' !== $slug && ( in_array( $slug, CPH_Hide_Login::forbidden_slugs(), true ) || false !== strpos( $slug, 'wp-login' ) ) ) {
			wp_safe_redirect( add_query_arg(
				array(
					'page'  => self::MENU_SLUG,
					'error' => rawurlencode( sprintf( 'The slug "%s" is reserved by WordPress and cannot be used.', $slug ) ),
				),
				network_admin_url( 'settings.php' )
			) );
			exit;
		}

		update_site_option( 'cph_hide_login_slug', $slug );
		update_site_option( 'cph_hide_login_block_mode', isset( $_POST['cph_hide_login_block_mode'] ) && 'redirect' === $_POST['cph_hide_login_block_mode'] ? 'redirect' : '404' );
		update_site_option( 'cph_hide_login_redirect_url', isset( $_POST['cph_hide_login_redirect_url'] ) ? esc_url_raw( wp_unslash( $_POST['cph_hide_login_redirect_url'] ) ) : '' );
		update_site_option( 'cph_hide_login_disable_password_reset', ! empty( $_POST['cph_hide_login_disable_password_reset'] ) ? 1 : 0 );
		update_site_option( 'cph_hide_login_logging', ! empty( $_POST['cph_hide_login_logging'] ) ? 1 : 0 );

		$retention = isset( $_POST['cph_hide_login_log_retention_days'] ) ? (int) $_POST['cph_hide_login_log_retention_days'] : 30;
		if ( $retention < 1 ) {
			$retention = 30;
		}
		update_site_option( 'cph_hide_login_log_retention_days', min( $retention, 365 ) );

		wp_safe_redirect( add_query_arg(
			array( 'page' => self::MENU_SLUG, 'updated' => 'true' ),
			network_admin_url( 'settings.php' )
		) );
		exit;
	}
}
