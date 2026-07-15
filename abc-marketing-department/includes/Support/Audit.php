<?php
/**
 * Audit logging.
 *
 * Records sensitive plugin actions to a searchable/filterable table. Sensitive
 * values (API keys, tokens, full manuscripts) must never be passed here.
 *
 * @package ABCMD
 */

namespace ABCMD\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Append-only audit trail writer/reader.
 */
final class Audit {

	/**
	 * Record an audit entry.
	 *
	 * @param string               $action     Machine action key (e.g. import.create).
	 * @param string               $summary    Short human description (no secrets).
	 * @param array<string,mixed>  $context    Extra structured context (no secrets).
	 * @param int|null             $book_id    Optional related book/workspace id.
	 * @param int|null             $author_id  Optional related author id.
	 * @return int Inserted row id (0 on failure).
	 */
	public static function log( string $action, string $summary, array $context = array(), ?int $book_id = null, ?int $author_id = null ): int {
		global $wpdb;

		$table = self::table();

		$user_id = get_current_user_id();
		$user    = $user_id ? wp_get_current_user() : null;

		// Defensive: scrub obvious secret keys from context.
		$context = self::scrub( $context );

		$ok = $wpdb->insert(
			$table,
			array(
				'created_at' => Helpers::now_mysql(),
				'user_id'    => $user_id ? $user_id : null,
				'user_login' => $user ? $user->user_login : 'system',
				'action'     => substr( $action, 0, 100 ),
				'summary'    => substr( wp_strip_all_tags( $summary ), 0, 500 ),
				'book_id'    => $book_id,
				'author_id'  => $author_id,
				'context'    => Helpers::json( $context ),
				'ip'         => self::client_ip(),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Query the audit log with simple filters.
	 *
	 * @param array<string,mixed> $args action, search, book_id, author_id, user_id, from, to, limit, offset.
	 * @return array<int,array<string,mixed>>
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;
		$table = self::table();

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['action'] ) ) {
			$where[]  = 'action = %s';
			$params[] = (string) $args['action'];
		}
		if ( ! empty( $args['book_id'] ) ) {
			$where[]  = 'book_id = %d';
			$params[] = (int) $args['book_id'];
		}
		if ( ! empty( $args['author_id'] ) ) {
			$where[]  = 'author_id = %d';
			$params[] = (int) $args['author_id'];
		}
		if ( ! empty( $args['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $args['user_id'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(summary LIKE %s OR action LIKE %s OR context LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		if ( ! empty( $args['from'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = (string) $args['from'];
		}
		if ( ! empty( $args['to'] ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = (string) $args['to'];
		}

		$limit  = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : 100;
		$offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

		$sql = 'SELECT * FROM ' . $table . ' WHERE ' . implode( ' AND ', $where )
			. ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$params[] = $limit;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table is internal; values are prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count rows matching the same filter set (for pagination).
	 *
	 * @param array<string,mixed> $args Same filters as query() (minus limit/offset).
	 */
	public static function count( array $args = array() ): int {
		global $wpdb;
		$table = self::table();

		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['action'] ) ) {
			$where[]  = 'action = %s';
			$params[] = (string) $args['action'];
		}
		if ( ! empty( $args['book_id'] ) ) {
			$where[]  = 'book_id = %d';
			$params[] = (int) $args['book_id'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(summary LIKE %s OR action LIKE %s OR context LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$sql = 'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . implode( ' AND ', $where );
		if ( $params ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Distinct action keys present in the log (for filter dropdowns).
	 *
	 * @return string[]
	 */
	public static function distinct_actions(): array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_col( 'SELECT DISTINCT action FROM ' . $table . ' ORDER BY action ASC' );
		return is_array( $rows ) ? $rows : array();
	}

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'abcmd_audit_log';
	}

	private static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		return substr( $ip, 0, 45 );
	}

	/**
	 * Remove likely-secret keys from a context array before storage.
	 *
	 * @param array<string,mixed> $context Raw context.
	 * @return array<string,mixed>
	 */
	private static function scrub( array $context ): array {
		$blocked = array( 'api_key', 'apikey', 'key', 'token', 'secret', 'authorization', 'password' );
		foreach ( $context as $k => $v ) {
			if ( in_array( strtolower( (string) $k ), $blocked, true ) ) {
				$context[ $k ] = '[redacted]';
			}
		}
		return $context;
	}
}
