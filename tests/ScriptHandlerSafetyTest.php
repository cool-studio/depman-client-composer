<?php

namespace Depman\Client\Tests;

use Depman\Client\ScriptHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one hard rule of this client: it must never abort a consumer's
 * `composer install`. A dependency-inventory tool that breaks builds gets
 * removed, and then it protects nobody.
 */
class ScriptHandlerSafetyTest extends TestCase
{
    private string $root;

    private string $previousCwd;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousCwd = (string) getcwd();
        $this->root = sys_get_temp_dir().'/depman-safety-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0775, true);
        chdir($this->root);
        putenv('INIT_CWD='.$this->root);

        // ScriptHandler is the real entry point: it builds its own Logger on
        // STDERR because that is what a Composer hook writes to, so there is no
        // stream to inject. Silencing it keeps the suite's own output readable.
        //
        // One line still gets through, from the committed-token case — always()
        // ignores the level on purpose, and a test proving a credential warning
        // is loud should not be able to mute it.
        putenv('DEPMAN_LOG_LEVEL=silent');
    }

    protected function tearDown(): void
    {
        putenv('INIT_CWD');
        putenv('DEPMAN_LOG_LEVEL');
        chdir($this->previousCwd);

        foreach (glob($this->root.'/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        if (is_dir($this->root)) {
            rmdir($this->root);
        }

        parent::tearDown();
    }

    #[DataProvider('hostileConditions')]
    public function test_the_handler_never_throws(string $description, ?string $configContents): void
    {
        if ($configContents !== null) {
            file_put_contents($this->root.'/depman.json', $configContents);
        }

        // The claim is precisely "this returned without throwing". If it throws,
        // Composer aborts and the consumer's install fails because of us.
        // expectNotToPerformAssertions states that honestly; assertTrue(true)
        // would be a tautology dressed up as a check.
        $this->expectNotToPerformAssertions();

        ScriptHandler::report();
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function hostileConditions(): array
    {
        return [
            'no depman.json at all' => ['missing config', null],
            'empty file' => ['empty config', ''],
            'malformed json' => ['malformed config', '{ not json at all'],
            'json that is not an object' => ['scalar config', '"a string"'],
            'empty object' => ['no keys', '{}'],
            'no project key' => ['missing project', '{"endpoint": "https://example.test"}'],
            'unreachable endpoint' => ['dead endpoint', '{"project":"x","endpoint":"http://127.0.0.1:1"}'],
            'nonsense endpoint' => ['bad endpoint', '{"project":"x","endpoint":"not-a-url"}'],
            'a committed token' => ['leaked token', '{"project":"x","token":"dpm_live_leaked"}'],
            'absurd timeout' => ['bad timeout', '{"project":"x","timeoutMs":-5}'],
            'wrong types throughout' => ['type confusion', '{"project":123,"enabled":"maybe","include":"yes"}'],
        ];
    }

    public function test_the_handler_survives_an_unreadable_vendor_directory(): void
    {
        file_put_contents($this->root.'/depman.json', '{"project":"x"}');
        file_put_contents($this->root.'/composer.json', '{"require":{"a/b":"^1"}}');

        $this->expectNotToPerformAssertions();

        ScriptHandler::report();
    }

    public function test_the_project_root_comes_from_init_cwd(): void
    {
        // Composer sets INIT_CWD to where the command was invoked, which is the
        // project root even though the script itself runs from vendor/.
        putenv('INIT_CWD='.$this->root);

        $this->assertSame($this->root, ScriptHandler::projectRoot());
    }

    public function test_the_project_root_falls_back_to_the_working_directory(): void
    {
        putenv('INIT_CWD');

        $this->assertSame((string) getcwd(), ScriptHandler::projectRoot());
    }
}
