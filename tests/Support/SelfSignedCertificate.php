<?php

namespace Tests\Support;

use RuntimeException;

/**
 * Generates throwaway self-signed certificates for parser tests.
 */
final class SelfSignedCertificate
{
    public static function pem(string $commonName = 'shop.example.com', int $validDays = 30): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = $key === false ? false : openssl_csr_new(['commonName' => $commonName, 'organizationName' => 'Example Org'], $key);
        $certificate = $csr === false || $key === false ? false : openssl_csr_sign($csr, null, $key, $validDays);

        if ($certificate === false || ! openssl_x509_export($certificate, $pem)) {
            throw new RuntimeException('Could not generate a test certificate.');
        }

        return $pem;
    }
}
