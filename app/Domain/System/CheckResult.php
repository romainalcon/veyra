<?php

namespace App\Domain\System;

final readonly class CheckResult
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    public function __construct(
        public string $name,
        public string $status,
        public string $detail,
        /** A failing critical check makes the instance "down" rather than "degraded". */
        public bool $critical = false,
    ) {}

    public static function ok(string $name, string $detail, bool $critical = false): self
    {
        return new self($name, self::OK, $detail, $critical);
    }

    public static function warn(string $name, string $detail, bool $critical = false): self
    {
        return new self($name, self::WARN, $detail, $critical);
    }

    public static function fail(string $name, string $detail, bool $critical = false): self
    {
        return new self($name, self::FAIL, $detail, $critical);
    }

    /**
     * @return array{name: string, status: string, detail: string}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'status' => $this->status, 'detail' => $this->detail];
    }
}
