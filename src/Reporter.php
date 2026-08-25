<?php

namespace Depman\Client;

/**
 * Builds the wire payload and delivers it.
 *
 * Zero runtime dependencies by design — this package installs into everyone
 * else's dependency tree, so it uses curl when available and a stream context
 * otherwise, and nothing else.
 */
final class Reporter
{
    public const WIRE_VERSION = 1;

    public const CLIENT_NAME = 'depman/client';

    public const CLIENT_VERSION = '1.0.0';

    public function __construct(private readonly Logger $logger) {}

    /**
     * @param  array<int, array<string, mixed>>  $packages
     * @param  array{name: string, resolvedFrom: string}  $environment
     * @return array<string, mixed>
     */
    public function buildPayload(Config $config, array $packages, array $environment, string $reason): array
    {
        return [
            'depmanWireVersion' => self::WIRE_VERSION,
            'report' => [
                'manifestDigest' => $this->digest($packages, $environment['name']),
                'generatedAt' => gmdate('c'),
                'reason' => $reason,
            ],
            'project' => $config->project(),
            'environment' => [
                'name' => $environment['name'],
                'resolvedFrom' => $environment['resolvedFrom'],
                'ci' => $this->looksLikeCi(),
            ],
            'client' => [
                'name' => self::CLIENT_NAME,
                'version' => self::CLIENT_VERSION,
                'packageManager' => ['name' => 'composer', 'version' => $this->composerVersion()],
                'runtime' => ['name' => 'php', 'version' => PHP_VERSION],
            ],
            'ecosystem' => 'composer',
            'manifest' => [
                'path' => $config->manifestPath(),
                'lockfileName' => 'composer.lock',
            ],
            'packages' => $packages,
            'counts' => ['packages' => count($packages)],
            'warnings' => [],
        ];
    }

    /**
     * Must match the server's computation exactly, or every report reports a
     * digest mismatch. Semantic content only — no timestamps, no client version.
     *
     * @param  array<int, array<string, mixed>>  $packages
     */
    public function digest(array $packages, string $environmentName): string
    {
        $lines = [];

        foreach ($packages as $package) {
            $lines[] = implode("\t", [
                (string) $package['purl'],
                (string) $package['scope'],
                (string) $package['relationship'],
            ]);
        }

        sort($lines, SORT_STRING);

        return 'sha256:'.hash('sha256',
            "depman-manifest-v1\ncomposer\n".$environmentName."\n".implode("\n", $lines)
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: string}|null Null on a transport failure.
     */
    public function send(string $endpoint, string $token, array $payload, int $timeoutMs): ?array
    {
        $url = $endpoint.'/api/v1/reports';
        $body = (string) json_encode($payload);

        $headers = [
            'Authorization: Bearer '.$token,
            'Content-Type: application/json',
            'Accept: application/json',
            'Depman-Wire-Version: '.self::WIRE_VERSION,
            'User-Agent: '.self::CLIENT_NAME.'/'.self::CLIENT_VERSION,
        ];

        return function_exists('curl_init')
            ? $this->sendWithCurl($url, $headers, $body, $timeoutMs)
            : $this->sendWithStream($url, $headers, $body, $timeoutMs);
    }

    /**
     * @param  array<int, string>  $headers
     * @return array{status: int, body: string}|null
     */
    private function sendWithCurl(string $url, array $headers, string $body, int $timeoutMs): ?array
    {
        $handle = curl_init($url);

        if ($handle === false) {
            return null;
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => min($timeoutMs, 5000),
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($response === false || $status === 0) {
            $this->logger->debug('transport failure: '.$error);

            return null;
        }

        return ['status' => $status, 'body' => (string) $response];
    }

    /**
     * @param  array<int, string>  $headers
     * @return array{status: int, body: string}|null
     */
    private function sendWithStream(string $url, array $headers, string $body, int $timeoutMs): ?array
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => $timeoutMs / 1000,
            'ignore_errors' => true,
        ]]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return null;
        }

        $status = 0;

        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return ['status' => $status, 'body' => $response];
    }

    private function looksLikeCi(): bool
    {
        foreach (['CI', 'GITHUB_ACTIONS', 'GITLAB_CI', 'BUILDKITE', 'CIRCLECI'] as $variable) {
            $value = getenv($variable);

            if ($value !== false && $value !== '' && strtolower($value) !== 'false') {
                return true;
            }
        }

        return false;
    }

    private function composerVersion(): string
    {
        $version = getenv('COMPOSER_VERSION');

        return $version !== false && $version !== '' ? $version : 'unknown';
    }
}
