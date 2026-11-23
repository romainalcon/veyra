<?php

namespace App\Domain\System\Metrics;

/**
 * One metric family of the Prometheus text exposition format (0.0.4).
 */
final class MetricFamily
{
    /**
     * @var list<array{suffix: string, labels: array<string, string>, value: float|int}>
     */
    private array $samples = [];

    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $help,
    ) {}

    /**
     * @param  array<string, string>  $labels
     */
    public function add(float|int $value, array $labels = [], string $suffix = ''): self
    {
        $this->samples[] = ['suffix' => $suffix, 'labels' => $labels, 'value' => $value];

        return $this;
    }

    public function render(): string
    {
        $lines = ["# HELP {$this->name} {$this->help}", "# TYPE {$this->name} {$this->type}"];

        foreach ($this->samples as $sample) {
            $labels = '';

            if ($sample['labels'] !== []) {
                $pairs = [];

                foreach ($sample['labels'] as $key => $value) {
                    $pairs[] = $key.'="'.self::escape($value).'"';
                }

                $labels = '{'.implode(',', $pairs).'}';
            }

            $lines[] = $this->name.$sample['suffix'].$labels.' '.self::number($sample['value']);
        }

        return implode("\n", $lines)."\n";
    }

    public static function number(float|int $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return match (true) {
            is_nan($value) => 'NaN',
            $value === INF => '+Inf',
            $value === -INF => '-Inf',
            default => rtrim(rtrim(sprintf('%.6F', $value), '0'), '.') ?: '0',
        };
    }

    private static function escape(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }
}
