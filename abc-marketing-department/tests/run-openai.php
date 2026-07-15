<?php
/**
 * OpenAI client request-construction & response-parsing tests.
 *
 * Stubs the WordPress HTTP layer to capture the outgoing request and return a
 * canned Responses API payload, verifying the plugin uses the current
 * /v1/responses endpoint, includes the web_search tool, and parses usage +
 * url_citation sources correctly. No network access.
 *
 * Usage: php tests/run-openai.php
 *
 * @package ABCMD
 */

require __DIR__ . '/bootstrap-wp-fake.php';

// --- HTTP layer stubs (capture request, return canned response) -------------
$GLOBALS['__captured'] = null;

function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['__captured'] = array( 'url' => $url, 'args' => $args );
	$body = array(
		'id'     => 'resp_test',
		'model'  => 'gpt-5.4',
		'output' => array(
			array( 'type' => 'web_search_call' ),
			array(
				'type'    => 'message',
				'role'    => 'assistant',
				'content' => array(
					array(
						'type'        => 'output_text',
						'text'        => "Analysis here.\n\n```json\n{\"recommendations\":[{\"title\":\"Do X\"}]}\n```",
						'annotations' => array(
							array( 'type' => 'url_citation', 'url' => 'https://example.com/a', 'title' => 'Source A' ),
							array( 'type' => 'url_citation', 'url' => 'https://example.com/a', 'title' => 'Source A dup' ),
							array( 'type' => 'url_citation', 'url' => 'https://example.com/b', 'title' => 'Source B' ),
						),
					),
				),
			),
		),
		'usage'  => array( 'input_tokens' => 1200, 'output_tokens' => 800, 'total_tokens' => 2000 ),
	);
	return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $body ) );
}
function is_wp_error( $t ) { return $t instanceof \WP_Error; }
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function get_error_message() { return 'error'; }
	}
}

use ABCMD\AI\OpenAIClient;
use ABCMD\Support\Encryption;

$pass = 0; $fail = 0; $msgs = array();
function ok( bool $c, string $l ): void {
	global $pass, $fail, $msgs;
	if ( $c ) { $pass++; echo "  \033[32mPASS\033[0m {$l}\n"; }
	else { $fail++; $msgs[] = $l; echo "  \033[31mFAIL\033[0m {$l}\n"; }
}

// Store an encrypted API key so respond() proceeds.
if ( ! Encryption::available() ) {
	echo "Skipping: no encryption backend.\n";
	exit( 0 );
}
update_option( ABCMD_OPT_OPENAI, array(
	'api_key_enc'   => Encryption::encrypt( 'sk-test-key' ),
	'organization'  => 'org-123',
	'project'       => 'proj-456',
	'default_model' => 'gpt-5.4',
	'temperature'   => 0.5,
	'timeout'       => 30,
	'retries'       => 0,
) );

$result = OpenAIClient::respond( 'gpt-5.4', 'You are a strategist.', 'Audit this book.', array(
	'web_search'        => true,
	'max_output_tokens' => 2000,
) );

$cap  = $GLOBALS['__captured'];
$body = json_decode( $cap['args']['body'] ?? '{}', true );

echo "\033[1mRequest construction\033[0m\n";
ok( str_ends_with( (string) $cap['url'], '/v1/responses' ), 'Targets the current Responses endpoint (/v1/responses)' );
ok( ( $body['model'] ?? '' ) === 'gpt-5.4', 'Sends the selected model' );
ok( ( $body['input'] ?? '' ) === 'Audit this book.', 'Sends the user prompt as input' );
ok( ( $body['instructions'] ?? '' ) === 'You are a strategist.', 'Sends system instructions' );
ok( isset( $body['tools'][0]['type'] ) && 'web_search' === $body['tools'][0]['type'], 'Includes the web_search tool when enabled' );
ok( ( $body['temperature'] ?? null ) === 0.5, 'Applies configured temperature' );
ok( ( $body['max_output_tokens'] ?? 0 ) === 2000, 'Applies max_output_tokens' );
$headers = $cap['args']['headers'] ?? array();
ok( ( $headers['Authorization'] ?? '' ) === 'Bearer sk-test-key', 'Authorization header carries the decrypted key' );
ok( ( $headers['OpenAI-Organization'] ?? '' ) === 'org-123', 'Organization header set' );
ok( ( $headers['OpenAI-Project'] ?? '' ) === 'proj-456', 'Project header set' );

echo "\n\033[1mResponse parsing\033[0m\n";
ok( $result['ok'] === true, 'Result reports ok' );
ok( str_contains( $result['text'], 'Analysis here' ), 'Extracts assistant text' );
ok( $result['input_tokens'] === 1200 && $result['output_tokens'] === 800, 'Parses token usage' );
ok( $result['web_calls'] === 1, 'Counts web_search_call items' );
ok( count( $result['sources'] ) === 2, 'De-duplicates url_citation sources (3 → 2)' );
ok( $result['sources'][0]['url'] === 'https://example.com/a', 'First source URL parsed' );

echo "\n----------------------------------------\n";
echo sprintf( "Passed: %d  Failed: %d\n", $pass, $fail );
if ( $fail > 0 ) { echo "Failures:\n - " . implode( "\n - ", $msgs ) . "\n"; exit( 1 ); }
echo "All OpenAI client tests passed.\n";
exit( 0 );
