<?php
/**
 * Integrations: phase-one status of planned platform connectors.
 *
 * @package ABCMD
 */

namespace ABCMD\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ABCMD\Admin\View;
use ABCMD\Support\Helpers;

/**
 * Informational page describing the integration roadmap.
 */
final class Integrations {

	/**
	 * Output the integrations status page.
	 */
	public static function render(): void {
		\ABCMD\Support\Helpers::require_cap();

		View::open(
			__( 'Integrations', 'abc-marketing-department' ),
			__( 'Where planned platform connectors will plug in. Phase one relies on manual CSV imports.', 'abc-marketing-department' )
		);

		$imports_url = Helpers::admin_url( 'abcmd-imports' );

		echo '<p>' . sprintf(
			/* translators: %s: imports link */
			esc_html__( 'This release (phase one) collects all sales and campaign data through manual CSV imports. %s', 'abc-marketing-department' ),
			wp_kses_post( '<a href="' . esc_url( $imports_url ) . '">' . esc_html__( 'Go to Imports', 'abc-marketing-department' ) . '</a>' )
		) . '</p>';

		// Roadmap table.
		$planned = array(
			__( 'Shopify', 'abc-marketing-department' )                       => __( 'Direct-to-reader store orders and revenue.', 'abc-marketing-department' ),
			__( 'Google Analytics 4', 'abc-marketing-department' )            => __( 'Website traffic, referrals and conversions.', 'abc-marketing-department' ),
			__( 'EmailOctopus', 'abc-marketing-department' )                  => __( 'Newsletter list size, opens and click rates.', 'abc-marketing-department' ),
			__( 'Meta Ads', 'abc-marketing-department' )                      => __( 'Facebook and Instagram ad spend and results.', 'abc-marketing-department' ),
			__( 'Amazon Ads', 'abc-marketing-department' )                    => __( 'Sponsored product spend, ACOS and sales.', 'abc-marketing-department' ),
			__( 'Social-platform analytics', 'abc-marketing-department' )     => __( 'Reach, engagement and follower growth.', 'abc-marketing-department' ),
			__( 'Retailer / distributor feeds', 'abc-marketing-department' )  => __( 'Automated unit sales and royalty statements.', 'abc-marketing-department' ),
			__( 'Direct publishing / scheduling', 'abc-marketing-department' ) => __( 'Push approved content straight to channels.', 'abc-marketing-department' ),
		);

		echo '<table class="wp-list-table widefat striped">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Integration', 'abc-marketing-department' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Purpose', 'abc-marketing-department' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Status', 'abc-marketing-department' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $planned as $name => $purpose ) {
			echo '<tr>';
			echo '<td><strong>' . esc_html( $name ) . '</strong></td>';
			echo '<td>' . esc_html( $purpose ) . '</td>';
			echo '<td>' . wp_kses_post( View::pill( 'Planned (Phase 2)' ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		// Extensibility note.
		echo '<h2>' . esc_html__( 'How connectors will plug in', 'abc-marketing-department' ) . '</h2>';
		echo '<p>' . esc_html__( 'The architecture is designed so future connectors can register themselves without changing core code. Three extension points are already exposed:', 'abc-marketing-department' ) . '</p>';
		echo '<ul class="ul-disc">';
		echo '<li><code>abcmd_import_templates</code> — ' . esc_html__( 'register new import templates so a connector\'s data maps onto the same metric store as CSV imports.', 'abc-marketing-department' ) . '</li>';
		echo '<li><code>abcmd_ai_task_types</code> — ' . esc_html__( 'add AI task types that reason over data a connector brings in.', 'abc-marketing-department' ) . '</li>';
		echo '<li><code>abcmd_loaded</code> — ' . esc_html__( 'fires once the plugin has booted, giving connectors a safe place to wire up their hooks.', 'abc-marketing-department' ) . '</li>';
		echo '</ul>';
		echo '<p class="description">' . esc_html__( 'Because connectors feed the same metrics, anomaly detection, recommendations and audits, no new pipelines are needed when they arrive — this page will simply flip each row from "Planned" to "Connected".', 'abc-marketing-department' ) . '</p>';

		View::close();
	}
}
