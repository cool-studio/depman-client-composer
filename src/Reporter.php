<?php

namespace Depman\Client;

/**
 * Builds the wire payload and delivers it.
 *
 * Zero runtime dependencies by design — this package installs into everyone
 * else's dependency tree, so it uses curl when available and a stream context
 * otherwise, and nothing else.
 *
 * **Not final, so that `send()` can be overridden in tests.** It is the only
 * class here that touches the network, which makes it the seam the orchestrator
 * has to be tested through — every branch in Client::interpret() is a response
 * status, and reaching them for real would mean a live server per case. The
 * client specification requires this seam of every client; see
 * docs/ingest-clients.md.
 */
class Reporter
{
    public const WIRE_VERSION = 1;

    public const CLIENT_NAME = 'depman/client';

    /**
     * The client's own release version, sent in the payload and the User-Agent.
     *
     * **Bump this in the commit that gets tagged**, not afterwards: it is the
     * only thing that tells support which build produced a report, and a
     * constant that lags the tag makes every report lie about itself. See the
     * client release checklist in docs/release-checklist.md.
     */
    public const CLIENT_VERSION = '0.1.3';

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

        // No curl_close(). Since PHP 8.0 the handle is an object freed by the
        // garbage collector, and calling it is deprecated in 8.5 — which the
        // client printed on every `composer install`, from inside a tool whose
        // entire job is to be invisible during one.
        unset($handle);

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

        // PHP 8.5 deprecated the magic local $http_response_header in favour of
        // http_get_last_response_headers(). This package supports 8.1, where
        // that function does not exist, so both spellings have to stay — and
        // the ternary is what keeps the deprecated one from being evaluated at
        // all on the runtimes that warn about it.
        if (function_exists('http_get_last_response_headers')) {
            $headers = http_get_last_response_headers() ?? [];
        } else {
            // Reached only on PHP < 8.5, and read through get_defined_vars()
            // rather than by name on purpose: 8.5 deprecated the magic local
            // $http_response_header, and it emits that deprecation when the
            // file is COMPILED, not when the branch runs — so spelling the
            // variable out here would warn on every 8.5 host even though this
            // line can never execute on one.
            $headers = get_defined_vars()['http_response_header'];
        }

        foreach ($headers as $header) {
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
