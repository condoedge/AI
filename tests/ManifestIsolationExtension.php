<?php

namespace Condoedge\Ai\Tests;

use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Test-environment only: stops the Windows "rename(...services.php): Access is
 * denied (code: 5)" flake.
 *
 * Laravel's ProviderRepository rewrites the services manifest (tempnam + rename
 * over the existing file) every time an app boots with a provider list that
 * differs from the cached one. Test classes register different providers, so a
 * single shared bootstrap/cache/services.php is overwritten constantly, and on
 * Windows a rename over a file that is still open (another phpunit process,
 * or the AV scanner inspecting the freshly written file) fails.
 *
 * Before each test this points APP_SERVICES_CACHE at a manifest file unique to
 * the current process AND test class. Each file is written once (the first
 * boot of the class, to a name that does not exist yet) and only read after
 * that, so the rename-over-an-existing-file case practically never happens.
 * Files are removed at process shutdown by tests/bootstrap.php.
 *
 * Laravel reads the variable live (Application::normalizeCachePath -> Env::get)
 * on every boot, so updating $_SERVER / $_ENV / putenv between tests is enough.
 * An APP_SERVICES_CACHE supplied from outside (shell / phpunit.xml) is respected.
 */
final class ManifestIsolationExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        if (! defined('KOMPO_AI_TEST_MANIFEST_ISOLATION')) {
            return; // an external APP_SERVICES_CACHE override is in effect
        }

        $facade->registerSubscriber(new class implements PreparationStartedSubscriber {
            public function notify(PreparationStarted $event): void
            {
                $test = $event->test();
                $scope = $test instanceof TestMethod ? $test->className() : $test->id();

                ManifestIsolationExtension::useServicesManifestFor($scope);
            }
        });
    }

    public static function useServicesManifestFor(string $scope): void
    {
        $relative = 'bootstrap/cache/services.phpunit-'.getmypid().'-'.substr(md5($scope), 0, 12).'.php';

        putenv('APP_SERVICES_CACHE='.$relative);
        $_ENV['APP_SERVICES_CACHE'] = $relative;
        $_SERVER['APP_SERVICES_CACHE'] = $relative;
    }
}
