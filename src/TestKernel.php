<?php

declare(strict_types=1);

namespace Quire\Testing;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Quire\QuireBundle;
use Quire\Testing\Database\QueryLog;
use Quire\Testing\Database\QueryLogMiddleware;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * A minimal Quire application for testing extensions, with exactly the given extensions enabled.
 *
 * It doesn't use the host project's configuration, so extension tests behave the same in an
 * extension's own repository and inside a site. The database comes from DATABASE_URL, with "_test"
 * appended to the database name.
 *
 * @api
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param list<string> $extensions    IDs of the extensions to enable (required extensions must be listed too)
     * @param string|null  $installedJson Composer installed.json to read extensions from; null for the project's own
     */
    public function __construct(
        private readonly array $extensions,
        private readonly ?string $installedJson = null,
        string $environment = 'test',
        bool $debug = true,
    ) {
        parent::__construct($environment, $debug);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new DoctrineBundle();
        yield new QuireBundle();
    }

    public function getProjectDir(): string
    {
        return TestInstallation::projectDir();
    }

    /**
     * One cache directory per set of extensions, so test classes enabling different extensions don't
     * share a build.
     */
    public function getCacheDir(): string
    {
        return $this->getProjectDir().'/var/cache/quire-test/'.$this->buildId();
    }

    public function getBuildDir(): string
    {
        return $this->getCacheDir();
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir().'/var/log';
    }

    /**
     * A distinct class name per build, so several builds can be loaded in one PHPUnit run.
     */
    protected function getContainerClass(): string
    {
        return 'QuireTestContainer_'.$this->buildId();
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'quire-test',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
        ]);

        $container->extension('twig', ['strict_variables' => true]);

        $container->extension('doctrine', [
            'dbal' => [
                'url' => '%env(resolve:DATABASE_URL)%',
                'dbname_suffix' => '_test',
            ],
        ]);

        // Counts database queries for ExtensionTestCase::assertQueryCount().
        $services = $container->services();
        $services->set(QueryLog::class);
        $services->set(QueryLogMiddleware::class)->autowire()->autoconfigure();

        $container->extension('quire', [
            'extensions' => [
                'pinned' => true,
                'enabled' => $this->extensions,
                'installed_json' => $this->installedJson ?? TestInstallation::writeInstalledJson($this->getCacheDir()),
            ],
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@QuireBundle/config/routes.php');
    }

    private function buildId(): string
    {
        return substr(hash('xxh128', implode(',', $this->extensions).'|'.$this->installedJson.'|'.$this->environment), 0, 16);
    }
}
