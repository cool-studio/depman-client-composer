<?php

namespace Depman\Client;

/**
 * The outcome of draining the spool.
 *
 * Counts rather than a single status, because a drain is not one thing: some
 * payloads land, one can be rejected outright, and the run can stop partway
 * because the server is still unreachable. Collapsing that into "ok" or "not
 * ok" throws away the number the operator actually wants, which is how much
 * inventory is still sitting on disk.
 */
final class PushResult
{
    /**
     * @param  int  $sent  Payloads the server accepted and that have been deleted.
     * @param  int  $discarded  Payloads dropped unsent because they can never succeed.
     * @param  int  $remaining  Payloads still on disk, to be retried by a later push.
     * @param  string  $detail  Why the drain stopped, or 'ok' when it ran to the end.
     * @param  bool  $failed  Whether the user has something to fix.
     */
    private function __construct(
        public readonly string $status,
        public readonly int $sent,
        public readonly int $discarded,
        public readonly int $remaining,
        public readonly string $detail,
        public readonly bool $failed,
    ) {}

    public static function skipped(string $detail): self
    {
        return new self('skipped', 0, 0, 0, $detail, false);
    }

    /**
     * The drain reached the end of the queue.
     *
     * Still a failure when anything was discarded: a rejected payload is a
     * configuration problem, and it is the one outcome here that a person has
     * to act on.
     */
    public static function drained(int $sent, int $discarded): self
    {
        return new self('drained', $sent, $discarded, 0, 'ok', $discarded > 0);
    }

    /**
     * The drain stopped early, leaving the rest for next time.
     *
     * `$failed` is the caller's judgement, not this class's: an unreachable
     * server is exactly what the spool is for and must not be an error, while a
     * rejected token is a thing somebody has to fix.
     */
    public static function stopped(int $sent, int $discarded, int $remaining, string $detail, bool $failed): self
    {
        return new self('stopped', $sent, $discarded, $remaining, $detail, $failed);
    }

    public function isFailure(): bool
    {
        return $this->failed;
    }
}
