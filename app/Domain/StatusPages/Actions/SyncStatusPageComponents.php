<?php

namespace App\Domain\StatusPages\Actions;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\StatusPages\Models\StatusPage;

class SyncStatusPageComponents
{
    /**
     * Replaces the page's components, keeping their order. Monitors of other
     * organizations are ignored (defence in depth: the Form Request validates
     * the same constraint).
     *
     * @param  list<array{monitor_id: string, display_name: string, group_name: string|null}>  $components
     */
    public function handle(StatusPage $statusPage, array $components): void
    {
        $allowed = Monitor::query()
            ->where('organization_id', $statusPage->organization_id)
            ->whereIn('id', array_column($components, 'monitor_id'))
            ->pluck('id')
            ->flip();

        $kept = [];

        foreach ($components as $position => $component) {
            if (! $allowed->has($component['monitor_id']) || isset($kept[$component['monitor_id']])) {
                continue;
            }

            $statusPage->components()->updateOrCreate(
                ['monitor_id' => $component['monitor_id']],
                [
                    'display_name' => $component['display_name'],
                    'group_name' => $component['group_name'],
                    'position' => $position,
                ],
            );

            $kept[$component['monitor_id']] = true;
        }

        $statusPage->components()->whereNotIn('monitor_id', array_keys($kept))->delete();
    }
}
