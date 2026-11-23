<?php

namespace App\Domain\System\Site;

use InvalidArgumentException;

/**
 * The public roadmap (ADR-049): upcoming milestones written for visitors in
 * `resources/site/roadmap.php`, with English source strings translated per
 * request like the rest of the interface. The internal sprint plan with its
 * acceptance criteria stays in `docs/ROADMAP.md`.
 */
class Roadmap
{
    public function __construct(private readonly ?string $path = null) {}

    /**
     * Milestones in the order of work, translated in the current language.
     *
     * @return list<array{id: string, version: string, status: string, statusLabel: string, title: string, summary: string, highlights: list<string>}>
     */
    public function milestones(): array
    {
        return array_map(fn (array $milestone): array => [
            'id' => $milestone['id'],
            'version' => $milestone['version'],
            'status' => $milestone['status']->value,
            'statusLabel' => $milestone['status']->label(),
            'title' => (string) __($milestone['title']),
            'summary' => (string) __($milestone['summary']),
            'highlights' => array_map(fn (string $highlight): string => (string) __($highlight), $milestone['highlights']),
        ], $this->entries());
    }

    /**
     * The milestones as written in the data file, validated, untranslated.
     *
     * @return list<array{id: string, version: string, status: RoadmapStatus, title: string, summary: string, highlights: list<string>}>
     */
    public function entries(): array
    {
        $entries = require $this->path ?? resource_path('site/roadmap.php');

        if (! is_array($entries) || ! array_is_list($entries)) {
            throw new InvalidArgumentException('The roadmap must be a list of milestones.');
        }

        $ids = [];

        return array_map(function (mixed $entry) use (&$ids): array {
            if (! is_array($entry)) {
                throw new InvalidArgumentException('Each roadmap milestone must be an array.');
            }

            foreach (['id', 'version', 'title', 'summary'] as $key) {
                if (! is_string($entry[$key] ?? null) || $entry[$key] === '') {
                    throw new InvalidArgumentException("A roadmap milestone has no [{$key}].");
                }
            }

            if (preg_match('/^[a-z0-9-]+$/', $entry['id']) !== 1 || in_array($entry['id'], $ids, true)) {
                throw new InvalidArgumentException("The roadmap milestone id [{$entry['id']}] is invalid or repeated.");
            }

            $status = RoadmapStatus::tryFrom(is_string($entry['status'] ?? null) ? $entry['status'] : '');
            $highlights = $entry['highlights'] ?? [];

            if ($status === null) {
                throw new InvalidArgumentException("The roadmap milestone [{$entry['id']}] has an unknown status.");
            }

            if (! is_array($highlights) || ! array_is_list($highlights) || array_filter($highlights, fn (mixed $highlight): bool => ! is_string($highlight) || $highlight === '') !== []) {
                throw new InvalidArgumentException("The highlights of the roadmap milestone [{$entry['id']}] must be a list of strings.");
            }

            $ids[] = $entry['id'];

            /** @var list<string> $highlights */
            return [
                'id' => $entry['id'],
                'version' => $entry['version'],
                'status' => $status,
                'title' => $entry['title'],
                'summary' => $entry['summary'],
                'highlights' => $highlights,
            ];
        }, $entries);
    }
}
