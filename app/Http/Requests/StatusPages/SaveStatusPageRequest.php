<?php

namespace App\Http\Requests\StatusPages;

use App\Domain\Organizations\Models\Organization;
use App\Domain\StatusPages\DTOs\StatusPageData;
use App\Domain\StatusPages\Models\StatusPage;
use App\Domain\StatusPages\Services\StatusPageSnapshot;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Authorization runs before validation so that non-managers get a 403
 * (the controller re-checks the same StatusPagePolicy abilities).
 */
class SaveStatusPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->organization();

        if ($organization === null) {
            return false;
        }

        $statusPage = $this->statusPage();

        return $statusPage === null
            ? $this->user()?->can('create', [StatusPage::class, $organization]) === true
            : $this->user()?->can('update', [$statusPage, $organization]) === true;
    }

    protected function prepareForValidation(): void
    {
        $components = is_array($this->input('components')) ? array_values($this->input('components')) : [];

        $this->merge([
            'title' => is_string($this->input('title')) ? trim($this->input('title')) : $this->input('title'),
            'slug' => is_string($this->input('slug')) ? Str::lower(trim($this->input('slug'))) : $this->input('slug'),
            'description' => is_string($this->input('description')) && trim($this->input('description')) !== '' ? trim($this->input('description')) : null,
            'is_published' => $this->boolean('is_published'),
            'show_uptime' => $this->boolean('show_uptime', true),
            'components' => array_map(fn ($component): mixed => is_array($component) ? [
                'monitor_id' => $component['monitor_id'] ?? null,
                'display_name' => is_string($component['display_name'] ?? null) ? trim($component['display_name']) : null,
                'group_name' => is_string($component['group_name'] ?? null) && trim($component['group_name']) !== '' ? trim($component['group_name']) : null,
            ] : $component, $components),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:2', 'max:120'],
            'slug' => [
                'required',
                'string',
                'min:3',
                'max:60',
                'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/',
                Rule::notIn(StatusPage::RESERVED_SLUGS),
                Rule::unique('status_pages', 'slug')->ignore($this->statusPage()?->id),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_published' => ['boolean'],
            'show_uptime' => ['boolean'],
            'components' => ['array', 'max:'.StatusPageSnapshot::MAX_COMPONENTS],
            'components.*' => ['array:monitor_id,display_name,group_name'],
            'components.*.monitor_id' => [
                'required',
                'string',
                'uuid',
                'distinct',
                Rule::exists('monitors', 'id')
                    ->where('organization_id', $this->organization()?->id)
                    ->whereNull('deleted_at'),
            ],
            'components.*.display_name' => ['required', 'string', 'min:1', 'max:100'],
            'components.*.group_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => __('Use lowercase letters, digits and dashes.'),
            'slug.not_in' => __('This address is reserved.'),
        ];
    }

    public function payload(): StatusPageData
    {
        /** @var list<array{monitor_id: string, display_name: string, group_name: string|null}> $components */
        $components = array_values(array_map(fn (array $component): array => [
            'monitor_id' => (string) $component['monitor_id'],
            'display_name' => (string) $component['display_name'],
            'group_name' => $component['group_name'] ?? null,
        ], (array) $this->validated('components', [])));

        return new StatusPageData(
            title: (string) $this->validated('title'),
            slug: (string) $this->validated('slug'),
            description: $this->validated('description'),
            isPublished: (bool) $this->validated('is_published'),
            showUptime: (bool) $this->validated('show_uptime'),
            components: $components,
        );
    }

    public function statusPage(): ?StatusPage
    {
        $statusPage = $this->route('statusPage');

        return $statusPage instanceof StatusPage ? $statusPage : null;
    }

    private function organization(): ?Organization
    {
        $organization = $this->route('organization');

        return $organization instanceof Organization ? $organization : null;
    }
}
