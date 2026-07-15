<?php
/**
 * Admin view helpers: consistent, escaped HTML building blocks.
 *
 * @package ABCMD
 */

namespace ABCMD\Admin;

use ABCMD\Support\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small, escaping-by-default UI primitives shared across admin pages.
 */
final class View {

	/**
	 * Open a standard admin page wrapper with a title.
	 *
	 * @param string $title    Page title.
	 * @param string $subtitle Optional subtitle/description.
	 */
	public static function open( string $title, string $subtitle = '' ): void {
		echo '<div class="wrap abcmd-wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html( $title ) . '</h1>';
		if ( '' !== $subtitle ) {
			echo '<p class="description abcmd-subtitle">' . esc_html( $subtitle ) . '</p>';
		}
		self::flash();
	}

	/**
	 * Close the page wrapper.
	 */
	public static function close(): void {
		echo '</div>';
	}

	/**
	 * Render a dismissible notice.
	 *
	 * @param string $message Message (plain text).
	 * @param string $type    success|error|warning|info.
	 */
	public static function notice( string $message, string $type = 'info' ): void {
		$class = in_array( $type, array( 'success', 'error', 'warning', 'info' ), true ) ? $type : 'info';
		echo '<div class="notice notice-' . esc_attr( $class ) . ' is-dismissible"><p>' . wp_kses_post( $message ) . '</p></div>';
	}

	/**
	 * Show a flash message stored in a transient after a redirect.
	 */
	public static function flash(): void {
		$key = 'abcmd_flash_' . get_current_user_id();
		$msg = get_transient( $key );
		if ( is_array( $msg ) && ! empty( $msg['text'] ) ) {
			delete_transient( $key );
			self::notice( (string) $msg['text'], (string) ( $msg['type'] ?? 'info' ) );
		}
	}

	/**
	 * Store a flash message for display after a redirect.
	 *
	 * @param string $text Message.
	 * @param string $type Type.
	 */
	public static function set_flash( string $text, string $type = 'success' ): void {
		set_transient( 'abcmd_flash_' . get_current_user_id(), array( 'text' => $text, 'type' => $type ), 60 );
	}

	/**
	 * Open a form posting to admin-post.php with a nonce.
	 *
	 * @param string $action     admin-post action (without abcmd_ echoed as-is).
	 * @param string $enctype    Optional enctype (e.g. multipart/form-data).
	 * @param array<string,string> $hidden Extra hidden fields.
	 */
	public static function form_open( string $action, string $enctype = '', array $hidden = array() ): void {
		$enc = $enctype ? ' enctype="' . esc_attr( $enctype ) . '"' : '';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . $enc . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		wp_nonce_field( $action, '_abcmd_nonce' );
		foreach ( $hidden as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '" />';
		}
	}

	/**
	 * Close a form.
	 */
	public static function form_close(): void {
		echo '</form>';
	}

	/**
	 * Text input row inside a form-table.
	 *
	 * @param string $name  Field name.
	 * @param string $label Label.
	 * @param string $value Current value.
	 * @param string $type  HTML input type.
	 * @param string $hint  Help text.
	 */
	public static function field( string $name, string $label, string $value = '', string $type = 'text', string $hint = '' ): void {
		$id = 'abcmd_' . $name;
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="' . esc_attr( $type ) . '" class="regular-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
		if ( '' !== $hint ) {
			echo '<p class="description">' . esc_html( $hint ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Textarea row.
	 *
	 * @param string $name  Field name.
	 * @param string $label Label.
	 * @param string $value Value.
	 * @param int    $rows  Rows.
	 * @param string $hint  Help text.
	 */
	public static function textarea( string $name, string $label, string $value = '', int $rows = 4, string $hint = '' ): void {
		$id = 'abcmd_' . $name;
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<textarea class="large-text" rows="' . (int) $rows . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">' . esc_textarea( $value ) . '</textarea>';
		if ( '' !== $hint ) {
			echo '<p class="description">' . esc_html( $hint ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Select row.
	 *
	 * @param string               $name    Field name.
	 * @param string               $label   Label.
	 * @param array<string|int,string> $options Value => label.
	 * @param string|int           $current Selected value.
	 * @param string               $hint    Help text.
	 */
	public static function select( string $name, string $label, array $options, $current = '', string $hint = '' ): void {
		$id = 'abcmd_' . $name;
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
		foreach ( $options as $val => $lab ) {
			echo '<option value="' . esc_attr( (string) $val ) . '" ' . selected( (string) $current, (string) $val, false ) . '>' . esc_html( $lab ) . '</option>';
		}
		echo '</select>';
		if ( '' !== $hint ) {
			echo '<p class="description">' . esc_html( $hint ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Checkbox row.
	 *
	 * @param string $name    Field name.
	 * @param string $label   Label.
	 * @param bool   $checked Checked state.
	 * @param string $hint    Help text.
	 */
	public static function checkbox( string $name, string $label, bool $checked = false, string $hint = '' ): void {
		$id = 'abcmd_' . $name;
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		echo '<label><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1" ' . checked( $checked, true, false ) . ' /> ' . esc_html( $hint ) . '</label>';
		echo '</td></tr>';
	}

	/**
	 * Submit button.
	 *
	 * @param string $label   Button text.
	 * @param string $variant primary|secondary|delete.
	 */
	public static function submit( string $label, string $variant = 'primary' ): void {
		$class = 'delete' === $variant ? 'button button-link-delete' : ( 'secondary' === $variant ? 'button' : 'button button-primary' );
		echo '<p><button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></p>';
	}

	/**
	 * Render a link styled as a button.
	 *
	 * @param string $url     URL.
	 * @param string $label   Label.
	 * @param string $variant primary|secondary.
	 */
	public static function button_link( string $url, string $label, string $variant = 'secondary' ): string {
		$class = 'primary' === $variant ? 'button button-primary' : 'button';
		return '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}

	/**
	 * Render tab navigation.
	 *
	 * @param array<string,string> $tabs    slug => label.
	 * @param string               $current Current slug.
	 * @param string               $page    Page slug.
	 * @param array<string,mixed>  $base    Extra query args.
	 */
	public static function tabs( array $tabs, string $current, string $page, array $base = array() ): void {
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			$url   = Helpers::admin_url( $page, array_merge( $base, array( 'tab' => $slug ) ) );
			$class = $slug === $current ? 'nav-tab nav-tab-active' : 'nav-tab';
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</h2>';
	}

	/**
	 * A simple metric "stat card".
	 *
	 * @param string $label Label.
	 * @param string $value Value (already formatted).
	 * @param string $sub   Sub-line.
	 */
	public static function stat( string $label, string $value, string $sub = '' ): void {
		echo '<div class="abcmd-stat"><div class="abcmd-stat-value">' . esc_html( $value ) . '</div>';
		echo '<div class="abcmd-stat-label">' . esc_html( $label ) . '</div>';
		if ( '' !== $sub ) {
			echo '<div class="abcmd-stat-sub">' . esc_html( $sub ) . '</div>';
		}
		echo '</div>';
	}

	/**
	 * Escaped status pill.
	 *
	 * @param string $status Status text.
	 */
	public static function pill( string $status ): string {
		$slug = sanitize_html_class( str_replace( array( ' ', '_' ), '-', strtolower( $status ) ) );
		return '<span class="abcmd-pill abcmd-pill-' . esc_attr( $slug ) . '">' . esc_html( ucwords( str_replace( array( '_', '-' ), ' ', $status ) ) ) . '</span>';
	}

	/**
	 * Empty-state message.
	 *
	 * @param string $message Message.
	 */
	public static function empty_state( string $message ): void {
		echo '<div class="abcmd-empty"><p>' . esc_html( $message ) . '</p></div>';
	}
}
