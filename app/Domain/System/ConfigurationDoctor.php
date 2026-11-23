<?php

namespace App\Domain\System;

use App\Domain\Entitlements\CommunityEntitlements;
use App\Domain\Entitlements\Contracts\Entitlements;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Production readiness checks for `php artisan veyra:doctor`.
 */
class ConfigurationDoctor
{
    public function __construct(
        private readonly HealthCheck $healthCheck,
        private readonly Entitlements $entitlements,
    ) {}

    /**
     * @return list<CheckResult>
     */
    public function run(): array
    {
        $isProduction = app()->isProduction();

        return [
            config('app.key') ? CheckResult::ok('app.key', 'Set') : CheckResult::fail('app.key', 'APP_KEY is missing. Back it up: encrypted headers and channels depend on it.'),
            config('app.debug') && $isProduction ? CheckResult::fail('app.debug', 'APP_DEBUG must be false in production.') : CheckResult::ok('app.debug', config('app.debug') ? 'Enabled (non-production)' : 'Disabled'),
            str_starts_with((string) config('app.url'), 'https://') ? CheckResult::ok('app.url', (string) config('app.url')) : ($isProduction ? CheckResult::fail('app.url', 'APP_URL must use https in production.') : CheckResult::warn('app.url', 'APP_URL is not https.')),
            config('session.secure') || ! $isProduction ? CheckResult::ok('session.secure', config('session.secure') ? 'Secure cookies' : 'Not required outside production') : CheckResult::fail('session.secure', 'Set SESSION_SECURE_COOKIE=true behind HTTPS.'),
            config('database.default') === 'mysql' ? CheckResult::ok('database.driver', 'mysql driver (MariaDB)') : CheckResult::fail('database.driver', 'DB_CONNECTION must be mysql (MariaDB).'),
            $this->healthCheck->database(),
            $this->skipLocked(),
            $this->timezone(),
            $this->healthCheck->redis(),
            config('queue.default') === 'redis' ? CheckResult::ok('queue.driver', 'redis') : CheckResult::fail('queue.driver', 'QUEUE_CONNECTION must be redis (Horizon).'),
            config('cache.default') === 'redis' ? CheckResult::ok('cache.driver', 'redis') : CheckResult::warn('cache.driver', 'CACHE_STORE should be redis (locks for onOneServer).'),
            config('broadcasting.default') === 'reverb' && config('broadcasting.connections.reverb.key') ? CheckResult::ok('reverb', 'Configured') : CheckResult::warn('reverb', 'Reverb is not configured: the UI falls back to polling.'),
            in_array(config('mail.default'), ['log', 'array'], true) ? ($isProduction ? CheckResult::fail('mail', 'MAIL_MAILER is "'.config('mail.default').'": e-mail notifications are not delivered.') : CheckResult::warn('mail', 'Mail is only logged.')) : CheckResult::ok('mail', (string) config('mail.default')),
            config('veyra.source_url') ? CheckResult::ok('source_url', (string) config('veyra.source_url')) : CheckResult::warn('source_url', 'Set VEYRA_SOURCE_URL so users can obtain the source code (AGPL-3.0 §13).'),
            config('veyra.probes.allow_private_networks') ? CheckResult::warn('probes.private_networks', 'Private networks are reachable by probes: restrict who can create monitors.') : CheckResult::ok('probes.private_networks', 'Blocked'),
            $this->entitlementsBinding(),
            $this->metrics(),
            CheckResult::warn('egress', 'Reminder: restrict outbound traffic of Horizon workers at network level (see docs/deployment).'),
        ];
    }

    /**
     * /metrics (ADR-048): enabled without any restriction it serves nothing;
     * a short token or a malformed allow-list entry is a misconfiguration.
     */
    private function metrics(): CheckResult
    {
        if (! config('veyra.metrics.enabled')) {
            return CheckResult::ok('metrics', 'Disabled');
        }

        $token = (string) config('veyra.metrics.token');
        /** @var list<string> $allowedIps */
        $allowedIps = (array) config('veyra.metrics.allowed_ips', []);

        if ($token === '' && $allowedIps === []) {
            return CheckResult::fail('metrics', 'VEYRA_METRICS_ENABLED is set without VEYRA_METRICS_TOKEN or VEYRA_METRICS_ALLOWED_IPS: every scrape is refused.');
        }

        if ($token !== '' && strlen($token) < 32) {
            return CheckResult::fail('metrics', 'VEYRA_METRICS_TOKEN must be at least 32 characters.');
        }

        foreach ($allowedIps as $entry) {
            if (! self::isIpOrCidr($entry)) {
                return CheckResult::fail('metrics', "VEYRA_METRICS_ALLOWED_IPS contains an invalid entry: {$entry}.");
            }
        }

        return CheckResult::ok('metrics', 'Enabled ('.implode(' + ', array_filter([$token !== '' ? 'token' : null, $allowedIps !== [] ? 'IP allow-list' : null])).')');
    }

    private static function isIpOrCidr(string $entry): bool
    {
        [$address, $mask] = array_pad(explode('/', $entry, 2), 2, null);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if ($mask === null) {
            return true;
        }

        $max = str_contains($address, ':') ? 128 : 32;

        return ctype_digit($mask) && (int) $mask <= $max;
    }

    /**
     * A Cloud instance must bind its own entitlements; with the Community ones nothing is limited.
     */
    private function entitlementsBinding(): CheckResult
    {
        $isCommunityBinding = $this->entitlements instanceof CommunityEntitlements;

        return config('veyra.edition') === 'cloud' && $isCommunityBinding
            ? CheckResult::fail('entitlements', 'VEYRA_EDITION is "cloud" but no Cloud entitlements are installed: organizations are unlimited.')
            : CheckResult::ok('entitlements', $isCommunityBinding ? 'Community (unlimited)' : class_basename($this->entitlements));
    }

    private function skipLocked(): CheckResult
    {
        try {
            DB::transaction(fn () => DB::table('monitors')->limit(1)->lock('for update skip locked')->get());
        } catch (Throwable $exception) {
            return CheckResult::fail('database.skip_locked', 'SELECT … FOR UPDATE SKIP LOCKED is not supported ('.class_basename($exception).').');
        }

        return CheckResult::ok('database.skip_locked', 'Supported');
    }

    private function timezone(): CheckResult
    {
        try {
            $offset = (string) DB::selectOne('select TIMEDIFF(NOW(), UTC_TIMESTAMP()) as tz_offset')->tz_offset;
        } catch (Throwable) {
            return CheckResult::warn('database.timezone', 'Unable to read the session time zone.');
        }

        return in_array($offset, ['00:00:00', '-00:00:00'], true)
            ? CheckResult::ok('database.timezone', 'UTC')
            : CheckResult::fail('database.timezone', "Database session time zone is not UTC (offset {$offset}).");
    }
}
