<?php

namespace App\Domain\Monitoring\Certificates;

use App\Domain\Monitoring\DTOs\TlsCertificate;
use Carbon\CarbonImmutable;

/**
 * Reads the leaf certificate from cURL's CURLINFO_CERTINFO output (enabled
 * with CURLOPT_CERTINFO). Returns null whenever anything is missing or
 * malformed: certificate tracking must never fail a check.
 */
final class CertificateParser
{
    public static function fromCertinfo(mixed $certinfo): ?TlsCertificate
    {
        if (! is_array($certinfo) || ! isset($certinfo[0]) || ! is_array($certinfo[0])) {
            return null;
        }

        $pem = $certinfo[0]['Cert'] ?? null;

        return is_string($pem) ? self::fromPem($pem) : null;
    }

    public static function fromPem(string $pem): ?TlsCertificate
    {
        $parsed = @openssl_x509_parse($pem);
        $fingerprint = @openssl_x509_fingerprint($pem, 'sha256');

        if (! is_array($parsed) || ! is_string($fingerprint)
            || ! isset($parsed['validFrom_time_t'], $parsed['validTo_time_t'])) {
            return null;
        }

        return new TlsCertificate(
            fingerprint: strtolower($fingerprint),
            subject: self::name($parsed['subject'] ?? null, ['CN', 'O']),
            issuer: self::name($parsed['issuer'] ?? null, ['O', 'CN']),
            notBefore: CarbonImmutable::createFromTimestampUTC((int) $parsed['validFrom_time_t']),
            notAfter: CarbonImmutable::createFromTimestampUTC((int) $parsed['validTo_time_t']),
        );
    }

    /**
     * @param  list<string>  $preferredKeys
     */
    private static function name(mixed $name, array $preferredKeys): ?string
    {
        if (! is_array($name)) {
            return null;
        }

        foreach ($preferredKeys as $key) {
            $value = $name[$key] ?? null;
            $value = is_array($value) ? ($value[0] ?? null) : $value;

            if (is_string($value) && $value !== '') {
                return mb_substr($value, 0, 255);
            }
        }

        return null;
    }
}
