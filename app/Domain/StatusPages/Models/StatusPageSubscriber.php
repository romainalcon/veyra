<?php

namespace App\Domain\StatusPages\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A visitor subscribed by e-mail to a status page. The address is encrypted
 * at rest, looked up by an HMAC, and never shown in the interface or logs.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $status_page_id
 * @property string $email
 * @property string $email_hash
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $confirmation_sent_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read StatusPage $statusPage
 */
#[Fillable(['organization_id', 'status_page_id', 'email', 'email_hash', 'confirmed_at', 'confirmation_sent_at'])]
#[Hidden(['email', 'email_hash'])]
class StatusPageSubscriber extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email' => 'encrypted',
            'confirmed_at' => 'datetime',
            'confirmation_sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<StatusPage, $this>
     */
    public function statusPage(): BelongsTo
    {
        return $this->belongsTo(StatusPage::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeConfirmed(Builder $query): void
    {
        $query->whereNotNull('confirmed_at');
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function hashEmail(string $email): string
    {
        return hash_hmac('sha256', 'status-page-subscriber|'.self::normalizeEmail($email), (string) config('app.key'));
    }
}
