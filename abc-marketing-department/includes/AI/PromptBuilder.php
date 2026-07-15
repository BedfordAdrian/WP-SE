<?php
/**
 * Builds the user prompt / data package for an AI run.
 *
 * Only the data categories that survived consent enforcement are included.
 * Missing or delayed sources are stated transparently — never silently omitted.
 *
 * @package ABCMD
 */

namespace ABCMD\AI;

use ABCMD\Repository\Authors;
use ABCMD\Repository\Books;
use ABCMD\Repository\Consent;
use ABCMD\Repository\Files;
use ABCMD\Repository\Metrics;
use ABCMD\Repository\Recommendations;
use ABCMD\Support\Helpers;
use ABCMD\Support\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Assembles the data package supplied to the model.
 */
final class PromptBuilder {

	/**
	 * Build a prompt for a book workspace.
	 *
	 * @param int                 $book_id    Book id.
	 * @param string[]            $categories Allowed data-sharing category slugs.
	 * @param array<string,mixed> $extra      custom_instruction, passages(string),
	 *                                        manuscript_summary(string), file_ids(int[]).
	 * @return string
	 */
	public static function build( int $book_id, array $categories, array $extra = array() ): string {
		$books  = new Books();
		$book   = $books->find( $book_id );
		if ( ! $book ) {
			return '';
		}
		$authors = new Authors();
		$author  = $book['author_id'] ? $authors->find( (int) $book['author_id'] ) : null;
		$metrics = new Metrics();
		$sym     = Helpers::symbol_for( (string) ( $book['currency'] ?? Options::currency() ) );

		$has = static fn( string $c ) => in_array( $c, $categories, true );

		$lines   = array();
		$lines[] = '=== DATA PACKAGE (agency-internal) ===';
		$lines[] = 'Prepared: ' . Helpers::now()->format( 'Y-m-d H:i' ) . ' (' . Options::get( 'timezone', ABCMD_TIMEZONE ) . ')';
		$lines[] = 'Reporting currency: ' . (string) ( $book['currency'] ?? Options::currency() );
		$lines[] = '';

		// --- Book profile ---
		if ( $has( 'book_profile' ) ) {
			$lines[] = '## Book profile';
			$lines[] = 'Title: ' . $book['title'] . ( $book['subtitle'] ? ( ' — ' . $book['subtitle'] ) : '' );
			$lines[] = 'Author (display): ' . ( $author ? Authors::display_name( $author ) : 'Unknown' );
			$lines[] = 'Publisher: ' . ( $book['publisher'] ?: 'n/a' );
			$lines[] = 'Publication date: ' . ( $book['publication_date'] ?: 'n/a' );
			$lines[] = 'Genre: ' . ( $book['genre'] ?: 'n/a' ) . ' | Territory: ' . ( $book['territory'] ?: 'n/a' );
			$lines[] = 'Audience: ' . ( $book['audience'] ?: 'n/a' );
			$lines[] = 'Formats/prices: ' . self::format_prices( $book, $sym );
			$lines[] = 'Campaign start: ' . ( $book['campaign_start'] ?: 'n/a' );
			$lines[] = 'Weekly hours available: ' . (float) $book['weekly_hours'];
			$lines[] = 'Excluded channels: ' . self::list_str( $book['excluded_channels'] ?? array() );
			$lines[] = '';
		}

		if ( $has( 'synopsis' ) && ! empty( $book['synopsis'] ) ) {
			$lines[] = '## Synopsis';
			$lines[] = (string) $book['synopsis'];
			$lines[] = '';
		}

		if ( $has( 'book_profile' ) && ! empty( $book['proposition'] ) ) {
			$lines[] = '## Proposition';
			$lines[] = (string) $book['proposition'];
			$lines[] = '';
		}

		// --- Author profile ---
		if ( $has( 'author_profile' ) && $author ) {
			$lines[] = '## Author profile';
			$lines[] = 'Name: ' . Authors::display_name( $author );
			$lines[] = 'Website: ' . ( $author['website'] ?: 'n/a' );
			if ( ! empty( $author['biography'] ) ) {
				$lines[] = 'Bio: ' . wp_trim_words( (string) $author['biography'], 120 );
			}
			$lines[] = '';
		}

		// --- Targets & budget ---
		if ( $has( 'budget' ) ) {
			$lines[] = '## Targets & budget';
			$lines[] = 'Initial budget: ' . $sym . number_format( (float) $book['budget'], 2 );
			$lines[] = 'Conditional max budget: ' . $sym . number_format( (float) $book['budget_max'], 2 );
			$lines[] = '90-day targets: ' . self::list_str( $book['targets_90day'] ?? array() );
			$lines[] = 'Break-even requirement: ' . self::list_str( $book['break_even'] ?? array() );
			$lines[] = '';
		}

		// --- Sales data ---
		if ( $has( 'sales_data' ) ) {
			$lines[] = '## Sales (dated snapshots)';
			$lines[] = self::metric_summary( $metrics, $book_id, 'sales', $sym );
			$by_format = $metrics->sum_by( $book_id, 'sales', 'format' );
			if ( $by_format ) {
				$lines[] = 'Sales by format: ' . self::kv( $by_format );
			}
			$by_terr = $metrics->sum_by( $book_id, 'sales', 'territory' );
			if ( $by_terr ) {
				$lines[] = 'Sales by territory: ' . self::kv( $by_terr );
			}
			$rev = $metrics->sum( $book_id, 'revenue' );
			if ( $rev ) {
				$lines[] = 'Total revenue recorded: ' . $sym . number_format( $rev, 2 );
			}
			$lines[] = '';
		}

		// --- Advertising ---
		if ( $has( 'advertising_data' ) ) {
			$lines[] = '## Advertising';
			$lines[] = 'Ad spend: ' . $sym . number_format( $metrics->sum( $book_id, 'ad_spend' ), 2 )
				. ' | Clicks: ' . (int) $metrics->sum( $book_id, 'clicks' )
				. ' | Conversions: ' . (int) $metrics->sum( $book_id, 'conversions' );
			$lines[] = '';
		}

		// --- Email ---
		if ( $has( 'email_data' ) ) {
			$lines[] = '## Email';
			$lines[] = 'List size: ' . (int) $metrics->latest_value( $book_id, 'mailing_list_size' )
				. ' | Open rate: ' . self::pct( $metrics->latest_value( $book_id, 'open_rate' ) )
				. ' | Click rate: ' . self::pct( $metrics->latest_value( $book_id, 'click_rate' ) );
			$lines[] = '';
		}

		// --- Social ---
		if ( $has( 'social_data' ) ) {
			$lines[] = '## Social';
			$followers = $metrics->sum_by( $book_id, 'followers', 'platform' );
			$lines[]   = $followers ? ( 'Followers by platform: ' . self::kv( $followers ) ) : 'No follower data recorded.';
			$lines[]   = '';
		}

		// --- Reviews ---
		if ( $has( 'reviews' ) ) {
			$lines[] = '## Reviews';
			$rev_by = $metrics->sum_by( $book_id, 'reviews', 'platform' );
			$lines[] = $rev_by ? ( 'Reviews by platform: ' . self::kv( $rev_by ) ) : 'No review counts recorded.';
			$avg    = $metrics->latest_value( $book_id, 'avg_rating' );
			if ( $avg ) {
				$lines[] = 'Average rating: ' . round( $avg, 2 );
			}
			$lines[] = '';
		}

		// --- Campaign history / previous recommendations ---
		if ( $has( 'previous_recs' ) ) {
			$recs = ( new Recommendations() )->active_for( $book_id );
			if ( $recs ) {
				$lines[] = '## Previous active recommendations';
				foreach ( array_slice( $recs, 0, 10 ) as $r ) {
					$lines[] = '- [' . $r['status'] . '] ' . $r['title'];
				}
				$lines[] = '';
			}
		}

		// --- Manuscript summary / passages (consent-gated categories) ---
		if ( $has( 'manuscript_summary' ) && ! empty( $extra['manuscript_summary'] ) ) {
			$lines[] = '## Manuscript summary (locally generated)';
			$lines[] = (string) $extra['manuscript_summary'];
			$lines[] = '';
		}
		if ( $has( 'manuscript_passages' ) && ! empty( $extra['passages'] ) ) {
			$lines[] = '## Selected manuscript passages';
			$lines[] = (string) $extra['passages'];
			$lines[] = '';
		}
		if ( $has( 'full_manuscript' ) && ! empty( $extra['file_ids'] ) ) {
			$lines[] = '## Full manuscript (extracted text)';
			$lines[] = self::file_text( (array) $extra['file_ids'] );
			$lines[] = '';
		}

		// --- Data gaps note ---
		$last = $metrics->last_date( $book_id );
		$lines[] = '## Data provenance';
		$lines[] = $last ? ( 'Most recent metric date: ' . $last ) : 'No metrics recorded yet.';
		$lines[] = 'Note: figures come from manual imports and may be delayed, estimated or reconciled. Treat gaps as gaps, not zeros.';
		$lines[] = '';

		// --- Custom instruction ---
		if ( ! empty( $extra['custom_instruction'] ) ) {
			$lines[] = '## Specific request from the agency operator';
			$lines[] = (string) $extra['custom_instruction'];
			$lines[] = '';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Directive appended to instructions to elicit a parseable structured block.
	 *
	 * @param string[] $emits Which structures to request.
	 */
	public static function structured_directive( array $emits ): string {
		if ( empty( $emits ) ) {
			return "\n\nReturn clear, well-structured markdown.";
		}

		$schema = array();
		if ( in_array( 'recommendations', $emits, true ) ) {
			$schema[] = '"recommendations": [{"title":str,"description":str,"rec_type":str,"evidence_label":"evidence-backed|inferred|experimental","confidence":"high|medium|low","objective":str,"expected_cost":number,"expected_time":str,"expected_impact":str,"success_metric":str,"stop_rule":str,"scale_rule":str,"rank_score":number(0-100)}]';
		}
		if ( in_array( 'tasks', $emits, true ) ) {
			$schema[] = '"tasks": [{"title":str,"priority":"high|medium|low","effort":str,"budget":number,"notes":str}]';
		}
		if ( in_array( 'content', $emits, true ) ) {
			$schema[] = '"content": [{"channel":str,"format":str,"title":str,"copy":str,"caption":str,"hashtags":str,"cta":str,"image_prompt":str,"objective":str}]';
		}

		return "\n\nAfter your markdown analysis, append EXACTLY ONE fenced code block labelled json containing an object with these keys where relevant:\n{"
			. implode( ', ', $schema )
			. "}\nOnly include arrays you actually have content for. Do not invent citations or figures.";
	}

	/**
	 * Concatenate extracted text for a set of files (capped to keep within limits).
	 *
	 * @param int[] $file_ids File ids.
	 */
	private static function file_text( array $file_ids ): string {
		$files = new Files();
		$out   = array();
		$budget = 120000; // characters cap; extractor already chunked bigger docs.
		foreach ( $file_ids as $fid ) {
			$f = $files->find( (int) $fid );
			if ( $f && ! empty( $f['extracted_text'] ) ) {
				$text  = (string) $f['extracted_text'];
				$out[] = '### ' . $f['original_name'] . "\n" . substr( $text, 0, $budget );
				$budget -= strlen( $text );
				if ( $budget <= 0 ) {
					$out[] = '(Further manuscript text truncated to respect model limits — chunk or summarise for full coverage.)';
					break;
				}
			}
		}
		return implode( "\n\n", $out );
	}

	private static function metric_summary( Metrics $metrics, int $book_id, string $key, string $sym ): string {
		$total = $metrics->sum( $book_id, $key );
		$series = $metrics->weekly_series( $book_id, $key, 6 );
		$weeks  = array();
		foreach ( $series as $yw => $v ) {
			$weeks[] = $yw . '=' . round( $v, 1 );
		}
		return 'Total ' . $key . ': ' . round( $total, 1 ) . ( $weeks ? ( ' | recent weeks: ' . implode( ', ', $weeks ) ) : '' );
	}

	private static function format_prices( array $book, string $sym ): string {
		$prices  = is_array( $book['prices'] ?? null ) ? $book['prices'] : array();
		$formats = is_array( $book['formats'] ?? null ) ? $book['formats'] : array();
		if ( ! $prices && $formats ) {
			return implode( ', ', array_map( 'strval', $formats ) );
		}
		$parts = array();
		foreach ( $prices as $fmt => $price ) {
			$parts[] = $fmt . ' ' . $sym . number_format( (float) $price, 2 );
		}
		return $parts ? implode( ', ', $parts ) : 'n/a';
	}

	private static function list_str( $value ): string {
		if ( is_array( $value ) ) {
			if ( array_is_list( $value ) ) {
				return implode( ', ', array_map( 'strval', $value ) ) ?: 'n/a';
			}
			$parts = array();
			foreach ( $value as $k => $v ) {
				$parts[] = $k . ': ' . ( is_scalar( $v ) ? $v : wp_json_encode( $v ) );
			}
			return implode( ', ', $parts ) ?: 'n/a';
		}
		return '' !== (string) $value ? (string) $value : 'n/a';
	}

	private static function kv( array $map ): string {
		$parts = array();
		foreach ( $map as $k => $v ) {
			$parts[] = $k . '=' . round( (float) $v, 1 );
		}
		return implode( ', ', $parts );
	}

	private static function pct( float $v ): string {
		return round( $v, 1 ) . '%';
	}
}
