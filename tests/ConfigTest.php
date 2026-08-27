<?php

namespace Depman\Client\Tests;

use Depman\Client\Config;
use Depman\Client\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    /** @var resource */
    private $stream;

    /** @var array<int, string> */
    private array $putenv = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->stream = fopen('php://memory', 'rw+');
    }

    protected function tearDown(): void
    {
        foreach ($this->putenv as $key) {
            putenv($key);
        }

        $this->putenv = [];

        parent::tearDown();
    }

    /**
     * Set a real process environment variable for one test, and undo it after.
     */
    private function putenv(string $key, string $value): void
    {
        $this->putenv[] = $key;
        putenv($key.'='.$value);
    }

    private function logger(string $level = 'debug'): Logger
    {
        return new Logger($level, $this->stream);
    }

    private function logged(): string
    {
        rewind($this->stream);

        return (string) stream_get_contents($this->stream);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function config(array $raw, string $level = 'debug'): Config
    {
        return Config::fromArray('/tmp/project', $raw, $this->logger($level));
    }

    #[DataProvider('tokenKeys')]
    public function test_a_token_in_the_committed_config_is_refused_loudly(string $key): void
    {
        // depman.json is committed, so a token there is already leaked. The
        // warning prints regardless of log level — staying quiet helps nobody.
        $path = sys_get_temp_dir().'/depman-'.bin2hex(random_bytes(4));
        mkdir($path);
        file_put_contents($path.'/depman.json', json_encode(['project' => 'x', $key => 'dpm_live_secret']));

        Config::load($path, new Logger('silent', $this->stream));

        $this->assertStringContainsString($key, $this->logged());
        $this->assertStringContainsString('Rotate', $this->logged());

        unlink($path.'/depman.json');
        rmdir($path);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function tokenKeys(): array
    {
        return [
            'token' => ['token'],
            'apiToken' => ['apiToken'],
            'secret' => ['secret'],
        ];
    }

    public function test_the_environment_falls_back_to_local_and_never_production(): void
    {
        // Mislabelling a laptop as production produces alerts nobody asked for
        // and buries the ones that matter.
        //
        // Named variables that are definitely unset: the resolver deliberately
        // falls through to the real process environment when a key is absent
        // from the supplied array, and APP_ENV is set while tests run.
        $resolved = $this->config([
            'environment' => ['from' => ['DEPMAN_TEST_UNSET_A', 'DEPMAN_TEST_UNSET_B']],
        ])->environment([]);

        $this->assertSame('local', $resolved['name']);
        $this->assertSame('fallback', $resolved['resolvedFrom']);
    }

    public function test_the_resolver_reads_the_real_process_environment_too(): void
    {
        // A client running inside a Laravel app inherits APP_ENV, which is the
        // whole point: the environment is resolved at run time, not committed.
        //
        // The variable is set here rather than borrowed from the runner. This
        // test used to assert 'testing' and pass only because the parent
        // application's phpunit.xml exported APP_ENV=testing — a dependency on
        // the host that is invisible until the suite is run anywhere else, and
        // precisely what a client suite must not have.
        $this->putenv('APP_ENV', 'staging');

        $resolved = $this->config(['environment' => ['from' => ['APP_ENV']]])->environment([]);

        $this->assertSame('staging', $resolved['name']);
        $this->assertSame('APP_ENV', $resolved['resolvedFrom']);
    }

    public function test_the_environment_resolves_from_the_first_matching_variable(): void
    {
        $resolved = $this->config(['environment' => ['from' => ['DEPMAN_ENV', 'APP_ENV']]])
            ->environment(['APP_ENV' => 'staging']);

        $this->assertSame('staging', $resolved['name']);
        $this->assertSame('APP_ENV', $resolved['resolvedFrom']);
    }

    public function test_the_first_source_wins(): void
    {
        $resolved = $this->config(['environment' => ['from' => ['DEPMAN_ENV', 'APP_ENV']]])
            ->environment(['DEPMAN_ENV' => 'production', 'APP_ENV' => 'local']);

        $this->assertSame('production', $resolved['name']);
    }

    public function test_environment_names_are_mapped(): void
    {
        $resolved = $this->config([
            'environment' => ['from' => ['APP_ENV'], 'map' => ['prod' => 'production']],
        ])->environment(['APP_ENV' => 'prod']);

        $this->assertSame('production', $resolved['name']);
    }

    public function test_a_disallowed_environment_is_coerced_rather_than_invented(): void
    {
        // Otherwise a typo silently creates a new environment nobody meant.
        $resolved = $this->config([
            'environment' => [
                'from' => ['APP_ENV'],
                'allowed' => ['local', 'production'],
                'fallback' => 'local',
            ],
        ])->environment(['APP_ENV' => 'prodcution']);

        $this->assertSame('local', $resolved['name']);
        $this->assertStringContainsString('not in the allowed list', $this->logged());
    }

    public function test_environment_variables_override_the_file(): void
    {
        $config = $this->config(['endpoint' => 'https://from-file.test', 'project' => 'from-file']);

        $this->assertSame('https://from-env.test', $config->endpoint(['DEPMAN_ENDPOINT' => 'https://from-env.test']));
        $this->assertSame('from-env', $config->project(['DEPMAN_PROJECT' => 'from-env']));
    }

    public function test_the_kill_switch_works_without_a_code_change(): void
    {
        $config = $this->config(['project' => 'x']);

        $this->assertTrue($config->isEnabled([]));
        $this->assertFalse($config->isEnabled(['DEPMAN_ENABLED' => 'false']));
        $this->assertFalse($config->isEnabled(['DEPMAN_ENABLED' => '0']));
    }

    public function test_the_timeout_is_clamped_to_something_sane(): void
    {
        $config = $this->config([]);

        $this->assertSame(10000, $config->timeoutMs([]));
        $this->assertSame(1000, $config->timeoutMs(['DEPMAN_TIMEOUT_MS' => '10']));
        $this->assertSame(60000, $config->timeoutMs(['DEPMAN_TIMEOUT_MS' => '999999']));
    }

    public function test_missing_config_is_not_an_error(): void
    {
        $this->assertNull(Config::load('/nonexistent/path', $this->logger()));
    }

    public function test_malformed_config_is_not_an_error(): void
    {
        $path = sys_get_temp_dir().'/depman-'.bin2hex(random_bytes(4));
        mkdir($path);
        file_put_contents($path.'/depman.json', '{ not json');

        $this->assertNull(Config::load($path, $this->logger()));

        unlink($path.'/depman.json');
        rmdir($path);
    }
}
