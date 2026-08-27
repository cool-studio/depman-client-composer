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
        foreach (['vendor/composer/installed.php', 'composer.json'] as $file) {
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
     */
    private function seed(array $installed, array $composerJson = []): void
    {
        file_put_contents(
            $this->root.'/vendor/composer/installed.php',
            '<?php return '.var_export($installed, true).';'
        );

        if ($composerJson !== []) {
            file_put_contents($this->root.'/composer.json', json_encode($composerJson));
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
