<?php

use App\Domain\Monitoring\DTOs\ExpectedStatuses;

test('expressions are parsed into sorted ranges', function () {
    $statuses = ExpectedStatuses::parse(' 301, 200-299 ,404 ');

    expect($statuses->ranges)->toBe([
        ['from' => 200, 'to' => 299],
        ['from' => 301, 'to' => 301],
        ['from' => 404, 'to' => 404],
    ])->and($statuses->toExpression())->toBe('200-299, 301, 404');
});

test('membership is checked against every range', function () {
    $statuses = ExpectedStatuses::parse('200-299, 301');

    expect($statuses->contains(204))->toBeTrue()
        ->and($statuses->contains(301))->toBeTrue()
        ->and($statuses->contains(302))->toBeFalse()
        ->and($statuses->contains(500))->toBeFalse();
});

test('invalid expressions are rejected', function (string $expression) {
    ExpectedStatuses::parse($expression);
})->throws(InvalidArgumentException::class)->with([
    'empty' => [''],
    'text' => ['ok'],
    'two digits' => ['20'],
    'out of range' => ['600'],
    'below range' => ['099'],
    'reversed' => ['299-200'],
    'too many' => [implode(',', range(200, 221))],
]);

test('the default accepts 2xx', function () {
    expect(ExpectedStatuses::default()->toExpression())->toBe('200-299');
});
