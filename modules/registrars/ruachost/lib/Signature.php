<?php

namespace RuachostRegistrar;

/**
 * Request signing scheme shared with the Ruachost reseller API (version RH1).
 *
 *   string_to_sign = "RH1" + "\n" + key_id + "\n" + timestamp + "\n" + request_id
 *                    + "\n" + sha256_hex(raw_body)
 *   signature      = hmac_sha256_hex(string_to_sign, api_secret)
 *
 * Keep in sync with addons/ruachost_reseller/lib/Api/Signature.php.
 */
final class Signature
{
    const VERSION = 'RH1';

    public static function stringToSign(string $keyId, string $timestamp, string $requestId, string $body): string
    {
        return implode("\n", [self::VERSION, $keyId, $timestamp, $requestId, hash('sha256', $body)]);
    }

    public static function sign(string $secret, string $keyId, string $timestamp, string $requestId, string $body): string
    {
        return hash_hmac('sha256', self::stringToSign($keyId, $timestamp, $requestId, $body), $secret);
    }
}
