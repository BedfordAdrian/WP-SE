<?php
/**
 * Generic table repository with prepared queries.
 *
 * Subclasses declare their table key, column formats and JSON columns. All
 * writes go through wpdb->insert/update with explicit formats; all reads use
 * prepared statements.
 *
 * @package ABCMD
 */

namespace ABCMD\Repository;

use ABCMD\Database\Schema;
use ABCMD\Support\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class for entity repositories.
 */
abstract class BaseRepository {

	/**
	 * Bare table key (e.g. 'books').
	 */
	abstract protected function key(): string;

	/**
	 * Column => wpdb format map (e.g. ['title' => '%s', 'budget' => '%f']).
	 *
	 * @return array<string,string>
	 */
	abstract protected function columns(): array;

	/**
	 * Columns whose values are stored as JSON longtext.
	 *
	 * @return string[]
	 */
	protected function json_columns(): array {
		return array();
	}

	/**
	 * Fully-qualified table name.
	 */
	public function table(): string {
		return Schema::table( $this->key() );
	}

	/**
	 * Insert a row. Returns the new id or 0 on failure.
	 *
	 * @param array<string,mixed> $data Column values.
	 */
	public function insert( array $data ): int {
		global $wpdb;
		$prepared = $this->prepare_write( $data, true );
		$ok       = $wpdb->insert( $this->table(), $prepared['data'], $prepared['format'] );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update a row by id. Returns rows affected (false on error).
	 *
	 * @param int                 $id   Row id.
	 * @param array<string,mixed> $data Column values.
	 * @return int|false
	 */
	public function update( int $id, array $data ) {
		global $wpdb;
		$prepared = $this->prepare_write( $data, false );
		return $wpdb->update( $this->table(), $prepared['data'], array( 'id' => $id ), $prepared['format'], array( '%d' ) );
	}

	/**
	 * Fetch a single row by id as an associative array (JSON columns decoded).
	 *
	 * @param int $id Row id.
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id ),
			ARRAY_A
		);
		return $row ? $this->decode_row( $row ) : null;
	}

	/**
	 * Delete a row by id.
	 *
	 * @param int $id Row id.
	 */
	public function delete( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Query rows with equality filters, ordering and paging.
	 *
	 * @param array<string,mixed> $where   Column => value equality filters.
	 * @param string              $orderby Column to sort by.
	 * @param string              $order   ASC|DESC.
	 * @param int                 $limit   Max rows (0 = no limit).
	 * @param int                 $offset  Offset.
	 * @return array<int,array<string,mixed>>
	 */
	public function where( array $where = array(), string $orderby = 'id', string $order = 'DESC', int $limit = 0, int $offset = 0 ): array {
		global $wpdb;

		$columns = array_merge( array( 'id' => '%d', 'created_at' => '%s', 'updated_at' => '%s' ), $this->columns() );
		$clauses = array( '1=1' );
		$params  = array();

		foreach ( $where as $col => $val ) {
			if ( ! isset( $columns[ $col ] ) && 'id' !== $col ) {
				continue; // Ignore unknown columns (defensive).
			}
			$fmt       = $columns[ $col ] ?? '%d';
			$clauses[] = $col . ' = ' . $fmt;
			$params[]  = $val;
		}

		$orderby = preg_replace( '/[^a-z0-9_]/i', '', $orderby ) ?: 'id';
		$order   = strtoupper( $order ) === 'ASC' ? 'ASC' : 'DESC';

		$sql = 'SELECT * FROM ' . $this->table() . ' WHERE ' . implode( ' AND ', $clauses )
			. ' ORDER BY ' . $orderby . ' ' . $order;

		if ( $limit > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = $limit;
			$params[] = $offset;
		}

		if ( $params ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table internal; values prepared.
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $sql, ARRAY_A );
		}

		return array_map( array( $this, 'decode_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Count rows matching equality filters.
	 *
	 * @param array<string,mixed> $where Column => value filters.
	 */
	public function count( array $where = array() ): int {
		global $wpdb;
		$columns = array_merge( array( 'id' => '%d' ), $this->columns() );
		$clauses = array( '1=1' );
		$params  = array();
		foreach ( $where as $col => $val ) {
			if ( ! isset( $columns[ $col ] ) ) {
				continue;
			}
			$clauses[] = $col . ' = ' . $columns[ $col ];
			$params[]  = $val;
		}
		$sql = 'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE ' . implode( ' AND ', $clauses );
		if ( $params ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Return all rows (optionally filtered) — convenience for small sets.
	 *
	 * @param array<string,mixed> $where Filters.
	 * @return array<int,array<string,mixed>>
	 */
	public function all( array $where = array(), string $orderby = 'id', string $order = 'DESC' ): array {
		return $this->where( $where, $orderby, $order, 0, 0 );
	}

	/**
	 * Encode/normalise a write payload and compute wpdb formats.
	 *
	 * @param array<string,mixed> $data     Input values.
	 * @param bool                $is_insert Whether to add created_at.
	 * @return array{data:array<string,mixed>,format:string[]}
	 */
	protected function prepare_write( array $data, bool $is_insert ): array {
		$columns = $this->columns();
		$json    = $this->json_columns();
		$out     = array();
		$format  = array();

		foreach ( $data as $col => $val ) {
			if ( ! isset( $columns[ $col ] ) ) {
				continue; // Never write unknown columns.
			}
			if ( in_array( $col, $json, true ) ) {
				$out[ $col ] = is_string( $val ) ? $val : Helpers::json( $val );
				$format[]    = '%s';
			} else {
				$out[ $col ] = $val;
				$format[]    = $columns[ $col ];
			}
		}

		$now = Helpers::now_mysql();
		if ( isset( $columns['updated_at'] ) || $this->has_timestamp( 'updated_at' ) ) {
			$out['updated_at'] = $now;
			$format[]          = '%s';
		}
		if ( $is_insert && ( isset( $columns['created_at'] ) || $this->has_timestamp( 'created_at' ) ) ) {
			$out['created_at'] = $now;
			$format[]          = '%s';
		}

		return array(
			'data'   => $out,
			'format' => $format,
		);
	}

	/**
	 * Whether the table has a given timestamp column (created_at/updated_at).
	 * These are implicit and not listed in columns().
	 *
	 * @param string $name Column name.
	 */
	protected function has_timestamp( string $name ): bool {
		return in_array( $name, $this->timestamp_columns(), true );
	}

	/**
	 * Timestamp columns present on the table.
	 *
	 * @return string[]
	 */
	protected function timestamp_columns(): array {
		return array( 'created_at', 'updated_at' );
	}

	/**
	 * Decode JSON columns on a fetched row.
	 *
	 * @param array<string,mixed> $row Raw DB row.
	 * @return array<string,mixed>
	 */
	protected function decode_row( array $row ): array {
		foreach ( $this->json_columns() as $col ) {
			if ( array_key_exists( $col, $row ) ) {
				$row[ $col . '_raw' ] = $row[ $col ];
				$row[ $col ]          = Helpers::unjson( is_string( $row[ $col ] ) ? $row[ $col ] : null );
			}
		}
		return $row;
	}
}
