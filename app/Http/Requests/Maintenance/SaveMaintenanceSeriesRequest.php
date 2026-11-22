<?php

namespace App\Http\Requests\Maintenance;

use App\Domain\Maintenance\DTOs\MaintenanceSeriesData;
use App\Domain\Maintenance\Enums\MaintenanceFrequency;
use App\Domain\Maintenance\Models\MaintenanceSeries;
use App\Domain\Organizations\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a recurring maintenance series (ADR-041). Dates and the start
 * time are local to the organization time zone. Authorization runs before
 * validation (the controller re-checks the MaintenanceSeriesPolicy).
 */
class SaveMaintenanceSeriesRequest extends FormRequest
{
    public const MAX_SERIES = 50;

    public const MIN_DURATION_MINUTES = 5;

    public function authorize(): bool
    {
        $organization = $this->organization();

        if ($organization === null) {
            return false;
        }

        $series = $this->series();

        return $series === null
            ? $this->user()?->can('create', [MaintenanceSeries::class, $organization]) === true
            : $this->user()?->can('update', [$series, $organization]) === true;
    }

    protected function prepareForValidation(): void
    {
        $description = $this->input('description');
        $endsOn = $this->input('ends_on');

        $this->merge([
            'title' => is_string($this->input('title')) ? trim($this->input('title')) : $this->input('title'),
            'description' => is_string($description) && trim($description) !== '' ? trim($description) : null,
            'ends_on' => is_string($endsOn) && trim($endsOn) !== '' ? trim($endsOn) : null,
            'weekdays' => is_array($this->input('weekdays')) ? array_values($this->input('weekdays')) : [],
            'monitor_ids' => is_array($this->input('monitor_ids')) ? array_values($this->input('monitor_ids')) : [],
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $organization = $this->organization();
        $maxMinutes = (int) config('veyra.maintenance.max_duration_hours') * 60;

        return [
            'title' => ['required', 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'frequency' => ['required', Rule::enum(MaintenanceFrequency::class)],
            'weekdays' => ['exclude_unless:frequency,weekly', 'required', 'array', 'min:1', 'max:7'],
            'weekdays.*' => ['integer', 'between:1,7', 'distinct'],
            'month_day' => ['exclude_unless:frequency,monthly', 'required', 'integer', 'between:1,31'],
            'start_time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', 'min:'.self::MIN_DURATION_MINUTES, 'max:'.$maxMinutes],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'monitor_ids' => ['required', 'array', 'min:1', 'max:'.SaveMaintenanceWindowRequest::MAX_MONITORS],
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
     * @return array<string, string>
     */
    public function messages(): array
    {
        $hours = (int) config('veyra.maintenance.max_duration_hours');

        return [
            'duration_minutes.max' => __('A maintenance window may last at most :hours hours.', ['hours' => $hours]),
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $organization = $this->organization();

                if ($organization === null || $this->series() !== null) {
                    return;
                }

                if ($organization->maintenanceSeries()->count() >= self::MAX_SERIES) {
                    $validator->errors()->add('title', __('An organization may have at most :count recurring maintenance series.', ['count' => self::MAX_SERIES]));
                }

                if (! $validator->errors()->has('starts_on')) {
                    $today = CarbonImmutable::now($organization->timezone)->toDateString();

                    if ((string) $this->input('starts_on') < $today) {
                        $validator->errors()->add('starts_on', __('A new series cannot start in the past.'));
                    }
                }
            },
        ];
    }

    public function payload(): MaintenanceSeriesData
    {
        /** @var list<string> $monitorIds */
        $monitorIds = array_values(array_map('strval', (array) $this->validated('monitor_ids')));
        $frequency = MaintenanceFrequency::from((string) $this->validated('frequency'));

        /** @var list<int> $weekdays */
        $weekdays = $frequency === MaintenanceFrequency::Weekly
            ? array_values(array_unique(array_map('intval', (array) $this->validated('weekdays'))))
            : [];
        sort($weekdays);

        $endsOn = $this->validated('ends_on');

        return new MaintenanceSeriesData(
            title: (string) $this->validated('title'),
            description: $this->validated('description'),
            frequency: $frequency,
            weekdays: $weekdays,
            monthDay: $frequency === MaintenanceFrequency::Monthly ? (int) $this->validated('month_day') : null,
            startTime: (string) $this->validated('start_time'),
            durationMinutes: (int) $this->validated('duration_minutes'),
            startsOn: (string) $this->validated('starts_on'),
            endsOn: is_string($endsOn) ? $endsOn : null,
            monitorIds: $monitorIds,
        );
    }

    public function series(): ?MaintenanceSeries
    {
        $series = $this->route('maintenanceSeries');

        return $series instanceof MaintenanceSeries ? $series : null;
    }

    private function organization(): ?Organization
    {
        $organization = $this->route('organization');

        return $organization instanceof Organization ? $organization : null;
    }
}
