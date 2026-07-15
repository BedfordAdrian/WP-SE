<?php
/**
 * REST API endpoints (all permission-gated).
 *
 * These power dynamic admin interactions (e.g. live AI cost estimates). Every
 * route enforces the plugin capability via its permission callback.
 *
 * @package ABCMD
 */

namespace ABCMD\Rest;

use ABCMD\AI\Runner;
use ABCMD\AI\TaskTypes;
use ABCMD\Repository\Anomalies;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles plugin REST routes.
 */
final class Controller {

	private const NS = 'abcmd/v1';

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NS,
			'/ping',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'ping' ),
				'permission_callback' => array( $this, 'can' ),
			)
		);

		register_rest_route(
			self::NS,
			'/ai/estimate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'estimate' ),
				'permission_callback' => array( $this, 'can' ),
				'args'                => array(
					'book_id'  => array( 'required' => true, 'sanitize_callback' => 'absint' ),
					'run_type' => array( 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
					'model'    => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/anomalies',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'anomalies' ),
				'permission_callback' => array( $this, 'can' ),
				'args'                => array(
					'book_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				),
			)
		);
	}

	/**
	 * Capability + nonce permission callback.
	 */
	public function can(): bool {
		return current_user_can( ABCMD_CAP );
	}

	/**
	 * Health check.
	 *
	 * @return \WP_REST_Response
	 */
	public function ping(): \WP_REST_Response {
		return new \WP_REST_Response( array( 'ok' => true, 'version' => ABCMD_VERSION ), 200 );
	}

	/**
	 * Live cost estimate for an AI run.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response
	 */
	public function estimate( \WP_REST_Request $req ): \WP_REST_Response {
		$book_id  = (int) $req->get_param( 'book_id' );
		$run_type = (string) $req->get_param( 'run_type' );
		$model    = (string) $req->get_param( 'model' );
		$web      = (bool) $req->get_param( 'web_search' );
		$cats     = (array) ( $req->get_param( 'categories' ) ?: array() );

		if ( ! TaskTypes::get( $run_type ) ) {
			return new \WP_REST_Response( array( 'error' => 'Unknown run type.' ), 400 );
		}

		$est = Runner::estimate( $book_id, $run_type, $model, array_map( 'sanitize_key', $cats ), array(), $web );
		return new \WP_REST_Response( $est, 200 );
	}

	/**
	 * Open anomalies for a workspace.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response
	 */
	public function anomalies( \WP_REST_Request $req ): \WP_REST_Response {
		$book_id = (int) $req->get_param( 'book_id' );
		$rows    = ( new Anomalies() )->open_for( $book_id );
		return new \WP_REST_Response( array( 'anomalies' => $rows ), 200 );
	}
}
