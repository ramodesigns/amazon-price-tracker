<?php
/**
 * Encryption Helper
 *
 * Handles encryption/decryption of sensitive data like API keys.
 *
 * @package AmazonPriceTracker
 */

namespace APT\Helpers;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Encryption
 */
class Encryption {

    /**
     * Cipher method
     */
    private const CIPHER_METHOD = 'aes-256-cbc';

    /**
     * Get encryption key
     *
     * Uses WordPress AUTH_KEY if available, otherwise generates a fallback.
     *
     * @return string
     */
    private static function get_key(): string {
        if (defined('AUTH_KEY') && AUTH_KEY) {
            return hash('sha256', AUTH_KEY . 'apt_encryption', true);
        }

        // Fallback key (not as secure, but better than nothing)
        $fallback = get_option('apt_encryption_key');

        if (!$fallback) {
            $fallback = wp_generate_password(64, true, true);
            update_option('apt_encryption_key', $fallback);
        }

        return hash('sha256', $fallback, true);
    }

    /**
     * Encrypt a string
     *
     * @param string $data Data to encrypt
     * @return string Encrypted data (base64 encoded)
     */
    public static function encrypt(string $data): string {
        if (empty($data)) {
            return '';
        }

        $key = self::get_key();
        $iv_length = openssl_cipher_iv_length(self::CIPHER_METHOD);
        $iv = openssl_random_pseudo_bytes($iv_length);

        $encrypted = openssl_encrypt(
            $data,
            self::CIPHER_METHOD,
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($encrypted === false) {
            return '';
        }

        // Prepend IV to encrypted data and base64 encode
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt a string
     *
     * @param string $data Encrypted data (base64 encoded)
     * @return string Decrypted data
     */
    public static function decrypt(string $data): string {
        if (empty($data)) {
            return '';
        }

        $data = base64_decode($data);

        if ($data === false) {
            return '';
        }

        $key = self::get_key();
        $iv_length = openssl_cipher_iv_length(self::CIPHER_METHOD);

        // Extract IV from the beginning of the data
        $iv = substr($data, 0, $iv_length);
        $encrypted = substr($data, $iv_length);

        $decrypted = openssl_decrypt(
            $encrypted,
            self::CIPHER_METHOD,
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        return $decrypted !== false ? $decrypted : '';
    }

    /**
     * Mask a sensitive string for display
     *
     * @param string $data String to mask
     * @param int $visible_chars Number of characters to show at start and end
     * @return string Masked string
     */
    public static function mask(string $data, int $visible_chars = 4): string {
        $length = strlen($data);

        if ($length <= $visible_chars * 2) {
            return str_repeat('*', $length);
        }

        $start = substr($data, 0, $visible_chars);
        $end = substr($data, -$visible_chars);
        $middle_length = $length - ($visible_chars * 2);

        return $start . str_repeat('*', $middle_length) . $end;
    }
}
