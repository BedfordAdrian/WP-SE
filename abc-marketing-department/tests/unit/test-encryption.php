<?php
/**
 * Encryption round-trip tests.
 *
 * @package ABCMD
 */

use ABCMD\Support\Encryption;

if ( ! Encryption::available() ) {
	ok( false, 'An encryption backend is available' );
	return;
}
ok( true, 'An encryption backend is available' );

$secret = 'sk-test-1234567890ABCDEFghijklmnop';
$cipher = Encryption::encrypt( $secret );
ok( '' !== $cipher && $cipher !== $secret, 'Ciphertext is non-empty and differs from plaintext' );
eq( $secret, Encryption::decrypt( $cipher ), 'Decrypt returns the original secret' );

eq( '', Encryption::encrypt( '' ), 'Encrypting empty string returns empty' );
eq( null, Encryption::decrypt( 'not-valid-ciphertext' ), 'Decrypting garbage returns null' );

// Tamper detection: flip a byte in the payload.
$parts   = explode( ':', $cipher, 2 );
$decoded = base64_decode( $parts[1] );
$decoded[ strlen( $decoded ) - 1 ] = chr( ord( $decoded[ strlen( $decoded ) - 1 ] ) ^ 0xFF );
$tampered = $parts[0] . ':' . base64_encode( $decoded );
eq( null, Encryption::decrypt( $tampered ), 'Tampered ciphertext fails authentication (returns null)' );

$masked = Encryption::mask( $secret );
ok( str_ends_with( $masked, substr( $secret, -4 ) ) && ! str_contains( $masked, 'sk-test' ), 'Mask hides all but last 4 chars' );
