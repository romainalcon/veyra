<?php

namespace App\Http\Resources\Api\V1;

use App\Domain\Monitoring\Models\MonitorCheck;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A raw check result (ADR-044). Check metadata other than the redirect count
 * stays internal; a check is a probe result, not the confirmed state.
 *
 * @mixin MonitorCheck
 */
class CheckResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MonitorCheck $check */
        $check = $this->resource;

        return [
            'id' => $check->id,
            'scheduled_for' => $check->scheduled_for->toIso8601String(),
            'checked_at' => $check->checked_at->toIso8601String(),
            'success' => $check->success,
            'slow' => $check->slow,
            'in_maintenance' => $check->in_maintenance,
            'duration_ms' => $check->duration_ms,
            'status_code' => $check->status_code,
            'error_code' => $check->error_code?->value,
            'redirects' => (int) ($check->metadata['redirects'] ?? 0),
        ];
    }
}
