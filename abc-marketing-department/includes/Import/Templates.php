<?php
/**
 * CSV import template catalogue.
 *
 * Templates are intentionally flexible: they declare canonical target fields and
 * header aliases for auto-mapping, but the operator always confirms the mapping.
 * This avoids brittle single-layout assumptions when platforms change exports.
 *
 * @package ABCMD
 */

namespace ABCMD\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Defines the supported import templates and their canonical fields.
 */
final class Templates {

	/**
	 * Canonical target field definitions shared across templates.
	 *
	 * role: date|format|territory|source|platform|channel (dimension roles).
	 * metric: metric_key for numeric/currency targets.
	 * type: date|dimension|number|currency|text|identifier.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function fields(): array {
		return array(
			'date'        => array( 'label' => 'Date', 'type' => 'date', 'role' => 'date' ),
			'identifier'  => array( 'label' => 'Transaction / row ID', 'type' => 'identifier' ),
			'format'      => array( 'label' => 'Format', 'type' => 'dimension', 'role' => 'format' ),
			'territory'   => array( 'label' => 'Territory / country', 'type' => 'dimension', 'role' => 'territory' ),
			'retailer'    => array( 'label' => 'Retailer / distributor', 'type' => 'dimension', 'role' => 'source' ),
			'platform'    => array( 'label' => 'Platform', 'type' => 'dimension', 'role' => 'platform' ),
			'channel'     => array( 'label' => 'Channel / campaign', 'type' => 'dimension', 'role' => 'channel' ),
			'units'       => array( 'label' => 'Units sold', 'type' => 'number', 'metric' => 'sales' ),
			'net_units'   => array( 'label' => 'Net units (returns applied)', 'type' => 'number', 'metric' => 'sales' ),
			'revenue'     => array( 'label' => 'Revenue', 'type' => 'currency', 'metric' => 'revenue' ),
			'net_income'  => array( 'label' => 'Net income / royalty', 'type' => 'currency', 'metric' => 'contribution' ),
			'ad_spend'    => array( 'label' => 'Ad spend', 'type' => 'currency', 'metric' => 'ad_spend' ),
			'impressions' => array( 'label' => 'Impressions', 'type' => 'number', 'metric' => 'impressions' ),
			'clicks'      => array( 'label' => 'Clicks', 'type' => 'number', 'metric' => 'clicks' ),
			'conversions' => array( 'label' => 'Conversions / orders', 'type' => 'number', 'metric' => 'conversions' ),
			'reach'       => array( 'label' => 'Reach', 'type' => 'number', 'metric' => 'reach' ),
			'engagement'  => array( 'label' => 'Engagement', 'type' => 'number', 'metric' => 'engagement' ),
			'followers'   => array( 'label' => 'Followers', 'type' => 'number', 'metric' => 'followers' ),
			'list_size'   => array( 'label' => 'Mailing-list size', 'type' => 'number', 'metric' => 'mailing_list_size' ),
			'delivered'   => array( 'label' => 'Delivered', 'type' => 'number', 'metric' => 'delivered' ),
			'opens'       => array( 'label' => 'Opens', 'type' => 'number', 'metric' => 'opens' ),
			'open_rate'   => array( 'label' => 'Open rate (%)', 'type' => 'number', 'metric' => 'open_rate' ),
			'click_rate'  => array( 'label' => 'Click rate (%)', 'type' => 'number', 'metric' => 'click_rate' ),
			'unsubscribes'=> array( 'label' => 'Unsubscribes', 'type' => 'number', 'metric' => 'unsubscribes' ),
			'reviews'     => array( 'label' => 'Reviews count', 'type' => 'number', 'metric' => 'reviews' ),
			'avg_rating'  => array( 'label' => 'Average rating', 'type' => 'number', 'metric' => 'avg_rating' ),
			'rank'        => array( 'label' => 'Retailer rank', 'type' => 'number', 'metric' => 'retailer_rank' ),
		);
	}

	/**
	 * All import templates.
	 *
	 * Each template lists target => [header alias substrings] used for auto-map
	 * suggestions, plus a default source label and typical format.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		$templates = array(
			'ingramspark' => array(
				'label'   => 'IngramSpark (print & ebook sales)',
				'source'  => 'IngramSpark',
				'targets' => array(
					'date'      => array( 'date', 'reporting period', 'month' ),
					'title'     => array( 'title' ),
					'format'    => array( 'format', 'product type', 'binding' ),
					'territory' => array( 'market', 'country', 'territory' ),
					'units'     => array( 'net qty', 'quantity', 'units', 'net units' ),
					'net_income'=> array( 'pub comp', 'compensation', 'earnings', 'royalty' ),
				),
				'note'    => 'IngramSpark data is often delayed — set source status accordingly.',
			),
			'draft2digital' => array(
				'label'   => 'Draft2Digital (ebook sales)',
				'source'  => 'Draft2Digital',
				'targets' => array(
					'date'      => array( 'date', 'sale month', 'period' ),
					'retailer'  => array( 'retailer', 'vendor', 'store', 'channel' ),
					'territory' => array( 'country', 'market' ),
					'units'     => array( 'units', 'sales', 'qty', 'quantity' ),
					'net_income'=> array( 'payment', 'earnings', 'royalty', 'net' ),
				),
			),
			'acx' => array(
				'label'   => 'ACX (audiobook)',
				'source'  => 'ACX',
				'targets' => array(
					'date'      => array( 'date', 'month' ),
					'territory' => array( 'marketplace', 'country' ),
					'units'     => array( 'units sold', 'sales', 'qty' ),
					'net_income'=> array( 'royalty', 'earnings' ),
				),
			),
			'inaudio' => array(
				'label'   => 'InAudio (audiobook distribution)',
				'source'  => 'InAudio',
				'targets' => array(
					'date'      => array( 'date', 'period' ),
					'retailer'  => array( 'platform', 'retailer', 'store' ),
					'units'     => array( 'units', 'sales', 'quantity' ),
					'net_income'=> array( 'net', 'earnings', 'revenue' ),
				),
			),
			'spotify' => array(
				'label'   => 'Spotify (audiobook streams/sales)',
				'source'  => 'Spotify',
				'targets' => array(
					'date'      => array( 'date', 'month' ),
					'territory' => array( 'country', 'market' ),
					'units'     => array( 'sales', 'units', 'streams', 'quantity' ),
					'net_income'=> array( 'net', 'earnings', 'revenue' ),
				),
			),
			'amazon_sales' => array(
				'label'   => 'Amazon sales report',
				'source'  => 'Amazon',
				'targets' => array(
					'date'      => array( 'date', 'royalty date', 'month' ),
					'format'    => array( 'format', 'type' ),
					'territory' => array( 'marketplace', 'country' ),
					'units'     => array( 'net units sold', 'units sold', 'units', 'quantity' ),
					'net_income'=> array( 'royalty', 'earnings' ),
					'revenue'   => array( 'sales', 'revenue' ),
				),
			),
			'amazon_ads' => array(
				'label'   => 'Amazon Ads',
				'source'  => 'Amazon Ads',
				'targets' => array(
					'date'        => array( 'date', 'day' ),
					'channel'     => array( 'campaign', 'campaign name' ),
					'ad_spend'    => array( 'spend', 'cost' ),
					'impressions' => array( 'impressions' ),
					'clicks'      => array( 'clicks' ),
					'conversions' => array( 'orders', 'conversions', 'purchases' ),
				),
			),
			'meta_ads' => array(
				'label'   => 'Meta Ads',
				'source'  => 'Meta Ads',
				'targets' => array(
					'date'        => array( 'date', 'day', 'reporting starts' ),
					'channel'     => array( 'campaign', 'campaign name', 'ad set name' ),
					'ad_spend'    => array( 'amount spent', 'spend', 'cost' ),
					'impressions' => array( 'impressions' ),
					'clicks'      => array( 'clicks', 'link clicks' ),
					'conversions' => array( 'results', 'purchases', 'conversions' ),
					'reach'       => array( 'reach' ),
				),
			),
			'emailoctopus' => array(
				'label'   => 'EmailOctopus (email campaigns)',
				'source'  => 'EmailOctopus',
				'targets' => array(
					'date'         => array( 'date', 'sent', 'send date' ),
					'channel'      => array( 'campaign', 'name', 'subject' ),
					'delivered'    => array( 'delivered', 'sent', 'recipients' ),
					'opens'        => array( 'opens', 'opened', 'unique opens' ),
					'open_rate'    => array( 'open rate', 'opened %' ),
					'click_rate'   => array( 'click rate', 'clicked %' ),
					'unsubscribes' => array( 'unsubscribes', 'unsubscribed' ),
				),
			),
			'shopify' => array(
				'label'   => 'Shopify (direct shop orders)',
				'source'  => 'Shopify',
				'targets' => array(
					'date'      => array( 'date', 'created at', 'day' ),
					'identifier'=> array( 'order', 'order id', 'name' ),
					'territory' => array( 'shipping country', 'country' ),
					'conversions'=> array( 'orders' ),
					'units'     => array( 'quantity', 'net items sold', 'items' ),
					'revenue'   => array( 'total sales', 'net sales', 'total' ),
				),
			),
			'generic_sales' => array(
				'label'   => 'Generic sales',
				'source'  => 'Manual/Other',
				'targets' => array(
					'date'      => array( 'date' ),
					'identifier'=> array( 'id', 'ref' ),
					'format'    => array( 'format' ),
					'territory' => array( 'territory', 'country' ),
					'retailer'  => array( 'retailer', 'store', 'source' ),
					'units'     => array( 'units', 'sales', 'quantity', 'qty' ),
					'revenue'   => array( 'revenue', 'sales value' ),
					'net_income'=> array( 'net', 'contribution', 'royalty' ),
				),
			),
			'generic_advertising' => array(
				'label'   => 'Generic advertising',
				'source'  => 'Manual/Other',
				'targets' => array(
					'date'        => array( 'date' ),
					'channel'     => array( 'campaign', 'channel', 'platform' ),
					'ad_spend'    => array( 'spend', 'cost' ),
					'impressions' => array( 'impressions' ),
					'clicks'      => array( 'clicks' ),
					'conversions' => array( 'conversions', 'orders', 'sales' ),
				),
			),
			'generic_audience' => array(
				'label'   => 'Generic audience metrics',
				'source'  => 'Manual/Other',
				'targets' => array(
					'date'      => array( 'date' ),
					'platform'  => array( 'platform', 'network', 'channel' ),
					'followers' => array( 'followers', 'subscribers' ),
					'reach'     => array( 'reach' ),
					'engagement'=> array( 'engagement', 'likes' ),
					'list_size' => array( 'list size', 'subscribers' ),
				),
			),
		);

		/**
		 * Filter import templates so phase-two connectors can register their own.
		 *
		 * @param array<string,array<string,mixed>> $templates Templates.
		 */
		return apply_filters( 'abcmd_import_templates', $templates );
	}

	/**
	 * Get a single template.
	 *
	 * @param string $key Template key.
	 * @return array<string,mixed>|null
	 */
	public static function get( string $key ): ?array {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Options list (key => label) for dropdowns.
	 *
	 * @return array<string,string>
	 */
	public static function options(): array {
		$out = array();
		foreach ( self::all() as $k => $t ) {
			$out[ $k ] = (string) $t['label'];
		}
		return $out;
	}

	/**
	 * Suggest a mapping from CSV headers to template targets using aliases.
	 *
	 * @param string   $template_key Template key.
	 * @param string[] $headers      CSV header row.
	 * @return array<string,string> target => matched header (or '').
	 */
	public static function suggest_mapping( string $template_key, array $headers ): array {
		$tpl = self::get( $template_key );
		$map = array();
		if ( ! $tpl ) {
			return $map;
		}
		$norm_headers = array();
		foreach ( $headers as $h ) {
			$norm_headers[ $h ] = strtolower( trim( (string) $h ) );
		}
		foreach ( (array) $tpl['targets'] as $target => $aliases ) {
			$map[ $target ] = '';
			foreach ( $aliases as $alias ) {
				foreach ( $norm_headers as $orig => $norm ) {
					if ( $norm === strtolower( $alias ) || str_contains( $norm, strtolower( $alias ) ) ) {
						$map[ $target ] = (string) $orig;
						break 2;
					}
				}
			}
		}
		return $map;
	}

	/**
	 * Valid source-status values.
	 *
	 * @return array<string,string>
	 */
	public static function source_statuses(): array {
		return array(
			'live'              => 'Live',
			'delayed'           => 'Delayed',
			'estimated'         => 'Estimated',
			'reconciled'        => 'Reconciled',
			'manually_adjusted' => 'Manually adjusted',
		);
	}
}
