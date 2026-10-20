<?php

/*
 * PHPUnit bootstrap (test environment only).
 *
 * Stops the Windows "rename(...bootstrap/cache/services.php): Access is denied
 * (code: 5)" flake. Every Testbench boot may make Laravel rewrite the services
 * manifest via Filesystem::replace() (tempnam + rename over the old file); on
 * Windows that rename fails while any other handle (a concurrent phpunit process,
 * the AV scanner) has the target open.
 *
 * - APP_PACKAGES_CACHE: one file per phpunit process.
 * - APP_SERVICES_CACHE: one file per process here, then switched to one file per
 *   process + test class before each test by ManifestIsolationExtension
 *   (registered in phpunit.xml).
 *
 * Laravel reads both in Application::normalizeCachePath(). The values are relative
 * to the Testbench base path because Laravel only treats values starting with "/"
 * or "\" as absolute (a Windows "C:\..." path would get the base path prefixed).
 * Being env-based, this covers every test, including those that extend
 * Orchestra\Testbench\TestCase directly instead of tests/TestCase.php.
 * Values supplied from outside (shell or phpunit.xml <env>) are respected.
 */

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/ManifestIsolationExtension.php';

(static function (): void {
    $isSet = static fn (string $key): bool => getenv($key) !== false || isset($_ENV[$key]) || isset($_SERVER[$key]);
    $set = static function (string $key, string $value): void {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    };

    $pid = getmypid();
    $isolated = false;

    if (! $isSet('APP_PACKAGES_CACHE')) {
        $set('APP_PACKAGES_CACHE', 'bootstrap/cache/packages.phpunit-'.$pid.'.php');
        $isolated = true;
    }

    if (! $isSet('APP_SERVICES_CACHE')) {
        $set('APP_SERVICES_CACHE', 'bootstrap/cache/services.phpunit-'.$pid.'.php');
        define('KOMPO_AI_TEST_MANIFEST_ISOLATION', true);
        $isolated = true;
    }

    if (! $isolated) {
        return;
    }

    $cacheDir = \Orchestra\Testbench\default_skeleton_path('bootstrap/cache');

    if ($cacheDir === false) {
        return;
    }

    // Sweep manifests left behind by crashed runs (older than an hour, so a
    // concurrently running phpunit process is never affected).
    foreach (glob($cacheDir.DIRECTORY_SEPARATOR.'*.phpunit-*.php') ?: [] as $stale) {
        if (@filemtime($stale) < time() - 3600) {
            @unlink($stale);
        }
    }

    register_shutdown_function(static function () use ($cacheDir, $pid): void {
        foreach (glob($cacheDir.DIRECTORY_SEPARATOR.'*.phpunit-'.$pid.'[.-]*php') ?: [] as $file) {
            @unlink($file);
        }
    });
})();
