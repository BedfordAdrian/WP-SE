<?php
/**
 * Render smoke test.
 *
 * Boots the fake-WordPress environment, installs the demo workspace, then
 * renders every admin page and reports any fatal/exception. Not a correctness
 * test — a guard against runtime wiring mistakes (wrong method names, bad calls).
 *
 * Usage: php tests/smoke-render.php
 *
 * @package ABCMD
 */

require __DIR__ . '/bootstrap-wp-fake.php';

$pass = 0;
$fail = 0;
$errors = array();

/**
 * Render a page callback under output buffering and report success/failure.
 */
function render_page( string $label, callable $cb ): void {
	global $pass, $fail, $errors;
	$_POST = array();
	try {
		ob_start();
		$cb();
		$html = ob_get_clean();
		if ( strlen( $html ) < 20 ) {
			throw new \RuntimeException( 'suspiciously short output (' . strlen( $html ) . ' bytes)' );
		}
		echo "  \033[32mPASS\033[0m {$label} (" . strlen( $html ) . " bytes)\n";
		$pass++;
	} catch ( \Throwable $e ) {
		if ( ob_get_level() > 0 ) { ob_end_clean(); }
		echo "  \033[31mFAIL\033[0m {$label}: " . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() . "\n";
		$errors[] = $label . ': ' . $e->getMessage();
		$fail++;
	}
}

echo "\033[1mInstalling demo workspace…\033[0m\n";
try {
	$demo    = \ABCMD\Demo\DemoData::install();
	$book_id = (int) $demo['book_id'];
	$author_id = (int) $demo['author_id'];
	echo "  \033[32mPASS\033[0m DemoData::install (book #{$book_id}, author #{$author_id})\n";
	$pass++;
} catch ( \Throwable $e ) {
	echo "  \033[31mFAIL\033[0m DemoData::install: " . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() . "\n";
	$errors[] = 'DemoData::install: ' . $e->getMessage();
	$fail++;
	$book_id = 0;
	$author_id = 0;
}

echo "\n\033[1mRendering pages…\033[0m\n";

$P = 'ABCMD\\Admin\\Pages\\';

render_page( 'Dashboard', array( $P . 'Dashboard', 'render' ) );

$_GET = array(); render_page( 'Authors (list)', array( $P . 'Authors', 'render' ) );
$_GET = array( 'author' => $author_id ); render_page( 'Authors (edit)', array( $P . 'Authors', 'render' ) );

$_GET = array(); render_page( 'Books (list)', array( $P . 'Books', 'render' ) );
foreach ( array( 'overview', 'edit', 'consent', 'ai', 'anomalies' ) as $tab ) {
	$_GET = array( 'book' => $book_id, 'tab' => $tab );
	render_page( "Books (workspace/$tab)", array( $P . 'Books', 'render' ) );
}
$_GET = array( 'author' => $author_id ); render_page( 'Books (author dashboard)', array( $P . 'Books', 'render' ) );

$_GET = array(); render_page( 'WeeklyReview', array( $P . 'WeeklyReview', 'render' ) );
$_GET = array( 'book' => $book_id ); render_page( 'Recommendations', array( $P . 'Recommendations', 'render' ) );
$_GET = array( 'book' => $book_id ); render_page( 'Tasks', array( $P . 'Tasks', 'render' ) );

foreach ( array( 'recommendations', 'content', 'tasks', 'ai_runs' ) as $tab ) {
	$_GET = array( 'tab' => $tab );
	render_page( "ApprovalQueue/$tab", array( $P . 'ApprovalQueue', 'render' ) );
}

foreach ( array( 'list', 'calendar' ) as $view ) {
	$_GET = array( 'view' => $view, 'book' => $book_id );
	render_page( "ContentCalendar/$view", array( $P . 'ContentCalendar', 'render' ) );
}

$_GET = array(); render_page( 'Imports (no book)', array( $P . 'Imports', 'render' ) );
$_GET = array( 'book' => $book_id, 'template' => 'amazon_sales' ); render_page( 'Imports (book+template)', array( $P . 'Imports', 'render' ) );

$_GET = array( 'book' => $book_id ); render_page( 'FilesPage', array( $P . 'FilesPage', 'render' ) );
$_GET = array(); render_page( 'AiRunsPage (list)', array( $P . 'AiRunsPage', 'render' ) );
$_GET = array(); render_page( 'ResearchLog', array( $P . 'ResearchLog', 'render' ) );
$_GET = array(); render_page( 'AuditLogPage', array( $P . 'AuditLogPage', 'render' ) );
$_GET = array(); render_page( 'Integrations', array( $P . 'Integrations', 'render' ) );

foreach ( array( 'general', 'openai', 'pricing', 'privacy', 'data' ) as $tab ) {
	$_GET = array( 'tab' => $tab );
	render_page( "Settings/$tab", array( $P . 'Settings', 'render' ) );
}

$_GET = array(); render_page( 'Help', array( $P . 'Help', 'render' ) );
$_GET = array(); render_page( 'Setup', array( $P . 'Setup', 'render' ) );

echo "\n----------------------------------------\n";
echo sprintf( "Rendered OK: %d   Failed: %d\n", $pass, $fail );
if ( $fail > 0 ) {
	echo "\nFailures:\n - " . implode( "\n - ", $errors ) . "\n";
	exit( 1 );
}
echo "All pages rendered without fatals.\n";
exit( 0 );
