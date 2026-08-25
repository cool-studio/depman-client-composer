<?php

namespace Depman\Client;

/**
 * The outcome of a report attempt.
 *
 * Note that `skipped` and `spooled` are not failures: no token, no depman.json
 * and an unreachable server are all ordinary situations that must not fail a
 * consumer's build.
 */
final class Result
{
    private function __construct(
        public readonly string $status,
        public readonly string $detail,
        public readonly int $packages = 0,
        public readonly bool $deduplicated = false,
    ) {}

    public static function reported(int $packages, bool $deduplicated): self
    {
        return new self('reported', 'ok', $packages, $deduplicated);
    }

    public static function skipped(string $detail): self
    {
        return new self('skipped', $detail);
    }

    public static function spooled(string $detail): self
    {
        return new self('spooled', $detail);
    }

    public static function failed(string $detail): self
    {
        return new self('failed', $detail);
    }

    public function isFailure(): bool
    {
        return $this->status === 'failed';
    }
}
