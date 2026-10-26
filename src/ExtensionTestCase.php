<?php

declare(strict_types=1);

namespace Quire\Testing;

use Doctrine\DBAL\Connection;
use Quire\Audit\AuditEntry;
use Quire\Audit\AuditLog;
use Quire\Content\Content;
use Quire\Content\ContentWriter;
use Quire\Content\Revision\PendingRevisionRepository;
use Quire\Content\Revision\PendingRevisionWriter;
use Quire\Extension\ExtensionManager;
use Quire\Settings\Settings;
use Quire\Testing\Database\QueryLog;
use Quire\Testing\Factory\ChangesBuilder;
use Quire\Testing\Factory\ContentBuilder;
use Quire\User\CreateUser;
use Quire\User\User;
use Quire\User\UserRepository;
use Quire\User\UserWriter;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
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
    /** The password of the users that {@see signIn()} and {@see CreateUser()} create */
    public const string PASSWORD = 'correct-horse-42';

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

    /** Audit entries with a greater ID were recorded during this test */
    private string $auditMark = '';

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
        // IDs are time-ordered UUIDs: entries of earlier tests that committed have smaller ones.
        $mark = $this->connection()->fetchOne('SELECT uuidv7()');
        $this->auditMark = \is_string($mark) ? $mark : '';
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
     * Signs the browser in, as the given user or, with a role's name, as a user named after it with that
     * role ("editor" signs in as "Editor"), created when there's none. Others signed in before are
     * signed out.
     *
     *     $this->signIn('editor');
     *     $this->signIn($grace);
     */
    protected function signIn(User|string $who = 'admin'): User
    {
        $this->signOut();

        if ($who instanceof User) {
            $this->client->loginUser($who, 'main');

            return $who;
        }

        $user = $this->service(UserRepository::class)->findByUsername($who) ?? $this->createUser(ucfirst($who), [$who], username: $who);
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->filter('input[name="_username"]')->closest('form')?->form(['_username' => $user->username, '_password' => self::PASSWORD]) ?? throw new \LogicException('The sign-in page has no sign-in form.'));

        return $user;
    }

    protected function signOut(): void
    {
        $this->client->getCookieJar()->clear();
    }

    /**
     * A CSRF token for the browser's session, to post to a form that no page shows, e.g.
     * `$this->client->request('POST', $url, ['_token' => $this->csrfToken('quire_view_as')])`.
     */
    protected function csrfToken(string $id): string
    {
        $factory = static::getContainer()->get('session.factory');
        $session = $factory->createSession();
        $cookie = $this->client->getCookieJar()->get($session->getName());

        if ($cookie instanceof Cookie) {
            $session->setId($cookie->getValue());
        }

        $session->start();
        $token = bin2hex(random_bytes(16));
        $session->set('_csrf/'.$id, $token);
        $session->save();

        if (!$cookie instanceof Cookie) {
            $this->client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));
        }

        return $token;
    }

    /**
     * @param list<string> $roles
     * @param string|null  $password Null for a user without one, e.g. who was invited
     */
    protected function createUser(string $name, array $roles = [], ?string $username = null, ?string $email = null, ?string $password = self::PASSWORD): User
    {
        $username ??= strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $name));

        return $this->service(UserWriter::class)->create(new CreateUser($name, $email ?? $username.'@example.com', $username, $password, $roles));
    }

    /**
     * A content item to create, e.g. `$this->content('post', 'Hello')->published()->create()`.
     */
    protected function content(string $type, string $title): ContentBuilder
    {
        return new ContentBuilder($this->service(ContentWriter::class), $type, $title);
    }

    /**
     * Changes to a published item that aren't live yet, e.g. `$this->changes($post)->title('New')->submitted()->create()`.
     */
    protected function changes(Content $item): ChangesBuilder
    {
        return new ChangesBuilder($this->service(PendingRevisionWriter::class), $this->service(PendingRevisionRepository::class), $item);
    }

    /**
     * What the audit log recorded during this test, oldest first.
     *
     * @return list<AuditEntry>
     */
    protected function audited(?string $action = null, ?string $subjectId = null): array
    {
        $ids = $this->connection()->fetchFirstColumn(
            'SELECT id FROM quire.audit_entry WHERE id > ? AND (?::text IS NULL OR action = ?) AND (?::text IS NULL OR subject_id = ?) ORDER BY id',
            [$this->auditMark, $action, $action, $subjectId, $subjectId],
        );
        $log = $this->service(AuditLog::class);

        return array_values(array_filter(array_map(static fn (mixed $id): ?AuditEntry => \is_string($id) ? $log->get($id) : null, $ids)));
    }

    /**
     * Asserts that the action was recorded during this test, and returns its latest entry.
     */
    protected function assertAudited(string $action, ?string $subjectId = null, string $message = ''): AuditEntry
    {
        $entries = $this->audited($action, $subjectId);
        $recorded = array_map(static fn (AuditEntry $entry): string => $entry->action, $this->audited());
        self::assertNotEmpty($entries, ('' === $message ? '' : $message."\n").\sprintf('"%s" wasn\'t recorded%s. Recorded: %s.', $action, null === $subjectId ? '' : ' for '.$subjectId, [] === $recorded ? 'nothing' : implode(', ', $recorded)));

        return $entries[\count($entries) - 1];
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
