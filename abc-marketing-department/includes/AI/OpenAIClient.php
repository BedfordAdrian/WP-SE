<?php
/**
 * OpenAI API client using the current Responses API (/v1/responses).
 *
 * The Responses API is OpenAI's current text-generation endpoint (the legacy
 * Completions endpoint is not used). Web research uses the hosted `web_search`
 * tool where the selected model supports it.
 *
 * @package ABCMD
 */

namespace ABCMD\AI;

use ABCMD\Support\Encryption;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin, defensive HTTP client for OpenAI Responses.
 */
final class OpenAIClient {

	private const ENDPOINT = 'https://api.openai.com/v1/responses';

	/**
	 * Stored OpenAI settings (raw option, key still encrypted).
	 *
	 * @return array<string,mixed>
	 */
	public static function settings(): array {
		$defaults = array(
			'api_key_enc'    => '',
			'organization'   => '',
			'project'        => '',
			'default_model'  => 'gpt-5.4',
			'allowed_models' => array( 'gpt-5.4' ),
			'temperature'    => 0.7,
			'web_search'     => 0,
			'timeout'        => 60,
			'retries'        => 2,
		);
		$saved = get_option( ABCMD_OPT_OPENAI, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( $defaults, $saved );
	}

	/**
	 * Whether an API key has been configured.
	 */
	public static function has_key(): bool {
		$s = self::settings();
		return '' !== (string) $s['api_key_enc'];
	}

	/**
	 * Decrypt the stored API key (never exposed to the UI).
	 */
	private static function api_key(): ?string {
		$s   = self::settings();
		$enc = (string) $s['api_key_enc'];
		if ( '' === $enc ) {
			return null;
		}
		return Encryption::decrypt( $enc );
	}

	/**
	 * Allowed model ids for task selection.
	 *
	 * @return string[]
	 */
	public static function allowed_models(): array {
		$s = self::settings();
		$m = is_array( $s['allowed_models'] ?? null ) ? $s['allowed_models'] : array();
		return array_values( array_filter( array_map( 'strval', $m ) ) );
	}

	/**
	 * Make a Responses API request.
	 *
	 * @param string              $model        Model id.
	 * @param string              $instructions System-level instructions.
	 * @param string              $input        User prompt.
	 * @param array<string,mixed> $options      web_search(bool), temperature(float|null),
	 *                                          max_output_tokens(int).
	 * @return array{ok:bool,text:string,sources:array<int,array<string,string>>,input_tokens:int,output_tokens:int,web_calls:int,model:string,error:string,error_code:string}
	 */
	public static function respond( string $model, string $instructions, string $input, array $options = array() ): array {
		$result = array(
			'ok'            => false,
			'text'          => '',
			'sources'       => array(),
			'input_tokens'  => 0,
			'output_tokens' => 0,
			'web_calls'     => 0,
			'model'         => $model,
			'error'         => '',
			'error_code'    => '',
		);

		$key = self::api_key();
		if ( null === $key || '' === $key ) {
			$result['error']      = __( 'No valid OpenAI API key is configured (or it could not be decrypted — check whether WordPress salts changed).', 'abc-marketing-department' );
			$result['error_code'] = 'no_key';
			return $result;
		}

		$settings = self::settings();

		$body = array(
			'model' => $model,
			'input' => $input,
		);
		if ( '' !== trim( $instructions ) ) {
			$body['instructions'] = $instructions;
		}

		// Temperature (only if provided; some reasoning models reject it — errors surface clearly).
		$temp = $options['temperature'] ?? $settings['temperature'];
		if ( null !== $temp && '' !== $temp ) {
			$body['temperature'] = (float) $temp;
		}

		if ( ! empty( $options['max_output_tokens'] ) ) {
			$body['max_output_tokens'] = (int) $options['max_output_tokens'];
		}

		// Web search tool.
		$want_web = ! empty( $options['web_search'] );
		if ( $want_web ) {
			/**
			 * Filter the web-search tool descriptor sent to the Responses API.
			 *
			 * @param array<string,mixed> $tool Tool descriptor.
			 */
			$tool           = apply_filters( 'abcmd_web_search_tool', array( 'type' => 'web_search' ) );
			$body['tools']  = array( $tool );
		}

		// Drop parameters this model is known to reject (learned from prior runs,
		// e.g. reasoning models that do not accept `temperature`). Avoids a
		// wasted round-trip.
		foreach ( self::model_unsupported( $model ) as $bad ) {
			unset( $body[ $bad ] );
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $key,
			'Content-Type'  => 'application/json',
		);
		if ( ! empty( $settings['organization'] ) ) {
			$headers['OpenAI-Organization'] = (string) $settings['organization'];
		}
		if ( ! empty( $settings['project'] ) ) {
			$headers['OpenAI-Project'] = (string) $settings['project'];
		}

		$timeout = max( 5, (int) $settings['timeout'] );
		$retries = max( 0, (int) $settings['retries'] );

		$code = 0;
		$data = null;

		// Outer loop: on an "unsupported parameter" rejection, drop that param
		// and retry (bounded), so a model that refuses e.g. `temperature` still
		// completes instead of failing.
		for ( $strip = 0; $strip < 4; $strip++ ) {
			$attempt  = 0;
			$response = null;
			do {
				$response = wp_remote_post(
					self::ENDPOINT,
					array(
						'headers' => $headers,
						'body'    => wp_json_encode( $body ),
						'timeout' => $timeout,
					)
				);

				if ( ! is_wp_error( $response ) ) {
					$code = (int) wp_remote_retrieve_response_code( $response );
					// Retry only on transient server/rate errors.
					if ( ! in_array( $code, array( 429, 500, 502, 503, 504 ), true ) ) {
						break;
					}
				}
				$attempt++;
				if ( $attempt <= $retries ) {
					sleep( min( 8, 2 ** $attempt ) );
				}
			} while ( $attempt <= $retries );

			// Transport failure (timeout, DNS, TLS...).
			if ( is_wp_error( $response ) ) {
				$result['error']      = $response->get_error_message();
				$result['error_code'] = 'transport';
				return $result;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$raw  = wp_remote_retrieve_body( $response );
			$data = json_decode( $raw, true );

			if ( 200 === $code ) {
				break;
			}

			// Adaptive parameter stripping.
			$bad = self::unsupported_param( is_array( $data ) ? $data : array() );
			if ( '' !== $bad && array_key_exists( $bad, $body ) && self::is_droppable_param( $bad ) ) {
				unset( $body[ $bad ] );
				self::remember_unsupported( $model, $bad );
				continue;
			}

			// Non-recoverable error.
			$msg = is_array( $data ) && isset( $data['error']['message'] )
				? (string) $data['error']['message']
				: ( 'HTTP ' . $code );
			$result['error']      = $msg;
			$result['error_code'] = self::classify_error( $code, is_array( $data ) ? $data : array() );
			return $result;
		}

		if ( 200 !== $code ) {
			$msg = is_array( $data ) && isset( $data['error']['message'] )
				? (string) $data['error']['message']
				: ( 'HTTP ' . $code );
			$result['error']      = $msg;
			$result['error_code'] = self::classify_error( $code, is_array( $data ) ? $data : array() );
			return $result;
		}

		if ( ! is_array( $data ) ) {
			$result['error']      = __( 'The API returned a malformed (non-JSON) response.', 'abc-marketing-department' );
			$result['error_code'] = 'malformed';
			return $result;
		}

		$parsed = self::parse_output( $data );
		if ( '' === $parsed['text'] ) {
			$result['error']      = __( 'The API response contained no readable text.', 'abc-marketing-department' );
			$result['error_code'] = 'empty';
			// Still return usage below so cost is logged.
		}

		$usage  = $data['usage'] ?? array();
		$result = array_merge(
			$result,
			array(
				'ok'            => '' === $parsed['text'] ? false : true,
				'text'          => $parsed['text'],
				'sources'       => $parsed['sources'],
				'web_calls'     => $parsed['web_calls'],
				'input_tokens'  => (int) ( $usage['input_tokens'] ?? 0 ),
				'output_tokens' => (int) ( $usage['output_tokens'] ?? 0 ),
				'model'         => (string) ( $data['model'] ?? $model ),
			)
		);

		return $result;
	}

	/**
	 * Extract text, web sources and web-call count from a Responses payload.
	 *
	 * @param array<string,mixed> $data Decoded response.
	 * @return array{text:string,sources:array<int,array<string,string>>,web_calls:int}
	 */
	private static function parse_output( array $data ): array {
		$text      = '';
		$sources   = array();
		$web_calls = 0;

		// Convenience aggregate provided by some responses.
		if ( isset( $data['output_text'] ) && is_string( $data['output_text'] ) ) {
			$text = $data['output_text'];
		}

		$output = $data['output'] ?? array();
		if ( is_array( $output ) ) {
			foreach ( $output as $item ) {
				$type = $item['type'] ?? '';
				if ( 'web_search_call' === $type ) {
					$web_calls++;
					continue;
				}
				if ( 'message' === $type && isset( $item['content'] ) && is_array( $item['content'] ) ) {
					foreach ( $item['content'] as $part ) {
						if ( ( $part['type'] ?? '' ) === 'output_text' && isset( $part['text'] ) ) {
							if ( '' === $text ) {
								$text = (string) $part['text'];
							}
							foreach ( (array) ( $part['annotations'] ?? array() ) as $ann ) {
								if ( ( $ann['type'] ?? '' ) === 'url_citation' ) {
									$sources[] = array(
										'title' => (string) ( $ann['title'] ?? '' ),
										'url'   => (string) ( $ann['url'] ?? '' ),
									);
								}
							}
						}
					}
				}
			}
		}

		// De-duplicate sources by URL.
		$seen = array();
		$dedup = array();
		foreach ( $sources as $s ) {
			$u = $s['url'];
			if ( '' === $u || isset( $seen[ $u ] ) ) {
				continue;
			}
			$seen[ $u ] = true;
			$dedup[]    = $s;
		}

		return array(
			'text'      => trim( $text ),
			'sources'   => $dedup,
			'web_calls' => $web_calls,
		);
	}

	/**
	 * Map an HTTP status + error payload to a plugin error code.
	 *
	 * @param int                 $code HTTP status.
	 * @param array<string,mixed> $data Decoded error body.
	 */
	private static function classify_error( int $code, array $data ): string {
		if ( 401 === $code ) {
			return 'invalid_key';
		}
		if ( 429 === $code ) {
			return 'rate_limit';
		}
		if ( 404 === $code ) {
			return 'model_unavailable';
		}
		$msg = strtolower( (string) ( $data['error']['message'] ?? '' ) );
		if ( str_contains( $msg, 'model' ) && ( str_contains( $msg, 'does not exist' ) || str_contains( $msg, 'not found' ) ) ) {
			return 'model_unavailable';
		}
		if ( str_contains( $msg, 'web_search' ) || str_contains( $msg, 'tool' ) ) {
			return 'web_search_unsupported';
		}
		return 'api_error';
	}

	/**
	 * Extract the name of a parameter the API rejected as unsupported, if any.
	 *
	 * @param array<string,mixed> $data Decoded error body.
	 * @return string Parameter name, or '' when not a parameter-support error.
	 */
	private static function unsupported_param( array $data ): string {
		$err = $data['error'] ?? array();
		if ( ! is_array( $err ) ) {
			return '';
		}
		if ( ! empty( $err['param'] ) && is_string( $err['param'] ) ) {
			return $err['param'];
		}
		$msg = strtolower( (string) ( $err['message'] ?? '' ) );
		$is_param_error = str_contains( $msg, 'unsupported parameter' )
			|| ( str_contains( $msg, 'not supported' ) && str_contains( $msg, 'parameter' ) )
			|| ( str_contains( $msg, 'unknown parameter' ) );
		if ( $is_param_error && preg_match( "/'([a-z0-9_]+)'/i", (string) ( $err['message'] ?? '' ), $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Whether a parameter is safe to drop and retry without.
	 *
	 * @param string $param Parameter name.
	 */
	private static function is_droppable_param( string $param ): bool {
		return in_array( $param, array( 'temperature', 'top_p', 'presence_penalty', 'frequency_penalty' ), true );
	}

	/**
	 * Parameters a given model has previously rejected (learned quirks).
	 *
	 * @param string $model Model id.
	 * @return string[]
	 */
	private static function model_unsupported( string $model ): array {
		$quirks = get_option( 'abcmd_model_quirks', array() );
		if ( ! is_array( $quirks ) ) {
			return array();
		}
		$list = $quirks[ $model ] ?? array();
		return is_array( $list ) ? array_values( array_filter( array_map( 'strval', $list ) ) ) : array();
	}

	/**
	 * Remember that a model rejects a parameter, so future runs omit it upfront.
	 *
	 * @param string $model Model id.
	 * @param string $param Parameter name.
	 */
	private static function remember_unsupported( string $model, string $param ): void {
		$quirks = get_option( 'abcmd_model_quirks', array() );
		if ( ! is_array( $quirks ) ) {
			$quirks = array();
		}
		$list = isset( $quirks[ $model ] ) && is_array( $quirks[ $model ] ) ? $quirks[ $model ] : array();
		if ( ! in_array( $param, $list, true ) ) {
			$list[] = $param;
		}
		$quirks[ $model ] = $list;
		update_option( 'abcmd_model_quirks', $quirks, false );
	}
}
