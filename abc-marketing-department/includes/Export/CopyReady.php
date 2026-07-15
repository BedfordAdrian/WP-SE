<?php
/**
 * Copy-ready text export for content, emails, ad copy, pitches and plans.
 *
 * Produces clean copy blocks without internal metadata unless requested.
 *
 * @package ABCMD
 */

namespace ABCMD\Export;

use ABCMD\Repository\Content;
use ABCMD\Support\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns content items into clean, paste-ready copy.
 */
final class CopyReady {

	/**
	 * Build copy-ready text for a set of content items.
	 *
	 * @param int[] $ids           Content item ids.
	 * @param bool  $with_metadata Include channel/objective/CTA lines.
	 * @return array{filename:string,content:string}
	 */
	public static function build( array $ids, bool $with_metadata = false ): array {
		$repo   = new Content();
		$blocks = array();

		foreach ( $ids as $id ) {
			$c = $repo->find( (int) $id );
			if ( ! $c ) {
				continue;
			}
			$block = array();
			if ( $with_metadata ) {
				$block[] = '--- ' . ( $c['channel'] ?: 'content' ) . ( $c['format'] ? ' / ' . $c['format'] : '' ) . ' ---';
				if ( $c['objective'] ) {
					$block[] = 'Objective: ' . $c['objective'];
				}
			}
			if ( $c['title'] ) {
				$block[] = $c['title'];
			}
			if ( $c['copy'] ) {
				$block[] = '';
				$block[] = wp_strip_all_tags( (string) $c['copy'] );
			}
			if ( $c['caption'] ) {
				$block[] = '';
				$block[] = wp_strip_all_tags( (string) $c['caption'] );
			}
			if ( $c['script'] ) {
				$block[] = '';
				$block[] = wp_strip_all_tags( (string) $c['script'] );
			}
			if ( $c['hashtags'] ) {
				$block[] = '';
				$block[] = (string) $c['hashtags'];
			}
			if ( $with_metadata ) {
				if ( $c['cta'] ) {
					$block[] = '';
					$block[] = 'CTA: ' . $c['cta'];
				}
				if ( $c['destination_link'] ) {
					$block[] = 'Link: ' . $c['destination_link'];
				}
			}
			$blocks[] = implode( "\n", $block );
		}

		Audit::log( 'export.copy', sprintf( 'Exported %d content item(s) as copy-ready text.', count( $ids ) ), array( 'count' => count( $ids ) ) );

		return array(
			'filename' => 'abcmd-copy-' . gmdate( 'Ymd' ) . '.txt',
			'content'  => implode( "\n\n\n", $blocks ),
		);
	}
}
