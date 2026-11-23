<?php

namespace App\Domain\Monitoring\Actions;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\Tag;
use App\Domain\Organizations\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Replaces the tags of a monitor (ADR-047). Missing tags are created under
 * the organization row lock, so concurrent saves cannot exceed the
 * organization cap or race on the unique (organization_id, name) key; tags
 * no monitor carries any more are removed.
 */
class SyncMonitorTags
{
    /**
     * @param  list<string>  $names  normalized names (SaveMonitorRequest)
     *
     * @throws ValidationException when the organization would exceed its tag limit
     */
    public function handle(Monitor $monitor, array $names): void
    {
        DB::transaction(function () use ($monitor, $names): void {
            Organization::query()->whereKey($monitor->organization_id)->lockForUpdate()->value('id');

            $names = array_values(array_unique(array_map(Tag::normalize(...), $names)));
            $existing = Tag::query()
                ->where('organization_id', $monitor->organization_id)
                ->whereIn('name', $names === [] ? [''] : $names)
                ->pluck('id', 'name');

            $missing = array_values(array_diff($names, $existing->keys()->all()));

            if ($missing !== []) {
                $count = Tag::query()->where('organization_id', $monitor->organization_id)->count();

                if ($count + count($missing) > Tag::MAX_PER_ORGANIZATION) {
                    throw ValidationException::withMessages([
                        'tags' => __('An organization can have at most :count tags.', ['count' => Tag::MAX_PER_ORGANIZATION]),
                    ]);
                }

                foreach ($missing as $name) {
                    $existing[$name] = Tag::query()->create(['organization_id' => $monitor->organization_id, 'name' => $name])->id;
                }
            }

            $monitor->tags()->sync($existing->values()->all());

            self::pruneOrphans($monitor->organization_id);
        });
    }

    /**
     * Tags carried by no live monitor (deleted monitors do not count).
     */
    public static function pruneOrphans(string $organizationId): void
    {
        Tag::query()
            ->where('organization_id', $organizationId)
            ->whereDoesntHave('monitors')
            ->delete();
    }
}
