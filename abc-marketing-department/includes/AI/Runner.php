<?php
/**
 * Orchestrates an AI run end-to-end.
 *
 * Enforces consent, rate limits and cost thresholds; builds the prompt; calls
 * the Responses API; logs the run; and (for tasks that emit them) creates
 * recommendations, draft tasks and proposed content in the approval queue.
 *
 * @package ABCMD
 */

namespace ABCMD\AI;

use ABCMD\Repository\AiRuns;
use ABCMD\Repository\Books;
use ABCMD\Repository\Consent;
use ABCMD\Repository\Content;
use ABCMD\Repository\Recommendations;
use ABCMD\Repository\Tasks;
use ABCMD\Support\Audit;
use ABCMD\Support\Helpers;
use ABCMD\Support\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI run controller.
 */
final class Runner {

	/**
	 * Pre-run cost estimate for the data-sharing / confirmation screen.
	 *
	 * @param int                 $book_id    Book id.
	 * @param string              $run_type   Task slug.
	 * @param string              $model      Model id.
	 * @param string[]            $categories Allowed categories.
	 * @param array<string,mixed> $extra      Extra prompt inputs.
	 * @param bool                $web_search Whether web search is on.
	 * @return array{input_tokens:int,output_tokens:int,cost:float,currency:string,over_threshold:bool,threshold:float}
	 */
	public static function estimate( int $book_id, string $run_type, string $model, array $categories, array $extra, bool $web_search ): array {
		$prompt       = PromptBuilder::build( $book_id, $categories, $extra );
		$instruction  = TaskTypes::system_instruction( $run_type );
		$in_tokens    = Helpers::estimate_tokens( $prompt . $instruction ) + ( $web_search ? 8000 : 0 );
		$out_tokens   = self::expected_output_tokens( $run_type );
		$calc         = Cost::estimate( $model, $in_tokens, $out_tokens, $web_search );
		$threshold    = (float) Options::get( 'ai_cost_confirm_threshold', 1.0 );

		return array(
			'input_tokens'   => $in_tokens,
			'output_tokens'  => $out_tokens,
			'cost'           => $calc['cost'],
			'currency'       => $calc['currency'],
			'over_threshold' => $calc['cost'] > $threshold,
			'threshold'      => $threshold,
		);
	}

	/**
	 * Execute a run. Returns a structured result including the ai_run id.
	 *
	 * @param array<string,mixed> $args book_id, run_type, model, web_search, categories,
	 *                                  extra, scheduled(bool), confirmed(bool).
	 * @return array{ok:bool,ai_run_id:int,error:string,error_code:string,text:string,created:array<string,int>}
	 */
	public static function run( array $args ): array {
		$out = array(
			'ok'         => false,
			'ai_run_id'  => 0,
			'error'      => '',
			'error_code' => '',
			'text'       => '',
			'created'    => array( 'recommendations' => 0, 'tasks' => 0, 'content' => 0 ),
		);

		$book_id    = (int) ( $args['book_id'] ?? 0 );
		$run_type   = (string) ( $args['run_type'] ?? 'custom' );
		$model      = (string) ( $args['model'] ?? OpenAIClient::settings()['default_model'] );
		$web_search = ! empty( $args['web_search'] );
		$categories = array_values( (array) ( $args['categories'] ?? array() ) );
		$extra      = (array) ( $args['extra'] ?? array() );
		$scheduled  = ! empty( $args['scheduled'] );

		$books = new Books();
		$book  = $books->find( $book_id );
		if ( ! $book ) {
			$out['error']      = __( 'Workspace not found.', 'abc-marketing-department' );
			$out['error_code'] = 'no_book';
			return $out;
		}
		$author_id = (int) $book['author_id'];

		// 1) Consent gate.
		$consent = new Consent();
		if ( ! $consent->ai_allowed( $book_id ) ) {
			$out['error']      = __( 'AI is not permitted for this workspace. Record client consent first.', 'abc-marketing-department' );
			$out['error_code'] = 'no_consent';
			Audit::log( 'ai.blocked_consent', 'AI run blocked: no consent.', array( 'run_type' => $run_type ), $book_id, $author_id );
			return $out;
		}

		// 2) Enforce data-sharing against consent.
		$enforced   = DataSharing::enforce( $book_id, $categories );
		$categories = $enforced['allowed'];

		// 3) Web-search permission (consent + settings).
		if ( $web_search && ! $consent->category_allowed( $book_id, 'web_research' ) ) {
			$web_search = false;
		}

		// 4) Rate limit.
		$runs      = new AiRuns();
		$limit     = (int) Options::get( 'ai_rate_limit_per_hour', 60 );
		$user_id   = get_current_user_id();
		if ( ! $scheduled && $user_id && $runs->count_recent_for_user( $user_id ) >= $limit ) {
			$out['error']      = __( 'AI hourly rate limit reached. Try again later.', 'abc-marketing-department' );
			$out['error_code'] = 'rate_limit';
			return $out;
		}

		// 5) Cost threshold confirmation (interactive only).
		$estimate = self::estimate( $book_id, $run_type, $model, $categories, $extra, $web_search );
		if ( ! $scheduled && $estimate['over_threshold'] && empty( $args['confirmed'] ) ) {
			$out['error']      = sprintf(
				/* translators: 1: estimated cost, 2: threshold */
				__( 'Estimated cost %1$s exceeds the confirmation threshold %2$s. Re-submit with confirmation.', 'abc-marketing-department' ),
				$estimate['currency'] . ' ' . number_format( $estimate['cost'], 4 ),
				$estimate['currency'] . ' ' . number_format( $estimate['threshold'], 2 )
			);
			$out['error_code'] = 'needs_confirm';
			return $out;
		}

		// 6) Build prompt + call API.
		$prompt      = PromptBuilder::build( $book_id, $categories, $extra );
		$type        = TaskTypes::get( $run_type ) ?? array( 'emits' => array() );
		$emits       = (array) ( $type['emits'] ?? array() );
		$instruction = TaskTypes::system_instruction( $run_type ) . PromptBuilder::structured_directive( $emits );

		$api = OpenAIClient::respond(
			$model,
			$instruction,
			$prompt,
			array(
				'web_search'        => $web_search,
				'max_output_tokens' => self::expected_output_tokens( $run_type ),
			)
		);

		// 7) Persist the run (whether success or failure) so cost/errors are recorded.
		$actual = Cost::calculate( $api['model'], $api['input_tokens'], $api['output_tokens'], $api['web_calls'] );

		$ai_run_id = $runs->insert(
			array(
				'book_id'          => $book_id,
				'author_id'        => $author_id,
				'run_type'         => $run_type,
				'user_id'          => $user_id,
				'model'            => $api['model'],
				'source_materials' => $extra['file_ids'] ?? array(),
				'data_sharing'     => $categories,
				'prompt'           => $prompt,
				'response'         => $api['text'],
				'web_search'       => $web_search ? 1 : 0,
				'sources'          => $api['sources'],
				'input_tokens'     => $api['input_tokens'],
				'output_tokens'    => $api['output_tokens'],
				'est_cost'         => $estimate['cost'],
				'actual_cost'      => $actual['cost'],
				'currency'         => $actual['currency'],
				'approval_status'  => 'awaiting_review',
				'error_state'      => $api['ok'] ? '' : ( $api['error_code'] . ': ' . $api['error'] ),
			)
		);
		$out['ai_run_id'] = $ai_run_id;

		Audit::log(
			'ai.run',
			sprintf( 'AI run "%s" (%s) — %s', $run_type, $api['model'], $api['ok'] ? 'ok' : ( 'error: ' . $api['error_code'] ) ),
			array(
				'ai_run_id'   => $ai_run_id,
				'data_shared' => $categories,
				'web_search'  => $web_search,
				'est_cost'    => $estimate['cost'],
				'actual_cost' => $actual['cost'],
				'variance'    => round( $actual['cost'] - $estimate['cost'], 6 ),
			),
			$book_id,
			$author_id
		);

		if ( ! $api['ok'] ) {
			$out['error']      = $api['error'];
			$out['error_code'] = $api['error_code'];
			return $out;
		}

		$out['ok']   = true;
		$out['text'] = $api['text'];

		// 8) Parse & persist structured outputs into the approval queue.
		if ( $emits ) {
			$out['created'] = self::persist_structured( $api['text'], $api['sources'], $emits, $book_id, $author_id, $ai_run_id );
		}

		return $out;
	}

	/**
	 * Extract the trailing JSON block and create recs/tasks/content.
	 *
	 * @param string                          $text      Model output.
	 * @param array<int,array<string,string>> $sources   Web sources.
	 * @param string[]                        $emits     Expected structures.
	 * @param int                             $book_id   Book id.
	 * @param int                             $author_id Author id.
	 * @param int                             $ai_run_id AI run id.
	 * @return array{recommendations:int,tasks:int,content:int}
	 */
	private static function persist_structured( string $text, array $sources, array $emits, int $book_id, int $author_id, int $ai_run_id ): array {
		$counts = array( 'recommendations' => 0, 'tasks' => 0, 'content' => 0 );
		$data   = self::extract_json( $text );
		if ( ! is_array( $data ) ) {
			Audit::log( 'ai.parse_warning', 'AI output had no parseable JSON block; stored raw response only.', array( 'ai_run_id' => $ai_run_id ), $book_id, $author_id );
			return $counts;
		}

		if ( in_array( 'recommendations', $emits, true ) && ! empty( $data['recommendations'] ) ) {
			$repo = new Recommendations();
			foreach ( (array) $data['recommendations'] as $r ) {
				if ( empty( $r['title'] ) ) {
					continue;
				}
				$repo->insert(
					array(
						'book_id'         => $book_id,
						'author_id'       => $author_id,
						'ai_run_id'       => $ai_run_id,
						'title'           => sanitize_text_field( (string) $r['title'] ),
						'description'     => wp_kses_post( (string) ( $r['description'] ?? '' ) ),
						'rec_type'        => sanitize_text_field( (string) ( $r['rec_type'] ?? '' ) ),
						'evidence_label'  => self::evidence_label( (string) ( $r['evidence_label'] ?? 'inferred' ) ),
						'confidence'      => self::confidence( (string) ( $r['confidence'] ?? 'medium' ) ),
						'objective'       => sanitize_text_field( (string) ( $r['objective'] ?? '' ) ),
						'expected_cost'   => Helpers::money( $r['expected_cost'] ?? 0 ),
						'expected_time'   => sanitize_text_field( (string) ( $r['expected_time'] ?? '' ) ),
						'expected_impact' => sanitize_text_field( (string) ( $r['expected_impact'] ?? '' ) ),
						'success_metric'  => sanitize_text_field( (string) ( $r['success_metric'] ?? '' ) ),
						'stop_rule'       => sanitize_text_field( (string) ( $r['stop_rule'] ?? '' ) ),
						'scale_rule'      => sanitize_text_field( (string) ( $r['scale_rule'] ?? '' ) ),
						'source_links'    => $sources,
						'rank_score'      => (float) ( $r['rank_score'] ?? 0 ),
						'status'          => 'awaiting_review',
					)
				);
				$counts['recommendations']++;
			}
		}

		if ( in_array( 'tasks', $emits, true ) && ! empty( $data['tasks'] ) ) {
			$repo = new Tasks();
			foreach ( (array) $data['tasks'] as $t ) {
				if ( empty( $t['title'] ) ) {
					continue;
				}
				$repo->insert(
					array(
						'book_id'   => $book_id,
						'author_id' => $author_id,
						'title'     => sanitize_text_field( (string) $t['title'] ),
						'priority'  => self::priority( (string) ( $t['priority'] ?? 'medium' ) ),
						'effort'    => sanitize_text_field( (string) ( $t['effort'] ?? '' ) ),
						'budget'    => Helpers::money( $t['budget'] ?? 0 ),
						'notes'     => wp_kses_post( (string) ( $t['notes'] ?? '' ) ),
						'status'    => 'draft',
					)
				);
				$counts['tasks']++;
			}
		}

		if ( in_array( 'content', $emits, true ) && ! empty( $data['content'] ) ) {
			$repo = new Content();
			foreach ( (array) $data['content'] as $c ) {
				if ( empty( $c['title'] ) && empty( $c['copy'] ) ) {
					continue;
				}
				$repo->insert(
					array(
						'book_id'         => $book_id,
						'author_id'       => $author_id,
						'ai_run_id'       => $ai_run_id,
						'channel'         => sanitize_text_field( (string) ( $c['channel'] ?? '' ) ),
						'format'          => sanitize_text_field( (string) ( $c['format'] ?? '' ) ),
						'title'           => sanitize_text_field( (string) ( $c['title'] ?? '' ) ),
						'copy'            => wp_kses_post( (string) ( $c['copy'] ?? '' ) ),
						'caption'         => wp_kses_post( (string) ( $c['caption'] ?? '' ) ),
						'hashtags'        => sanitize_text_field( (string) ( $c['hashtags'] ?? '' ) ),
						'cta'             => sanitize_text_field( (string) ( $c['cta'] ?? '' ) ),
						'image_prompt'    => wp_kses_post( (string) ( $c['image_prompt'] ?? '' ) ),
						'objective'       => sanitize_text_field( (string) ( $c['objective'] ?? '' ) ),
						'approval_status' => 'awaiting_review',
					)
				);
				$counts['content']++;
			}
		}

		return $counts;
	}

	/**
	 * Pull the last fenced ```json block (or a bare {...}) and decode it.
	 *
	 * @param string $text Model output.
	 * @return array<string,mixed>|null
	 */
	public static function extract_json( string $text ): ?array {
		if ( preg_match_all( '/```json\s*(\{.*?\})\s*```/s', $text, $m ) && ! empty( $m[1] ) ) {
			$json = end( $m[1] );
			$decoded = json_decode( (string) $json, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		// Fallback: last balanced-looking object.
		if ( preg_match( '/\{(?:[^{}]|(?R))*\}/s', $text, $mm ) ) {
			$decoded = json_decode( $mm[0], true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return null;
	}

	private static function expected_output_tokens( string $run_type ): int {
		$big = array( 'full_audit', 'weekly_review', 'plan_90', 'content_calendar' );
		return in_array( $run_type, $big, true ) ? 2500 : 1200;
	}

	private static function evidence_label( string $v ): string {
		$v = strtolower( trim( $v ) );
		$map = array( 'evidence-backed', 'inferred', 'experimental' );
		return in_array( $v, $map, true ) ? $v : 'inferred';
	}

	private static function confidence( string $v ): string {
		$v = strtolower( trim( $v ) );
		return in_array( $v, array( 'high', 'medium', 'low' ), true ) ? $v : 'medium';
	}

	private static function priority( string $v ): string {
		$v = strtolower( trim( $v ) );
		return in_array( $v, array( 'high', 'medium', 'low' ), true ) ? $v : 'medium';
	}
}
