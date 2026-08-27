<?php

/**
 * Test bootstrap for a package with no runtime dependencies.
 *
 * The client ships zero dependencies on purpose — it installs into everyone
 * else's production tree — and that constraint reaches the test suite too: it
 * must be runnable without a vendor/ directory of its own, so the monorepo's
 * gate can drive it with the parent's PHPUnit binary and no second install.
 *
 * If the package has been installed standalone, its own autoloader wins.
 * Otherwise a four-line PSR-4 registration does the same job.
 */
declare(strict_types=1);

$standalone = __DIR__.'/../vendor/autoload.php';

if (is_file($standalone)) {
    require $standalone;

    return;
}

spl_autoload_register(static function (string $class): void {
    foreach (['Depman\\Client\\Tests\\' => __DIR__, 'Depman\\Client\\' => __DIR__.'/../src'] as $prefix => $directory) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $path = $directory.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

        if (is_file($path)) {
            require $path;
        }

        return;
    }
});
