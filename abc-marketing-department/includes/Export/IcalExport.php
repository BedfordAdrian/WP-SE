<?php
/**
 * iCalendar export for approved, scheduled tasks and content items.
 *
 * @package ABCMD
 */

namespace ABCMD\Export;

use ABCMD\Repository\Books;
use ABCMD\Repository\Content;
use ABCMD\Repository\Tasks;
use ABCMD\Support\Audit;
use ABCMD\Support\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Produces a .ics file of the marketing calendar.
 */
final class IcalExport {

	/**
	 * Build an ICS document.
	 *
	 * @param int|null $book_id Optional workspace filter.
	 * @return array{filename:string,content:string}
	 */
	public static function build( ?int $book_id = null ): array {
		$events = array();

		// Approved / scheduled tasks with a due date.
		$tasks = new Tasks();
		$where = $book_id ? array( 'book_id' => $book_id ) : array();
		foreach ( $tasks->all( $where, 'due_date', 'ASC' ) as $t ) {
			if ( empty( $t['due_date'] ) || ! in_array( $t['status'], array( 'approved', 'scheduled', 'in_progress' ), true ) ) {
				continue;
			}
			$events[] = self::event(
				'task-' . $t['id'],
				(string) $t['due_date'],
				'Task: ' . $t['title'],
				'Owner: ' . ( $t['owner'] ?: 'unassigned' ) . '. Status: ' . $t['status'],
				'',
				(int) $t['book_id'],
				Helpers::admin_url( 'abcmd-tasks', array( 'task' => $t['id'] ) )
			);
		}

		// Approved content items with a publish date.
		$content = new Content();
		foreach ( $content->all( $where, 'publish_date', 'ASC' ) as $c ) {
			if ( empty( $c['publish_date'] ) || 'approved' !== $c['approval_status'] ) {
				continue;
			}
			$events[] = self::event(
				'content-' . $c['id'],
				(string) $c['publish_date'],
				'Post: ' . ( $c['title'] ?: $c['channel'] ),
				trim( (string) $c['objective'] . ' ' . wp_trim_words( (string) $c['copy'], 30 ) ),
				(string) $c['channel'],
				(int) $c['book_id'],
				Helpers::admin_url( 'abcmd-content', array( 'item' => $c['id'] ) )
			);
		}

		$ics  = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//ABC Book Marketing//Marketing Department//EN\r\nCALSCALE:GREGORIAN\r\n";
		$ics .= implode( '', $events );
		$ics .= "END:VCALENDAR\r\n";

		Audit::log( 'export.ical', 'Exported calendar as iCalendar.', array( 'book_id' => $book_id, 'events' => count( $events ) ), $book_id );

		return array(
			'filename' => 'abcmd-calendar-' . gmdate( 'Ymd' ) . '.ics',
			'content'  => $ics,
		);
	}

	/**
	 * Build one VEVENT block.
	 */
	private static function event( string $uid, string $when, string $summary, string $desc, string $channel, int $book_id, string $url ): string {
		$books = new Books();
		$book  = $book_id ? $books->find( $book_id ) : null;
		$ws    = $book ? (string) $book['title'] : '';

		// Accept 'Y-m-d' or 'Y-m-d H:i:s'.
		$ts = strtotime( $when );
		if ( ! $ts ) {
			$ts = time();
		}
		$is_datetime = (bool) preg_match( '/\d{2}:\d{2}/', $when );

		$dt = $is_datetime
			? 'DTSTART:' . gmdate( 'Ymd\THis\Z', $ts )
			: 'DTSTART;VALUE=DATE:' . gmdate( 'Ymd', $ts );

		$description = $desc;
		if ( $channel ) {
			$description = 'Channel: ' . $channel . '. ' . $description;
		}
		if ( $ws ) {
			$description = 'Workspace: ' . $ws . '. ' . $description;
		}
		$description .= ' Dashboard: ' . $url;

		return "BEGIN:VEVENT\r\n"
			. 'UID:abcmd-' . $uid . '@' . self::host() . "\r\n"
			. 'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ) . "\r\n"
			. $dt . "\r\n"
			. 'SUMMARY:' . self::escape( $summary ) . "\r\n"
			. 'DESCRIPTION:' . self::escape( $description ) . "\r\n"
			. 'URL:' . self::escape( $url ) . "\r\n"
			. "END:VEVENT\r\n";
	}

	private static function escape( string $s ): string {
		$s = str_replace( array( '\\', ';', ',', "\n", "\r" ), array( '\\\\', '\\;', '\\,', '\\n', '' ), $s );
		return $s;
	}

	private static function host(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return $host ? (string) $host : 'localhost';
	}
}
