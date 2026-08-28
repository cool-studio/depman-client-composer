<?php

namespace Depman\Client\Tests;

use Depman\Client\Graph;
use Depman\Client\Logger;
use PHPUnit\Framework\TestCase;

class GraphTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/depman-graph-'.bin2hex(random_bytes(4)).'.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }

        parent::tearDown();
    }

    /**
     * @param  array<int, array<string, mixed>>  $packages
     */
    private function seed(array $packages): void
    {
        file_put_contents($this->path, (string) json_encode(['packages' => $packages]));
    }

    /**
     * @param  list<string>  $roots
     * @return array<string, array{depth: int, paths: list<list<string>>}>
     */
    private function attribute(array $roots): array
    {
        $graph = Graph::read($this->path, new Logger('silent', fopen('php://memory', 'rw+')));

        $this->assertNotNull($graph);

        return $graph->attribute($roots);
    }

    /**
     * @return array<string, string>
     */
    private function types(): array
    {
        $graph = Graph::read($this->path, new Logger('silent', fopen('php://memory', 'rw+')));

        $this->assertNotNull($graph);

        return $graph->types();
    }

    public function test_it_reads_a_composer_installers_type(): void
    {
        $this->seed([
            ['name' => 'roots/wordpress-no-content', 'type' => 'wordpress-core', 'require' => []],
        ]);

        // The signal that a Composer package is a shim for software that is not
        // a Composer library, and therefore that our advisory coverage of it is
        // partial at best.
        $this->assertSame(['roots/wordpress-no-content' => 'wordpress-core'], $this->types());
    }

    public function test_the_library_default_is_not_reported(): void
    {
        $this->seed([
            ['name' => 'laravel/framework', 'type' => 'library', 'require' => []],
            ['name' => 'psr/log', 'require' => []],
        ]);

        // `library` is composer's default and nearly every package in every
        // tree. Reporting it would add a field to a thousand package entries to
        // say nothing at all.
        $this->assertSame([], $this->types());
    }

    public function test_a_type_that_is_not_a_string_is_ignored_rather_than_fatal(): void
    {
        $this->seed([
            ['name' => 'acme/odd', 'type' => ['not', 'a', 'string'], 'require' => []],
            ['name' => 'acme/blank', 'type' => '', 'require' => []],
        ]);

        // installed.json is somebody else's file. A shape we do not expect costs
        // the signal for that package and nothing else.
        $this->assertSame([], $this->types());
    }

    public function test_types_are_keyed_lowercase_to_match_attribution(): void
    {
        $this->seed([
            ['name' => 'Roots/WordPress-No-Content', 'type' => 'wordpress-core', 'require' => []],
        ]);

        // Packagist names are case-insensitive and the resolver looks types up
        // by strtolower($name), exactly as it does the attribution map.
        $this->assertArrayHasKey('roots/wordpress-no-content', $this->types());
    }

    public function test_a_direct_dependency_is_depth_zero_with_no_ancestry(): void
    {
        $this->seed([['name' => 'laravel/framework', 'require' => []]]);

        $attributed = $this->attribute(['laravel/framework']);

        $this->assertSame(0, $attributed['laravel/framework']['depth']);
        // A direct dependency has nothing to attribute it to. Saying so is the
        // whole point: an invented parent is worse than an admitted gap.
        $this->assertSame([], $attributed['laravel/framework']['paths']);
    }

    public function test_it_attributes_a_transitive_dependency_to_the_direct_one(): void
    {
        $this->seed([
            ['name' => 'laravel/framework', 'require' => ['symfony/console' => '^7.0']],
            ['name' => 'symfony/console', 'require' => []],
        ]);

        $attributed = $this->attribute(['laravel/framework']);

        $this->assertSame(1, $attributed['symfony/console']['depth']);
        $this->assertSame([['laravel/framework']], $attributed['symfony/console']['paths']);
    }

    public function test_a_chain_records_every_hop_root_first(): void
    {
        $this->seed([
            ['name' => 'laravel/framework', 'require' => ['symfony/console' => '^7.0']],
            ['name' => 'symfony/console', 'require' => ['psr/log' => '^3.0']],
            ['name' => 'psr/log', 'require' => []],
        ]);

        $attributed = $this->attribute(['laravel/framework']);

        $this->assertSame(2, $attributed['psr/log']['depth']);
        // Element 0 is the package the reader edits in their own manifest.
        $this->assertSame([['laravel/framework', 'symfony/console']], $attributed['psr/log']['paths']);
    }

    public function test_a_package_reached_by_two_routes_records_both(): void
    {
        $this->seed([
            ['name' => 'a/one', 'require' => ['shared/lib' => '*']],
            ['name' => 'b/two', 'require' => ['shared/lib' => '*']],
            ['name' => 'shared/lib', 'require' => []],
        ]);

        $attributed = $this->attribute(['a/one', 'b/two']);

        $this->assertSame([['a/one'], ['b/two']], $attributed['shared/lib']['paths']);
    }

    public function test_at_most_three_paths_are_kept_and_the_choice_is_deterministic(): void
    {
        // The server column stores three. Five routes must always yield the
        // same three, or an unchanged tree starts producing changing reports.
        $packages = [['name' => 'shared/lib', 'require' => []]];
        $roots = [];

        foreach (['e/five', 'c/three', 'a/one', 'd/four', 'b/two'] as $name) {
            $packages[] = ['name' => $name, 'require' => ['shared/lib' => '*']];
            $roots[] = $name;
        }

        $this->seed($packages);

        $this->assertSame(
            [['a/one'], ['b/two'], ['c/three']],
            $this->attribute($roots)['shared/lib']['paths'],
        );

        // Same graph, roots offered in a different order: same answer.
        $this->assertSame(
            [['a/one'], ['b/two'], ['c/three']],
            $this->attribute(array_reverse($roots))['shared/lib']['paths'],
        );
    }

    public function test_a_cycle_terminates_and_does_not_reattribute_its_own_ancestor(): void
    {
        // Legal in a real tree via replace, and an unguarded walk never returns.
        $this->seed([
            ['name' => 'a/one', 'require' => ['b/two' => '*']],
            ['name' => 'b/two', 'require' => ['c/three' => '*']],
            ['name' => 'c/three', 'require' => ['a/one' => '*']],
        ]);

        $attributed = $this->attribute(['a/one']);

        $this->assertSame(0, $attributed['a/one']['depth']);
        $this->assertSame([], $attributed['a/one']['paths']);
        $this->assertSame([['a/one', 'b/two']], $attributed['c/three']['paths']);
    }

    public function test_a_replaced_package_is_attributed_to_whatever_replaced_it(): void
    {
        // Without this the edge dangles and every dependent loses its parent.
        $this->seed([
            ['name' => 'acme/suite', 'require' => [], 'replace' => ['acme/console' => 'self.version']],
            ['name' => 'a/one', 'require' => ['acme/console' => '*']],
        ]);

        $attributed = $this->attribute(['a/one']);

        $this->assertSame(1, $attributed['acme/suite']['depth']);
        $this->assertSame([['a/one']], $attributed['acme/suite']['paths']);
        $this->assertArrayNotHasKey('acme/console', $attributed);
    }

    public function test_a_provided_package_resolves_to_its_provider(): void
    {
        $this->seed([
            ['name' => 'monolog/monolog', 'require' => [], 'provide' => ['psr/log-implementation' => '3.0']],
            ['name' => 'a/one', 'require' => ['psr/log-implementation' => '*']],
        ]);

        $attributed = $this->attribute(['a/one']);

        $this->assertSame([['a/one']], $attributed['monolog/monolog']['paths']);
    }

    public function test_platform_requirements_are_not_walked(): void
    {
        $this->seed([['name' => 'a/one', 'require' => ['php' => '^8.2', 'ext-json' => '*']]]);

        $this->assertSame(['a/one'], array_keys($this->attribute(['a/one'])));
    }

    public function test_a_dependencys_dev_requirements_are_not_edges(): void
    {
        // require-dev of a dependency is never installed. Walking it would
        // attribute packages to parents that cannot have pulled them in.
        $this->seed([
            ['name' => 'a/one', 'require' => [], 'require-dev' => ['phpunit/phpunit' => '^11.0']],
            ['name' => 'phpunit/phpunit', 'require' => []],
        ]);

        $this->assertArrayNotHasKey('phpunit/phpunit', $this->attribute(['a/one']));
    }

    public function test_an_absurdly_long_chain_keeps_its_depth_but_carries_no_path(): void
    {
        $packages = [];

        for ($i = 0; $i < 20; $i++) {
            $packages[] = ['name' => sprintf('a/p%02d', $i), 'require' => [sprintf('a/p%02d', $i + 1) => '*']];
        }

        $packages[] = ['name' => 'a/p20', 'require' => []];

        $this->seed($packages);

        $attributed = $this->attribute(['a/p00']);

        $this->assertSame(20, $attributed['a/p20']['depth']);
        $this->assertSame([], $attributed['a/p20']['paths']);
        // The cut is at sixteen hops, not before it.
        $this->assertSame([], $attributed['a/p17']['paths']);
        $this->assertCount(1, $attributed['a/p16']['paths']);
    }

    public function test_a_root_requirement_that_is_not_installed_is_ignored(): void
    {
        $this->seed([['name' => 'a/one', 'require' => []]]);

        $this->assertSame(['a/one'], array_keys($this->attribute(['a/one', 'never/installed'])));
    }

    public function test_a_missing_file_is_not_an_error(): void
    {
        $this->assertNull(Graph::read($this->path, new Logger('silent', fopen('php://memory', 'rw+'))));
    }

    public function test_an_unexpected_shape_is_not_an_error(): void
    {
        file_put_contents($this->path, '{"nope":true}');

        $this->assertNull(Graph::read($this->path, new Logger('silent', fopen('php://memory', 'rw+'))));
    }
}
