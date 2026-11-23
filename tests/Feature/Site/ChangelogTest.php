<?php

use App\Domain\System\Site\Changelog;

function changelogFile(string $markdown): string
{
    $path = tempnam(sys_get_temp_dir(), 'changelog');
    file_put_contents($path, $markdown);

    return $path;
}

test('the released versions are read newest first, without unreleased changes or link references', function () {
    $path = changelogFile(<<<'MD'
        # Changelog

        Intro text.

        ## [Unreleased]
        - Not shipped yet.

        ## [1.1.0] — 2026-11-21

        ### Added
        - **Tags** with `code`.

        ### Fixed
        - A bug.

        ## [1.0.0] - 2026-11-20
        - First release.

        [Unreleased]: https://example.com/compare/v1.1.0...HEAD
        [1.1.0]: https://example.com/compare/v1.0.0...v1.1.0
        MD);

    $releases = (new Changelog($path))->releases();

    expect($releases)->toHaveCount(2)
        ->and($releases[0])->toMatchArray(['version' => '1.1.0', 'date' => '2026-11-21', 'anchor' => 'v1-1-0'])
        ->and($releases[0]['html'])->toContain('<h3>Added</h3>', '<strong>Tags</strong>', '<code>code</code>', '<h3>Fixed</h3>')
        ->and($releases[0]['html'])->not->toContain('Not shipped yet', 'example.com', 'Intro text')
        ->and($releases[1])->toMatchArray(['version' => '1.0.0', 'date' => '2026-11-20'])
        ->and($releases[1]['html'])->toBe("<ul>\n<li>First release.</li>\n</ul>");
});

test('raw HTML and unsafe links are stripped from release notes', function () {
    $path = changelogFile(<<<'MD'
        ## [1.0.0] — 2026-11-20
        - <script>alert(1)</script> <img src=x onerror=alert(1)> [click](javascript:alert(1))
        MD);

    $html = (new Changelog($path))->releases()[0]['html'];

    expect($html)->not->toContain('<script', '<img', 'onerror', 'javascript:');
});

test('a release without a date is kept', function () {
    $releases = (new Changelog(changelogFile("## [2.0.0]\n- Big change.\n")))->releases();

    expect($releases[0])->toMatchArray(['version' => '2.0.0', 'date' => null]);
});

test('a missing or empty changelog has no releases', function () {
    expect((new Changelog('/nonexistent/CHANGELOG.md'))->releases())->toBe([])
        ->and((new Changelog(changelogFile('')))->releases())->toBe([])
        ->and((new Changelog(changelogFile('')))->latest())->toBeNull();
});

test('a changed file is parsed again', function () {
    $path = changelogFile("## [1.0.0] — 2026-11-20\n- First.\n");
    $changelog = new Changelog($path);

    expect($changelog->latest()['version'])->toBe('1.0.0');

    file_put_contents($path, "## [1.1.0] — 2026-11-21\n- Second.\n\n## [1.0.0] — 2026-11-20\n- First.\n");

    expect($changelog->latest()['version'])->toBe('1.1.0');
});

test('the shipped changelog parses into dated releases', function () {
    $releases = app(Changelog::class)->releases();

    expect($releases)->not->toBeEmpty();

    foreach ($releases as $release) {
        expect($release['version'])->toMatch('/^\d+\.\d+\.\d+$/')
            ->and($release['date'])->toMatch('/^\d{4}-\d{2}-\d{2}$/')
            ->and($release['html'])->not->toBe('');
    }
});
