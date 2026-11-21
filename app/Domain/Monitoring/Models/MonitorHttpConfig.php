<?php

namespace App\Domain\Monitoring\Models;

use App\Domain\Monitoring\DTOs\ExpectedStatuses;
use App\Domain\Monitoring\Enums\HttpMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $monitor_id
 * @property string $url
 * @property HttpMethod $method
 * @property ExpectedStatuses $expected_statuses
 * @property bool $follow_redirects
 * @property array<string, string>|null $headers_encrypted
 * @property-read Monitor $monitor
 */
#[Fillable(['monitor_id', 'url', 'method', 'expected_statuses', 'follow_redirects', 'headers_encrypted'])]
#[Hidden(['headers_encrypted'])]
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
     * @return BelongsTo<Monitor, $this>
     */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }
}
