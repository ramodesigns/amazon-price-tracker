<?php
/**
 * Encryption Helper Tests
 *
 * @package AmazonPriceTracker\Tests\Unit
 */

use APT\Helpers\Encryption;

require_once __DIR__ . '/encryption-function-overrides.php';

/**
 * Test case for the Encryption helper class.
 */
class Test_Encryption extends WP_UnitTestCase {

    /**
     * Reset forced-failure toggles so they never leak into other tests.
     */
    public function tearDown(): void {
        $GLOBALS['apt_test_force_openssl_encrypt_failure'] = false;
        $GLOBALS['apt_test_force_base64_decode_failure'] = false;
        parent::tearDown();
    }

    /**
     * Test that encrypting and then decrypting returns the original value.
     */
    public function test_encrypt_decrypt_round_trip() {
        $original = 'AKIAIOSFODNN7EXAMPLE';

        $encrypted = Encryption::encrypt($original);
        $decrypted = Encryption::decrypt($encrypted);

        $this->assertNotSame($original, $encrypted);
        $this->assertSame($original, $decrypted);
    }

    /**
     * Test round-tripping a value containing multi-byte characters.
     */
    public function test_encrypt_decrypt_round_trip_with_unicode() {
        $original = 'Sëcret Kéy 秘密鍵';

        $encrypted = Encryption::encrypt($original);
        $decrypted = Encryption::decrypt($encrypted);

        $this->assertSame($original, $decrypted);
    }

    /**
     * Test that encrypting an empty string returns an empty string.
     */
    public function test_encrypt_empty_string() {
        $this->assertSame('', Encryption::encrypt(''));
    }

    /**
     * Test that decrypting an empty string returns an empty string.
     */
    public function test_decrypt_empty_string() {
        $this->assertSame('', Encryption::decrypt(''));
    }

    /**
     * Test that encrypting the same value twice produces different ciphertext
     * (due to a random IV per call) but both still decrypt correctly.
     */
    public function test_encrypt_uses_random_iv() {
        $original = 'my-secret-key';

        $encrypted_a = Encryption::encrypt($original);
        $encrypted_b = Encryption::encrypt($original);

        $this->assertNotSame($encrypted_a, $encrypted_b);
        $this->assertSame($original, Encryption::decrypt($encrypted_a));
        $this->assertSame($original, Encryption::decrypt($encrypted_b));
    }

    /**
     * Test that decrypting malformed (but well-formed base64) data fails
     * gracefully and returns an empty string rather than erroring.
     */
    public function test_decrypt_invalid_data_returns_empty_string() {
        $garbage = base64_encode(str_repeat('X', 32));

        $this->assertSame('', Encryption::decrypt($garbage));
    }

    /**
     * Test masking with the default number of visible characters.
     */
    public function test_mask_default_visible_chars() {
        $masked = Encryption::mask('ABCDEFGHIJKLMNOP');

        $this->assertSame('ABCD********MNOP', $masked);
    }

    /**
     * Test masking a string just past the fully-redacted threshold (2x
     * visible_chars) is partially, not fully, redacted. Distinguishes the
     * `visible_chars * 2` threshold from off-by-one/factor mutations.
     */
    public function test_mask_just_above_threshold_is_partially_redacted() {
        $masked = Encryption::mask('ABCDEFGHIJ', 4);

        $this->assertSame('ABCD**GHIJ', $masked);
    }

    /**
     * Test masking with a custom number of visible characters.
     */
    public function test_mask_custom_visible_chars() {
        $masked = Encryption::mask('ABCDEFGHIJKLMNOP', 2);

        $this->assertSame('AB************OP', $masked);
    }

    /**
     * Test masking a string shorter than twice the visible character count
     * fully redacts the value.
     */
    public function test_mask_short_string_fully_redacted() {
        $masked = Encryption::mask('AB');

        $this->assertSame('**', $masked);
    }

    /**
     * Test masking a string exactly at the visible-chars boundary fully
     * redacts the value.
     */
    public function test_mask_boundary_length_fully_redacted() {
        $masked = Encryption::mask('ABCDEF', 3);

        $this->assertSame('******', $masked);
    }

    /**
     * Test that encrypt() returns an empty string when openssl_encrypt()
     * itself fails, instead of propagating a falsy value.
     */
    public function test_encrypt_returns_empty_string_when_openssl_encrypt_fails() {
        $GLOBALS['apt_test_force_openssl_encrypt_failure'] = true;

        $this->assertSame('', Encryption::encrypt('some-secret'));
    }

    /**
     * Test that decrypt() returns an empty string when base64_decode()
     * itself fails, instead of propagating a falsy value.
     */
    public function test_decrypt_returns_empty_string_when_base64_decode_fails() {
        $GLOBALS['apt_test_force_base64_decode_failure'] = true;

        $this->assertSame('', Encryption::decrypt('anything'));
    }

    /**
     * Test that get_key() derives its key from AUTH_KEY exactly as
     * documented (sha256 of AUTH_KEY concatenated with a fixed suffix, in
     * that order), rather than merely returning *some* 32-byte string.
     * The public encrypt/decrypt round trip alone can't tell these apart,
     * since it stays internally consistent regardless of how the key is
     * derived - this reaches into the private method via reflection to
     * pin down the actual derivation.
     */
    public function test_get_key_derives_from_auth_key() {
        $this->assertTrue(defined('AUTH_KEY') && AUTH_KEY, 'Test assumes AUTH_KEY is defined and truthy in the WP test environment.');

        $method = new \ReflectionMethod(Encryption::class, 'get_key');

        $expected = hash('sha256', AUTH_KEY . 'apt_encryption', true);

        $this->assertSame($expected, $method->invoke(null));
    }
}
