<?php
/**
 * Database schema definitions.
 *
 * All CREATE TABLE statements are dbDelta-compatible (two spaces after PRIMARY
 * KEY, KEY not INDEX, one column per line). Reporting-critical numeric fields
 * are real columns; flexible/variable structures are stored as JSON longtext.
 *
 * @package ABCMD
 */

namespace ABCMD\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Table definitions and creation.
 */
final class Schema {

	/**
	 * Fully-qualified table name for a bare key.
	 *
	 * @param string $key Table key without the abcmd_ prefix (e.g. 'books').
	 */
	public static function table( string $key ): string {
		global $wpdb;
		return $wpdb->prefix . 'abcmd_' . $key;
	}

	/**
	 * Create or update all tables via dbDelta.
	 *
	 * @return array<string,mixed> dbDelta result messages.
	 */
	public static function create_all(): array {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$results = array();
		foreach ( self::definitions() as $key => $sql ) {
			$results[ $key ] = dbDelta( $sql );
		}
		return $results;
	}

	/**
	 * Bare table keys (for purge / export enumeration).
	 *
	 * @return string[]
	 */
	public static function keys(): array {
		return array(
			'authors',
			'books',
			'metrics',
			'imports',
			'ai_runs',
			'recommendations',
			'tasks',
			'content',
			'files',
			'file_chunks',
			'consent',
			'anomalies',
			'mapping_profiles',
			'audit_log',
		);
	}

	/**
	 * All CREATE TABLE statements keyed by bare table key.
	 *
	 * @return array<string,string>
	 */
	public static function definitions(): array {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix . 'abcmd_';

		$defs = array();

		$defs['authors'] = "CREATE TABLE {$p}authors (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(200) NOT NULL DEFAULT '',
  pen_name varchar(200) NOT NULL DEFAULT '',
  email varchar(200) NOT NULL DEFAULT '',
  website varchar(300) NOT NULL DEFAULT '',
  biography longtext NULL,
  social_profiles longtext NULL,
  email_platform longtext NULL,
  notes longtext NULL,
  consent_status varchar(30) NOT NULL DEFAULT 'unknown',
  status varchar(30) NOT NULL DEFAULT 'active',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY name (name(100)),
  KEY status (status)
) {$charset};";

		$defs['books'] = "CREATE TABLE {$p}books (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  author_id bigint(20) unsigned NOT NULL DEFAULT 0,
  title varchar(300) NOT NULL DEFAULT '',
  subtitle varchar(300) NOT NULL DEFAULT '',
  publisher varchar(200) NOT NULL DEFAULT '',
  publication_date date NULL,
  genre varchar(150) NOT NULL DEFAULT '',
  territory varchar(150) NOT NULL DEFAULT '',
  audience varchar(300) NOT NULL DEFAULT '',
  synopsis longtext NULL,
  proposition longtext NULL,
  comparison_titles longtext NULL,
  formats longtext NULL,
  prices longtext NULL,
  net_income longtext NULL,
  distributors longtext NULL,
  retailer_links longtext NULL,
  universal_link varchar(300) NOT NULL DEFAULT '',
  campaign_start date NULL,
  long_term_target longtext NULL,
  targets_90day longtext NULL,
  weekly_hours decimal(6,2) NOT NULL DEFAULT 0,
  budget decimal(12,2) NOT NULL DEFAULT 0,
  budget_max decimal(12,2) NOT NULL DEFAULT 0,
  break_even longtext NULL,
  excluded_channels longtext NULL,
  currency varchar(3) NOT NULL DEFAULT 'GBP',
  anomaly_thresholds longtext NULL,
  status varchar(30) NOT NULL DEFAULT 'active',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY author_id (author_id),
  KEY status (status)
) {$charset};";

		$defs['metrics'] = "CREATE TABLE {$p}metrics (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  book_id bigint(20) unsigned NOT NULL DEFAULT 0,
  author_id bigint(20) unsigned NOT NULL DEFAULT 0,
  metric_date date NOT NULL,
  metric_key varchar(60) NOT NULL DEFAULT '',
  format varchar(40) NOT NULL DEFAULT '',
  territory varchar(60) NOT NULL DEFAULT '',
  channel varchar(80) NOT NULL DEFAULT '',
  platform varchar(80) NOT NULL DEFAULT '',
  value_num decimal(16,4) NOT NULL DEFAULT 0,
  value_text varchar(255) NOT NULL DEFAULT '',
  currency varchar(3) NOT NULL DEFAULT 'GBP',
  source varchar(80) NOT NULL DEFAULT '',
  source_status varchar(20) NOT NULL DEFAULT 'live',
  import_id bigint(20) unsigned NOT NULL DEFAULT 0,
  dedup_key varchar(191) NOT NULL DEFAULT '',
  is_adjustment tinyint(1) NOT NULL DEFAULT 0,
  meta longtext NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY book_metric_date (book_id,metric_key,metric_date),
  KEY author_id (author_id),
  KEY import_id (import_id),
  KEY dedup_key (dedup_key)
) {$charset};";

		$defs['imports'] = "CREATE TABLE {$p}imports (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  book_id bigint(20) unsigned NOT NULL DEFAULT 0,
  author_id bigint(20) unsigned NOT NULL DEFAULT 0,
  source varchar(80) NOT NULL DEFAULT '',
  source_status varchar(20) NOT NULL DEFAULT 'live',
  template varchar(80) NOT NULL DEFAULT '',
  original_filename varchar(255) NOT NULL DEFAULT '',
  checksum varchar(64) NOT NULL DEFAULT '',
  rows_total int(11) NOT NULL DEFAULT 0,
  rows_imported int(11) NOT NULL DEFAULT 0,
  rows_rejected int(11) NOT NULL DEFAULT 0,
  rows_duplicate int(11) NOT NULL DEFAULT 0,
  mapping longtext NULL,
  errors longtext NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  audit_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'completed',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY book_id (book_id),
  KEY checksum (checksum),
  KEY source (source)
) {$charset};";

		$defs['ai_runs'] = "CREATE TABLE {$p}ai_runs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  book_id bigint(20) unsigned NOT NULL DEFAULT 0,
  author_id bigint(20) unsigned NOT NULL DEFAULT 0,
  run_type varchar(60) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  model varchar(80) NOT NULL DEFAULT '',
  source_materials longtext NULL,
  data_sharing longtext NULL,
  prompt longtext NULL,
  response longtext NULL,
  web_search tinyint(1) NOT NULL DEFAULT 0,
  sources longtext NULL,
  input_tokens int(11) NOT NULL DEFAULT 0,
  output_tokens int(11) NOT NULL DEFAULT 0,
  est_cost decimal(12,6) NOT NULL DEFAULT 0,
  actual_cost decimal(12,6) NOT NULL DEFAULT 0,
  currency varchar(3) NOT NULL DEFAULT 'USD',
  approval_status varchar(30) NOT NULL DEFAULT 'draft',
  error_state varchar(255) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY book_id (book_id),
  KEY run_type (run_type),
  KEY created_at (created_at)
) {$charset};";

		$defs['recommendations'] = "CREATE TABLE {$p}recommendations (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  book_id bigint(20) unsigned NOT NULL DEFAULT 0,
  author_id bigint(20) unsigned NOT NULL DEFAULT 0,
  ai_run_id bigint(20) unsigned NOT NULL DEFAULT 0,
  title varchar(300) NOT NULL DEFAULT '',
  description longtext NULL,
  rec_type varchar(60) NOT NULL DEFAULT '',
  evidence_label varchar(20) NOT NULL DEFAULT 'inferred',
  confidence varchar(10) NOT NULL DEFAULT 'medium',
  objective varchar(255) NOT NULL DEFAULT '',
  expected_cost decimal(12,2) NOT NULL DEFAULT 0,
  expected_time varchar(80) NOT NULL DEFAULT '',
  expected_impact varchar(255) NOT NULL DEFAULT '',
  success_metric varchar(255) NOT NULL DEFAULT '',
  stop_rule varchar(255) NOT NULL DEFAULT '',
  scale_rule varchar(255) NOT NULL DEFAULT '',
  dependencies longtext NULL,
  source_links longtext NULL,
  rank_score decimal(8,3) NOT NULL DEFAULT 0,
  status varchar(30) NOT NULL DEFAULT 'draft',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY book_id (book_id),
  KEY status (status)
) {$charset};";

		$defs['tasks'] = "CREATE TABLE {$p}tasks (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  book_id bigint(20) unsigned NOT NULL DEFAULT 0,
  author_id bigint(20) unsigned NOT NULL DEFAULT 0,
  recommendation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  title varchar(300) NOT NULL DEFAULT '',
  owner varchar(120) NOT NULL DEFAULT '',
  priority varchar(20) NOT NULL DEFAULT 'medium',
  due_date date NULL,
  effort varchar(80) NOT NULL DEFAULT '',
  budget decimal(12,2) NOT NULL DEFAULT 0,
  actual_cost decimal(12,2) NOT NULL DEFAULT 0,
  status varchar(30) NOT NULL DEFAULT 'draft',
  dependencies longtext NULL,
  recurring varchar(40) NOT NULL DEFAULT '',
  notes longtext NULL,
  content_id bigint(20) unsigned NOT NULL DEFAULT 0,
  completion_date date NULL,
  override_reason varchar(255) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY book_id (book_id),
  KEY status (status),
  KEY due_date (due_date)
) {$charset};";

		$defs['content'] = "CREATE TABLE {$p}content (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  book_id bigint(20) unsigned NOT NULL DEFAULT 0,
  author_id bigint(20) unsigned NOT NULL DEFAULT 0,
  campaign varchar(200) NOT NULL DEFAULT '',
  channel varchar(80) NOT NULL DEFAULT '',
  format varchar(80) NOT NULL DEFAULT '',
  title varchar(300) NOT NULL DEFAULT '',
  copy longtext NULL,
  caption longtext NULL,
  hashtags varchar(500) NOT NULL DEFAULT '',
  script longtext NULL,
  design_direction longtext NULL,
  image_prompt longtext NULL,
  cta varchar(255) NOT NULL DEFAULT '',
  destination_link varchar(300) NOT NULL DEFAULT '',
  tracking_link varchar(300) NOT NULL DEFAULT '',
  publish_date datetime NULL,
  objective varchar(255) NOT NULL DEFAULT '',
  approval_status varchar(30) NOT NULL DEFAULT 'draft',
  export_status varchar(30) NOT NULL DEFAULT 'not_exported',
  version_history longtext NULL,
  edit_history longtext NULL,
  ai_run_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY book_id (book_id),
  KEY approval_status (approval_status),
  KEY publish_date (publish_date)
) {$charset};";

		$defs['files'] = "CREATE TABLE {$p}files (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  book_id bigint(20) unsigned NOT NULL DEFAULT 0,
  author_id bigint(20) unsigned NOT NULL DEFAULT 0,
  original_name varchar(255) NOT NULL DEFAULT '',
  safe_name varchar(255) NOT NULL DEFAULT '',
  stored_path varchar(500) NOT NULL DEFAULT '',
  mime_type varchar(100) NOT NULL DEFAULT '',
  size_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
  document_type varchar(60) NOT NULL DEFAULT '',
  extract_status varchar(30) NOT NULL DEFAULT 'pending',
  extract_error varchar(500) NOT NULL DEFAULT '',
  chunk_count int(11) NOT NULL DEFAULT 0,
  extracted_text longtext NULL,
  summary longtext NULL,
  consent_class varchar(40) NOT NULL DEFAULT 'unclassified',
  checksum varchar(64) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY book_id (book_id),
  KEY document_type (document_type)
) {$charset};";

		$defs['file_chunks'] = "CREATE TABLE {$p}file_chunks (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  file_id bigint(20) unsigned NOT NULL DEFAULT 0,
  book_id bigint(20) unsigned NOT NULL DEFAULT 0,
  chunk_index int(11) NOT NULL DEFAULT 0,
  reference varchar(120) NOT NULL DEFAULT '',
  content longtext NULL,
  token_estimate int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY file_id (file_id),
  KEY book_id (book_id)
) {$charset};";

		$defs['consent'] = "CREATE TABLE {$p}consent (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  book_id bigint(20) unsigned NOT NULL DEFAULT 0,
  author_id bigint(20) unsigned NOT NULL DEFAULT 0,
  allow_openai tinyint(1) NOT NULL DEFAULT 0,
  data_categories longtext NULL,
  allow_manuscript tinyint(1) NOT NULL DEFAULT 0,
  allow_sales tinyint(1) NOT NULL DEFAULT 0,
  allow_personal tinyint(1) NOT NULL DEFAULT 0,
  allow_web_research tinyint(1) NOT NULL DEFAULT 0,
  granted_date date NULL,
  method varchar(120) NOT NULL DEFAULT '',
  notes longtext NULL,
  revoked_date date NULL,
  status varchar(20) NOT NULL DEFAULT 'none',
  updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY book_id (book_id)
) {$charset};";

		$defs['anomalies'] = "CREATE TABLE {$p}anomalies (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  book_id bigint(20) unsigned NOT NULL DEFAULT 0,
  author_id bigint(20) unsigned NOT NULL DEFAULT 0,
  rule varchar(80) NOT NULL DEFAULT '',
  severity varchar(20) NOT NULL DEFAULT 'info',
  title varchar(300) NOT NULL DEFAULT '',
  detail longtext NULL,
  metric_key varchar(60) NOT NULL DEFAULT '',
  dedup_key varchar(191) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'open',
  detected_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY book_id (book_id),
  KEY status (status),
  KEY dedup_key (dedup_key)
) {$charset};";

		$defs['mapping_profiles'] = "CREATE TABLE {$p}mapping_profiles (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(200) NOT NULL DEFAULT '',
  template varchar(80) NOT NULL DEFAULT '',
  mapping longtext NULL,
  date_format varchar(40) NOT NULL DEFAULT '',
  currency varchar(3) NOT NULL DEFAULT 'GBP',
  format_map longtext NULL,
  retailer_map longtext NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY template (template)
) {$charset};";

		$defs['audit_log'] = "CREATE TABLE {$p}audit_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  user_id bigint(20) unsigned NULL,
  user_login varchar(120) NOT NULL DEFAULT '',
  action varchar(100) NOT NULL DEFAULT '',
  summary varchar(500) NOT NULL DEFAULT '',
  book_id bigint(20) unsigned NULL,
  author_id bigint(20) unsigned NULL,
  context longtext NULL,
  ip varchar(45) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY action (action),
  KEY book_id (book_id),
  KEY created_at (created_at)
) {$charset};";

		return $defs;
	}
}
