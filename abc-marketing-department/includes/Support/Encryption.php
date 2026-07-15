<?php
/**
 * Encryption at rest for API keys and access tokens.
 *
 * Design goals:
 *  - Never store the usable decryption key in the database.
 *  - Derive key material from WordPress secret salts (wp-config.php), which live
 *    outside the database.
 *  - Prefer libsodium's authenticated secretbox; fall back to OpenSSL
 *    AES-256-GCM (also authenticated) where sodium is unavailable.
 *  - Never log or expose raw secrets.
 *
 * Consequence of rotating WordPress salts: the derived key changes, so any value
 * encrypted with the old salts becomes undecryptable and must be re-entered.
 * This is documented in the Settings screen and README.
 *
 * @package ABCMD
 */

namespace ABCMD\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authenticated symmetric encryption with a salt-derived key.
 */
final class Encryption {

	private const V_SODIUM = 'sv1'; // sodium secretbox.
	private const V_OSSL   = 'ov1'; // openssl aes-256-gcm.

	/**
	 * Whether any encryption backend is available.
	 */
	public static function available(): bool {
		return self::has_sodium() || self::has_openssl();
	}

	/**
	 * Encrypt a plaintext secret. Returns an opaque, storable string.
	 *
	 * @param string $plaintext Secret value.
	 * @return string Base64 ciphertext prefixed with a version tag.
	 * @throws \RuntimeException When no backend is available.
	 */
	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}

		if ( self::has_sodium() ) {
			$key   = self::derive_key( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
			sodium_memzero( $key );
			return self::V_SODIUM . ':' . base64_encode( $nonce . $cipher );
		}

		if ( self::has_openssl() ) {
			$key    = self::derive_key( 32 );
			$iv     = random_bytes( 12 );
			$tag    = '';
			$cipher = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
			if ( false === $cipher ) {
				throw new \RuntimeException( 'OpenSSL encryption failed.' );
			}
			return self::V_OSSL . ':' . base64_encode( $iv . $tag . $cipher );
		}

		throw new \RuntimeException( 'No encryption backend available.' );
	}

	/**
	 * Decrypt a value produced by encrypt(). Returns null on any failure.
	 *
	 * @param string $stored Stored ciphertext.
	 */
	public static function decrypt( string $stored ): ?string {
		if ( '' === $stored ) {
			return '';
		}

		$parts = explode( ':', $stored, 2 );
		if ( count( $parts ) !== 2 ) {
			return null;
		}
		list( $version, $payload ) = $parts;
		$raw = base64_decode( $payload, true );
		if ( false === $raw ) {
			return null;
		}

		try {
			if ( self::V_SODIUM === $version && self::has_sodium() ) {
				$nonce_len = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
				if ( strlen( $raw ) <= $nonce_len ) {
					return null;
				}
				$nonce  = substr( $raw, 0, $nonce_len );
				$cipher = substr( $raw, $nonce_len );
				$key    = self::derive_key( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
				$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
				sodium_memzero( $key );
				return false === $plain ? null : $plain;
			}

			if ( self::V_OSSL === $version && self::has_openssl() ) {
				if ( strlen( $raw ) <= 28 ) {
					return null;
				}
				$iv     = substr( $raw, 0, 12 );
				$tag    = substr( $raw, 12, 16 );
				$cipher = substr( $raw, 28 );
				$key    = self::derive_key( 32 );
				$plain  = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
				return false === $plain ? null : $plain;
			}
		} catch ( \Exception $e ) {
			return null;
		}

		return null;
	}

	/**
	 * Mask a secret for display (never reveal it after saving).
	 *
	 * @param string $secret Plaintext secret.
	 */
	public static function mask( string $secret ): string {
		$len = strlen( $secret );
		if ( 0 === $len ) {
			return '';
		}
		$tail = substr( $secret, -4 );
		return str_repeat( '•', max( 4, min( 20, $len - 4 ) ) ) . $tail;
	}

	/**
	 * Derive a fixed-length key from WordPress salts + a plugin context string.
	 *
	 * @param int $length Desired key length in bytes.
	 */
	private static function derive_key( int $length ): string {
		// Combine multiple salts so rotating any of them invalidates old data.
		$material = '';
		foreach ( array( 'auth', 'secure_auth', 'logged_in', 'nonce' ) as $scheme ) {
			$material .= wp_salt( $scheme );
		}
		$material .= 'abcmd-secret-context-v1';

		// HKDF-like derivation using hash_hkdf when available.
		if ( function_exists( 'hash_hkdf' ) ) {
			return hash_hkdf( 'sha256', $material, $length, 'abcmd', '' );
		}

		// Fallback: sha256 stretch.
		$hash = hash( 'sha256', $material, true );
		while ( strlen( $hash ) < $length ) {
			$hash .= hash( 'sha256', $hash, true );
		}
		return substr( $hash, 0, $length );
	}

	private static function has_sodium(): bool {
		return function_exists( 'sodium_crypto_secretbox' )
			&& defined( 'SODIUM_CRYPTO_SECRETBOX_KEYBYTES' );
	}

	private static function has_openssl(): bool {
		return function_exists( 'openssl_encrypt' )
			&& in_array( 'aes-256-gcm', array_map( 'strtolower', openssl_get_cipher_methods() ), true );
	}
}
