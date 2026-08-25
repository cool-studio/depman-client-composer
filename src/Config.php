<?php

namespace Depman\Client;

/**
 * The depman.json contract, as loaded from a consumer project.
 *
 * Precedence for every setting is: process env var > depman.json > default.
 *
 * depman.json is committed to source control, so it must never contain a
 * credential. A `token` key is refused loudly rather than silently honoured.
 */
final class Config
{
    private const TOKEN_KEYS = ['token', 'apiToken', 'api_token', 'secret'];

    /**
     * @param  array<string, mixed>  $raw
     */
    private function __construct(
        public readonly string $projectRoot,
        private readonly array $raw,
        private readonly Logger $logger,
    ) {}

    /**
     * @param  array<string, string>  $env
     */
    public static function load(string $projectRoot, Logger $logger, array $env = []): ?self
    {
        $path = rtrim($projectRoot, '/').'/depman.json';

        if (!is_file($path)) {
            $logger->debug('No depman.json found; nothing to report.');

            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (!is_array($decoded)) {
            $logger->error('depman.json is not valid JSON; skipping.');

            return null;
        }

        $config = new self($projectRoot, $decoded, $logger);
        $config->warnAboutCommittedSecrets();

        return $config;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(string $projectRoot, array $raw, Logger $logger): self
    {
        return new self($projectRoot, $raw, $logger);
    }

    private function warnAboutCommittedSecrets(): void
    {
        foreach (self::TOKEN_KEYS as $key) {
            if (!array_key_exists($key, $this->raw)) {
                continue;
            }

            // Deliberately printed regardless of log level. A token in a
            // committed file is already leaked; staying quiet helps nobody.
            $this->logger->always(sprintf(
                'a "%s" key was found in depman.json, which is committed to source control. '
                .'It has been ignored. Rotate that token and use the DEPMAN_TOKEN environment variable.',
                $key,
            ));
        }
    }

    public function isEnabled(array $env = []): bool
    {
        $override = $env['DEPMAN_ENABLED'] ?? getenv('DEPMAN_ENABLED');

        if ($override !== false && $override !== null && $override !== '') {
            return !in_array(strtolower((string) $override), ['0', 'false', 'no', 'off'], true);
        }

        return (bool) ($this->raw['enabled'] ?? true);
    }

    public function endpoint(array $env = []): string
    {
        $endpoint = $this->fromEnv('DEPMAN_ENDPOINT', $env)
            ?? (string) ($this->raw['endpoint'] ?? 'https://api.depman.io');

        return rtrim($endpoint, '/');
    }

    public function project(array $env = []): ?string
    {
        $project = $this->fromEnv('DEPMAN_PROJECT', $env) ?? ($this->raw['project'] ?? null);

        return is_string($project) && $project !== '' ? $project : null;
    }

    /**
     * Resolve the environment at run time.
     *
     * The same commit runs in local, CI, staging and production, so a literal
     * would be wrong. The fallback is deliberately "local" and never
     * "production": mislabelling a laptop as production produces alerts nobody
     * asked for and hides the ones that matter.
     *
     * @param  array<string, string>  $env
     * @return array{name: string, resolvedFrom: string}
     */
    public function environment(array $env = []): array
    {
        $sources = $this->raw['environment']['from'] ?? [
            'DEPMAN_ENV', 'APP_ENV', 'NODE_ENV', 'ENVIRONMENT', 'CI_ENVIRONMENT_NAME',
        ];

        foreach ((array) $sources as $variable) {
            $value = $this->fromEnv((string) $variable, $env);

            if ($value === null || $value === '') {
                continue;
            }

            return [
                'name' => $this->mapEnvironment($this->normalise($value)),
                'resolvedFrom' => (string) $variable,
            ];
        }

        return [
            'name' => $this->normalise((string) ($this->raw['environment']['fallback'] ?? 'local')),
            'resolvedFrom' => 'fallback',
        ];
    }

    private function mapEnvironment(string $value): string
    {
        $map = $this->raw['environment']['map'] ?? [];
        $mapped = is_array($map) ? ($map[$value] ?? $value) : $value;

        $allowed = $this->raw['environment']['allowed'] ?? null;

        if (is_array($allowed) && !in_array($mapped, $allowed, true)) {
            // Coerce rather than invent an environment. Typos would otherwise
            // proliferate into environments nobody meant to create.
            $fallback = $this->normalise((string) ($this->raw['environment']['fallback'] ?? 'local'));
            $this->logger->warn(sprintf('environment "%s" is not in the allowed list; using "%s".', $mapped, $fallback));

            return $fallback;
        }

        return (string) $mapped;
    }

    public function includeDev(array $env = []): bool
    {
        $override = $this->fromEnv('DEPMAN_INCLUDE_DEV', $env);

        if ($override !== null) {
            return !in_array(strtolower($override), ['0', 'false', 'no', 'off'], true);
        }

        return (bool) ($this->raw['include']['dev'] ?? true);
    }

    public function failOnError(array $env = []): bool
    {
        $override = $this->fromEnv('DEPMAN_FAIL_ON_ERROR', $env);

        if ($override !== null) {
            return in_array(strtolower($override), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) ($this->raw['failOnError'] ?? false);
    }

    public function timeoutMs(array $env = []): int
    {
        $override = $this->fromEnv('DEPMAN_TIMEOUT_MS', $env);
        $value = $override !== null ? (int) $override : (int) ($this->raw['timeoutMs'] ?? 10000);

        return max(1000, min($value, 60000));
    }

    public function logLevel(array $env = []): string
    {
        return $this->fromEnv('DEPMAN_LOG_LEVEL', $env) ?? (string) ($this->raw['logLevel'] ?? 'warn');
    }

    public function runsOnPostinstall(): bool
    {
        return (bool) ($this->raw['runOn']['postinstall'] ?? true);
    }

    public function manifestPath(): string
    {
        return (string) ($this->raw['manifest']['path'] ?? '.');
    }

    public function spoolDirectory(array $env = []): string
    {
        $dir = $this->fromEnv('DEPMAN_SPOOL_DIR', $env)
            ?? (string) ($this->raw['offline']['spoolDir'] ?? '.depman/spool');

        return rtrim($this->projectRoot, '/').'/'.ltrim($dir, '/');
    }

    public function offlineMode(array $env = []): string
    {
        $mode = $this->fromEnv('DEPMAN_OFFLINE_MODE', $env)
            ?? (string) ($this->raw['offline']['mode'] ?? 'spool');

        return in_array($mode, ['spool', 'skip'], true) ? $mode : 'spool';
    }

    /**
     * @param  array<string, string>  $env
     */
    private function fromEnv(string $key, array $env): ?string
    {
        if (array_key_exists($key, $env)) {
            return $env[$key] === '' ? null : $env[$key];
        }

        $value = getenv($key);

        return ($value === false || $value === '') ? null : $value;
    }

    private function normalise(string $value): string
    {
        return strtolower(trim($value));
    }
}
