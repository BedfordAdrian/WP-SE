<?php
/**
 * OpenAI cost estimation and calculation.
 *
 * Prices are stored in an editable option so they can be updated without a
 * plugin release. Nothing here treats pricing as permanent fact.
 *
 * @package ABCMD
 */

namespace ABCMD\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Token-based cost maths for AI runs.
 */
final class Cost {

	/**
	 * Default per-1M-token USD prices (July 2026 list prices; user-editable).
	 * Web search adds a per-call fee plus retrieved-content input tokens.
	 *
	 * @return array<string,mixed>
	 */
	public static function default_prices(): array {
		return array(
			'currency'          => 'USD',
			'web_search_per_1k' => 10.0, // USD per 1,000 web_search tool calls.
			'models'            => array(
				// model id => [input per 1M, output per 1M].
				'gpt-5.6-sol'   => array( 'in' => 5.00, 'out' => 30.00 ),
				'gpt-5.6-terra' => array( 'in' => 2.50, 'out' => 15.00 ),
				'gpt-5.6-luna'  => array( 'in' => 1.00, 'out' => 6.00 ),
				'gpt-5.5'       => array( 'in' => 5.00, 'out' => 30.00 ),
				'gpt-5.4'       => array( 'in' => 2.50, 'out' => 15.00 ),
				'gpt-5.4-nano'  => array( 'in' => 0.20, 'out' => 1.25 ),
				'gpt-5'         => array( 'in' => 1.25, 'out' => 10.00 ),
				'gpt-4.1'       => array( 'in' => 2.00, 'out' => 8.00 ),
				'gpt-4o'        => array( 'in' => 2.50, 'out' => 10.00 ),
				'gpt-4o-mini'   => array( 'in' => 0.15, 'out' => 0.60 ),
			),
		);
	}

	/**
	 * Current price table (editable option merged over defaults).
	 *
	 * @return array<string,mixed>
	 */
	public static function prices(): array {
		$saved = get_option( ABCMD_OPT_PRICES, array() );
		if ( ! is_array( $saved ) || empty( $saved['models'] ) ) {
			return self::default_prices();
		}
		return $saved;
	}

	/**
	 * List of known model ids from the price table.
	 *
	 * @return string[]
	 */
	public static function known_models(): array {
		$prices = self::prices();
		return array_keys( $prices['models'] ?? array() );
	}

	/**
	 * Per-1M input/output price for a model (0 if unknown).
	 *
	 * @param string $model Model id.
	 * @return array{in:float,out:float}
	 */
	public static function model_price( string $model ): array {
		$prices = self::prices();
		$m      = $prices['models'][ $model ] ?? array( 'in' => 0.0, 'out' => 0.0 );
		return array(
			'in'  => (float) ( $m['in'] ?? 0 ),
			'out' => (float) ( $m['out'] ?? 0 ),
		);
	}

	/**
	 * Estimate cost before a run from token counts.
	 *
	 * @param string $model         Model id.
	 * @param int    $input_tokens  Estimated input tokens.
	 * @param int    $output_tokens Estimated output tokens.
	 * @param bool   $web_search    Whether one web_search call is expected.
	 * @return array{cost:float,currency:string,breakdown:array<string,float>}
	 */
	public static function estimate( string $model, int $input_tokens, int $output_tokens, bool $web_search = false ): array {
		return self::calculate( $model, $input_tokens, $output_tokens, $web_search ? 1 : 0 );
	}

	/**
	 * Calculate actual cost from realised token usage.
	 *
	 * @param string $model         Model id.
	 * @param int    $input_tokens  Input tokens.
	 * @param int    $output_tokens Output tokens.
	 * @param int    $web_calls     Number of web_search calls made.
	 * @return array{cost:float,currency:string,breakdown:array<string,float>}
	 */
	public static function calculate( string $model, int $input_tokens, int $output_tokens, int $web_calls = 0 ): array {
		$prices  = self::prices();
		$price   = self::model_price( $model );
		$in_cost = ( $input_tokens / 1_000_000 ) * $price['in'];
		$out_cost = ( $output_tokens / 1_000_000 ) * $price['out'];
		$web_cost = ( $web_calls / 1000 ) * (float) ( $prices['web_search_per_1k'] ?? 0 );

		$total = round( $in_cost + $out_cost + $web_cost, 6 );

		return array(
			'cost'      => $total,
			'currency'  => (string) ( $prices['currency'] ?? 'USD' ),
			'breakdown' => array(
				'input'      => round( $in_cost, 6 ),
				'output'     => round( $out_cost, 6 ),
				'web_search' => round( $web_cost, 6 ),
			),
		);
	}
}
