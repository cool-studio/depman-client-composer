<?php

namespace Depman\Client\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The manifest carries claims consumers rely on before any code runs.
 *
 * ADR-0047: the clients are MIT; the application is not. A `license` field is
 * a claim about a file — MIT requires its notice to travel with copies, and
 * the dist archive Packagist serves is shaped by `.gitattributes`, so the
 * LICENSE both existing and not being export-ignored is what makes the
 * claim true.
 */
class PackageTest extends TestCase
{
    private const ROOT = __DIR__.'/..';

    public function test_the_declared_licence_ships_its_text(): void
    {
        $manifest = json_decode((string) file_get_contents(self::ROOT.'/composer.json'), true);

        $this->assertSame('MIT', $manifest['license']);
        $this->assertStringStartsWith('MIT License', (string) file_get_contents(self::ROOT.'/LICENSE'));
    }

    public function test_the_licence_reaches_the_dist_archive(): void
    {
        $attributes = (string) file_get_contents(self::ROOT.'/.gitattributes');

        $this->assertStringNotContainsString('LICENSE', $attributes, 'export-ignoring the LICENSE would strip the MIT notice from every copy Packagist serves.');
    }
}
