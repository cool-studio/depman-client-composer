<?php

namespace Depman\Client;

/**
 * Holds payloads that could not be delivered, for a later `depman push`.
 *
 * An air-gapped build or a flaky runner should not lose its inventory. The
 * directory is capped so a permanently offline project cannot fill a disk.
 */
final class Spool
{
    private const MAX_FILES = 20;

    public function __construct(
        private readonly string $directory,
        private readonly Logger $logger,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function write(array $payload): bool
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            $this->logger->debug('Could not create the spool directory; dropping this report.');

            return false;
        }

        $file = sprintf('%s/%s-%s.json', $this->directory, date('Ymd-His'), bin2hex(random_bytes(4)));

        if (@file_put_contents($file, json_encode($payload)) === false) {
            return false;
        }

        $this->prune();
        $this->warnIfTracked();

        return true;
    }

    /**
     * @return array<int, string>
     */
    public function pending(): array
    {
        $files = glob($this->directory.'/*.json') ?: [];
        sort($files);

        return $files;
    }

    public function forget(string $file): void
    {
        @unlink($file);
    }

    private function prune(): void
    {
        $files = $this->pending();

        // Oldest first: a stale inventory is less useful than a recent one.
        foreach (array_slice($files, 0, max(0, count($files) - self::MAX_FILES)) as $stale) {
            @unlink($stale);
        }
    }

    private function warnIfTracked(): void
    {
        $gitignore = dirname($this->directory, 2).'/.gitignore';

        if (!is_file($gitignore)) {
            return;
        }

        $contents = (string) file_get_contents($gitignore);

        if (!str_contains($contents, '.depman')) {
            $this->logger->warn('the spool directory is not in .gitignore; add ".depman/" to avoid committing inventory files.');
        }
    }
}
