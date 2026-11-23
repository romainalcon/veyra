<?php

namespace App\Domain\System\Site;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Released versions of `CHANGELOG.md` (Keep a Changelog), for the public
 * changelog page (ADR-049). The file ships with the application, so the page
 * always describes the code that serves it. Unreleased changes and link
 * reference definitions are left out; raw HTML and unsafe links are stripped,
 * since the result is inserted as HTML. Parsed once per file content.
 */
class Changelog
{
    private const RELEASE_HEADING = '/^## \[(\d+\.\d+\.\d+)\](?:\s+[—–-]\s+(\d{4}-\d{2}-\d{2}))?\s*$/u';

    private const SECTION_HEADING = '/^## /';

    private const LINK_REFERENCE = '/^\[[^\]]+\]:\s+\S+/';

    public function __construct(private readonly ?string $path = null) {}

    /**
     * Released versions, newest first, as written in the file.
     *
     * @return list<array{version: string, date: string|null, anchor: string, html: string}>
     */
    public function releases(): array
    {
        $path = $this->path ?? base_path('CHANGELOG.md');
        $markdown = is_file($path) ? @file_get_contents($path) : false;

        if ($markdown === false || $markdown === '') {
            return [];
        }

        /** @var list<array{version: string, date: string|null, anchor: string, html: string}> */
        return Cache::remember('site.changelog.'.hash('xxh128', $markdown), now()->addDay(), fn (): array => $this->parse($markdown));
    }

    /**
     * The newest released version, if any.
     *
     * @return array{version: string, date: string|null, anchor: string, html: string}|null
     */
    public function latest(): ?array
    {
        return $this->releases()[0] ?? null;
    }

    /**
     * @return list<array{version: string, date: string|null, anchor: string, html: string}>
     */
    private function parse(string $markdown): array
    {
        $releases = [];
        $current = null;

        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            if (preg_match(self::SECTION_HEADING, $line) === 1) {
                if ($current !== null) {
                    $releases[] = $current;
                }

                $current = preg_match(self::RELEASE_HEADING, $line, $matches) === 1
                    ? ['version' => $matches[1], 'date' => ($matches[2] ?? '') !== '' ? $matches[2] : null, 'lines' => []]
                    : null;

                continue;
            }

            if ($current !== null && preg_match(self::LINK_REFERENCE, $line) !== 1) {
                $current['lines'][] = $line;
            }
        }

        if ($current !== null) {
            $releases[] = $current;
        }

        return array_map(fn (array $release): array => [
            'version' => $release['version'],
            'date' => $release['date'],
            'anchor' => 'v'.str_replace('.', '-', $release['version']),
            'html' => trim((string) Str::markdown(implode("\n", $release['lines']), [
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ])),
        ], $releases);
    }
}
