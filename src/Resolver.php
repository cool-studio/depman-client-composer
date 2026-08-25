<?php

namespace Depman\Client;

/**
 * Reads the installed dependency tree from Composer's own metadata.
 *
 * Deliberately reads vendor/composer/installed.php rather than shelling out to
 * `composer show --format=json`:
 *
 *   - No subprocess, so it adds milliseconds rather than seconds to an install.
 *   - It is exactly what is on disk, not a fresh re-solve.
 *   - It carries dev_requirement per package, which is what distinguishes a dev
 *     dependency from a runtime one.
 *
 * Direct-versus-transitive is not in installed.php, so it is reconstructed from
 * the root composer.json's require and require-dev keys.
 */
final class Resolver
{
    public function __construct(private readonly Logger $logger) {}

    /**
     * @return array<int, array<string, mixed>>|null Null when the tree cannot be read.
     */
    public function resolve(string $projectRoot, bool $includeDev): ?array
    {
        $installedPath = rtrim($projectRoot, '/').'/vendor/composer/installed.php';

        if (!is_file($installedPath)) {
            $this->logger->debug('vendor/composer/installed.php not found; nothing to report.');

            return null;
        }

        /** @var array{versions?: array<string, array<string, mixed>>}|mixed $installed */
        $installed = require $installedPath;

        if (!is_array($installed) || !isset($installed['versions']) || !is_array($installed['versions'])) {
            $this->logger->warn('vendor/composer/installed.php has an unexpected shape; skipping.');

            return null;
        }

        $direct = $this->directRequirements($projectRoot);
        $rootName = $installed['root']['name'] ?? null;

        $packages = [];

        foreach ($installed['versions'] as $name => $meta) {
            if (!is_array($meta)) {
                continue;
            }

            // The root package is the consumer's own project, not a dependency.
            if ($name === $rootName) {
                continue;
            }

            // Metapackages and replaced packages have no version of their own.
            $version = $meta['pretty_version'] ?? $meta['version'] ?? null;

            if (!is_string($version) || $version === '') {
                continue;
            }

            $isDev = (bool) ($meta['dev_requirement'] ?? false);

            if ($isDev && !$includeDev) {
                continue;
            }

            $isDirect = isset($direct[$name]);

            $packages[] = [
                'purl' => $this->purl($name, $version),
                'name' => $name,
                'version' => $version,
                'scope' => $isDev ? 'dev' : 'runtime',
                'relationship' => $isDirect ? 'direct' : 'transitive',
                'depth' => $isDirect ? 0 : 1,
                'requestedConstraint' => $direct[$name] ?? null,
            ];
        }

        usort($packages, static fn (array $a, array $b) => strcmp($a['purl'], $b['purl']));

        return $packages;
    }

    /**
     * @return array<string, string> Package name => the constraint the root asked for.
     */
    private function directRequirements(string $projectRoot): array
    {
        $path = rtrim($projectRoot, '/').'/composer.json';

        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (!is_array($decoded)) {
            return [];
        }

        $direct = [];

        foreach (['require', 'require-dev'] as $section) {
            foreach ((array) ($decoded[$section] ?? []) as $name => $constraint) {
                // php, ext-*, lib-* and composer-* are platform requirements,
                // not packages, and have no place in a dependency inventory.
                if (!is_string($name) || !str_contains($name, '/')) {
                    continue;
                }

                $direct[$name] = is_string($constraint) ? $constraint : null;
            }
        }

        return $direct;
    }

    private function purl(string $name, string $version): string
    {
        // Composer names are vendor/name and are case-insensitive on Packagist.
        $name = strtolower($name);

        return 'pkg:composer/'.$name.'@'.rawurlencode($version);
    }
}
