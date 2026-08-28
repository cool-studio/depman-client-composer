<?php

namespace Depman\Client;

/**
 * The installed dependency graph, read from vendor/composer/installed.json.
 *
 * installed.php — what the Resolver reads for the package set — carries no
 * edges. installed.json, written by the same installer alongside it, carries
 * every package's own `require`, plus `replace` and `provide`. That is the
 * exact edge list of what was installed, which a lockfile is not: a lockfile is
 * an intention, and docs/ingest-clients.md §5 forbids parsing one by hand.
 *
 * Attribution answers "which of my dependencies pulled this in?", so the walk
 * starts at the root manifest's own requirements and proceeds breadth-first.
 * The first layer is depth 0, and a package's ancestry chain begins with the
 * direct dependency the reader can actually edit.
 *
 * It also carries each package's `type`, which installed.php does not have.
 * A composer/installers type — `wordpress-core`, `wordpress-plugin` — says the
 * package is a shim for software that is not a Composer library, which is the
 * strongest cheap signal there is that DepMan's advisory coverage of it is
 * partial. Read-only and best-effort, exactly like the edges.
 */
final class Graph
{
    /**
     * Nobody acts on a chain this long, and carrying it is payload weight with
     * no reader. The depth is still reported.
     */
    private const MAX_CHAIN = 16;

    /** The server stores at most three (`report_dependencies.paths`). */
    private const MAX_PATHS = 3;

    /**
     * Candidates held before ranking. A diamond-shaped graph can reach one
     * package by hundreds of equally short routes; this bounds the work without
     * deciding which three survive.
     */
    private const MAX_CANDIDATES = 12;

    /**
     * @param  array<string, list<string>>  $requires  Package name => the names it requires.
     * @param  array<string, string>  $aliases  A replaced or provided name => the package supplying it.
     * @param  array<string, string>  $types  Package name => its composer `type`.
     */
    private function __construct(
        private readonly array $requires,
        private readonly array $aliases,
        private readonly array $types,
    ) {}

    /**
     * Each package's composer `type`, keyed by lowercased name.
     *
     * `library` is omitted: it is composer's default, it is the overwhelming
     * majority of every tree, and reporting it would add a field to nearly every
     * package entry on the wire to say nothing at all.
     *
     * @return array<string, string>
     */
    public function types(): array
    {
        return $this->types;
    }

    /**
     * Null when the file is missing or unreadable — the caller reports without
     * paths rather than guessing at them.
     */
    public static function read(string $path, Logger $logger): ?self
    {
        if (!is_file($path)) {
            $logger->debug('vendor/composer/installed.json not found; reporting without dependency paths or types.');

            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (!is_array($decoded) || !is_array($decoded['packages'] ?? null)) {
            $logger->debug('vendor/composer/installed.json has an unexpected shape; reporting without dependency paths or types.');

            return null;
        }

        $requires = [];
        $aliases = [];
        $types = [];

        foreach ($decoded['packages'] as $package) {
            if (!is_array($package) || !is_string($package['name'] ?? null)) {
                continue;
            }

            $name = strtolower($package['name']);

            $type = $package['type'] ?? null;

            if (is_string($type) && $type !== '' && $type !== 'library') {
                $types[$name] = $type;
            }

            // `require` only. A dependency's own require-dev is not installed,
            // and walking it would invent edges that are not on disk.
            $requires[$name] = self::packageNames($package['require'] ?? null);

            foreach (['replace', 'provide'] as $key) {
                foreach (self::packageNames($package[$key] ?? null) as $alias) {
                    // Lowest name wins, so two packages providing the same thing
                    // resolve the same way on every run.
                    if (!isset($aliases[$alias]) || strcmp($name, $aliases[$alias]) < 0) {
                        $aliases[$alias] = $name;
                    }
                }
            }
        }

        ksort($requires);
        ksort($aliases);
        ksort($types);

        return new self($requires, $aliases, $types);
    }

    /**
     * Breadth-first from the root's requirements.
     *
     * @param  list<string>  $roots  The names the root manifest requires.
     * @return array<string, array{depth: int, paths: list<list<string>>}> Keyed by lowercased package name.
     */
    public function attribute(array $roots): array
    {
        /** @var array<string, int> $depth */
        $depth = [];
        /** @var array<string, list<list<string>>> $chains Ancestry, root-first, excluding the package itself. */
        $chains = [];
        $frontier = [];

        foreach ($roots as $root) {
            $name = $this->resolve(strtolower($root));

            if ($name === null || isset($depth[$name])) {
                continue;
            }

            $depth[$name] = 0;
            // A direct dependency has an empty ancestry, which is what makes its
            // children's chains start with the direct dependency itself.
            $chains[$name] = [[]];
            $frontier[] = $name;
        }

        sort($frontier, SORT_STRING);

        while ($frontier !== []) {
            $next = [];

            foreach ($frontier as $parent) {
                foreach ($this->requires[$parent] ?? [] as $required) {
                    $child = $this->resolve($required);

                    if ($child === null || $child === $parent) {
                        continue;
                    }

                    if (!isset($depth[$child])) {
                        $depth[$child] = $depth[$parent] + 1;
                        $chains[$child] = [];
                        $next[] = $child;
                    }

                    // An edge back to an equal or shallower package is a cycle, or
                    // a longer way round to something already attributed. `replace`
                    // makes both reachable in real trees, and neither adds a path.
                    if ($depth[$child] !== $depth[$parent] + 1) {
                        continue;
                    }

                    foreach ($chains[$parent] as $chain) {
                        if (count($chains[$child]) >= self::MAX_CANDIDATES) {
                            break;
                        }

                        $chain[] = $parent;

                        if (count($chain) <= self::MAX_CHAIN) {
                            $chains[$child][] = $chain;
                        }
                    }
                }
            }

            sort($next, SORT_STRING);
            $frontier = $next;
        }

        $attributed = [];

        foreach ($depth as $name => $distance) {
            $attributed[$name] = [
                'depth' => $distance,
                'paths' => $distance === 0 ? [] : $this->rank($chains[$name]),
            ];
        }

        return $attributed;
    }

    /**
     * Same tree, same bytes. Every chain here is already a shortest one, so the
     * length comparison only decides ties that MAX_CHAIN truncation can leave.
     *
     * @param  list<list<string>>  $chains
     * @return list<list<string>>
     */
    private function rank(array $chains): array
    {
        usort($chains, static function (array $a, array $b): int {
            return count($a) <=> count($b) ?: strcmp(implode("\n", $a), implode("\n", $b));
        });

        return array_slice($chains, 0, self::MAX_PATHS);
    }

    /**
     * A name as the graph knows it: the package itself if installed, else
     * whatever replaced or provided it. Null for anything not in the tree.
     */
    private function resolve(string $name): ?string
    {
        if (isset($this->requires[$name])) {
            return $name;
        }

        return $this->aliases[$name] ?? null;
    }

    /**
     * @return list<string> Lowercased, sorted, without platform pseudo-packages.
     */
    private static function packageNames(mixed $links): array
    {
        if (!is_array($links)) {
            return [];
        }

        $names = [];

        foreach (array_keys($links) as $name) {
            // php, ext-*, lib-* and composer-* are platform requirements, not
            // packages, and have no place in a dependency graph.
            if (is_string($name) && str_contains($name, '/')) {
                $names[] = strtolower($name);
            }
        }

        sort($names, SORT_STRING);

        return array_values(array_unique($names));
    }
}
