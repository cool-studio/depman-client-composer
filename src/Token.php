<?php

namespace Depman\Client;

/**
 * Finds the ingest token, which never lives in depman.json.
 *
 * Order, first hit wins:
 *
 *   1. `DEPMAN_TOKEN` in the project's `.env`
 *   2. `DEPMAN_TOKEN` in the process environment
 *   3. `DEPMAN_TOKEN_FILE`, from either, pointing at a file
 *   4. `~/.depman/credentials`, keyed by endpoint host
 *
 * **`.env` comes first because of when this runs.** The hook fires during
 * `composer install`, and at that moment a Laravel application has not booted:
 * nothing has loaded `.env` into the process environment, so a developer who
 * put `DEPMAN_TOKEN` there — the obvious place, and the place every other
 * secret in their project lives — got "no token found, skipping" and no
 * explanation. Silence that looks like success is the one failure mode this
 * product cannot afford, and it was reachable through the most natural setup a
 * user could choose.
 *
 * The remaining order is unchanged: the process environment for CI, then a
 * file for Docker and Kubernetes secret mounts (which keeps the value out of
 * `ps` output and crash dumps), then a per-host credentials file.
 */
final class Token
{
    /**
     * @param  array<string, string>  $env
     */
    public static function find(string $endpoint, array $env = [], ?string $projectRoot = null): ?string
    {
        return self::locate($endpoint, $env, $projectRoot)?->value;
    }

    /**
     * The token and the mechanism that supplied it.
     *
     * @param  array<string, string>  $env
     */
    public static function locate(string $endpoint, array $env = [], ?string $projectRoot = null): ?FoundToken
    {
        if ($projectRoot !== null) {
            $fromDotEnv = DotEnv::get($projectRoot, 'DEPMAN_TOKEN');

            if ($fromDotEnv !== null) {
                return new FoundToken($fromDotEnv, '.env');
            }
        }

        $direct = self::env('DEPMAN_TOKEN', $env);

        if ($direct !== null) {
            return new FoundToken($direct, 'DEPMAN_TOKEN');
        }

        $file = self::tokenFilePath($env, $projectRoot);

        if ($file !== null && is_readable($file)) {
            $contents = trim((string) file_get_contents($file));

            if ($contents !== '') {
                return new FoundToken($contents, 'DEPMAN_TOKEN_FILE ('.$file.')');
            }
        }

        $credentials = self::fromCredentialsFile($endpoint, $env);

        return $credentials === null ? null : new FoundToken($credentials, '~/.depman/credentials');
    }

    /**
     * `DEPMAN_TOKEN_FILE` from the process environment, then from `.env`.
     *
     * Both sources, in the same order as the token itself, so the two
     * mechanisms do not disagree about where they look.
     *
     * @param  array<string, string>  $env
     */
    private static function tokenFilePath(array $env, ?string $projectRoot): ?string
    {
        $file = self::env('DEPMAN_TOKEN_FILE', $env);

        if ($file !== null) {
            return $file;
        }

        return $projectRoot === null ? null : DotEnv::get($projectRoot, 'DEPMAN_TOKEN_FILE');
    }

    /**
     * @param  array<string, string>  $env
     */
    private static function fromCredentialsFile(string $endpoint, array $env): ?string
    {
        $home = self::env('DEPMAN_HOME', $env) ?? self::env('HOME', $env);

        if ($home === null) {
            return null;
        }

        $path = rtrim($home, '/').'/.depman/credentials';

        if (!is_readable($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (!is_array($decoded)) {
            return null;
        }

        // Keyed by host so a developer can hold tokens for a self-hosted
        // instance and the SaaS at the same time.
        $host = parse_url($endpoint, PHP_URL_HOST);

        $token = $decoded[$host] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * @param  array<string, string>  $env
     */
    private static function env(string $key, array $env): ?string
    {
        if (array_key_exists($key, $env)) {
            return $env[$key] === '' ? null : $env[$key];
        }

        $value = getenv($key);

        return ($value === false || $value === '') ? null : $value;
    }
}
