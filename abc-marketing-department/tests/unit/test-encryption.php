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

// Regression: OpenSSL backend must round-trip independently of libsodium.
// (Hosts using WordPress's sodium_compat polyfill fall back to this path.)
if ( function_exists( 'openssl_encrypt' ) ) {
	$ossl = Encryption::encrypt( $secret, 'openssl' );
	ok( str_starts_with( $ossl, 'ov1:' ), 'Forced OpenSSL backend produces an ov1 ciphertext' );
	eq( $secret, Encryption::decrypt( $ossl ), 'OpenSSL-encrypted value decrypts back to the original' );
}

// Regression: sodium backend encrypt must not throw even when memory-wipe is
// unavailable (sodium_compat polyfill raises on sodium_memzero). Encrypt should
// still succeed and round-trip.
if ( function_exists( 'sodium_crypto_secretbox' ) ) {
	$sod = Encryption::encrypt( $secret, 'sodium' );
	ok( str_starts_with( $sod, 'sv1:' ), 'Forced sodium backend produces an sv1 ciphertext (wipe is guarded)' );
	eq( $secret, Encryption::decrypt( $sod ), 'Sodium-encrypted value decrypts back to the original' );
}
