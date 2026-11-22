<?php

namespace App\Domain\Notifications\Channels;

use App\Domain\Notifications\Channels\Contracts\ChannelSender;
use App\Domain\Notifications\Models\NotificationChannel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * Sends a message through the Telegram Bot API (ADR-039). The host is fixed,
 * so no probe SSRF policy is needed; the bot token is part of the API path,
 * so neither the URL nor exception messages are ever stored or logged.
 * Messages use Telegram's HTML mode and every untrusted value is escaped.
 */
class TelegramSender implements ChannelSender
{
    public const API_HOST = 'https://api.telegram.org';

    public const TIMEOUT_SECONDS = 5;

    public const TOKEN_PATTERN = '/^\d{5,16}:[A-Za-z0-9_-]{30,64}$/';

    /** Numeric chat, group or channel id, or a public `@channelusername`. */
    public const CHAT_ID_PATTERN = '/^(-?\d{1,20}|@[A-Za-z][A-Za-z0-9_]{4,31})$/';

    public const MAX_TEXT_LENGTH = 4096;

    public function __construct(private readonly HttpFactory $http) {}

    public static function isValidToken(mixed $token): bool
    {
        return is_string($token) && preg_match(self::TOKEN_PATTERN, $token) === 1;
    }

    public static function isValidChatId(mixed $chatId): bool
    {
        return is_string($chatId) && preg_match(self::CHAT_ID_PATTERN, $chatId) === 1;
    }

    public function send(NotificationChannel $channel, NotificationMessage $message): SendResult
    {
        $token = $channel->token();
        $chatId = $channel->chatId();

        if (! self::isValidToken($token) || ! self::isValidChatId($chatId)) {
            return SendResult::permanent('Invalid Telegram configuration.');
        }

        try {
            $response = $this->http
                ->timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->withOptions(['allow_redirects' => false, 'proxy' => '', 'protocols' => ['https']])
                ->acceptJson()
                ->post(self::API_HOST."/bot{$token}/sendMessage", $this->payload($message, (string) $chatId));
        } catch (ConnectionException) {
            return SendResult::retryable('Could not reach Telegram.');
        } catch (Throwable $exception) {
            return SendResult::retryable('Unexpected error: '.class_basename($exception));
        }

        $status = $response->status();
        $detail = ResponseClassifier::safeDetail($response->json('description'), [(string) $token]);

        return match (true) {
            $response->successful() && $response->json('ok') === true => SendResult::sent(),
            $status === 429 => SendResult::retryable('Rate limited by Telegram (HTTP 429).', ResponseClassifier::retryAfter($response->json('parameters.retry_after') ?? $response->header('Retry-After'))),
            $status >= 500 => SendResult::retryable("Telegram error (HTTP {$status})."),
            default => SendResult::permanent("Telegram rejected the message (HTTP {$status}{$detail})."),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(NotificationMessage $message, string $chatId): array
    {
        $facts = array_map(
            fn (array $fact): string => '<b>'.self::escape($message->translate(':label:', ['label' => $fact['label']])).'</b> '.self::escape($fact['value']),
            $message->facts(),
        );

        $text = implode("\n", [
            '<b>'.self::escape(mb_substr($message->title(), 0, 300)).'</b>',
            '',
            self::escape(mb_substr($message->summary(), 0, 1000)),
            '',
            ...$facts,
            '',
            '<i>'.self::escape(mb_substr($message->organizationName, 0, 200)).'</i>',
            '<a href="'.self::escape($message->url).'">'.self::escape($message->translate('Open in Veyra')).'</a>',
        ]);

        // Cutting HTML could break a tag or an entity: an oversized message is sent as plain text instead.
        if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            return [
                'chat_id' => $chatId,
                'text' => mb_substr($message->title()."\n\n".$message->plainText()."\n\n".$message->url, 0, self::MAX_TEXT_LENGTH),
                'link_preview_options' => ['is_disabled' => true],
            ];
        }

        return [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
        ];
    }

    /**
     * Telegram's HTML mode: `<`, `>`, `&` and quotes must be entities.
     */
    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8');
    }
}
