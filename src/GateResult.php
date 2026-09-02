<?php

namespace Depman\Client;

/**
 * The verdict of `report --wait --fail-on=`.
 *
 * **Only three of the four outcomes are a pass, and the default for anything
 * ambiguous is to fail.** That is the opposite of every other decision this
 * client makes, and deliberately so: a gate is the one mode a user opted into
 * specifically to break a build, and a gate that cannot get an answer and
 * reports success is worse than no gate at all — it is a green tick over an
 * unanswered question.
 */
final class GateResult
{
    public const PASSED = 'passed';

    public const BREACHED = 'breached';

    public const TIMED_OUT = 'timed_out';

    public const ERRORED = 'errored';

    /**
     * @param  array<string, int>  $counts  Open Findings by severity, worst first.
     */
    private function __construct(
        public readonly string $status,
        public readonly string $detail,
        public readonly int $breaching = 0,
        public readonly array $counts = [],
    ) {}

    /**
     * @param  array<string, int>  $counts
     */
    public static function passed(array $counts): self
    {
        return new self(self::PASSED, 'ok', 0, $counts);
    }

    /**
     * @param  array<string, int>  $counts
     */
    public static function breached(int $breaching, string $threshold, array $counts): self
    {
        return new self(
            self::BREACHED,
            sprintf('%d open finding%s at %s or above', $breaching, $breaching === 1 ? '' : 's', $threshold),
            $breaching,
            $counts,
        );
    }

    public static function timedOut(string $detail): self
    {
        return new self(self::TIMED_OUT, $detail);
    }

    public static function errored(string $detail): self
    {
        return new self(self::ERRORED, $detail);
    }

    public function isFailure(): bool
    {
        return $this->status !== self::PASSED;
    }

    /**
     * The per-severity line for a build log, or null when there is nothing to
     * report because we never got an answer.
     *
     * Every band is printed, zeros included. "0 critical" is a fact somebody
     * wanted confirmed; omitting it leaves the reader wondering whether the
     * band was checked or merely absent.
     */
    public function summary(): ?string
    {
        if ($this->counts === []) {
            return null;
        }

        $parts = [];

        foreach ($this->counts as $severity => $count) {
            $parts[] = $count.' '.$severity;
        }

        return implode(', ', $parts);
    }

    /**
     * The process exit code.
     *
     * Two codes rather than one, and the distinction is the point: `1` is "we
     * looked and your build is over the line", `2` is "we could not find out".
     * A pipeline that wants to retry the second and never the first has no
     * other way to tell them apart.
     */
    public function exitCode(): int
    {
        return match ($this->status) {
            self::PASSED => 0,
            self::BREACHED => 1,
            default => 2,
        };
    }
}
