<?php

namespace App\Http\Requests\Maintenance;

use App\Domain\Maintenance\DTOs\MaintenanceWindowData;
use App\Domain\Maintenance\Models\MaintenanceWindow;
use App\Domain\Organizations\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Authorization runs before validation so that non-managers get a 403
 * (the controller re-checks the same MaintenanceWindowPolicy abilities).
 * Dates are submitted as ISO-8601 instants and stored in UTC.
 */
class SaveMaintenanceWindowRequest extends FormRequest
{
    public const START_TOLERANCE_MINUTES = 5;

    public const MAX_MONITORS = 100;

    public function authorize(): bool
    {
        $organization = $this->organization();

        if ($organization === null) {
            return false;
        }

        $window = $this->window();

        return $window === null
            ? $this->user()?->can('create', [MaintenanceWindow::class, $organization]) === true
            : $this->user()?->can('update', [$window, $organization]) === true;
    }

    protected function prepareForValidation(): void
    {
        $description = $this->input('description');

        $this->merge([
            'title' => is_string($this->input('title')) ? trim($this->input('title')) : $this->input('title'),
            'description' => is_string($description) && trim($description) !== '' ? trim($description) : null,
            'monitor_ids' => is_array($this->input('monitor_ids')) ? array_values($this->input('monitor_ids')) : [],
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $organization = $this->organization();

        return [
            'title' => ['required', 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'monitor_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_MONITORS],
            'monitor_ids.*' => [
                'string',
                'uuid',
                'distinct',
                Rule::exists('monitors', 'id')
                    ->where('organization_id', $organization?->id)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['starts_at', 'ends_at'])) {
                    return;
                }

                $startsAt = $this->startsAt();
                $endsAt = $this->endsAt();
                $maxHours = (int) config('veyra.maintenance.max_duration_hours');

                if ($startsAt->diffInHours($endsAt, absolute: true) > $maxHours) {
                    $validator->errors()->add('ends_at', __('A maintenance window may last at most :hours hours.', ['hours' => $maxHours]));
                }

                if ($this->window() === null && $startsAt->lessThan(CarbonImmutable::now()->subMinutes(self::START_TOLERANCE_MINUTES))) {
                    $validator->errors()->add('starts_at', __('A new maintenance window cannot start in the past.'));
                }
            },
        ];
    }

    public function payload(): MaintenanceWindowData
    {
        /** @var list<string> $monitorIds */
        $monitorIds = array_values(array_map('strval', (array) $this->validated('monitor_ids')));

        return new MaintenanceWindowData(
            title: (string) $this->validated('title'),
            description: $this->validated('description'),
            startsAt: $this->startsAt(),
            endsAt: $this->endsAt(),
            monitorIds: $monitorIds,
        );
    }

    public function window(): ?MaintenanceWindow
    {
        $window = $this->route('maintenanceWindow');

        return $window instanceof MaintenanceWindow ? $window : null;
    }

    private function organization(): ?Organization
    {
        $organization = $this->route('organization');

        return $organization instanceof Organization ? $organization : null;
    }

    private function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->input('starts_at'))->utc()->startOfSecond();
    }

    private function endsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->input('ends_at'))->utc()->startOfSecond();
    }
}
