<?php

namespace Depman\Client;

/**
 * Finds the ingest token, which never lives in depman.json.
 *
 * Order is deliberate: env var for CI, then a file for Docker and Kubernetes
 * secret mounts (which keeps the value out of `ps` output and crash dumps),
 * then a per-host credentials file for local development.
 */
final class Token
{
    /**
     * @param  array<string, string>  $env
     */
    public static function find(string $endpoint, array $env = []): ?string
    {
        $direct = self::env('DEPMAN_TOKEN', $env);

        if ($direct !== null) {
            return $direct;
        }

        $file = self::env('DEPMAN_TOKEN_FILE', $env);

        if ($file !== null && is_readable($file)) {
            $contents = trim((string) file_get_contents($file));

            if ($contents !== '') {
                return $contents;
            }
        }

        return self::fromCredentialsFile($endpoint, $env);
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
