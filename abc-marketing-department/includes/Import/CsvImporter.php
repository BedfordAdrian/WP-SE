<?php
/**
 * CSV importer: parse, preview, map, validate, de-duplicate, insert, roll back.
 *
 * @package ABCMD
 */

namespace ABCMD\Import;

use ABCMD\Repository\Books;
use ABCMD\Repository\Imports;
use ABCMD\Repository\Metrics;
use ABCMD\Support\Audit;
use ABCMD\Support\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns an uploaded CSV into dated metric snapshots.
 */
final class CsvImporter {

	/**
	 * Parse a CSV file into headers + rows. Handles a leading UTF-8 BOM.
	 *
	 * @param string $path    Absolute file path.
	 * @param int    $max_rows Safety cap (0 = unlimited).
	 * @return array{headers:string[],rows:array<int,array<int,string>>,error:string}
	 */
	public static function parse( string $path, int $max_rows = 0 ): array {
		$out = array( 'headers' => array(), 'rows' => array(), 'error' => '' );
		if ( ! is_readable( $path ) ) {
			$out['error'] = __( 'File is not readable.', 'abc-marketing-department' );
			return $out;
		}
		$fh = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $fh ) {
			$out['error'] = __( 'Could not open file.', 'abc-marketing-department' );
			return $out;
		}

		$first = true;
		$count = 0;
		while ( ( $row = fgetcsv( $fh, 0, ',' ) ) !== false ) {
			if ( $first ) {
				// Strip BOM from the first cell.
				if ( isset( $row[0] ) ) {
					$row[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $row[0] );
				}
				$out['headers'] = array_map( static fn( $h ) => trim( (string) $h ), $row );
				$first          = false;
				continue;
			}
			// Skip fully-empty lines.
			if ( count( array_filter( $row, static fn( $c ) => '' !== trim( (string) $c ) ) ) === 0 ) {
				continue;
			}
			$out['rows'][] = array_map( static fn( $c ) => (string) $c, $row );
			$count++;
			if ( $max_rows > 0 && $count >= $max_rows ) {
				break;
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $out;
	}

	/**
	 * SHA-256 checksum of a file.
	 *
	 * @param string $path File path.
	 */
	public static function checksum( string $path ): string {
		$hash = @hash_file( 'sha256', $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return $hash ?: '';
	}

	/**
	 * Import parsed data into metrics.
	 *
	 * @param array<string,mixed> $args book_id, template, source_status, mapping,
	 *   date_format, currency, format_map, retailer_map, path, original_filename,
	 *   checksum, confirm_duplicate(bool).
	 * @return array{ok:bool,import_id:int,imported:int,rejected:int,duplicate:int,total:int,errors:array<int,array<string,mixed>>,message:string}
	 */
	public static function import( array $args ): array {
		$result = array(
			'ok'        => false,
			'import_id' => 0,
			'imported'  => 0,
			'rejected'  => 0,
			'duplicate' => 0,
			'total'     => 0,
			'errors'    => array(),
			'message'   => '',
		);

		$book_id  = (int) ( $args['book_id'] ?? 0 );
		$template = (string) ( $args['template'] ?? 'generic_sales' );
		$path     = (string) ( $args['path'] ?? '' );
		$mapping  = (array) ( $args['mapping'] ?? array() );
		$dfmt     = (string) ( $args['date_format'] ?? 'auto' );
		$currency = strtoupper( (string) ( $args['currency'] ?? 'GBP' ) );
		$sstatus  = (string) ( $args['source_status'] ?? 'live' );
		$fmt_map  = (array) ( $args['format_map'] ?? array() );
		$ret_map  = (array) ( $args['retailer_map'] ?? array() );

		$books = new Books();
		$book  = $books->find( $book_id );
		if ( ! $book ) {
			$result['message'] = __( 'Workspace not found.', 'abc-marketing-department' );
			return $result;
		}
		$tpl = Templates::get( $template );
		if ( ! $tpl ) {
			$result['message'] = __( 'Unknown import template.', 'abc-marketing-department' );
			return $result;
		}

		$checksum = (string) ( $args['checksum'] ?? ( $path ? self::checksum( $path ) : '' ) );
		$imports  = new Imports();
		if ( $checksum && empty( $args['confirm_duplicate'] ) && $imports->checksum_seen( $book_id, $checksum ) ) {
			$result['message']   = __( 'This exact file has already been imported for this workspace. Re-submit with "import anyway" to proceed.', 'abc-marketing-department' );
			$result['duplicate'] = -1; // Signal: whole-file duplicate.
			return $result;
		}

		$parsed = self::parse( $path );
		if ( $parsed['error'] ) {
			$result['message'] = $parsed['error'];
			return $result;
		}

		$headers = $parsed['headers'];
		$rows    = $parsed['rows'];
		$result['total'] = count( $rows );

		$fields   = Templates::fields();
		$header_i = array_flip( $headers ); // header => column index.
		$metrics  = new Metrics();
		$source   = (string) ( $tpl['source'] ?? $template );

		// Create the import record first so metrics can reference its id.
		$import_id = $imports->insert(
			array(
				'book_id'           => $book_id,
				'author_id'         => (int) $book['author_id'],
				'source'            => $source,
				'source_status'     => $sstatus,
				'template'          => $template,
				'original_filename' => (string) ( $args['original_filename'] ?? basename( $path ) ),
				'checksum'          => $checksum,
				'rows_total'        => $result['total'],
				'mapping'           => $mapping,
				'user_id'           => get_current_user_id(),
				'status'            => 'processing',
			)
		);
		$result['import_id'] = $import_id;

		foreach ( $rows as $line_no => $row ) {
			$get = static function ( string $target ) use ( $mapping, $header_i, $row ) {
				$src = $mapping[ $target ] ?? '';
				if ( '' === $src || ! isset( $header_i[ $src ] ) ) {
					return '';
				}
				$idx = $header_i[ $src ];
				return isset( $row[ $idx ] ) ? trim( (string) $row[ $idx ] ) : '';
			};

			// Date is required.
			$raw_date = $get( 'date' );
			$date     = self::parse_date( $raw_date, $dfmt );
			if ( ! $date ) {
				$result['rejected']++;
				$result['errors'][] = array( 'row' => $line_no + 2, 'reason' => 'Missing/invalid date', 'value' => $raw_date );
				continue;
			}

			$format    = self::map_value( $get( 'format' ), $fmt_map );
			$territory = $get( 'territory' );
			$retailer  = self::map_value( $get( 'retailer' ), $ret_map );
			$platform  = $get( 'platform' );
			$channel   = $get( 'channel' );
			$row_ident = $get( 'identifier' );
			$row_src   = '' !== $retailer ? $retailer : $source;

			// Row base for dedup: prefer explicit identifier; else hash raw row.
			$base = '' !== $row_ident ? $row_ident : substr( sha1( implode( '|', $row ) ), 0, 16 );

			$made      = 0;
			$row_dupes = 0;
			foreach ( $fields as $target => $def ) {
				if ( empty( $def['metric'] ) ) {
					continue; // Only numeric/currency targets create metrics.
				}
				if ( empty( $mapping[ $target ] ) ) {
					continue; // Not mapped.
				}
				$raw_val = $get( $target );
				if ( '' === $raw_val ) {
					continue;
				}
				$val = self::parse_number( $raw_val );
				if ( null === $val ) {
					continue;
				}

				$metric_key = (string) $def['metric'];
				$dedup      = sha1( implode( '|', array( $book_id, $template, $metric_key, $date, $format, $territory, $row_src, $channel, $base ) ) );

				if ( $metrics->dedup_exists( $dedup ) ) {
					$row_dupes++;
					continue;
				}

				$is_currency = ( 'currency' === ( $def['type'] ?? '' ) );
				$ok = $metrics->insert(
					array(
						'book_id'       => $book_id,
						'author_id'     => (int) $book['author_id'],
						'metric_date'   => $date,
						'metric_key'    => $metric_key,
						'format'        => $format,
						'territory'     => $territory,
						'channel'       => $channel,
						'platform'      => $platform,
						'value_num'     => $val,
						'currency'      => $is_currency ? $currency : '',
						'source'        => $row_src,
						'source_status' => $sstatus,
						'import_id'     => $import_id,
						'dedup_key'     => $dedup,
						'is_adjustment' => 0,
					)
				);
				if ( $ok ) {
					$made++;
				}
			}

			if ( $made > 0 ) {
				$result['imported']++;
			} elseif ( $row_dupes > 0 ) {
				// Row's values were all previously imported.
				$result['duplicate']++;
			} else {
				// Valid date but no usable numeric mapped values.
				$result['rejected']++;
				$result['errors'][] = array( 'row' => $line_no + 2, 'reason' => 'No mapped numeric values', 'value' => '' );
			}
		}

		// Finalise import record.
		$imports->update(
			$import_id,
			array(
				'rows_imported'  => $result['imported'],
				'rows_rejected'  => $result['rejected'],
				'rows_duplicate' => max( 0, $result['duplicate'] ),
				'errors'         => array_slice( $result['errors'], 0, 500 ),
				'status'         => 'completed',
			)
		);

		$audit_id = Audit::log(
			'import.create',
			sprintf( 'Imported %s: %d rows in, %d rejected, %d duplicate.', $source, $result['imported'], $result['rejected'], max( 0, $result['duplicate'] ) ),
			array(
				'import_id' => $import_id,
				'template'  => $template,
				'checksum'  => $checksum,
				'status'    => $sstatus,
			),
			$book_id,
			(int) $book['author_id']
		);
		$imports->update( $import_id, array( 'audit_id' => $audit_id ) );

		$result['ok']      = true;
		$result['message'] = __( 'Import complete.', 'abc-marketing-department' );
		return $result;
	}

	/**
	 * Roll back an import: delete its metrics and mark it rolled back.
	 *
	 * @param int $import_id Import id.
	 * @return int Metric rows removed.
	 */
	public static function rollback( int $import_id ): int {
		$imports = new Imports();
		$rec     = $imports->find( $import_id );
		if ( ! $rec ) {
			return 0;
		}
		$removed = ( new Metrics() )->delete_by_import( $import_id );
		$imports->update( $import_id, array( 'status' => 'rolled_back' ) );
		Audit::log(
			'import.rollback',
			sprintf( 'Rolled back import #%d — removed %d metric rows.', $import_id, $removed ),
			array( 'import_id' => $import_id ),
			(int) $rec['book_id'],
			(int) $rec['author_id']
		);
		return $removed;
	}

	/**
	 * Insert a single manual adjustment metric.
	 *
	 * @param array<string,mixed> $args book_id, metric_key, value, date, format,
	 *   territory, note, currency.
	 * @return int Metric row id.
	 */
	public static function manual_adjustment( array $args ): int {
		$books = new Books();
		$book  = $books->find( (int) ( $args['book_id'] ?? 0 ) );
		if ( ! $book ) {
			return 0;
		}
		$metrics = new Metrics();
		$date    = self::parse_date( (string) ( $args['date'] ?? '' ), 'auto' ) ?: current_time( 'Y-m-d' );
		$id      = $metrics->insert(
			array(
				'book_id'       => (int) $book['id'],
				'author_id'     => (int) $book['author_id'],
				'metric_date'   => $date,
				'metric_key'    => sanitize_key( (string) ( $args['metric_key'] ?? 'sales' ) ),
				'format'        => sanitize_text_field( (string) ( $args['format'] ?? '' ) ),
				'territory'     => sanitize_text_field( (string) ( $args['territory'] ?? '' ) ),
				'value_num'     => (float) ( $args['value'] ?? 0 ),
				'currency'      => strtoupper( (string) ( $args['currency'] ?? '' ) ),
				'source'        => 'Manual adjustment',
				'source_status' => 'manually_adjusted',
				'is_adjustment' => 1,
				'meta'          => array( 'note' => (string) ( $args['note'] ?? '' ) ),
			)
		);
		Audit::log( 'metric.adjustment', 'Manual metric adjustment entered.', array( 'metric_key' => $args['metric_key'] ?? '' ), (int) $book['id'], (int) $book['author_id'] );
		return $id;
	}

	/**
	 * Parse a date cell using an explicit format or best-effort detection.
	 *
	 * @param string $value Raw cell.
	 * @param string $fmt   'auto' or a strftime-like key: dmy, mdy, ymd, or a PHP format.
	 * @return string|null YYYY-MM-DD or null.
	 */
	public static function parse_date( string $value, string $fmt = 'auto' ): ?string {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}

		$named = array(
			'ymd' => 'Y-m-d',
			'dmy' => 'd/m/Y',
			'mdy' => 'm/d/Y',
		);
		if ( isset( $named[ $fmt ] ) ) {
			$dt = \DateTime::createFromFormat( $named[ $fmt ], $value );
			if ( $dt ) {
				return $dt->format( 'Y-m-d' );
			}
		} elseif ( 'auto' !== $fmt && '' !== $fmt ) {
			$dt = \DateTime::createFromFormat( $fmt, $value );
			if ( $dt ) {
				return $dt->format( 'Y-m-d' );
			}
		}

		// Auto: try common explicit formats first to avoid ambiguity surprises.
		foreach ( array( 'Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'Y/m/d', 'd M Y', 'M d, Y', 'Y-m' ) as $try ) {
			$dt = \DateTime::createFromFormat( $try, $value );
			if ( $dt && $dt->format( $try ) === $value ) {
				return $dt->format( 'Y-m-d' );
			}
		}

		$ts = strtotime( $value );
		return $ts ? gmdate( 'Y-m-d', $ts ) : null;
	}

	/**
	 * Parse a numeric cell, tolerating currency symbols, thousands separators
	 * and parenthesised negatives.
	 *
	 * @param string $value Raw cell.
	 * @return float|null
	 */
	public static function parse_number( string $value ): ?float {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}
		$neg = false;
		if ( preg_match( '/^\((.*)\)$/', $value, $m ) ) {
			$neg   = true;
			$value = $m[1];
		}
		if ( str_starts_with( $value, '-' ) ) {
			$neg = true;
		}
		// Remove everything except digits and separators.
		$clean = preg_replace( '/[^0-9.,]/', '', $value );
		if ( '' === $clean ) {
			return null;
		}
		// If both separators present, assume last one is decimal.
		if ( str_contains( $clean, ',' ) && str_contains( $clean, '.' ) ) {
			if ( strrpos( $clean, ',' ) > strrpos( $clean, '.' ) ) {
				$clean = str_replace( '.', '', $clean );
				$clean = str_replace( ',', '.', $clean );
			} else {
				$clean = str_replace( ',', '', $clean );
			}
		} elseif ( str_contains( $clean, ',' ) ) {
			// Comma only: treat as thousands unless it looks decimal (e.g. 12,50).
			if ( preg_match( '/,\d{1,2}$/', $clean ) && substr_count( $clean, ',' ) === 1 ) {
				$clean = str_replace( ',', '.', $clean );
			} else {
				$clean = str_replace( ',', '', $clean );
			}
		}
		if ( ! is_numeric( $clean ) ) {
			return null;
		}
		$num = (float) $clean;
		return $neg ? -abs( $num ) : $num;
	}

	/**
	 * Apply a value map (source label => canonical), case-insensitively.
	 *
	 * @param string               $value Source value.
	 * @param array<string,string> $map   Mapping.
	 */
	private static function map_value( string $value, array $map ): string {
		if ( '' === $value || empty( $map ) ) {
			return $value;
		}
		foreach ( $map as $from => $to ) {
			if ( strcasecmp( (string) $from, $value ) === 0 ) {
				return (string) $to;
			}
		}
		return $value;
	}
}
