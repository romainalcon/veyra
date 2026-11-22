<?php

namespace App\Domain\Monitoring\Models;

use App\Domain\Monitoring\DTOs\ExpectedStatuses;
use App\Domain\Monitoring\Enums\HttpMethod;
use App\Domain\Monitoring\Enums\KeywordMode;
use App\Domain\Monitoring\Enums\RequestContentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Custom headers, the request body and basic auth credentials are secrets:
 * they are stored encrypted and never leave the server (ADR-037).
 *
 * @property string $monitor_id
 * @property string $url
 * @property HttpMethod $method
 * @property ExpectedStatuses $expected_statuses
 * @property bool $follow_redirects
 * @property array<string, string>|null $headers_encrypted
 * @property string|null $body_encrypted
 * @property RequestContentType|null $body_content_type
 * @property array{username: string, password: string}|null $basic_auth_encrypted
 * @property string|null $keyword
 * @property KeywordMode|null $keyword_mode
 * @property bool $keyword_case_sensitive
 * @property int|null $latency_threshold_ms
 * @property-read Monitor $monitor
 */
#[Fillable([
    'monitor_id', 'url', 'method', 'expected_statuses', 'follow_redirects', 'headers_encrypted',
    'body_encrypted', 'body_content_type', 'basic_auth_encrypted',
    'keyword', 'keyword_mode', 'keyword_case_sensitive', 'latency_threshold_ms',
])]
#[Hidden(['headers_encrypted', 'body_encrypted', 'basic_auth_encrypted'])]
class MonitorHttpConfig extends Model
{
    protected $primaryKey = 'monitor_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => HttpMethod::class,
            'expected_statuses' => ExpectedStatuses::class,
            'follow_redirects' => 'boolean',
            'headers_encrypted' => 'encrypted:array',
            'body_encrypted' => 'encrypted',
            'body_content_type' => RequestContentType::class,
            'basic_auth_encrypted' => 'encrypted:array',
            'keyword_mode' => KeywordMode::class,
            'keyword_case_sensitive' => 'boolean',
            'latency_threshold_ms' => 'integer',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers_encrypted ?? [];
    }

    /**
     * Header names only; values are secrets and never leave the server.
     *
     * @return list<string>
     */
    public function headerNames(): array
    {
        return array_keys($this->headers());
    }

    /**
     * Headers sent to the monitored host: custom headers plus basic auth.
     * Like custom headers, they are never forwarded to another host.
     *
     * @return array<string, string>
     */
    public function requestHeaders(): array
    {
        $headers = $this->headers();
        $auth = $this->basic_auth_encrypted;

        if ($auth !== null) {
            $headers['Authorization'] = 'Basic '.base64_encode($auth['username'].':'.$auth['password']);
        }

        return $headers;
    }

    public function basicAuthUsername(): ?string
    {
        return $this->basic_auth_encrypted['username'] ?? null;
    }

    public function hasBody(): bool
    {
        return $this->method->allowsBody() && $this->body_encrypted !== null;
    }

    /**
     * Size of the stored body in bytes (the content itself is never shown).
     */
    public function bodyBytes(): ?int
    {
        return $this->hasBody() ? strlen((string) $this->body_encrypted) : null;
    }

    /**
     * @return BelongsTo<Monitor, $this>
     */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }
}
