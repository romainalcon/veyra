<?php

namespace App\Http\Requests\Organizations;

use App\Domain\Organizations\Models\Organization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Every member chooses for themself whether they receive the weekly report.
 */
class UpdateWeeklyReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->route('organization');

        return $organization instanceof Organization
            && $this->user()?->can('view', $organization) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'weekly_report' => ['required', 'boolean'],
        ];
    }
}
