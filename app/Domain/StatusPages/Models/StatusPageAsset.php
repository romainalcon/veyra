<?php

namespace App\Domain\StatusPages\Models;

use App\Domain\StatusPages\Enums\AssetKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A validated branding image (logo or favicon) stored in the database.
 *
 * @property string $id
 * @property string $status_page_id
 * @property AssetKind $kind
 * @property string $mime
 * @property string $sha256
 * @property int $width
 * @property int $height
 * @property int $size
 * @property string $content
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read StatusPage $statusPage
 */
#[Fillable(['status_page_id', 'kind', 'mime', 'sha256', 'width', 'height', 'size', 'content'])]
#[Hidden(['content'])]
class StatusPageAsset extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => AssetKind::class,
            'width' => 'integer',
            'height' => 'integer',
            'size' => 'integer',
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
     * Public file name: the content hash makes it immutable and cacheable.
     */
    public function fileName(): string
    {
        return $this->kind->value.'-'.substr($this->sha256, 0, 16).'.'.$this->extension();
    }

    public function extension(): string
    {
        return $this->kind->mimeTypes()[$this->mime] ?? 'bin';
    }
}
