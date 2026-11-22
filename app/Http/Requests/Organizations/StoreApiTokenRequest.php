<?php

namespace App\Http\Requests\Organizations;

use App\Domain\Organizations\Enums\ApiTokenAbility;
use App\Domain\Organizations\Models\ApiToken;
use App\Domain\Organizations\Models\Organization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApiTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageApiTokens', $this->organization()) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'expires_in_days' => in_array($this->input('expires_in_days'), ['', 'never'], true) ? null : $this->input('expires_in_days'),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100', 'regex:/^[^\x00-\x1F\x7F]*$/'],
            'ability' => ['required', Rule::enum(ApiTokenAbility::class)],
            'expires_in_days' => ['nullable', 'integer', Rule::in(ApiToken::EXPIRY_DAYS)],
        ];
    }

    public function ability(): ApiTokenAbility
    {
        return ApiTokenAbility::from((string) $this->validated('ability'));
    }

    public function expiresInDays(): ?int
    {
        $days = $this->validated('expires_in_days');

        return $days === null ? null : (int) $days;
    }

    private function organization(): Organization
    {
        /** @var Organization $organization */
        $organization = $this->route('organization');

        return $organization;
    }
}
