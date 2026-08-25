<?php

namespace Depman\Client;

use Throwable;

/**
 * The Composer entry point.
 *
 * A script handler rather than a Composer plugin: plugins require explicit
 * allow-plugins consent, which is friction, and load earlier than needed.
 *
 * The $event parameter is deliberately untyped. Type-hinting
 * Composer\Script\Event would make composer/composer a dependency of a package
 * that installs into everyone else's tree, and nothing here needs it.
 */
final class ScriptHandler
{
    public static function report(?object $event = null): void
    {
        self::run('postinstall');
    }

    /**
     * The one hard rule of this class: it must never abort a consumer's
     * `composer install`. Every path returns void, and every throwable is
     * swallowed. A dependency-inventory tool that breaks builds gets removed.
     */
    private static function run(string $reason): void
    {
        try {
            $projectRoot = self::projectRoot();
            $logger = new Logger(self::logLevel($projectRoot));

            $client = new Client($logger, new Resolver($logger), new Reporter($logger));
            $result = $client->report($projectRoot, $reason);

            if ($result->status === 'reported' && !$result->deduplicated) {
                $logger->always(sprintf('reported %d packages.', $result->packages));
            }
        } catch (Throwable $exception) {
            // Intentionally swallowed. Failing here would break the install.
            if (getenv('DEPMAN_DEBUG') !== false) {
                fwrite(STDERR, 'DepMan: '.$exception->getMessage().PHP_EOL);
            }
        }
    }

    /**
     * Composer sets INIT_CWD to the directory the command was invoked from,
     * which is the project root even when the script runs from vendor/.
     */
    public static function projectRoot(): string
    {
        $initCwd = getenv('INIT_CWD');

        if (is_string($initCwd) && $initCwd !== '' && is_dir($initCwd)) {
            return $initCwd;
        }

        return (string) getcwd();
    }

    private static function logLevel(string $projectRoot): string
    {
        $override = getenv('DEPMAN_LOG_LEVEL');

        if (is_string($override) && $override !== '') {
            return $override;
        }

        $path = rtrim($projectRoot, '/').'/depman.json';

        if (!is_file($path)) {
            return 'warn';
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) && is_string($decoded['logLevel'] ?? null)
            ? $decoded['logLevel']
            : 'warn';
    }
}
