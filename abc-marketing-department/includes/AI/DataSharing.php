<?php
/**
 * Data-sharing checklist definitions and consent enforcement.
 *
 * @package ABCMD
 */

namespace ABCMD\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Defines the per-run data-sharing categories and gates them against consent.
 */
final class DataSharing {

	/**
	 * All selectable data-sharing categories.
	 *
	 * Each category maps to a consent gate (or null when not consent-restricted)
	 * and a sensitivity flag used to warn the user.
	 *
	 * @return array<string,array{label:string,consent:?string,sensitive:bool}>
	 */
	public static function categories(): array {
		return array(
			'book_profile'        => array( 'label' => 'Book profile', 'consent' => null, 'sensitive' => false ),
			'author_profile'      => array( 'label' => 'Author profile', 'consent' => 'personal', 'sensitive' => true ),
			'synopsis'            => array( 'label' => 'Synopsis', 'consent' => null, 'sensitive' => false ),
			'full_manuscript'     => array( 'label' => 'Full manuscript', 'consent' => 'manuscript', 'sensitive' => true ),
			'manuscript_passages' => array( 'label' => 'Selected manuscript passages', 'consent' => 'manuscript', 'sensitive' => true ),
			'manuscript_summary'  => array( 'label' => 'Locally generated manuscript summary', 'consent' => 'manuscript', 'sensitive' => true ),
			'reviews'             => array( 'label' => 'Uploaded reviews', 'consent' => null, 'sensitive' => false ),
			'press'               => array( 'label' => 'Press coverage', 'consent' => null, 'sensitive' => false ),
			'sales_data'          => array( 'label' => 'Sales data', 'consent' => 'sales', 'sensitive' => true ),
			'advertising_data'    => array( 'label' => 'Advertising data', 'consent' => 'sales', 'sensitive' => true ),
			'email_data'          => array( 'label' => 'Email data', 'consent' => 'sales', 'sensitive' => true ),
			'social_data'         => array( 'label' => 'Social data', 'consent' => 'sales', 'sensitive' => false ),
			'budget'              => array( 'label' => 'Budget', 'consent' => 'sales', 'sensitive' => true ),
			'campaign_history'    => array( 'label' => 'Campaign history', 'consent' => 'sales', 'sensitive' => false ),
			'previous_recs'       => array( 'label' => 'Previous AI recommendations', 'consent' => null, 'sensitive' => false ),
			'uploaded_files'      => array( 'label' => 'Uploaded files', 'consent' => 'manuscript', 'sensitive' => true ),
			'personal_client'     => array( 'label' => 'Personal client data', 'consent' => 'personal', 'sensitive' => true ),
		);
	}

	/**
	 * Given a set of requested categories and a book, filter out anything the
	 * current consent record forbids. Returns [allowed, blocked].
	 *
	 * @param int      $book_id    Book id.
	 * @param string[] $requested  Requested category slugs.
	 * @return array{allowed:string[],blocked:string[]}
	 */
	public static function enforce( int $book_id, array $requested ): array {
		$consent = new \ABCMD\Repository\Consent();
		$cats    = self::categories();
		$allowed = array();
		$blocked = array();

		foreach ( $requested as $slug ) {
			if ( ! isset( $cats[ $slug ] ) ) {
				continue;
			}
			$gate = $cats[ $slug ]['consent'];
			if ( null === $gate ) {
				$allowed[] = $slug;
				continue;
			}
			if ( $consent->category_allowed( $book_id, $gate ) ) {
				$allowed[] = $slug;
			} else {
				$blocked[] = $slug;
			}
		}

		return array(
			'allowed' => $allowed,
			'blocked' => $blocked,
		);
	}
}
