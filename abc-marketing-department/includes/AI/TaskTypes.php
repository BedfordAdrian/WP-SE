<?php
/**
 * AI task-type catalogue.
 *
 * Each task type declares a label, a system instruction, the minimum data
 * categories it needs (the checklist defaults to this minimum), whether web
 * research is useful, and whether it should attempt to emit structured
 * recommendations / tasks / content for the approval queue.
 *
 * @package ABCMD
 */

namespace ABCMD\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry of supported AI task types.
 */
final class TaskTypes {

	/**
	 * All task types keyed by machine slug.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		$types = array(
			'full_audit' => array(
				'label'    => 'Full book marketing audit',
				'min_data' => array( 'book_profile', 'sales_data', 'advertising_data', 'email_data', 'social_data', 'campaign_history' ),
				'web'      => true,
				'emits'    => array( 'recommendations', 'tasks' ),
			),
			'weekly_review' => array(
				'label'    => 'Weekly performance review',
				'min_data' => array( 'book_profile', 'sales_data', 'advertising_data', 'email_data', 'social_data' ),
				'web'      => true,
				'emits'    => array( 'recommendations', 'tasks', 'content' ),
			),
			'plan_90' => array(
				'label'    => '90-day plan',
				'min_data' => array( 'book_profile', 'sales_data', 'budget' ),
				'web'      => true,
				'emits'    => array( 'recommendations', 'tasks' ),
			),
			'campaign_ideas' => array(
				'label'    => 'Campaign-idea generation',
				'min_data' => array( 'book_profile', 'synopsis' ),
				'web'      => false,
				'emits'    => array( 'recommendations' ),
			),
			'content_calendar' => array(
				'label'    => 'Content-calendar generation',
				'min_data' => array( 'book_profile', 'synopsis' ),
				'web'      => false,
				'emits'    => array( 'content' ),
			),
			'ad_copy' => array(
				'label'    => 'Ad-copy generation',
				'min_data' => array( 'book_profile', 'synopsis' ),
				'web'      => false,
				'emits'    => array( 'content' ),
			),
			'email_draft' => array(
				'label'    => 'Email drafting',
				'min_data' => array( 'book_profile', 'synopsis', 'email_data' ),
				'web'      => false,
				'emits'    => array( 'content' ),
			),
			'publicity_pitch' => array(
				'label'    => 'Publicity-pitch drafting',
				'min_data' => array( 'book_profile', 'synopsis', 'author_profile' ),
				'web'      => false,
				'emits'    => array( 'content' ),
			),
			'review_mining' => array(
				'label'    => 'Review-mining analysis',
				'min_data' => array( 'book_profile', 'reviews' ),
				'web'      => false,
				'emits'    => array( 'recommendations' ),
			),
			'theme_analysis' => array(
				'label'    => 'Manuscript-theme analysis',
				'min_data' => array( 'book_profile', 'manuscript_summary' ),
				'web'      => false,
				'emits'    => array(),
			),
			'reader_magnet' => array(
				'label'    => 'Reader-magnet audit',
				'min_data' => array( 'book_profile', 'synopsis' ),
				'web'      => false,
				'emits'    => array( 'recommendations' ),
			),
			'metadata_audit' => array(
				'label'    => 'Metadata audit',
				'min_data' => array( 'book_profile' ),
				'web'      => true,
				'emits'    => array( 'recommendations' ),
			),
			'anomaly_explain' => array(
				'label'    => 'Anomaly explanation',
				'min_data' => array( 'book_profile', 'sales_data' ),
				'web'      => false,
				'emits'    => array(),
			),
			'custom' => array(
				'label'    => 'Custom prompt',
				'min_data' => array( 'book_profile' ),
				'web'      => false,
				'emits'    => array(),
			),
		);

		/**
		 * Filter the AI task-type catalogue (phase-two integrations may add types).
		 *
		 * @param array<string,array<string,mixed>> $types Task types.
		 */
		return apply_filters( 'abcmd_ai_task_types', $types );
	}

	/**
	 * Fetch one task type or null.
	 *
	 * @param string $slug Task slug.
	 * @return array<string,mixed>|null
	 */
	public static function get( string $slug ): ?array {
		$all = self::all();
		return $all[ $slug ] ?? null;
	}

	/**
	 * Options list (slug => label) for dropdowns.
	 *
	 * @return array<string,string>
	 */
	public static function options(): array {
		$out = array();
		foreach ( self::all() as $slug => $t ) {
			$out[ $slug ] = (string) $t['label'];
		}
		return $out;
	}

	/**
	 * System instruction for a task type.
	 *
	 * @param string $slug Task slug.
	 */
	public static function system_instruction( string $slug ): string {
		$common = "You are a senior book-marketing strategist working inside an agency's internal tool. "
			. "Be specific, commercially realistic, and honest about uncertainty. "
			. "Never invent sales figures, reviews, citations or URLs. "
			. "When you cite web research, only use sources actually provided to you. "
			. "Label each recommendation's evidence basis as evidence-backed, inferred, or experimental, and give a confidence of high, medium or low.";

		$specific = array(
			'full_audit'       => 'Produce a thorough audit of the book\'s marketing: positioning, metadata, pricing, channels, advertising efficiency, email and social performance, and the biggest opportunities. Then produce ranked recommendations.',
			'weekly_review'    => 'Review the most recent week against earlier periods. Note what changed, what is working, what is not, any data gaps, and the highest-leverage actions for the coming week. Produce ranked recommendations and a few proposed content items.',
			'plan_90'          => 'Produce a realistic 90-day marketing plan to move toward the stated targets within the stated budget and weekly hours. Break it into phases with measurable milestones.',
			'campaign_ideas'   => 'Generate distinctive, on-brand campaign concepts suited to the genre and audience.',
			'content_calendar' => 'Propose a dated content calendar of posts/emails across the relevant channels, each with a clear objective.',
			'ad_copy'          => 'Write several ad-copy variants suited to the platform and audience, with hooks and clear calls to action.',
			'email_draft'      => 'Draft an email to the author\'s mailing list appropriate to the current campaign stage.',
			'publicity_pitch'  => 'Draft a concise, compelling publicity pitch suitable for media/bloggers.',
			'review_mining'    => 'Analyse the supplied reviews for recurring themes, selling points, objections and quotable lines. Do not fabricate reviews.',
			'theme_analysis'   => 'Analyse the supplied manuscript summary/passages for themes, comps, and marketing angles.',
			'reader_magnet'    => 'Assess the reader-magnet / lead-generation approach and propose improvements.',
			'metadata_audit'   => 'Audit the book\'s metadata (title, subtitle, categories, keywords, description) for discoverability and conversion.',
			'anomaly_explain'  => 'Explain, cautiously, what might account for the flagged change in the data. Describe what to check. Do not assert fraud or certainty.',
			'custom'           => 'Follow the user\'s custom instruction using only the data provided.',
		);

		return $common . "\n\nTask: " . ( $specific[ $slug ] ?? $specific['custom'] );
	}
}
