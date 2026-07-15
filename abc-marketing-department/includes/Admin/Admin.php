<?php
/**
 * Admin menu registration and page routing.
 *
 * @package ABCMD
 */

namespace ABCMD\Admin;

use ABCMD\Admin\Pages\AiRunsPage;
use ABCMD\Admin\Pages\ApprovalQueue;
use ABCMD\Admin\Pages\AuditLogPage;
use ABCMD\Admin\Pages\Authors as AuthorsPage;
use ABCMD\Admin\Pages\Books as BooksPage;
use ABCMD\Admin\Pages\ContentCalendar;
use ABCMD\Admin\Pages\Dashboard;
use ABCMD\Admin\Pages\FilesPage;
use ABCMD\Admin\Pages\Help;
use ABCMD\Admin\Pages\Imports as ImportsPage;
use ABCMD\Admin\Pages\Integrations;
use ABCMD\Admin\Pages\Recommendations as RecommendationsPage;
use ABCMD\Admin\Pages\ResearchLog;
use ABCMD\Admin\Pages\Settings as SettingsPage;
use ABCMD\Admin\Pages\Setup;
use ABCMD\Admin\Pages\Tasks as TasksPage;
use ABCMD\Admin\Pages\WeeklyReview;
use ABCMD\Support\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin's admin menu tree.
 */
final class Admin {

	private const ICON = 'dashicons-megaphone';

	/**
	 * Wire admin hooks.
	 */
	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	/**
	 * Register the top-level menu and subpages.
	 */
	public function register_menu(): void {
		$cap = ABCMD_CAP;

		add_menu_page(
			__( 'Marketing Department', 'abc-marketing-department' ),
			__( 'Marketing Dept', 'abc-marketing-department' ),
			$cap,
			'abcmd',
			array( Dashboard::class, 'render' ),
			self::ICON,
			30
		);

		$pages = array(
			'abcmd'                 => array( __( 'Dashboard', 'abc-marketing-department' ), array( Dashboard::class, 'render' ) ),
			'abcmd-authors'         => array( __( 'Authors', 'abc-marketing-department' ), array( AuthorsPage::class, 'render' ) ),
			'abcmd-books'           => array( __( 'Book Workspaces', 'abc-marketing-department' ), array( BooksPage::class, 'render' ) ),
			'abcmd-weekly'          => array( __( 'Weekly Review', 'abc-marketing-department' ), array( WeeklyReview::class, 'render' ) ),
			'abcmd-recommendations' => array( __( 'Recommendations', 'abc-marketing-department' ), array( RecommendationsPage::class, 'render' ) ),
			'abcmd-tasks'           => array( __( 'Tasks', 'abc-marketing-department' ), array( TasksPage::class, 'render' ) ),
			'abcmd-approvals'       => array( __( 'Approval Queue', 'abc-marketing-department' ), array( ApprovalQueue::class, 'render' ) ),
			'abcmd-content'         => array( __( 'Content Calendar', 'abc-marketing-department' ), array( ContentCalendar::class, 'render' ) ),
			'abcmd-imports'         => array( __( 'Imports', 'abc-marketing-department' ), array( ImportsPage::class, 'render' ) ),
			'abcmd-files'           => array( __( 'Files', 'abc-marketing-department' ), array( FilesPage::class, 'render' ) ),
			'abcmd-ai-runs'         => array( __( 'AI Runs', 'abc-marketing-department' ), array( AiRunsPage::class, 'render' ) ),
			'abcmd-research'        => array( __( 'Research Log', 'abc-marketing-department' ), array( ResearchLog::class, 'render' ) ),
			'abcmd-audit'           => array( __( 'Audit Log', 'abc-marketing-department' ), array( AuditLogPage::class, 'render' ) ),
			'abcmd-integrations'    => array( __( 'Integrations', 'abc-marketing-department' ), array( Integrations::class, 'render' ) ),
			'abcmd-settings'        => array( __( 'Settings', 'abc-marketing-department' ), array( SettingsPage::class, 'render' ) ),
			'abcmd-help'            => array( __( 'Help', 'abc-marketing-department' ), array( Help::class, 'render' ) ),
		);

		foreach ( $pages as $slug => $meta ) {
			add_submenu_page( 'abcmd', $meta[0], $meta[0], $cap, $slug, $meta[1] );
		}

		// Hidden setup wizard (not shown in the menu list).
		add_submenu_page( '', __( 'Setup', 'abc-marketing-department' ), __( 'Setup', 'abc-marketing-department' ), $cap, 'abcmd-setup', array( Setup::class, 'render' ) );
	}
}
