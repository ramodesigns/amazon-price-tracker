<?php
/**
 * Namespace-scoped overrides of native functions used by Encryption.php.
 *
 * PHP resolves an unqualified function call first against the current
 * namespace, then falls back to the global function. Encryption.php calls
 * openssl_encrypt()/base64_decode() unqualified from inside APT\Helpers, so
 * declaring same-named functions here lets tests force their otherwise
 * unreachable failure paths (real OpenSSL/base64 calls don't fail on
 * well-formed input) without touching production code. Toggled via
 * $GLOBALS flags, defaulting to pass-through.
 *
 * @package AmazonPriceTracker\Tests\Unit
 */

namespace APT\Helpers;

$GLOBALS['apt_test_force_openssl_encrypt_failure'] = false;
$GLOBALS['apt_test_force_base64_decode_failure'] = false;

function openssl_encrypt($data, $cipher_algo, $passphrase, $options = 0, $iv = '') {
    if (!empty($GLOBALS['apt_test_force_openssl_encrypt_failure'])) {
        return false;
    }
    return \openssl_encrypt($data, $cipher_algo, $passphrase, $options, $iv);
}

function base64_decode($string, $strict = false) {
    if (!empty($GLOBALS['apt_test_force_base64_decode_failure'])) {
        return false;
    }
    return \base64_decode($string, $strict);
}
