<?php

use App\Domain\Entitlements\Enums\Feature;
use App\Domain\Incidents\Enums\IncidentEventType;
use App\Domain\Monitoring\Enums\HeartbeatPeriod;
use App\Domain\Monitoring\Enums\MonitorInterval;
use App\Domain\Monitoring\Enums\MonitorStatus;
use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Monitoring\Enums\ProbeErrorCode;
use App\Domain\Notifications\Enums\ChannelType;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Organizations\Enums\OrganizationRole;
use Symfony\Component\Finder\Finder;

/**
 * @return array<string, string>
 */
function frenchDictionary(): array
{
    return json_decode((string) file_get_contents(lang_path('fr.json')), true, flags: JSON_THROW_ON_ERROR);
}

test('every literal translation key used by the backend has a French translation', function () {
    $dictionary = frenchDictionary();
    $missing = [];

    $files = Finder::create()->files()->name('*.php')->in([app_path(), resource_path('views')]);

    foreach ($files as $file) {
        preg_match_all("/(?:__|trans_choice|Lang::get)\\(\\s*'((?:\\\\'|[^'])+)'/", $file->getContents(), $matches);

        foreach ($matches[1] as $key) {
            $key = str_replace("\\'", "'", $key);

            // Group keys ("auth.failed") live in lang/fr/*.php.
            if (preg_match('/^[a-z_]+\.[a-z_.]+$/', $key) === 1) {
                expect(trans($key, [], 'fr'))->not->toBe($key, "{$key} is missing from lang/fr");

                continue;
            }

            if (! array_key_exists($key, $dictionary)) {
                $missing[] = $file->getRelativePathname().': '.$key;
            }
        }
    }

    expect($missing)->toBe([]);
});

test('every enum label shown in the interface has a French translation', function () {
    $dictionary = frenchDictionary();
    app()->setLocale('en');

    $labels = [
        ...array_map(fn (MonitorType $case): string => $case->label(), MonitorType::cases()),
        ...array_map(fn (MonitorStatus $case): string => $case->label(), MonitorStatus::cases()),
        ...array_map(fn (ProbeErrorCode $case): string => $case->label(), ProbeErrorCode::cases()),
        ...array_map(fn (ChannelType $case): string => $case->label(), ChannelType::cases()),
        ...array_map(fn (IncidentEventType $case): string => $case->label(), IncidentEventType::cases()),
        ...array_map(fn (OrganizationRole $case): string => $case->label(), OrganizationRole::cases()),
        ...array_map(fn (NotificationEvent $case): string => $case->headline(), NotificationEvent::cases()),
        ...array_map(fn (Feature $case): string => $case->limitMessage(), Feature::cases()),
        'Check failed',
    ];

    $missing = array_values(array_filter(array_unique($labels), fn (string $label): bool => ! array_key_exists($label, $dictionary)));

    expect($missing)->toBe([]);
});

test('interval and period labels are translated', function () {
    app()->setLocale('fr');

    expect(HeartbeatPeriod::from(3600)->label())->toBe('1 heure')
        ->and(MonitorInterval::from(300)->label())->toBe('5 min');
});

test('stored incident causes are translated when displayed', function () {
    expect(ProbeErrorCode::translateCause('Unexpected status code (503)', 'fr'))->toBe('Code HTTP inattendu (503)')
        ->and(ProbeErrorCode::translateCause('Timed out', 'fr'))->toBe('Délai dépassé')
        ->and(ProbeErrorCode::translateCause('Something custom', 'fr'))->toBe('Something custom')
        ->and(ProbeErrorCode::translateCause(null, 'fr'))->toBeNull();
});
