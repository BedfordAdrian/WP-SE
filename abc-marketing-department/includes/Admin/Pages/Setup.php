<?php
/**
 * Setup wizard (hidden page) — guides first-run configuration.
 *
 * @package ABCMD
 */

namespace ABCMD\Admin\Pages;

use ABCMD\Admin\View;
use ABCMD\AI\OpenAIClient;
use ABCMD\Repository\Books as BooksRepo;
use ABCMD\Requirements;
use ABCMD\Support\Encryption;
use ABCMD\Support\Helpers;
use ABCMD\Support\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * First-run setup wizard.
 */
final class Setup {

	public static function render(): void {
		Helpers::require_cap();

		View::open( __( 'Marketing Department — Setup', 'abc-marketing-department' ), __( 'A short checklist to get you running. You can revisit each area any time from the menu.', 'abc-marketing-department' ) );

		// Step 1: environment.
		echo '<div class="abcmd-card"><h2>' . esc_html__( '1. Environment check', 'abc-marketing-department' ) . '</h2>';
		echo '<table class="wp-list-table widefat striped"><tbody>';
		foreach ( Requirements::report() as $check ) {
			$icon = $check['ok'] ? '✅' : ( $check['fatal'] ? '⛔' : '⚠️' );
			echo '<tr><td style="width:30px">' . esc_html( $icon ) . '</td><td><strong>' . esc_html( $check['label'] ) . '</strong></td><td>' . esc_html( $check['detail'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( ! Encryption::available() ) {
			View::notice( __( 'No encryption backend available — you will not be able to store an API key securely. Ask your host to enable libsodium or OpenSSL.', 'abc-marketing-department' ), 'error' );
		}
		echo '</div>';

		// Step 2: OpenAI.
		echo '<div class="abcmd-card"><h2>' . esc_html__( '2. Connect OpenAI', 'abc-marketing-department' ) . '</h2>';
		if ( OpenAIClient::has_key() ) {
			View::notice( __( 'An OpenAI API key is configured.', 'abc-marketing-department' ), 'success' );
		} else {
			echo '<p>' . esc_html__( 'Add your own OpenAI API key so the plugin can run AI-assisted audits and drafting. The key is encrypted at rest and never shown again.', 'abc-marketing-department' ) . '</p>';
		}
		echo '<p>' . View::button_link( Helpers::admin_url( 'abcmd-settings', array( 'tab' => 'openai' ) ), __( 'Open OpenAI settings', 'abc-marketing-department' ), 'primary' ) . '</p>';
		echo '</div>';

		// Step 3: demo or first workspace.
		echo '<div class="abcmd-card"><h2>' . esc_html__( '3. Add data', 'abc-marketing-department' ) . '</h2>';
		$has_books = ( new BooksRepo() )->count() > 0;
		if ( $has_books ) {
			View::notice( __( 'You already have at least one workspace.', 'abc-marketing-department' ), 'success' );
		}
		echo '<p>' . esc_html__( 'Install the demo workspace to explore the plugin with fictionalised data (removable at any time), or create your own author and book.', 'abc-marketing-department' ) . '</p>';
		echo '<p>';
		View::form_open( 'abcmd_install_demo' );
		View::submit( __( 'Install demo workspace', 'abc-marketing-department' ), 'secondary' );
		View::form_close();
		echo '</p>';
		echo '<p>' . View::button_link( Helpers::admin_url( 'abcmd-authors' ), __( 'Create an author', 'abc-marketing-department' ) ) . ' ' . View::button_link( Helpers::admin_url( 'abcmd-books' ), __( 'Create a workspace', 'abc-marketing-department' ) ) . '</p>';
		echo '</div>';

		// Step 4: cron.
		echo '<div class="abcmd-card"><h2>' . esc_html__( '4. Scheduling', 'abc-marketing-department' ) . '</h2>';
		echo '<p>' . esc_html__( 'The weekly audit runs Monday 00:00 and the summary email sends Monday 09:00 (Europe/London by default). WP-Cron is traffic-dependent — for reliability configure a real server cron. See Help.', 'abc-marketing-department' ) . '</p>';
		echo '<p>' . View::button_link( Helpers::admin_url( 'abcmd-help' ), __( 'Cron setup instructions', 'abc-marketing-department' ) ) . ' ' . View::button_link( Helpers::admin_url( 'abcmd-settings' ), __( 'Schedule settings', 'abc-marketing-department' ) ) . '</p>';
		echo '</div>';

		// Finish.
		echo '<div class="abcmd-card"><h2>' . esc_html__( '5. Finish', 'abc-marketing-department' ) . '</h2>';
		if ( Options::get( 'setup_complete', 0 ) ) {
			echo '<p>' . esc_html__( 'Setup already marked complete — but you can revisit any step above.', 'abc-marketing-department' ) . '</p>';
		}
		View::form_open( 'abcmd_complete_setup' );
		View::submit( __( 'Finish setup & go to dashboard', 'abc-marketing-department' ), 'primary' );
		View::form_close();
		echo '</div>';

		View::close();
	}
}
