<?php

declare(strict_types=1);

namespace Quire\Testing;

use Doctrine\DBAL\Connection;
use Quire\Extension\ExtensionManager;
use Quire\Settings\Settings;
use Quire\Testing\Database\QueryLog;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Base class for testing Quire extensions.
 *
 *     final class ReviewsTest extends ExtensionTestCase
 *     {
 *         protected static array $extensions = ['acme-comments', 'acme-reviews'];
 *
 *         public function testListsReviews(): void
 *         {
 *             $this->setSetting('acme-reviews.per_page', 5);
 *             $this->client->request('GET', '/reviews');
 *
 *             self::assertResponseIsSuccessful();
 *         }
 *     }
 *
 * Each test class runs a minimal Quire application ({@see TestKernel}) with only the listed extensions
 * enabled, and the theme in $theme if one is set. Before the first test, the test database is created and migrated. Every test runs inside a
 * database transaction that is rolled back afterwards, so tests can't see each other's data.
 *
 * @api
 */
abstract class ExtensionTestCase extends WebTestCase
{
    /**
     * IDs of the extensions to enable, including the extensions they require.
     *
     * @var list<string>
     */
    protected static array $extensions = [];

    /**
     * ID of the site's theme, e.g. to test a theme or how an extension looks in one; null for Quire's
     * built-in templates.
     */
    protected static ?string $theme = null;

    /** A browser for the test application; it keeps the same kernel (and transaction) across requests. */
    protected KernelBrowser $client;

    /** @var array<string, true> Builds whose database is ready, per PHPUnit run */
    private static array $preparedBuilds = [];

    /**
     * Composer installed.json to read extensions from. Null uses the project's own; override for fixtures.
     */
    protected static function installedJson(): ?string
    {
        return null;
    }

    /**
     * @param array<mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $environment = $options['environment'] ?? 'test';
        $debug = $options['debug'] ?? true;

        return new TestKernel(
            static::$extensions,
            static::installedJson(),
            \is_string($environment) ? $environment : 'test',
            \is_bool($debug) ? $debug : true,
            static::$theme,
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareDatabase();

        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = $this->connection();

        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * Changes a setting for this test only.
     */
    protected function setSetting(string $key, mixed $value): void
    {
        $this->settings()->set($key, $value);
    }

    protected function settings(): Settings
    {
        return $this->service(Settings::class);
    }

    protected function connection(): Connection
    {
        return $this->service(Connection::class);
    }

    /**
     * Runs the callback and asserts how many database queries it made, e.g. that a listing loads its
     * relations in batches instead of once per item (N+1). Returns what the callback returns.
     *
     *     $this->assertQueryCount(2, fn () => $this->client->request('GET', '/reviews'));
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    protected function assertQueryCount(int $expected, callable $callback, string $message = ''): mixed
    {
        $log = $this->service(QueryLog::class);
        $log->start();

        try {
            $result = $callback();
        } finally {
            $queries = $log->stop();
        }

        self::assertCount($expected, $queries, ('' === $message ? '' : $message."\n").\sprintf("Expected %d database queries, got %d:\n%s", $expected, \count($queries), implode("\n", array_map(static fn (string $sql): string => '- '.$sql, $queries))));

        return $result;
    }

    /**
     * Runs the background jobs waiting in the queue, as a worker would (see {@see JobRunner}).
     *
     * @return int How many jobs ran
     */
    protected function runJobs(int $limit = 100): int
    {
        return JobRunner::run(static::getContainer(), $limit);
    }

    protected static function assertExtensionRunning(string $id): void
    {
        $resolved = static::getContainer()->get(ExtensionManager::class)->resolve();

        self::assertTrue($resolved->isRunning($id), \sprintf('Extension "%s" is not running.%s', $id, isset($resolved->broken[$id]) ? ' It is enabled, but '.$resolved->broken[$id].'.' : ''));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    protected function service(string $id): object
    {
        $service = static::getContainer()->get($id);
        \assert($service instanceof $id);

        return $service;
    }

    /**
     * Creates the test database and runs migrations, once per set of extensions and PHPUnit run.
     */
    private function prepareDatabase(): void
    {
        $kernel = static::createKernel();
        $build = $kernel->getCacheDir();

        if (isset(self::$preparedBuilds[$build])) {
            return;
        }

        $application = new Application($kernel);
        $application->setAutoExit(false);

        // The npm packages of the extensions (see "extra.quire.importmap"), for pages that load their scripts.
        foreach ([['doctrine:database:create', '--if-not-exists' => true], ['quire:migrations:migrate', '--allow-no-migration' => true], ['importmap:install']] as $command) {
            $output = new BufferedOutput();
            $input = new ArrayInput(['command' => $command[0], ...\array_slice($command, 1, preserve_keys: true)]);
            $input->setInteractive(false);

            if (0 !== $application->run($input, $output)) {
                throw new \RuntimeException(\sprintf("Preparing the test database failed: \"%s\".\n%s\nCheck that DATABASE_URL points to a PostgreSQL server Quire can create databases on.", $command[0], $output->fetch()));
            }
        }

        $kernel->shutdown();
        self::$preparedBuilds[$build] = true;
    }
}
