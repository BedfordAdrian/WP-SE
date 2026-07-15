<?php
/**
 * Settings page with tabs: General, OpenAI, Pricing, Privacy template, Data.
 *
 * @package ABCMD
 */

namespace ABCMD\Admin\Pages;

use ABCMD\Admin\View;
use ABCMD\AI\Cost;
use ABCMD\AI\OpenAIClient;
use ABCMD\Repository\Authors as AuthorsRepo;
use ABCMD\Repository\Books as BooksRepo;
use ABCMD\Support\Encryption;
use ABCMD\Support\Helpers;
use ABCMD\Support\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders plugin settings.
 */
final class Settings {

	public static function render(): void {
		Helpers::require_cap();

		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'general';
		$tabs = array(
			'general' => __( 'General', 'abc-marketing-department' ),
			'openai'  => __( 'OpenAI', 'abc-marketing-department' ),
			'pricing' => __( 'Model pricing', 'abc-marketing-department' ),
			'privacy' => __( 'Privacy template', 'abc-marketing-department' ),
			'data'    => __( 'Data & purge', 'abc-marketing-department' ),
		);

		View::open( __( 'Settings', 'abc-marketing-department' ) );
		View::tabs( $tabs, $tab, 'abcmd-settings' );

		switch ( $tab ) {
			case 'openai':
				self::openai();
				break;
			case 'pricing':
				self::pricing();
				break;
			case 'privacy':
				self::privacy();
				break;
			case 'data':
				self::data();
				break;
			default:
				self::general();
		}
		View::close();
	}

	private static function general(): void {
		$s = Options::settings();
		View::form_open( 'abcmd_save_settings' );
		echo '<table class="form-table">';
		View::select( 'currency', __( 'Reporting currency', 'abc-marketing-department' ), array( 'GBP' => 'GBP £', 'USD' => 'USD $', 'EUR' => 'EUR €' ), (string) $s['currency'] );
		View::field( 'timezone', __( 'Timezone', 'abc-marketing-department' ), (string) $s['timezone'], 'text', __( 'Used for scheduling. Default Europe/London.', 'abc-marketing-department' ) );
		echo '<tr><th colspan="2"><h3>' . esc_html__( 'Weekly audit', 'abc-marketing-department' ) . '</h3></th></tr>';
		View::checkbox( 'weekly_audit_enabled', __( 'Enable weekly audit', 'abc-marketing-department' ), (bool) $s['weekly_audit_enabled'] );
		View::select( 'weekly_audit_weekday', __( 'Audit weekday', 'abc-marketing-department' ), array( 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday' ), (string) $s['weekly_audit_weekday'] );
		View::field( 'weekly_audit_hour', __( 'Audit hour (0–23, local)', 'abc-marketing-department' ), (string) $s['weekly_audit_hour'], 'number' );
		View::field( 'weekly_email_hour', __( 'Summary email hour (0–23, local)', 'abc-marketing-department' ), (string) $s['weekly_email_hour'], 'number' );
		View::field( 'weekly_email_recipients', __( 'Email recipients', 'abc-marketing-department' ), (string) $s['weekly_email_recipients'], 'text', __( 'Comma separated. Blank = site admin.', 'abc-marketing-department' ) );
		echo '<tr><th colspan="2"><h3>' . esc_html__( 'AI cost control', 'abc-marketing-department' ) . '</h3></th></tr>';
		View::field( 'ai_monthly_budget', __( 'Monthly budget warning', 'abc-marketing-department' ), (string) $s['ai_monthly_budget'], 'number' );
		View::field( 'ai_cost_confirm_threshold', __( 'Per-run confirmation threshold', 'abc-marketing-department' ), (string) $s['ai_cost_confirm_threshold'], 'number', __( 'Runs estimated above this require explicit confirmation.', 'abc-marketing-department' ) );
		View::field( 'ai_rate_limit_per_hour', __( 'AI runs per user per hour', 'abc-marketing-department' ), (string) $s['ai_rate_limit_per_hour'], 'number' );
		echo '<tr><th colspan="2"><h3>' . esc_html__( 'Uninstall', 'abc-marketing-department' ) . '</h3></th></tr>';
		View::checkbox( 'uninstall_purge', __( 'Delete all data on uninstall', 'abc-marketing-department' ), (bool) $s['uninstall_purge'], __( 'Off by default. When off, deactivating or deleting the plugin preserves all agency data.', 'abc-marketing-department' ) );
		echo '</table>';
		View::submit( __( 'Save settings', 'abc-marketing-department' ) );
		View::form_close();
	}

	private static function openai(): void {
		$o        = OpenAIClient::settings();
		$has_key  = OpenAIClient::has_key();
		$masked   = $has_key ? Encryption::mask( 'sk-xxxxxxxxxxxx' ) : '';
		$known    = Cost::known_models();

		if ( ! Encryption::available() ) {
			View::notice( __( 'No encryption backend (libsodium/OpenSSL) is available — API keys cannot be stored securely on this server.', 'abc-marketing-department' ), 'error' );
		}

		View::form_open( 'abcmd_save_openai' );
		echo '<table class="form-table">';
		echo '<tr><th scope="row"><label for="abcmd_api_key">' . esc_html__( 'API key', 'abc-marketing-department' ) . '</label></th><td>';
		echo '<input type="password" id="abcmd_api_key" name="api_key" value="" class="regular-text" autocomplete="new-password" placeholder="' . esc_attr( $has_key ? __( 'Saved — leave blank to keep', 'abc-marketing-department' ) : 'sk-…' ) . '" />';
		echo '<p class="description">' . esc_html__( 'Stored encrypted at rest. Never displayed after saving. Leave blank to keep the current key.', 'abc-marketing-department' ) . '</p>';
		if ( $has_key ) {
			echo '<p>' . esc_html__( 'A key is currently stored.', 'abc-marketing-department' ) . '</p>';
		}
		echo '</td></tr>';
		View::field( 'organization', __( 'Organization (optional)', 'abc-marketing-department' ), (string) $o['organization'] );
		View::field( 'project', __( 'Project (optional)', 'abc-marketing-department' ), (string) $o['project'] );

		$model_opts = array();
		foreach ( $known as $m ) {
			$model_opts[ $m ] = $m;
		}
		View::select( 'default_model', __( 'Default model', 'abc-marketing-department' ), $model_opts, (string) $o['default_model'] );

		echo '<tr><th scope="row">' . esc_html__( 'Allowed models', 'abc-marketing-department' ) . '</th><td>';
		$allowed = (array) $o['allowed_models'];
		foreach ( $known as $m ) {
			echo '<label style="display:inline-block;margin-right:12px"><input type="checkbox" name="allowed_models[]" value="' . esc_attr( $m ) . '" ' . checked( in_array( $m, $allowed, true ), true, false ) . ' /> ' . esc_html( $m ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'Operators can pick any allowed model per task.', 'abc-marketing-department' ) . '</p></td></tr>';

		View::field( 'temperature', __( 'Default temperature', 'abc-marketing-department' ), (string) ( $o['temperature'] ?? '' ), 'number', __( 'Blank to omit (some reasoning models ignore/reject it).', 'abc-marketing-department' ) );
		View::checkbox( 'web_search', __( 'Allow web research by default', 'abc-marketing-department' ), (bool) $o['web_search'], __( 'Only used where the model + API support it and consent permits.', 'abc-marketing-department' ) );
		View::field( 'timeout', __( 'Request timeout (seconds)', 'abc-marketing-department' ), (string) $o['timeout'], 'number' );
		View::field( 'retries', __( 'Retries on transient errors', 'abc-marketing-department' ), (string) $o['retries'], 'number' );
		echo '</table>';
		View::submit( __( 'Save OpenAI settings', 'abc-marketing-department' ) );
		View::form_close();

		echo '<hr /><p class="description"><strong>' . esc_html__( 'Important:', 'abc-marketing-department' ) . '</strong> ' . esc_html__( 'The encryption key is derived from your WordPress secret salts. If those salts change, the stored API key can no longer be decrypted and must be re-entered.', 'abc-marketing-department' ) . '</p>';
	}

	private static function pricing(): void {
		$prices = Cost::prices();
		echo '<p class="description">' . esc_html__( 'Model prices are editable so you can update them without a plugin release. Prices are per 1,000,000 tokens in the currency below. Nothing here is treated as permanent fact.', 'abc-marketing-department' ) . '</p>';
		View::form_open( 'abcmd_save_prices' );
		echo '<table class="form-table"><tr><th>' . esc_html__( 'Pricing currency', 'abc-marketing-department' ) . '</th><td><input type="text" name="price_currency" value="' . esc_attr( (string) ( $prices['currency'] ?? 'USD' ) ) . '" style="width:80px" /></td></tr>';
		echo '<tr><th>' . esc_html__( 'Web search per 1,000 calls', 'abc-marketing-department' ) . '</th><td><input type="number" step="0.01" name="web_search_per_1k" value="' . esc_attr( (string) ( $prices['web_search_per_1k'] ?? 0 ) ) . '" style="width:100px" /></td></tr></table>';

		echo '<table class="wp-list-table widefat striped"><thead><tr><th>' . esc_html__( 'Model', 'abc-marketing-department' ) . '</th><th>' . esc_html__( 'Input / 1M', 'abc-marketing-department' ) . '</th><th>' . esc_html__( 'Output / 1M', 'abc-marketing-department' ) . '</th></tr></thead><tbody>';
		foreach ( (array) ( $prices['models'] ?? array() ) as $model => $p ) {
			echo '<tr><td class="abcmd-mono">' . esc_html( (string) $model ) . '</td>';
			echo '<td><input type="number" step="0.01" name="price_in[' . esc_attr( (string) $model ) . ']" value="' . esc_attr( (string) ( $p['in'] ?? 0 ) ) . '" style="width:100px" /></td>';
			echo '<td><input type="number" step="0.01" name="price_out[' . esc_attr( (string) $model ) . ']" value="' . esc_attr( (string) ( $p['out'] ?? 0 ) ) . '" style="width:100px" /></td></tr>';
		}
		echo '<tr><td><input type="text" name="new_model" value="" placeholder="' . esc_attr__( 'add model id', 'abc-marketing-department' ) . '" /></td>';
		echo '<td><input type="number" step="0.01" name="new_in" value="" style="width:100px" /></td>';
		echo '<td><input type="number" step="0.01" name="new_out" value="" style="width:100px" /></td></tr>';
		echo '</tbody></table>';
		View::submit( __( 'Save pricing', 'abc-marketing-department' ) );
		View::form_close();
	}

	private static function privacy(): void {
		$text = (string) get_option( ABCMD_OPT_PRIVACY, '' );
		echo '<p class="description">' . esc_html__( 'Editable client-facing UK privacy / AI-processing template. This is a starting point that requires legal review — it is not legal advice.', 'abc-marketing-department' ) . '</p>';
		View::form_open( 'abcmd_save_privacy' );
		echo '<textarea name="privacy_template" rows="24" class="large-text code">' . esc_textarea( $text ) . '</textarea>';
		View::submit( __( 'Save template', 'abc-marketing-department' ) );
		View::form_close();
	}

	private static function data(): void {
		echo '<p class="description">' . esc_html__( 'Client data and campaign history are stored indefinitely by default. Deactivating the plugin never deletes data. Use these tools for deliberate removal.', 'abc-marketing-department' ) . '</p>';

		$books   = ( new BooksRepo() )->options();
		$authors = ( new AuthorsRepo() )->options();

		// Export.
		echo '<div class="abcmd-card"><h3>' . esc_html__( 'Exports', 'abc-marketing-department' ) . '</h3>';
		View::form_open( 'abcmd_export_csv' );
		echo '<label>' . esc_html__( 'Export CSV of:', 'abc-marketing-department' ) . ' <select name="type">';
		foreach ( \ABCMD\Export\CsvExport::types() as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $l ) . '</option>';
		}
		echo '</select></label> ';
		View::submit( __( 'Download CSV', 'abc-marketing-department' ), 'secondary' );
		View::form_close();
		echo '</div>';

		// Workspace / author purge.
		echo '<div class="abcmd-card"><h3>' . esc_html__( 'Purge', 'abc-marketing-department' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-confirm="' . esc_attr__( 'This permanently deletes the selected data. Continue?', 'abc-marketing-department' ) . '">';
		echo '<input type="hidden" name="action" value="abcmd_purge" />';
		wp_nonce_field( 'abcmd_purge', '_abcmd_nonce' );
		echo '<p><label>' . esc_html__( 'Scope', 'abc-marketing-department' ) . ' <select name="scope" id="abcmd_purge_scope">';
		echo '<option value="workspace">' . esc_html__( 'A workspace', 'abc-marketing-department' ) . '</option>';
		echo '<option value="author">' . esc_html__( 'An author (and all their books)', 'abc-marketing-department' ) . '</option>';
		echo '<option value="all">' . esc_html__( 'ALL agency data (keeps audit log)', 'abc-marketing-department' ) . '</option>';
		echo '</select></label></p>';
		echo '<p><label>' . esc_html__( 'Target (for workspace/author scope)', 'abc-marketing-department' ) . ' <select name="id"><option value="0">—</option>';
		echo '<optgroup label="' . esc_attr__( 'Workspaces', 'abc-marketing-department' ) . '">';
		foreach ( $books as $bid => $t ) {
			echo '<option value="' . esc_attr( (string) $bid ) . '">' . esc_html( $t ) . '</option>';
		}
		echo '</optgroup><optgroup label="' . esc_attr__( 'Authors', 'abc-marketing-department' ) . '">';
		foreach ( $authors as $aid => $t ) {
			echo '<option value="' . esc_attr( (string) $aid ) . '">' . esc_html( $t ) . '</option>';
		}
		echo '</optgroup></select> <span class="description">' . esc_html__( '(author ids and workspace ids are distinct; pick to match the scope)', 'abc-marketing-department' ) . '</span></p>';
		echo '<p>' . esc_html__( 'Type PURGE to confirm:', 'abc-marketing-department' ) . ' <input type="text" name="confirm" value="" /></p>';
		echo '<button type="submit" class="button button-link-delete">' . esc_html__( 'Purge selected data', 'abc-marketing-department' ) . '</button>';
		echo '</form></div>';
	}
}
