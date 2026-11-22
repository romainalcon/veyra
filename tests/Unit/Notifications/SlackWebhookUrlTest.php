<?php

use App\Domain\Notifications\Rules\SlackWebhookUrl;

test('genuine Slack incoming webhook URLs are accepted', function (string $url) {
    expect(SlackWebhookUrl::isValid($url))->toBeTrue();
})->with([
    'https://hooks.slack.com/services/T0123ABCD/B0456EFGH/abcdEFGHijklMNOPqrstUVwx',
    'https://hooks.slack.com:443/services/T0123ABCD/B0456EFGH/abcdEFGHijklMNOPqrstUVwx',
    'https://HOOKS.SLACK.COM/services/T0123ABCDEFG/B0456EFGHIJK/abcdEFGHijklMNOPqrstUVwx1234',
]);

test('anything else is refused', function (mixed $url) {
    expect(SlackWebhookUrl::isValid($url))->toBeFalse();
})->with([
    'plain http' => ['http://hooks.slack.com/services/T0123ABCD/B0456EFGH/abcdEFGHijklMNOPqrstUVwx'],
    'other host' => ['https://example.com/services/T0123ABCD/B0456EFGH/abcdEFGHijklMNOPqrstUVwx'],
    'lookalike host' => ['https://hooks.slack.com.evil.io/services/T0123ABCD/B0456EFGH/abcdEFGHijklMNOPqrstUVwx'],
    'other Slack host' => ['https://slack.com/services/T0123ABCD/B0456EFGH/abcdEFGHijklMNOPqrstUVwx'],
    'internal host' => ['https://169.254.169.254/services/T0123ABCD/B0456EFGH/abcdEFGHijklMNOPqrstUVwx'],
    'credentials' => ['https://user:pass@hooks.slack.com/services/T0123ABCD/B0456EFGH/abcdEFGHijklMNOPqrstUVwx'],
    'custom port' => ['https://hooks.slack.com:8443/services/T0123ABCD/B0456EFGH/abcdEFGHijklMNOPqrstUVwx'],
    'workflow trigger' => ['https://hooks.slack.com/triggers/T0123ABCD/1234567890/abcdEFGHijklMNOPqrstUVwx'],
    'malformed path' => ['https://hooks.slack.com/services/T0123ABCD/abcdEFGHijklMNOPqrstUVwx'],
    'traversal' => ['https://hooks.slack.com/services/T0123ABCD/B0456EFGH/../../api/chat.postMessage'],
    'query string' => ['https://hooks.slack.com/services/T0123ABCD/B0456EFGH/abcdEFGHijklMNOPqrstUVwx?x=1'],
    'fragment' => ['https://hooks.slack.com/services/T0123ABCD/B0456EFGH/abcdEFGHijklMNOPqrstUVwx#x'],
    'too long' => ['https://hooks.slack.com/services/T0123ABCD/B0456EFGH/'.str_repeat('a', 520)],
    'not a string' => [null],
]);
