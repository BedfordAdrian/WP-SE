<?php
/**
 * Help: documentation hub for setup, cron, privacy and troubleshooting.
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
 * Renders the plugin help and documentation page.
 */
final class Help {

	/**
	 * Output the help page.
	 */
	public static function render(): void {
		\ABCMD\Support\Helpers::require_cap();

		View::open(
			__( 'Help & Documentation', 'abc-marketing-department' ),
			__( 'Setup, scheduling, privacy and troubleshooting for Marketing Department.', 'abc-marketing-department' )
		);

		$setup_url    = Helpers::admin_url( 'abcmd-setup' );
		$settings_url = Helpers::admin_url( 'abcmd-settings' );
		$imports_url  = Helpers::admin_url( 'abcmd-imports' );

		// Getting started.
		echo '<h2>' . esc_html__( 'Getting started', 'abc-marketing-department' ) . '</h2>';
		echo '<p>' . sprintf(
			/* translators: %s: setup wizard link */
			esc_html__( 'New here? The setup wizard walks you through the essentials. %s', 'abc-marketing-department' ),
			wp_kses_post( '<a href="' . esc_url( $setup_url ) . '">' . esc_html__( 'Open the setup wizard', 'abc-marketing-department' ) . '</a>' )
		) . '</p>';
		echo '<ol>';
		echo '<li>' . esc_html__( 'Create an author, then a book workspace for each title.', 'abc-marketing-department' ) . '</li>';
		echo '<li>' . esc_html__( 'Record consent for each workspace before sharing any data with AI.', 'abc-marketing-department' ) . '</li>';
		echo '<li>' . esc_html__( 'Import sales and campaign data as CSV, then run an audit.', 'abc-marketing-department' ) . '</li>';
		echo '</ol>';

		// OpenAI key.
		echo '<h2>' . esc_html__( 'OpenAI API key', 'abc-marketing-department' ) . '</h2>';
		echo '<p>' . sprintf(
			/* translators: %s: settings link */
			esc_html__( 'AI audits and recommendations require an OpenAI API key. Add it under %s. The key is stored encrypted and never shown again once saved.', 'abc-marketing-department' ),
			wp_kses_post( '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'abc-marketing-department' ) . '</a>' )
		) . '</p>';
		echo '<ul class="ul-disc">';
		echo '<li>' . esc_html__( 'Create a key in your OpenAI account dashboard and paste it into the API key field.', 'abc-marketing-department' ) . '</li>';
		echo '<li>' . esc_html__( 'Choose which models are allowed and set a default model.', 'abc-marketing-department' ) . '</li>';
		echo '<li>' . esc_html__( 'Leave the key field blank when saving other settings to keep the existing key.', 'abc-marketing-department' ) . '</li>';
		echo '</ul>';

		// Cron setup.
		echo '<h2>' . esc_html__( 'Reliable scheduling (server cron)', 'abc-marketing-department' ) . '</h2>';
		echo '<p>' . esc_html__( 'By default WordPress runs scheduled jobs via WP-Cron, which only fires when someone visits the site. On a low-traffic site the weekly audit and summary email can be delayed. For dependable timing, disable WP-Cron and drive it from a real server cron instead.', 'abc-marketing-department' ) . '</p>';
		echo '<p>' . esc_html__( 'Add a crontab entry on your server (this example runs every 15 minutes):', 'abc-marketing-department' ) . '</p>';
		echo '<pre><code>' . esc_html( '*/15 * * * * curl -s https://YOURSITE/wp-cron.php?doing_wp_cron >/dev/null 2>&1' ) . '</code></pre>';
		echo '<p>' . sprintf(
			/* translators: %s: wp-config constant, already escaped */
			esc_html__( 'Then add this line to wp-config.php so WordPress stops triggering cron on page loads: %s', 'abc-marketing-department' ),
			'<code>' . esc_html( "define('DISABLE_WP_CRON', true);" ) . '</code>'
		) . '</p>';
		echo '<p class="description">' . esc_html__( 'Replace YOURSITE with your real domain. The Weekly Review page shows the next scheduled run and warns if an audit looks overdue.', 'abc-marketing-department' ) . '</p>';

		// Demo data.
		echo '<h2>' . esc_html__( 'Demo data', 'abc-marketing-department' ) . '</h2>';
		echo '<p>' . esc_html__( 'To explore the plugin quickly, install the demo dataset from the Dashboard when no workspaces exist. It creates sample authors, workspaces, metrics and recommendations so every screen has something to show. Remove it later by purging from Settings.', 'abc-marketing-department' ) . '</p>';

		// CSV import guide.
		echo '<h2>' . esc_html__( 'Importing data (CSV)', 'abc-marketing-department' ) . '</h2>';
		echo '<p>' . sprintf(
			/* translators: %s: imports link */
			esc_html__( 'Use %s to bring in sales, email and ad data.', 'abc-marketing-department' ),
			wp_kses_post( '<a href="' . esc_url( $imports_url ) . '">' . esc_html__( 'Imports', 'abc-marketing-department' ) . '</a>' )
		) . '</p>';
		echo '<ol>';
		echo '<li>' . esc_html__( 'Pick the workspace and the template that matches your file (e.g. retailer sales, email stats).', 'abc-marketing-department' ) . '</li>';
		echo '<li>' . esc_html__( 'Map each template field to a column header from your CSV; the importer suggests matches.', 'abc-marketing-department' ) . '</li>';
		echo '<li>' . esc_html__( 'Choose a date format if dates are ambiguous, then upload. Duplicate rows are detected and skipped.', 'abc-marketing-department' ) . '</li>';
		echo '<li>' . esc_html__( 'Review the import summary; you can roll an import back if something looks wrong.', 'abc-marketing-department' ) . '</li>';
		echo '</ol>';

		// Privacy & consent.
		echo '<h2>' . esc_html__( 'Privacy & consent', 'abc-marketing-department' ) . '</h2>';
		echo '<p>' . esc_html__( 'Marketing Department is an internal, admin-only tool: workspaces, manuscripts and settings are never exposed on the public site. Nothing is sent to OpenAI unless you record consent for that workspace and explicitly select which data categories may be shared.', 'abc-marketing-department' ) . '</p>';
		echo '<ul class="ul-disc">';
		echo '<li>' . esc_html__( 'Record consent per workspace, noting the date, method and any revocation.', 'abc-marketing-department' ) . '</li>';
		echo '<li>' . esc_html__( 'Manuscript and personal data are opt-in and kept separate from routine sales figures.', 'abc-marketing-department' ) . '</li>';
		echo '<li>' . esc_html__( 'The weekly summary email deliberately contains no manuscript or sensitive client data.', 'abc-marketing-department' ) . '</li>';
		echo '</ul>';

		// Encryption / salts warning.
		echo '<h2>' . esc_html__( 'Encryption & WordPress salts', 'abc-marketing-department' ) . '</h2>';
		View::notice(
			esc_html__( 'Your OpenAI API key is encrypted using your WordPress salts. If those salts are rotated or changed (for example, regenerating keys in wp-config.php), the stored key can no longer be decrypted and AI calls will fail. After rotating salts you must re-enter the API key in Settings.', 'abc-marketing-department' ),
			'warning'
		);

		// Backups.
		echo '<h2>' . esc_html__( 'Backups', 'abc-marketing-department' ) . '</h2>';
		echo '<p>' . esc_html__( 'All plugin data lives in your WordPress database (custom tables) and the uploads directory (imported files). Include both in your regular backups. Test a restore periodically so you know it works before you need it.', 'abc-marketing-department' ) . '</p>';

		// Troubleshooting.
		echo '<h2>' . esc_html__( 'Troubleshooting', 'abc-marketing-department' ) . '</h2>';
		echo '<table class="wp-list-table widefat striped">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Problem', 'abc-marketing-department' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'What it means and how to fix it', 'abc-marketing-department' ) . '</th>';
		echo '</tr></thead><tbody>';

		$issues = array(
			array(
				__( 'Invalid API key', 'abc-marketing-department' ),
				__( 'OpenAI rejected the key (HTTP 401). Re-enter a valid key in Settings; remember salt changes can invalidate a stored key.', 'abc-marketing-department' ),
			),
			array(
				__( 'Rate limit reached', 'abc-marketing-department' ),
				__( 'Too many requests (HTTP 429). Wait and retry, reduce the hourly rate limit in Settings, or check your OpenAI usage tier.', 'abc-marketing-department' ),
			),
			array(
				__( 'Model unavailable', 'abc-marketing-department' ),
				__( 'The selected model does not exist or is not enabled for your account. Choose a different default model in Settings.', 'abc-marketing-department' ),
			),
			array(
				__( 'Web search unsupported', 'abc-marketing-department' ),
				__( 'The chosen model cannot use the web-search tool. Disable web search for that run or pick a model that supports it.', 'abc-marketing-department' ),
			),
			array(
				__( 'Failed / missed cron run', 'abc-marketing-department' ),
				__( 'WP-Cron did not fire because of low traffic. Configure a real server cron as described above and check the Weekly Review page.', 'abc-marketing-department' ),
			),
			array(
				__( 'Failed import', 'abc-marketing-department' ),
				__( 'Headers did not match the template or the file was malformed. Re-check the column mapping and date format, then re-upload; roll back a bad import if needed.', 'abc-marketing-department' ),
			),
			array(
				__( 'Extraction failure', 'abc-marketing-department' ),
				__( 'Text could not be extracted from an uploaded document. Confirm the file type is supported and not password-protected, then re-run extraction.', 'abc-marketing-department' ),
			),
		);

		foreach ( $issues as $issue ) {
			echo '<tr>';
			echo '<td><strong>' . esc_html( $issue[0] ) . '</strong></td>';
			echo '<td>' . esc_html( $issue[1] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		// Full docs.
		echo '<h2>' . esc_html__( 'Full documentation', 'abc-marketing-department' ) . '</h2>';
		echo '<p>' . sprintf(
			/* translators: %s: docs folder path, already escaped */
			esc_html__( 'Complete reference documentation ships with the plugin in the %s folder, including import template details, data model notes and developer extension points.', 'abc-marketing-department' ),
			'<code>' . esc_html( 'docs/' ) . '</code>'
		) . '</p>';

		View::close();
	}
}
