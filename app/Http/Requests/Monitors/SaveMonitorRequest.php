<?php

namespace App\Http\Requests\Monitors;

use App\Domain\Entitlements\Services\Quota;
use App\Domain\Monitoring\Dns\DnsAnswerMatcher;
use App\Domain\Monitoring\Dns\DnsRecordName;
use App\Domain\Monitoring\DTOs\DnsTargetData;
use App\Domain\Monitoring\DTOs\ExpectedStatuses;
use App\Domain\Monitoring\DTOs\HeartbeatTargetData;
use App\Domain\Monitoring\DTOs\HttpTargetData;
use App\Domain\Monitoring\DTOs\InvalidExpectedStatuses;
use App\Domain\Monitoring\DTOs\MonitorData;
use App\Domain\Monitoring\DTOs\TcpTargetData;
use App\Domain\Monitoring\Enums\DnsMatchMode;
use App\Domain\Monitoring\Enums\DnsRecordType;
use App\Domain\Monitoring\Enums\DnsResolverChoice;
use App\Domain\Monitoring\Enums\HeartbeatPeriod;
use App\Domain\Monitoring\Enums\HttpMethod;
use App\Domain\Monitoring\Enums\KeywordMode;
use App\Domain\Monitoring\Enums\MonitorInterval;
use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Monitoring\Enums\RequestContentType;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Probes\DestinationGuard;
use App\Domain\Monitoring\Probes\DestinationRejected;
use App\Domain\Monitoring\Probes\ProbeSettings;
use App\Domain\Monitoring\Probes\UrlPolicy;
use App\Domain\Organizations\Models\Organization;
use App\Rules\AllowedMonitorDestination;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates monitor creation and updates. Authorization (MonitorPolicy) runs
 * before validation, so unauthorized users never trigger DNS lookups or see
 * validation errors; the controller re-checks it. The type is chosen on creation and is
 * immutable afterwards; only the fields of that type are validated.
 */
class SaveMonitorRequest extends FormRequest
{
    public const MAX_HEADERS = 10;

    public const MAX_HEADER_VALUE_LENGTH = 1024;

    /**
     * Request bodies are small probes, not uploads (bytes, ADR-037).
     */
    public const MAX_BODY_BYTES = 16_384;

    public const MAX_KEYWORD_LENGTH = 255;

    public const MIN_LATENCY_THRESHOLD_MS = 100;

    /**
     * Headers controlled by the probe or hop-by-hop headers.
     */
    public const FORBIDDEN_HEADERS = [
        'host', 'content-length', 'transfer-encoding', 'connection', 'upgrade',
        'te', 'trailer', 'keep-alive', 'expect', 'user-agent',
    ];

    /**
     * @var list<MonitorType>
     */
    public const CREATABLE_TYPES = [MonitorType::Http, MonitorType::Tcp, MonitorType::Dns, MonitorType::Heartbeat];

    public const MAX_DNS_EXPECTED_VALUES = 20;

    public const MAX_GRACE_MINUTES = 1440;

    /**
     * Heartbeats are not probed; this placeholder keeps the column meaningful.
     */
    public const HEARTBEAT_TIMEOUT_SECONDS = 10;

    public function authorize(): bool
    {
        $monitor = $this->route('monitor');

        return $monitor instanceof Monitor
            ? $this->user()?->can('update', [$monitor, $this->organization()]) === true
            : $this->user()?->can('create', [Monitor::class, $this->organization()]) === true;
    }

    protected function prepareForValidation(): void
    {
        $monitor = $this->route('monitor');

        $this->merge([
            'type' => $monitor instanceof Monitor ? $monitor->type->value : ($this->input('type') ?? MonitorType::Http->value),
            'host' => is_string($this->input('host')) ? trim($this->input('host')) : $this->input('host'),
            'url' => is_string($this->input('url')) ? trim($this->input('url')) : $this->input('url'),
            'record_name' => is_string($this->input('record_name')) ? trim($this->input('record_name')) : $this->input('record_name'),
            'follow_redirects' => $this->boolean('follow_redirects'),
            'keyword_case_sensitive' => $this->boolean('keyword_case_sensitive'),
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
        $common = [
            'type' => ['required', Rule::enum(MonitorType::class)->only(self::CREATABLE_TYPES)],
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'notification_channels_submitted' => ['nullable', 'boolean'],
            'notification_channel_ids' => ['array', 'max:50'],
            'notification_channel_ids.*' => [
                'string',
                'distinct',
                Rule::exists('notification_channels', 'id')->where('organization_id', $this->organization()->id),
            ],
        ];

        $probed = [
            'interval' => ['required', 'integer', Rule::enum(MonitorInterval::class)],
            'timeout' => ['required', 'integer', 'min:1', 'max:30'],
        ];

        return match ($this->monitorType()) {
            MonitorType::Heartbeat => [
                ...$common,
                'interval' => ['required', 'integer', Rule::enum(HeartbeatPeriod::class)],
                'grace_minutes' => ['required', 'integer', 'min:1', 'max:'.self::MAX_GRACE_MINUTES],
            ],
            MonitorType::Tcp => [
                ...$common,
                ...$probed,
                'host' => ['required', 'string', 'max:255'],
                'port' => ['required', 'integer', Rule::in(app(ProbeSettings::class)->tcpAllowedPorts)],
            ],
            MonitorType::Dns => [
                ...$common,
                ...$probed,
                'record_name' => ['required', 'string', 'max:253'],
                'record_type' => ['required', Rule::enum(DnsRecordType::class)],
                'resolver' => ['required', Rule::enum(DnsResolverChoice::class)->only(DnsResolverChoice::allowed())],
                'expected_values' => ['nullable', 'string', 'max:'.(self::MAX_DNS_EXPECTED_VALUES * 1100)],
                'match_mode' => ['required', Rule::enum(DnsMatchMode::class)],
            ],
            MonitorType::Http => [
                ...$common,
                ...$probed,
                'url' => ['required', 'string', 'max:'.UrlPolicy::MAX_LENGTH, app(AllowedMonitorDestination::class)],
                'method' => ['required', Rule::enum(HttpMethod::class)],
                'expected_statuses' => ['required', 'string', 'max:200'],
                'follow_redirects' => ['boolean'],
                'headers_mode' => [$this->isUpdate() ? 'required' : 'nullable', Rule::in(['keep', 'replace'])],
                'headers' => ['array', 'max:'.self::MAX_HEADERS],
                'headers.*.name' => ['required', 'string', 'max:100', 'regex:/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/'],
                'headers.*.value' => ['required', 'string', 'max:'.self::MAX_HEADER_VALUE_LENGTH, 'regex:/^[^\r\n\x00]*$/'],
                'body_mode' => ['nullable', Rule::in(['keep', 'replace'])],
                'body' => ['nullable', 'string'],
                'body_content_type' => ['nullable', 'required_with:body', Rule::enum(RequestContentType::class)],
                'auth_mode' => ['nullable', Rule::in(['keep', 'replace'])],
                'basic_auth_username' => ['nullable', 'string', 'max:255', 'regex:/^[^:\x00-\x1F\x7F]*$/'],
                'basic_auth_password' => ['nullable', 'required_with:basic_auth_username', 'string', 'max:255', 'regex:/^[^\x00-\x1F\x7F]*$/'],
                'keyword' => ['nullable', 'string', 'max:'.self::MAX_KEYWORD_LENGTH, 'regex:/^[^\x00-\x1F\x7F]*$/'],
                'keyword_mode' => ['nullable', Rule::enum(KeywordMode::class)],
                'keyword_case_sensitive' => ['boolean'],
                'latency_threshold_ms' => ['nullable', 'integer', 'min:'.self::MIN_LATENCY_THRESHOLD_MS, 'max:30000'],
            ],
        };
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->monitorType() !== MonitorType::Heartbeat && (int) $this->input('timeout') >= (int) $this->input('interval')) {
                    $validator->errors()->add('timeout', __('The timeout must be shorter than the interval.'));
                }

                if ($this->monitorType() !== MonitorType::Heartbeat && ! $validator->errors()->has('interval')) {
                    $this->validateMinimumInterval($validator);
                }

                match ($this->monitorType()) {
                    MonitorType::Tcp => $this->validateTcpEndpoint($validator),
                    MonitorType::Dns => $this->validateDnsQuery($validator),
                    MonitorType::Http => $this->validateHttpTarget($validator),
                    MonitorType::Heartbeat => null,
                };
            },
        ];
    }

    /**
     * Organization minimum for probed monitors (ADR-029). An edited monitor
     * may keep an interval below a lowered minimum, but not move further below it.
     */
    private function validateMinimumInterval(Validator $validator): void
    {
        $minimum = app(Quota::class)->minIntervalSeconds($this->organization());
        $interval = (int) $this->input('interval');
        $monitor = $this->route('monitor');

        if ($minimum === null || $interval >= $minimum) {
            return;
        }

        if ($monitor instanceof Monitor && $interval >= $monitor->interval_seconds) {
            return;
        }

        $validator->errors()->add('interval', __('The interval must be at least :seconds seconds for this organization.', ['seconds' => $minimum]));
    }

    /**
     * Early feedback only: the probe re-validates the endpoint before every
     * check, since DNS answers can change.
     */
    private function validateTcpEndpoint(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['host', 'port'])) {
            return;
        }

        try {
            app(DestinationGuard::class)->inspectEndpoint((string) $this->input('host'), (int) $this->input('port'));
        } catch (DestinationRejected $exception) {
            $validator->errors()->add('host', $exception->translatedMessage());
        }
    }

    /**
     * The name follows the DNS record name policy; each expected value (one
     * per line) must be valid for the record type.
     */
    private function validateDnsQuery(Validator $validator): void
    {
        if (! $validator->errors()->has('record_name')) {
            try {
                DnsRecordName::fromSettings(app(ProbeSettings::class))->normalize((string) $this->input('record_name'));
            } catch (DestinationRejected $exception) {
                $validator->errors()->add('record_name', $exception->translatedMessage());
            }
        }

        $type = DnsRecordType::tryFrom((string) $this->input('record_type'));

        if ($type === null || $validator->errors()->has('expected_values')) {
            return;
        }

        $values = self::expectedLines($this->input('expected_values'));

        if (count($values) > self::MAX_DNS_EXPECTED_VALUES) {
            $validator->errors()->add('expected_values', __('Enter at most :count expected values.', ['count' => self::MAX_DNS_EXPECTED_VALUES]));

            return;
        }

        foreach ($values as $value) {
            if (DnsAnswerMatcher::normalize($type, $value) === null) {
                $validator->errors()->add('expected_values', __('":value" is not a valid :type value.', ['value' => mb_substr($value, 0, 80), 'type' => $type->value]));

                return;
            }
        }
    }

    /**
     * @return list<string> non-empty, trimmed lines
     */
    private static function expectedLines(mixed $input): array
    {
        if (! is_string($input)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/\R/', $input) ?: []), fn (string $line): bool => $line !== ''));
    }

    private function dnsTarget(): DnsTargetData
    {
        $type = DnsRecordType::from((string) $this->validated('record_type'));
        $expected = array_map(
            fn (string $value): string => (string) DnsAnswerMatcher::normalize($type, $value),
            self::expectedLines($this->validated('expected_values')),
        );

        return new DnsTargetData(
            recordName: DnsRecordName::fromSettings(app(ProbeSettings::class))->normalize((string) $this->validated('record_name')),
            recordType: $type,
            resolver: DnsResolverChoice::from((string) $this->validated('resolver')),
            expectedValues: array_values(array_unique($expected)),
            matchMode: DnsMatchMode::from((string) $this->validated('match_mode')),
        );
    }

    private function validateHttpTarget(Validator $validator): void
    {
        try {
            ExpectedStatuses::parse((string) $this->input('expected_statuses'));
        } catch (InvalidExpectedStatuses $exception) {
            $validator->errors()->add('expected_statuses', $exception->translatedMessage());
        }

        $seen = [];

        foreach ((array) $this->input('headers', []) as $index => $header) {
            $name = strtolower(trim((string) ($header['name'] ?? '')));

            if (in_array($name, self::FORBIDDEN_HEADERS, true) || str_starts_with($name, 'proxy-')) {
                $validator->errors()->add("headers.{$index}.name", "The {$name} header cannot be set.");
            }

            if (isset($seen[$name])) {
                $validator->errors()->add("headers.{$index}.name", __('Each header may only be set once.'));
            }

            $seen[$name] = true;
        }

        $this->validateRequestOptions($validator, array_keys($seen));
    }

    /**
     * Body, basic auth, keyword and latency rules that depend on the method,
     * the timeout or the stored secrets (kept values are never re-sent).
     *
     * @param  list<string|int>  $submittedHeaders  lower-cased names of submitted headers
     */
    private function validateRequestOptions(Validator $validator, array $submittedHeaders): void
    {
        $method = HttpMethod::tryFrom((string) $this->input('method'));
        $monitor = $this->route('monitor');
        $config = $monitor instanceof Monitor ? $monitor->httpConfig : null;
        $headerNames = $this->isUpdate() && $this->input('headers_mode') !== 'replace'
            ? array_map('strtolower', $config?->headerNames() ?? [])
            : array_map('strval', $submittedHeaders);

        $replacesBody = ! $this->isUpdate() || $this->input('body_mode') === 'replace';
        $body = $this->input('body');

        if ($method !== null && ! $method->allowsBody() && $replacesBody && $body !== null) {
            $validator->errors()->add('body', __('A request body can only be sent with POST, PUT or PATCH.'));
        }

        if (is_string($body) && strlen($body) > self::MAX_BODY_BYTES) {
            $validator->errors()->add('body', __('The request body may not be larger than :size KiB.', ['size' => self::MAX_BODY_BYTES / 1024]));
        }

        if (is_string($body) && $this->input('body_content_type') === RequestContentType::Json->value && ! json_validate($body)) {
            $validator->errors()->add('body', __('The request body is not valid JSON.'));
        }

        $hasBody = $method?->allowsBody() === true && ($replacesBody ? $body !== null : $config?->body_encrypted !== null);

        if ($hasBody && in_array('content-type', $headerNames, true)) {
            $validator->errors()->add('headers', __('Remove the Content-Type header: it is set from the body content type.'));
        }

        $hasBasicAuth = ! $this->isUpdate() || $this->input('auth_mode') === 'replace'
            ? $this->input('basic_auth_username') !== null
            : $config?->basic_auth_encrypted !== null;

        if ($hasBasicAuth && in_array('authorization', $headerNames, true)) {
            $validator->errors()->add('basic_auth_username', __('Basic authentication cannot be combined with an Authorization header.'));
        }

        if ($method === HttpMethod::Head && $this->input('keyword') !== null) {
            $validator->errors()->add('keyword', __('HEAD responses have no body: use GET to check the content.'));
        }

        $threshold = $this->input('latency_threshold_ms');

        if (is_numeric($threshold) && (int) $threshold >= (int) $this->input('timeout') * 1000) {
            $validator->errors()->add('latency_threshold_ms', __('The latency threshold must be shorter than the timeout.'));
        }
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'headers.*.name' => 'header name',
            'headers.*.value' => 'header value',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'port.in' => __('This port is not allowed for TCP monitors on this instance.'),
        ];
    }

    public function toData(): MonitorData
    {
        $type = $this->monitorType();

        return new MonitorData(
            type: $type,
            name: trim((string) $this->validated('name')),
            intervalSeconds: (int) $this->validated('interval'),
            timeoutSeconds: $type === MonitorType::Heartbeat ? self::HEARTBEAT_TIMEOUT_SECONDS : (int) $this->validated('timeout'),
            http: $type === MonitorType::Http ? $this->httpTarget() : null,
            tcp: $type === MonitorType::Tcp ? new TcpTargetData((string) $this->validated('host'), (int) $this->validated('port')) : null,
            heartbeat: $type === MonitorType::Heartbeat ? new HeartbeatTargetData((int) $this->validated('grace_minutes') * 60) : null,
            dns: $type === MonitorType::Dns ? $this->dnsTarget() : null,
            notificationChannelIds: $this->boolean('notification_channels_submitted')
                ? array_values(array_map('strval', (array) $this->validated('notification_channel_ids', [])))
                : null,
        );
    }

    private function httpTarget(): HttpTargetData
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

        $method = HttpMethod::from((string) $this->validated('method'));
        $body = $this->validated('body');
        $username = $this->validated('basic_auth_username');
        $keyword = $this->validated('keyword');
        $threshold = $this->validated('latency_threshold_ms');

        return new HttpTargetData(
            url: (string) $this->validated('url'),
            method: $method,
            expectedStatuses: ExpectedStatuses::parse((string) $this->validated('expected_statuses')),
            followRedirects: (bool) $this->validated('follow_redirects'),
            headers: $headers,
            body: match (true) {
                ! $method->allowsBody() => false,
                $this->isUpdate() && $this->input('body_mode') !== 'replace' => null,
                $body === null => false,
                default => ['content' => (string) $body, 'content_type' => RequestContentType::from((string) $this->validated('body_content_type'))],
            },
            basicAuth: match (true) {
                $this->isUpdate() && $this->input('auth_mode') !== 'replace' => null,
                $username === null => false,
                default => ['username' => (string) $username, 'password' => (string) $this->validated('basic_auth_password')],
            },
            keyword: $keyword === null ? null : (string) $keyword,
            keywordMode: KeywordMode::tryFrom((string) $this->validated('keyword_mode')) ?? KeywordMode::Contains,
            keywordCaseSensitive: (bool) $this->validated('keyword_case_sensitive'),
            latencyThresholdMs: $threshold === null ? null : (int) $threshold,
        );
    }

    /**
     * The stored type on update; the submitted one (default HTTP) on creation.
     */
    private function monitorType(): MonitorType
    {
        $monitor = $this->route('monitor');

        if ($monitor instanceof Monitor) {
            return $monitor->type;
        }

        return MonitorType::tryFrom((string) $this->input('type')) ?? MonitorType::Http;
    }

    private function organization(): Organization
    {
        /** @var Organization $organization */
        $organization = $this->route('organization');

        return $organization;
    }

    private function isUpdate(): bool
    {
        return $this->route('monitor') instanceof Monitor;
    }
}
