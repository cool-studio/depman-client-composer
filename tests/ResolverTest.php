<?php

namespace Depman\Client\Tests;

use Depman\Client\Logger;
use Depman\Client\Resolver;
use PHPUnit\Framework\TestCase;

class ResolverTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/depman-resolver-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/vendor/composer', 0775, true);
    }

    protected function tearDown(): void
    {
        // Explicit existence checks rather than @unlink: PHPUnit 12's error
        // handler surfaces suppressed warnings as test warnings.
        foreach (['vendor/composer/installed.php', 'vendor/composer/installed.json', 'composer.json'] as $file) {
            if (is_file($this->root.'/'.$file)) {
                unlink($this->root.'/'.$file);
            }
        }

        foreach (['/vendor/composer', '/vendor', ''] as $directory) {
            if (is_dir($this->root.$directory)) {
                rmdir($this->root.$directory);
            }
        }

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $installed
     * @param  array<string, mixed>  $composerJson
     * @param  array<int, array<string, mixed>>|null  $edges  installed.json packages; null writes no such file.
     */
    private function seed(array $installed, array $composerJson = [], ?array $edges = null): void
    {
        file_put_contents(
            $this->root.'/vendor/composer/installed.php',
            '<?php return '.var_export($installed, true).';'
        );

        if ($composerJson !== []) {
            file_put_contents($this->root.'/composer.json', json_encode($composerJson));
        }

        if ($edges !== null) {
            file_put_contents(
                $this->root.'/vendor/composer/installed.json',
                (string) json_encode(['packages' => $edges])
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function resolve(bool $includeDev = true): ?array
    {
        return (new Resolver(new Logger('silent', fopen('php://memory', 'rw+'))))
            ->resolve($this->root, $includeDev);
    }

    public function test_it_reads_the_installed_tree(): void
    {
        $this->seed([
            'root' => ['name' => 'acme/app'],
            'versions' => [
                'acme/app' => ['pretty_version' => '1.0.0'],
                'laravel/framework' => ['pretty_version' => '11.2.0', 'dev_requirement' => false],
                'phpunit/phpunit' => ['pretty_version' => '11.5.0', 'dev_requirement' => true],
            ],
        ], ['require' => ['laravel/framework' => '^11.0'], 'require-dev' => ['phpunit/phpunit' => '^11.0']]);

        $packages = $this->resolve();

        // The root package is the consumer's own project, not a dependency.
        $this->assertCount(2, $packages);
        $this->assertSame('pkg:composer/laravel/framework@11.2.0', $packages[0]['purl']);
        $this->assertSame('direct', $packages[0]['relationship']);
        $this->assertSame('^11.0', $packages[0]['requestedConstraint']);
    }

    public function test_dev_dependencies_are_distinguished(): void
    {
        $this->seed([
            'versions' => [
                'laravel/framework' => ['pretty_version' => '11.2.0', 'dev_requirement' => false],
                'phpunit/phpunit' => ['pretty_version' => '11.5.0', 'dev_requirement' => true],
            ],
        ]);

        $scopes = array_column($this->resolve(), 'scope', 'name');

        $this->assertSame('runtime', $scopes['laravel/framework']);
        $this->assertSame('dev', $scopes['phpunit/phpunit']);
    }

    public function test_dev_dependencies_can_be_excluded(): void
    {
        $this->seed([
            'versions' => [
                'laravel/framework' => ['pretty_version' => '11.2.0', 'dev_requirement' => false],
                'phpunit/phpunit' => ['pretty_version' => '11.5.0', 'dev_requirement' => true],
            ],
        ]);

        $names = array_column($this->resolve(includeDev: false), 'name');

        $this->assertSame(['laravel/framework'], $names);
    }

    public function test_transitive_dependencies_are_identified_from_the_root_manifest(): void
    {
        // installed.php does not record direct-vs-transitive, so it is
        // reconstructed from the root composer.json's require keys.
        $this->seed([
            'versions' => [
                'laravel/framework' => ['pretty_version' => '11.2.0'],
                'symfony/console' => ['pretty_version' => '7.0.1'],
            ],
        ], ['require' => ['laravel/framework' => '^11.0']]);

        $relationships = array_column($this->resolve(), 'relationship', 'name');

        $this->assertSame('direct', $relationships['laravel/framework']);
        $this->assertSame('transitive', $relationships['symfony/console']);
    }

    public function test_platform_requirements_are_not_packages(): void
    {
        $this->seed([
            'versions' => ['laravel/framework' => ['pretty_version' => '11.2.0']],
        ], ['require' => ['php' => '^8.2', 'ext-json' => '*', 'laravel/framework' => '^11.0']]);

        $names = array_column($this->resolve(), 'name');

        $this->assertSame(['laravel/framework'], $names);
    }

    public function test_metapackages_without_a_version_are_skipped(): void
    {
        $this->seed([
            'versions' => [
                'laravel/framework' => ['pretty_version' => '11.2.0'],
                'some/metapackage' => ['dev_requirement' => false],
            ],
        ]);

        $this->assertCount(1, $this->resolve());
    }

    public function test_it_reports_a_composer_installers_type(): void
    {
        $this->seed(
            ['versions' => ['roots/wordpress-no-content' => ['pretty_version' => '6.8.2']]],
            ['require' => ['roots/wordpress-no-content' => '^6.8']],
            [['name' => 'roots/wordpress-no-content', 'type' => 'wordpress-core', 'require' => []]],
        );

        $this->assertSame('wordpress-core', $this->resolve()[0]['type']);
    }

    public function test_a_plain_library_carries_no_type_key_at_all(): void
    {
        $this->seed(
            ['versions' => ['laravel/framework' => ['pretty_version' => '11.2.0']]],
            ['require' => ['laravel/framework' => '^11.0']],
            [['name' => 'laravel/framework', 'type' => 'library', 'require' => []]],
        );

        // Not `'type' => null`. A key present on every entry of a 1 000-package
        // tree to say "nothing unusual" is payload weight with no reader.
        $this->assertArrayNotHasKey('type', $this->resolve()[0]);
    }

    public function test_without_installed_json_packages_carry_no_type(): void
    {
        $this->seed(['versions' => ['laravel/framework' => ['pretty_version' => '11.2.0']]]);

        // Degrading to the honest gap, exactly as attribution does: the package
        // set, version and scope are unchanged, and the signal is simply absent.
        $this->assertArrayNotHasKey('type', $this->resolve()[0]);
    }

    public function test_packages_are_sorted_deterministically(): void
    {
        // The manifest digest depends on ordering being stable.
        $this->seed([
            'versions' => [
                'z/last' => ['pretty_version' => '1.0.0'],
                'a/first' => ['pretty_version' => '1.0.0'],
                'm/middle' => ['pretty_version' => '1.0.0'],
            ],
        ]);

        $this->assertSame(
            ['a/first', 'm/middle', 'z/last'],
            array_column($this->resolve(), 'name'),
        );
    }

    public function test_transitive_dependencies_carry_the_path_back_to_a_direct_one(): void
    {
        $this->seed([
            'versions' => [
                'laravel/framework' => ['pretty_version' => '11.2.0'],
                'symfony/console' => ['pretty_version' => '7.0.1'],
                'psr/log' => ['pretty_version' => '3.0.0'],
            ],
        ], ['require' => ['laravel/framework' => '^11.0']], [
            ['name' => 'laravel/framework', 'require' => ['symfony/console' => '^7.0']],
            ['name' => 'symfony/console', 'require' => ['psr/log' => '^3.0']],
            ['name' => 'psr/log', 'require' => []],
        ]);

        $packages = array_column($this->resolve(), null, 'name');

        // Purls, not names: the server joins on the purl.
        $this->assertSame(
            [['pkg:composer/laravel/framework@11.2.0']],
            $packages['symfony/console']['paths'],
        );
        $this->assertSame(
            [['pkg:composer/laravel/framework@11.2.0', 'pkg:composer/symfony/console@7.0.1']],
            $packages['psr/log']['paths'],
        );
    }

    public function test_depth_is_the_real_distance_not_just_direct_or_not(): void
    {
        $this->seed([
            'versions' => [
                'laravel/framework' => ['pretty_version' => '11.2.0'],
                'symfony/console' => ['pretty_version' => '7.0.1'],
                'psr/log' => ['pretty_version' => '3.0.0'],
            ],
        ], ['require' => ['laravel/framework' => '^11.0']], [
            ['name' => 'laravel/framework', 'require' => ['symfony/console' => '^7.0']],
            ['name' => 'symfony/console', 'require' => ['psr/log' => '^3.0']],
            ['name' => 'psr/log', 'require' => []],
        ]);

        $depths = array_column($this->resolve(), 'depth', 'name');

        $this->assertSame(0, $depths['laravel/framework']);
        $this->assertSame(1, $depths['symfony/console']);
        $this->assertSame(2, $depths['psr/log']);
    }

    public function test_a_direct_dependency_carries_no_path(): void
    {
        $this->seed([
            'versions' => ['laravel/framework' => ['pretty_version' => '11.2.0']],
        ], ['require' => ['laravel/framework' => '^11.0']], [
            ['name' => 'laravel/framework', 'require' => []],
        ]);

        $this->assertArrayNotHasKey('paths', $this->resolve()[0]);
    }

    public function test_without_installed_json_the_report_still_goes_out_carrying_no_paths(): void
    {
        // Reporting inventory with an honest gap beats reaching for the
        // lockfile, which is an intention rather than what is on disk.
        $this->seed([
            'versions' => [
                'laravel/framework' => ['pretty_version' => '11.2.0'],
                'symfony/console' => ['pretty_version' => '7.0.1'],
            ],
        ], ['require' => ['laravel/framework' => '^11.0']]);

        $packages = array_column($this->resolve(), null, 'name');

        $this->assertArrayNotHasKey('paths', $packages['symfony/console']);
        $this->assertSame(1, $packages['symfony/console']['depth']);
    }

    public function test_a_path_through_an_excluded_dev_dependency_is_dropped(): void
    {
        // guzzlehttp/guzzle is a runtime dependency, but its shortest route in
        // the installed graph runs through phpunit. Under include.dev false
        // phpunit is not in the report, so that chain would name a package the
        // reader cannot find anywhere — it is dropped whole rather than shortened.
        $this->seed([
            'versions' => [
                'laravel/framework' => ['pretty_version' => '11.2.0', 'dev_requirement' => false],
                'acme/middle' => ['pretty_version' => '2.0.0', 'dev_requirement' => false],
                'guzzlehttp/guzzle' => ['pretty_version' => '7.8.0', 'dev_requirement' => false],
                'phpunit/phpunit' => ['pretty_version' => '11.5.0', 'dev_requirement' => true],
            ],
        ], [
            'require' => ['laravel/framework' => '^11.0'],
            'require-dev' => ['phpunit/phpunit' => '^11.0'],
        ], [
            ['name' => 'laravel/framework', 'require' => ['acme/middle' => '^2.0']],
            ['name' => 'acme/middle', 'require' => ['guzzlehttp/guzzle' => '^7.0']],
            ['name' => 'phpunit/phpunit', 'require' => ['guzzlehttp/guzzle' => '^7.0']],
            ['name' => 'guzzlehttp/guzzle', 'require' => []],
        ]);

        $withDev = array_column($this->resolve(), null, 'name');
        $this->assertSame([['pkg:composer/phpunit/phpunit@11.5.0']], $withDev['guzzlehttp/guzzle']['paths']);

        $withoutDev = array_column($this->resolve(includeDev: false), null, 'name');
        $this->assertArrayHasKey('guzzlehttp/guzzle', $withoutDev);
        $this->assertArrayNotHasKey('paths', $withoutDev['guzzlehttp/guzzle']);
    }

    public function test_an_unexpected_installed_json_shape_is_not_an_error(): void
    {
        $this->seed([
            'versions' => ['laravel/framework' => ['pretty_version' => '11.2.0']],
        ], ['require' => ['laravel/framework' => '^11.0']]);

        file_put_contents($this->root.'/vendor/composer/installed.json', 'not json at all');

        $this->assertCount(1, $this->resolve());
    }

    public function test_a_missing_installed_file_is_not_an_error(): void
    {
        $this->assertNull($this->resolve());
    }

    public function test_an_unexpected_shape_is_not_an_error(): void
    {
        file_put_contents($this->root.'/vendor/composer/installed.php', '<?php return "nonsense";');

        $this->assertNull($this->resolve());
    }
}
