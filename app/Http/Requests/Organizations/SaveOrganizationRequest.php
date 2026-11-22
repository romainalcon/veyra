<?php

namespace App\Http\Requests\Organizations;

use App\Domain\Localization\SupportedLocales;
use App\Domain\Organizations\DTOs\OrganizationData;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates organization creation and updates. Authorization for updates is
 * enforced in the controller through the OrganizationPolicy.
 */
class SaveOrganizationRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'locale' => ['nullable', 'string', Rule::in(SupportedLocales::codes())],
            'timezone' => ['nullable', 'string', 'timezone:all'],
        ];
    }

    public function toData(): OrganizationData
    {
        /** @var array{name: string, locale?: string|null, timezone?: string|null} $validated */
        $validated = $this->validated();

        return OrganizationData::fromArray($validated);
    }
}
