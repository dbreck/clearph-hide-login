<?php
/**
 * Access log for Clear pH Hide Login.
 *
 * Records blocked attempts to reach wp-login.php / wp-admin while logged out.
 */

defined( 'ABSPATH' ) || exit;

class CPH_Hide_Login_Log {

	const TABLE = 'cph_hide_login_log';

	/**
	 * Full table name for the current site.
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create or update the schema. Called on activation and on version bump.
	 */
	public static function install() {
		global $wpdb;

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ip VARCHAR(45) NOT NULL DEFAULT '',
			user_agent VARCHAR(255) NOT NULL DEFAULT '',
			request_uri VARCHAR(512) NOT NULL DEFAULT '',
			reason VARCHAR(64) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY ip (ip)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Record a blocked attempt. Skips silently when logging is off.
	 */
	public static function record( $reason ) {
		if ( ! CPH_Hide_Login::option( 'logging' ) ) {
			return;
		}

		global $wpdb;

		$wpdb->insert(
			self::table_name(),
			array(
				'ip'          => self::client_ip(),
				'user_agent'  => substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 255 ),
				'request_uri' => substr( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), 0, 512 ),
				'reason'      => substr( (string) $reason, 0, 64 ),
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		// Opportunistic prune (about 1 in 50 inserts).
		if ( wp_rand( 1, 50 ) === 1 ) {
			self::prune();
		}
	}

	/**
	 * Delete entries older than the configured retention period.
	 */
	public static function prune() {
		global $wpdb;

		$days = (int) CPH_Hide_Login::option( 'log_retention_days', 30 );
		if ( $days < 1 ) {
			return;
		}

		$table = self::table_name();
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s",
				gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
			)
		);
	}

	/**
	 * Clear every row.
	 */
	public static function clear() {
		global $wpdb;
		$table = self::table_name();
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	/**
	 * Drop the table (uninstall).
	 */
	public static function drop() {
		global $wpdb;
		$table = self::table_name();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	/**
	 * Fetch a page of entries, newest first.
	 *
	 * @param int $per_page
	 * @param int $page 1-indexed.
	 * @return array{rows:array,total:int}
	 */
	public static function fetch( $per_page = 50, $page = 1 ) {
		global $wpdb;

		$table    = self::table_name();
		$per_page = max( 1, (int) $per_page );
		$page     = max( 1, (int) $page );
		$offset   = ( $page - 1 ) * $per_page;

		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, ip, user_agent, request_uri, reason, created_at
				 FROM {$table}
				 ORDER BY id DESC
				 LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		return array(
			'rows'  => $rows ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Best-effort client IP. Trusts X-Forwarded-For first hop (Flywheel sits behind a proxy).
	 */
	private static function client_ip() {
		$candidates = array( 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );

		foreach ( $candidates as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}
			$ip = explode( ',', (string) $_SERVER[ $key ] )[0];
			$ip = trim( $ip );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}

		return '';
	}
}
