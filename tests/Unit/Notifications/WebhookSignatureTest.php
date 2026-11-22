<?php

use App\Domain\Notifications\Channels\WebhookSignature;

test('signatures match the Standard Webhooks reference vector', function () {
    expect(WebhookSignature::sign(
        'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw',
        'msg_p5jXN8AQM9LWM0D4loKWxJek',
        1614265330,
        '{"test": 2432232314}',
    ))->toBe('v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=');
});

test('generated secrets are random, prefixed and valid', function () {
    $first = WebhookSignature::generateSecret();

    expect($first)->toStartWith('whsec_')
        ->and(strlen((string) base64_decode(substr($first, 6), true)))->toBe(32)
        ->and(WebhookSignature::isValidSecret($first))->toBeTrue()
        ->and(WebhookSignature::generateSecret())->not->toBe($first);
});

test('malformed secrets are refused', function (mixed $secret) {
    expect(WebhookSignature::isValidSecret($secret))->toBeFalse();
})->with([
    'null' => [null],
    'no prefix' => [base64_encode(random_bytes(32))],
    'not base64' => ['whsec_***not-base64***'],
    'too short' => ['whsec_'.base64_encode('short')],
]);
