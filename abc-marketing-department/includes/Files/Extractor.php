<?php
/**
 * Local text extraction and indexing for uploaded documents.
 *
 * Extraction happens entirely on the WordPress server — source files are never
 * sent to an external extraction service. Reliable formats (TXT, CSV, DOCX,
 * EPUB, XLSX) are fully supported; PDF is best-effort with a clear status when
 * it cannot be read.
 *
 * @package ABCMD
 */

namespace ABCMD\Files;

use ABCMD\Repository\FileChunks;
use ABCMD\Repository\Files;
use ABCMD\Support\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extracts and chunks document text.
 */
final class Extractor {

	private const CHUNK_CHARS = 1500;

	/**
	 * Extract, store and chunk a file's text. Updates the file record.
	 *
	 * @param int $file_id File id.
	 * @return array{status:string,error:string,chunks:int}
	 */
	public static function process( int $file_id ): array {
		$files = new Files();
		$file  = $files->find( $file_id );
		if ( ! $file ) {
			return array( 'status' => 'error', 'error' => 'File record not found.', 'chunks' => 0 );
		}

		$path = (string) $file['stored_path'];
		$type = strtolower( (string) $file['document_type'] );
		$ext  = strtolower( pathinfo( (string) $file['original_name'], PATHINFO_EXTENSION ) );

		try {
			$extracted = self::extract( $path, $ext );
		} catch ( \Throwable $e ) {
			$files->update( $file_id, array( 'extract_status' => 'error', 'extract_error' => substr( $e->getMessage(), 0, 500 ) ) );
			return array( 'status' => 'error', 'error' => $e->getMessage(), 'chunks' => 0 );
		}

		if ( 'not_applicable' === $extracted['status'] ) {
			$files->update( $file_id, array( 'extract_status' => 'not_applicable', 'extract_error' => $extracted['error'] ) );
			return array( 'status' => 'not_applicable', 'error' => $extracted['error'], 'chunks' => 0 );
		}

		$text = trim( $extracted['text'] );
		if ( '' === $text ) {
			$files->update(
				$file_id,
				array(
					'extract_status' => 'error',
					'extract_error'  => $extracted['error'] ?: 'No extractable text found.',
				)
			);
			return array( 'status' => 'error', 'error' => $extracted['error'] ?: 'No text.', 'chunks' => 0 );
		}

		// Store chunks.
		$chunks_repo = new FileChunks();
		$chunks_repo->delete_for_file( $file_id );
		$chunks = self::chunk( $text );
		$i      = 0;
		foreach ( $chunks as $chunk ) {
			$chunks_repo->insert(
				array(
					'file_id'        => $file_id,
					'book_id'        => (int) $file['book_id'],
					'chunk_index'    => $i,
					'reference'      => 'part ' . ( $i + 1 ),
					'content'        => $chunk,
					'token_estimate' => Helpers::estimate_tokens( $chunk ),
				)
			);
			$i++;
		}

		$files->update(
			$file_id,
			array(
				'extract_status' => 'extracted',
				'extract_error'  => $extracted['error'],
				'chunk_count'    => count( $chunks ),
				'extracted_text' => $text,
			)
		);

		return array( 'status' => 'extracted', 'error' => $extracted['error'], 'chunks' => count( $chunks ) );
	}

	/**
	 * Dispatch extraction by extension.
	 *
	 * @param string $path File path.
	 * @param string $ext  Lower-case extension.
	 * @return array{text:string,status:string,error:string}
	 */
	public static function extract( string $path, string $ext ): array {
		if ( ! is_readable( $path ) ) {
			return array( 'text' => '', 'status' => 'error', 'error' => 'File not readable.' );
		}

		switch ( $ext ) {
			case 'txt':
			case 'md':
				return array( 'text' => (string) file_get_contents( $path ), 'status' => 'ok', 'error' => '' );
			case 'csv':
				return self::from_csv( $path );
			case 'docx':
				return self::from_zip_xml( $path, array( 'word/document.xml' ) );
			case 'epub':
				return self::from_epub( $path );
			case 'xlsx':
				return self::from_xlsx( $path );
			case 'pdf':
				return self::from_pdf( $path );
			case 'jpg':
			case 'jpeg':
			case 'png':
			case 'webp':
				return array( 'text' => '', 'status' => 'not_applicable', 'error' => 'Images are stored but not text-extracted.' );
			default:
				return array( 'text' => '', 'status' => 'error', 'error' => 'Unsupported file type for extraction.' );
		}
	}

	/**
	 * Split text into paragraph-aware chunks.
	 *
	 * @param string $text Full text.
	 * @return string[]
	 */
	public static function chunk( string $text ): array {
		$paras  = preg_split( '/\n\s*\n/', $text ) ?: array( $text );
		$chunks = array();
		$buf    = '';
		foreach ( $paras as $p ) {
			$p = trim( $p );
			if ( '' === $p ) {
				continue;
			}
			if ( strlen( $buf ) + strlen( $p ) + 2 > self::CHUNK_CHARS && '' !== $buf ) {
				$chunks[] = $buf;
				$buf      = '';
			}
			if ( strlen( $p ) > self::CHUNK_CHARS ) {
				// Hard-split an oversized paragraph.
				foreach ( str_split( $p, self::CHUNK_CHARS ) as $piece ) {
					if ( '' !== $buf ) {
						$chunks[] = $buf;
						$buf      = '';
					}
					$chunks[] = $piece;
				}
				continue;
			}
			$buf .= ( '' === $buf ? '' : "\n\n" ) . $p;
		}
		if ( '' !== $buf ) {
			$chunks[] = $buf;
		}
		return $chunks;
	}

	// --- Format-specific extractors ---------------------------------------

	private static function from_csv( string $path ): array {
		$lines = array();
		$fh    = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( $fh ) {
			$n = 0;
			while ( ( $row = fgetcsv( $fh, 0, ',' ) ) !== false && $n < 5000 ) {
				$lines[] = implode( ' | ', array_map( 'strval', $row ) );
				$n++;
			}
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return array( 'text' => implode( "\n", $lines ), 'status' => 'ok', 'error' => '' );
	}

	/**
	 * Extract concatenated text from named XML members of a ZIP container.
	 *
	 * @param string   $path    Zip path.
	 * @param string[] $members Member names to read.
	 * @return array{text:string,status:string,error:string}
	 */
	private static function from_zip_xml( string $path, array $members ): array {
		if ( ! class_exists( '\ZipArchive' ) ) {
			return array( 'text' => '', 'status' => 'error', 'error' => 'ZipArchive unavailable — cannot read this format.' );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return array( 'text' => '', 'status' => 'error', 'error' => 'Could not open document container.' );
		}
		$text = '';
		foreach ( $members as $member ) {
			$xml = $zip->getFromName( $member );
			if ( false !== $xml ) {
				$text .= "\n" . self::xml_to_text( $xml );
			}
		}
		$zip->close();
		return array( 'text' => trim( $text ), 'status' => 'ok', 'error' => '' );
	}

	private static function from_epub( string $path ): array {
		if ( ! class_exists( '\ZipArchive' ) ) {
			return array( 'text' => '', 'status' => 'error', 'error' => 'ZipArchive unavailable — cannot read EPUB.' );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return array( 'text' => '', 'status' => 'error', 'error' => 'Could not open EPUB.' );
		}
		$text = '';
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );
			if ( preg_match( '/\.(x?html?)$/i', $name ) ) {
				$html = $zip->getFromIndex( $i );
				if ( false !== $html ) {
					$text .= "\n\n" . self::xml_to_text( $html );
				}
			}
		}
		$zip->close();
		return array( 'text' => trim( $text ), 'status' => 'ok', 'error' => '' );
	}

	private static function from_xlsx( string $path ): array {
		if ( ! class_exists( '\ZipArchive' ) ) {
			return array( 'text' => '', 'status' => 'error', 'error' => 'ZipArchive unavailable — cannot read XLSX.' );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return array( 'text' => '', 'status' => 'error', 'error' => 'Could not open XLSX.' );
		}
		$shared = array();
		$ss_xml = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( false !== $ss_xml && preg_match_all( '/<t[^>]*>(.*?)<\/t>/s', $ss_xml, $m ) ) {
			foreach ( $m[1] as $s ) {
				$shared[] = html_entity_decode( wp_strip_all_tags( $s ), ENT_QUOTES | ENT_XML1 );
			}
		}
		$text = implode( "\n", $shared );
		$zip->close();
		$note = $text ? '' : 'No shared strings found; numeric-only sheets are not extracted.';
		return array( 'text' => $text, 'status' => 'ok', 'error' => $note );
	}

	/**
	 * Best-effort PDF text extraction (uncompressed + FlateDecode streams).
	 *
	 * Pure-PHP PDF extraction is inherently limited: many PDFs use fonts/encodings
	 * that cannot be decoded without a full library. On failure we return a clear
	 * status so the operator can paste passages or upload a DOCX/TXT instead.
	 *
	 * @param string $path PDF path.
	 * @return array{text:string,status:string,error:string}
	 */
	private static function from_pdf( string $path ): array {
		$raw = (string) file_get_contents( $path );
		if ( '' === $raw ) {
			return array( 'text' => '', 'status' => 'error', 'error' => 'Empty PDF.' );
		}

		$text = '';

		// Decode FlateDecode content streams where zlib is available.
		if ( function_exists( 'gzuncompress' ) && preg_match_all( '/stream\r?\n(.*?)\r?\nendstream/s', $raw, $streams ) ) {
			foreach ( $streams[1] as $stream ) {
				$data = @gzuncompress( $stream ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				if ( false !== $data ) {
					$text .= ' ' . self::pdf_text_operators( $data );
				}
			}
		}

		// Also scan any uncompressed text operators.
		$text .= ' ' . self::pdf_text_operators( $raw );

		$text = trim( preg_replace( '/\s+/', ' ', $text ) );

		if ( strlen( $text ) < 30 ) {
			return array(
				'text'   => '',
				'status' => 'error',
				'error'  => 'PDF text could not be reliably extracted (likely scanned or uses embedded font encoding). Upload a DOCX/TXT or paste passages instead.',
			);
		}

		return array( 'text' => $text, 'status' => 'ok', 'error' => 'PDF extraction is best-effort; verify important passages.' );
	}

	/**
	 * Pull text from PDF Tj/TJ operators.
	 *
	 * @param string $data Content stream.
	 */
	private static function pdf_text_operators( string $data ): string {
		$out = '';
		if ( preg_match_all( '/\((?:\\\\.|[^\\\\()])*\)\s*Tj/s', $data, $m ) ) {
			foreach ( $m[0] as $seg ) {
				if ( preg_match( '/\((.*)\)\s*Tj/s', $seg, $mm ) ) {
					$out .= self::pdf_unescape( $mm[1] ) . ' ';
				}
			}
		}
		if ( preg_match_all( '/\[(.*?)\]\s*TJ/s', $data, $m2 ) ) {
			foreach ( $m2[1] as $arr ) {
				if ( preg_match_all( '/\((?:\\\\.|[^\\\\()])*\)/s', $arr, $parts ) ) {
					foreach ( $parts[0] as $p ) {
						$out .= self::pdf_unescape( substr( $p, 1, -1 ) );
					}
					$out .= ' ';
				}
			}
		}
		return $out;
	}

	private static function pdf_unescape( string $s ): string {
		return str_replace(
			array( '\\(', '\\)', '\\\\', '\\n', '\\r', '\\t' ),
			array( '(', ')', '\\', "\n", "\r", "\t" ),
			$s
		);
	}

	/**
	 * Strip XML/HTML tags to readable text, preserving paragraph breaks.
	 *
	 * @param string $xml XML/HTML source.
	 */
	private static function xml_to_text( string $xml ): string {
		// DOCX uses <w:p> paragraphs and <w:tab/>; HTML uses <p>,<br>.
		$xml = preg_replace( '/<w:tab[^>]*>/', "\t", $xml );
		$xml = preg_replace( '/<\/w:p>|<\/p>|<br[^>]*>/i', "\n", $xml );
		$xml = preg_replace( '/<\/(h[1-6]|div|li)>/i', "\n", $xml );
		$text = wp_strip_all_tags( $xml );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_XML1 );
		return preg_replace( "/[ \t]+/", ' ', $text );
	}
}
