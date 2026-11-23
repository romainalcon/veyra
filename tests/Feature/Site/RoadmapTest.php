<?php

use App\Domain\System\Site\Roadmap;
use App\Domain\System\Site\RoadmapStatus;

function roadmapFile(mixed $entries): string
{
    $path = tempnam(sys_get_temp_dir(), 'roadmap').'.php';
    file_put_contents($path, '<?php return '.var_export($entries, true).';');

    return $path;
}

function milestone(array $overrides = []): array
{
    return [
        'id' => 'check-diagnostics',
        'version' => '1.10',
        'status' => 'planned',
        'title' => 'Check diagnostics',
        'summary' => 'See where the time goes in every HTTP check, and what the server answered when an outage started.',
        'highlights' => ['Latency chart by phase on the monitor page.'],
        ...$overrides,
    ];
}

test('milestones are translated in the current language', function () {
    app()->setLocale('fr');

    expect((new Roadmap(roadmapFile([milestone()])))->milestones())->toBe([[
        'id' => 'check-diagnostics',
        'version' => '1.10',
        'status' => 'planned',
        'statusLabel' => 'Prévu',
        'title' => 'Diagnostic des vérifications',
        'summary' => 'Voyez où passe le temps de chaque vérification HTTP, et ce que le serveur a répondu au début d’une panne.',
        'highlights' => ['Graphique de latence par phase sur la page du moniteur.'],
    ]]);
});

test('invalid milestones are refused', function (array $entry) {
    expect(fn () => (new Roadmap(roadmapFile([$entry])))->entries())->toThrow(InvalidArgumentException::class);
})->with([
    'unknown status' => [milestone(['status' => 'shipped'])],
    'missing title' => [milestone(['title' => ''])],
    'invalid id' => [milestone(['id' => 'Check Diagnostics'])],
    'highlights not a list of strings' => [milestone(['highlights' => [1]])],
]);

test('repeated ids are refused', function () {
    expect(fn () => (new Roadmap(roadmapFile([milestone(), milestone()])))->entries())->toThrow(InvalidArgumentException::class);
});

test('the shipped roadmap is valid and fully translated into French', function () {
    $dictionary = json_decode((string) file_get_contents(lang_path('fr.json')), true, flags: JSON_THROW_ON_ERROR);
    $entries = app(Roadmap::class)->entries();
    $strings = [...array_map(fn (RoadmapStatus $status): string => $status->label(), RoadmapStatus::cases())];

    foreach ($entries as $entry) {
        $strings = [...$strings, $entry['title'], $entry['summary'], ...$entry['highlights']];
    }

    expect($entries)->not->toBeEmpty()
        ->and(array_values(array_filter($strings, fn (string $string): bool => ! array_key_exists($string, $dictionary))))->toBe([]);
});
