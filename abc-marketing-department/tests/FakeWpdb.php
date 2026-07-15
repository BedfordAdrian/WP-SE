<?php
/**
 * Minimal in-memory $wpdb for render smoke tests.
 *
 * Supports the subset of wpdb used by the plugin: prepare(), insert(), update(),
 * delete(), get_row(), get_results(), get_var(), get_col(), esc_like(). SELECTs
 * understand simple equality filters and LIMIT; aggregates return 0/empty. Good
 * enough to render pages and exercise CRUD without a real database.
 *
 * @package ABCMD
 */

namespace ABCMD\Tests;

class FakeWpdb {

	public string $prefix = 'wp_';
	public int $insert_id = 0;
	public string $last_error = '';

	/** @var array<string,array<int,array<string,mixed>>> */
	private array $store = array();
	/** @var array<string,int> */
	private array $seq = array();

	public function get_charset_collate(): string {
		return '';
	}

	public function esc_like( $text ): string {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function prepare( $query, ...$args ) {
		if ( count( $args ) === 1 && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback(
			'/%[dsfF]/',
			function ( $m ) use ( &$i, $args ) {
				$val = $args[ $i ] ?? '';
				$i++;
				return match ( $m[0] ) {
					'%d'          => (string) (int) $val,
					'%f', '%F'    => (string) (float) $val,
					default       => "'" . str_replace( "'", "''", (string) $val ) . "'",
				};
			},
			(string) $query
		);
	}

	public function insert( $table, $data, $format = null ): int {
		$this->seq[ $table ] = ( $this->seq[ $table ] ?? 0 ) + 1;
		$id                  = $this->seq[ $table ];
		$row                 = array_merge( array( 'id' => $id ), $data );
		$this->store[ $table ][] = $row;
		$this->insert_id     = $id;
		return 1;
	}

	public function update( $table, $data, $where, $fmt = null, $wfmt = null ): int {
		$n = 0;
		foreach ( $this->store[ $table ] ?? array() as $idx => $row ) {
			if ( $this->matches( $row, $where ) ) {
				$this->store[ $table ][ $idx ] = array_merge( $row, $data );
				$n++;
			}
		}
		return $n;
	}

	public function delete( $table, $where, $fmt = null ): int {
		$n = 0;
		foreach ( $this->store[ $table ] ?? array() as $idx => $row ) {
			if ( $this->matches( $row, $where ) ) {
				unset( $this->store[ $table ][ $idx ] );
				$n++;
			}
		}
		if ( isset( $this->store[ $table ] ) ) {
			$this->store[ $table ] = array_values( $this->store[ $table ] );
		}
		return $n;
	}

	public function query( $sql ) {
		// DROP/DELETE-all etc. — nothing to do for smoke tests.
		return 0;
	}

	public function get_row( $sql, $output = ARRAY_A ) {
		$rows = $this->select( (string) $sql );
		return $rows[0] ?? null;
	}

	public function get_results( $sql, $output = ARRAY_A ) {
		return $this->select( (string) $sql );
	}

	public function get_var( $sql ) {
		$sql = (string) $sql;
		if ( preg_match( '/COUNT\(\*\)/i', $sql ) ) {
			return (string) count( $this->select( $sql, true ) );
		}
		// SUM/MAX/aggregates not modelled — return 0.
		if ( preg_match( '/SUM\(|MAX\(|COALESCE\(/i', $sql ) ) {
			return '0';
		}
		// Scalar lookup (e.g. SELECT id FROM ... WHERE ...): return first match.
		$rows = $this->select( $sql );
		if ( empty( $rows ) ) {
			return null;
		}
		if ( preg_match( '/SELECT\s+(\w+)\s+FROM/i', $sql, $cm ) && isset( $rows[0][ $cm[1] ] ) ) {
			return (string) $rows[0][ $cm[1] ];
		}
		return (string) reset( $rows[0] );
	}

	public function get_col( $sql ) {
		return array();
	}

	// --- helpers -----------------------------------------------------------

	/**
	 * Very small SELECT emulator: table + equality filters + LIMIT.
	 *
	 * @param string $sql        Prepared SQL (values already inlined).
	 * @param bool   $count_only Whether the caller only needs matching rows for a count.
	 * @return array<int,array<string,mixed>>
	 */
	private function select( string $sql, bool $count_only = false ): array {
		if ( ! preg_match( '/FROM\s+(\w+)/i', $sql, $tm ) ) {
			return array();
		}
		$table = $tm[1];
		$rows  = $this->store[ $table ] ?? array();

		// Parse simple equality conditions after WHERE (ignore 1=1).
		$filters = array();
		if ( preg_match( '/WHERE\s+(.*?)(?:GROUP BY|ORDER BY|LIMIT|$)/is', $sql, $wm ) ) {
			$clause = $wm[1];
			if ( preg_match_all( "/(\w+)\s*=\s*'([^']*)'/", $clause, $sm, PREG_SET_ORDER ) ) {
				foreach ( $sm as $s ) {
					$filters[ $s[1] ] = $s[2];
				}
			}
			if ( preg_match_all( '/(\w+)\s*=\s*(-?\d+)(?!\d)/', $clause, $nm, PREG_SET_ORDER ) ) {
				foreach ( $nm as $n ) {
					if ( '1' === $n[1] ) {
						continue; // skip 1=1
					}
					$filters[ $n[1] ] = $n[2];
				}
			}
		}

		$out = array();
		foreach ( $rows as $row ) {
			if ( $this->matches( $row, $filters ) ) {
				$out[] = $row;
			}
		}

		if ( ! $count_only && preg_match( '/LIMIT\s+(\d+)/i', $sql, $lm ) ) {
			$out = array_slice( $out, 0, (int) $lm[1] );
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $row     Row.
	 * @param array<string,mixed> $filters Equality filters.
	 */
	private function matches( array $row, array $filters ): bool {
		foreach ( $filters as $k => $v ) {
			if ( ! array_key_exists( $k, $row ) ) {
				return false;
			}
			if ( (string) $row[ $k ] !== (string) $v ) {
				return false;
			}
		}
		return true;
	}
}
