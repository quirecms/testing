<?php

declare(strict_types=1);

namespace Quire\Testing;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Quire\QuireBundle;
use Quire\Testing\Database\QueryLog;
use Quire\Testing\Database\QueryLogMiddleware;
use Quire\Testing\Demo\DemoSite;
use Quire\Testing\Demo\SeedDemoCommand;
use Quire\User\Security\ExtensionAuthenticators;
use Quire\User\Security\UserChecker;
use Quire\User\User;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
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
     * @param string|null  $theme         ID of the site's theme; null for Quire's built-in templates
     */
    public function __construct(
        private readonly array $extensions,
        private readonly ?string $installedJson = null,
        string $environment = 'test',
        bool $debug = true,
        private readonly ?string $theme = null,
    ) {
        parent::__construct($environment, $debug);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new DoctrineBundle();
        yield new SecurityBundle();
        yield new QuireBundle();
    }

    public function getProjectDir(): string
    {
        return TestInstallation::projectDir();
    }

    /**
     * One cache directory per set of extensions and theme, so test classes using different ones don't
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
     * Without the config directory, so FrameworkBundle doesn't write this kernel's config reference over the
     * project's config/reference.php.
     *
     * @return array<mixed>
     */
    protected function getKernelParameters(): array
    {
        $parameters = parent::getKernelParameters();
        unset($parameters['.kernel.config_dir']);

        return $parameters;
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
            'mailer' => ['dsn' => 'null://null'],
            // The site's address, e.g. for links in emails.
            'router' => ['default_uri' => 'http://localhost'],
        ]);

        $container->extension('twig', ['strict_variables' => true]);

        // Quire's users and sign-in page, as an application's security.yaml sets them up; cheap password
        // hashing for speed.
        $container->extension('security', [
            'password_hashers' => [User::class => ['algorithm' => 'auto', 'cost' => 4, 'time_cost' => 3, 'memory_cost' => 10]],
            'firewalls' => ['main' => [
                'lazy' => true,
                'provider' => 'quire_users',
                'user_checker' => UserChecker::class,
                'custom_authenticators' => [ExtensionAuthenticators::class],
                'form_login' => ['login_path' => 'quire_login', 'check_path' => 'quire_login', 'enable_csrf' => true, 'default_target_path' => 'quire_home'],
                'logout' => ['path' => 'quire_logout', 'target' => 'quire_home', 'enable_csrf' => true],
                'remember_me' => ['secret' => '%kernel.secret%', 'signature_properties' => ['password']],
            ]],
        ]);

        // Like an application's config/packages/asset_mapper.yaml: the project's own assets/ directory.
        if (is_dir($this->getProjectDir().'/assets')) {
            $container->extension('framework', ['asset_mapper' => ['paths' => ['assets/']]]);
        }

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
        // The demo site of the admin's designs, for tests that need a realistic one.
        $services->set(DemoSite::class)->autowire()->public();
        $services->set(SeedDemoCommand::class)->autowire()->autoconfigure();

        $container->extension('quire', [
            // Files that tests store stay out of the project's own storage.
            'storage' => ['root' => '%kernel.project_dir%/var/quire-test-storage'],
            'extensions' => [
                'pinned' => true,
                'enabled' => $this->extensions,
                'theme' => $this->theme,
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
        return substr(hash('xxh128', implode(',', $this->extensions).'|'.$this->theme.'|'.$this->installedJson.'|'.$this->environment), 0, 16);
    }
}
