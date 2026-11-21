<?php

namespace App\Http\Requests\Monitors;

use App\Domain\Monitoring\DTOs\ExpectedStatuses;
use App\Domain\Monitoring\DTOs\MonitorData;
use App\Domain\Monitoring\Enums\HttpMethod;
use App\Domain\Monitoring\Enums\MonitorInterval;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Probes\UrlPolicy;
use App\Rules\AllowedMonitorDestination;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * Validates monitor creation and updates. Authorization is enforced in the
 * controller through MonitorPolicy.
 */
class SaveMonitorRequest extends FormRequest
{
    public const MAX_HEADERS = 10;

    public const MAX_HEADER_VALUE_LENGTH = 1024;

    /**
     * Headers controlled by the probe or hop-by-hop headers.
     */
    public const FORBIDDEN_HEADERS = [
        'host', 'content-length', 'transfer-encoding', 'connection', 'upgrade',
        'te', 'trailer', 'keep-alive', 'expect', 'user-agent',
    ];

    protected function prepareForValidation(): void
    {
        $this->merge([
            'url' => is_string($this->input('url')) ? trim($this->input('url')) : $this->input('url'),
            'follow_redirects' => $this->boolean('follow_redirects'),
            'headers' => array_values(array_filter(
                (array) $this->input('headers', []),
                fn (mixed $header): bool => is_array($header) && trim((string) ($header['name'] ?? '')) !== '',
            )),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'url' => ['required', 'string', 'max:'.UrlPolicy::MAX_LENGTH, app(AllowedMonitorDestination::class)],
            'method' => ['required', Rule::enum(HttpMethod::class)],
            'interval' => ['required', 'integer', Rule::enum(MonitorInterval::class)],
            'timeout' => ['required', 'integer', 'min:1', 'max:30'],
            'expected_statuses' => ['required', 'string', 'max:200'],
            'follow_redirects' => ['boolean'],
            'headers_mode' => [$this->isUpdate() ? 'required' : 'nullable', Rule::in(['keep', 'replace'])],
            'headers' => ['array', 'max:'.self::MAX_HEADERS],
            'headers.*.name' => ['required', 'string', 'max:100', 'regex:/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/'],
            'headers.*.value' => ['required', 'string', 'max:'.self::MAX_HEADER_VALUE_LENGTH, 'regex:/^[^\r\n\x00]*$/'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                try {
                    ExpectedStatuses::parse((string) $this->input('expected_statuses'));
                } catch (InvalidArgumentException $exception) {
                    $validator->errors()->add('expected_statuses', $exception->getMessage());
                }

                if ((int) $this->input('timeout') >= (int) $this->input('interval')) {
                    $validator->errors()->add('timeout', 'The timeout must be shorter than the interval.');
                }

                $seen = [];

                foreach ((array) $this->input('headers', []) as $index => $header) {
                    $name = strtolower(trim((string) ($header['name'] ?? '')));

                    if (in_array($name, self::FORBIDDEN_HEADERS, true) || str_starts_with($name, 'proxy-')) {
                        $validator->errors()->add("headers.{$index}.name", "The {$name} header cannot be set.");
                    }

                    if (isset($seen[$name])) {
                        $validator->errors()->add("headers.{$index}.name", 'Each header may only be set once.');
                    }

                    $seen[$name] = true;
                }
            },
        ];
    }

    public function attributes(): array
    {
        return [
            'headers.*.name' => 'header name',
            'headers.*.value' => 'header value',
        ];
    }

    public function toData(): MonitorData
    {
        $headers = null;

        if (! $this->isUpdate() || $this->input('headers_mode') === 'replace') {
            $headers = [];

            /** @var array<int, array{name: string, value: string}> $validatedHeaders */
            $validatedHeaders = $this->validated('headers', []);

            foreach ($validatedHeaders as $header) {
                $headers[trim($header['name'])] = $header['value'];
            }
        }

        return new MonitorData(
            name: trim((string) $this->validated('name')),
            url: (string) $this->validated('url'),
            method: HttpMethod::from((string) $this->validated('method')),
            interval: MonitorInterval::from((int) $this->validated('interval')),
            timeoutSeconds: (int) $this->validated('timeout'),
            expectedStatuses: ExpectedStatuses::parse((string) $this->validated('expected_statuses')),
            followRedirects: (bool) $this->validated('follow_redirects'),
            headers: $headers,
        );
    }

    private function isUpdate(): bool
    {
        return $this->route('monitor') instanceof Monitor;
    }
}
