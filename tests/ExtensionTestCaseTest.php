<?php

declare(strict_types=1);

namespace Quire\Testing\Tests;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Depends;
use Quire\Testing\ExtensionTestCase;
use Quire\Testing\TestInstallation;

/**
 * Tests ExtensionTestCase and TestKernel with the fixture extension in tests/Fixtures/extensions/hello.
 */
final class ExtensionTestCaseTest extends ExtensionTestCase
{
    #[\Override]
    protected static array $extensions = ['quire-fixture-hello'];

    protected static function installedJson(): string
    {
        return TestInstallation::projectDir().'/tests/Fixtures/installed.json';
    }

    public function testRunsTheListedExtensions(): void
    {
        self::assertExtensionRunning('quire-fixture-hello');

        $this->client->request('GET', '/_fixture/hello/Ada');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Hello, Ada!');
    }

    public function testMigratesTheTestDatabase(): void
    {
        self::assertTrue($this->connection()->createSchemaManager()->tablesExist(['quire_fixture_hello_visit']));
    }

    public function testSettingsApplyToRequests(): void
    {
        $this->setSetting('quire-fixture-hello.greeting', 'Howdy');
        $this->connection()->insert('quire_fixture_hello_visit', ['name' => 'Ada']);

        $this->client->request('GET', '/_fixture/hello/Ada');
        self::assertSelectorTextSame('h1', 'Howdy, Ada!');

        $this->client->request('GET', '/_fixture/hello/Grace');
        self::assertSelectorTextSame('h1', 'Howdy, Grace!', 'The same transaction is kept across requests.');
    }

    #[Depends('testSettingsApplyToRequests')]
    public function testEachTestStartsWithACleanDatabase(): void
    {
        self::assertSame('Hello', $this->settings()->getString('quire-fixture-hello.greeting'));
        self::assertEquals(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM quire_fixture_hello_visit'));
    }

    public function testCoreRoutesAreAvailable(): void
    {
        $this->client->request('GET', '/_quire/health');

        self::assertResponseIsSuccessful();
    }

    public function testCountsDatabaseQueries(): void
    {
        // No posts: the page query only; relations aren't loaded for an empty page.
        $this->assertQueryCount(1, fn (): \Symfony\Component\DomCrawler\Crawler => $this->client->request('GET', '/_fixture/posts'));
        self::assertSame(3, $this->assertQueryCount(1, fn (): mixed => $this->connection()->fetchOne('SELECT 3')), 'The callback\'s result is returned.');

        try {
            $this->assertQueryCount(0, fn (): mixed => $this->connection()->fetchOne('SELECT 1'));
            self::fail('A wrong count fails.');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString("Expected 0 database queries, got 1:\n- SELECT 1", $e->getMessage());
        }
    }
}
