<?php

declare(strict_types=1);

namespace Quire\Testing\Tests;

use Quire\Audit\AuditEntry;
use Quire\Content\ContentRepository;
use Quire\Content\Revision\PendingRevisionRepository;
use Quire\Content\Status;
use Quire\Testing\Demo\DemoSite;
use Quire\Testing\ExtensionTestCase;
use Quire\User\User;
use Quire\User\UserRepository;

/**
 * The demo site of the admin's designs: what the mockups show is there, and the same seed and time
 * give the same site.
 */
final class DemoSiteTest extends ExtensionTestCase
{
    #[\Override]
    protected static array $extensions = ['quire-admin'];

    public function testTheSiteOfTheMockups(): void
    {
        $this->emptySite();
        $created = $this->service(DemoSite::class)->seed(now: $this->now());

        self::assertSame(['people' => 15, 'pages' => 6, 'posts' => 54], array_diff_key($created, ['activity' => 0]));
        self::assertGreaterThan(50, $created['activity']);

        $users = $this->service(UserRepository::class);
        $ada = $users->findByUsername('ada');
        self::assertInstanceOf(User::class, $ada);
        self::assertSame(['admin'], $ada->roles);
        self::assertSame(['editor', 'translator'], $users->findByUsername('grace')?->roles);
        self::assertNull($users->findByUsername('lin')?->passwordHash, 'Invited, not joined.');
        $this->signIn($ada);
        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();

        $content = $this->service(ContentRepository::class);
        $scheduled = $content->query('post')->withoutVisibilityScope()->where('status', '=', Status::SCHEDULED)->orderBy('published_at', 'asc')->get();
        self::assertSame(['Final ticket sales', 'Referee list', 'Match day 1', 'Results'], array_map(static fn (\Quire\Content\Content $item): string => $item->title, $scheduled));
        self::assertSame($this->now()->modify('+1 day')->format('Y-m-d').' 09:00', $scheduled[0]->publishedAt?->setTimezone(new \DateTimeZone('Europe/Skopje'))->format('Y-m-d H:i'));
        self::assertGreaterThan(0, $content->query('post')->where('locale', '=', 'de')->count(), 'Posts in German too.');

        $waiting = $this->service(PendingRevisionRepository::class)->awaitingReview();
        self::assertContains('Spring draw: all groups', array_map(static fn (\Quire\Content\Revision\PendingRevision $pending): string => $pending->proposed->title, $waiting));
        self::assertNotNull($this->connection()->fetchOne('SELECT 1 FROM quire.content_pending_revision WHERE publish_problem IS NOT NULL'), 'A scheduled change that failed.');
        self::assertSame(['core.alt-text' => 'error'], $this->settings()->get('quire.content.check_levels'));

        $activity = $this->audited();
        self::assertSame([], array_values(array_filter($activity, static fn (AuditEntry $entry): bool => null === $entry->actorId)), 'Every entry has a person; the seeding\'s own entries are gone.');
        $times = array_map(static fn (AuditEntry $entry): \DateTimeImmutable => $entry->occurredAt, $activity);
        self::assertNotEmpty($times);
        self::assertGreaterThan($this->now()->modify('-32 days'), min($times), 'A month of activity.');
    }

    public function testTheSameSeedAndTimeGiveTheSameSite(): void
    {
        $this->emptySite();
        $titles = [];

        foreach ([1, 2] as $run) {
            $this->connection()->beginTransaction();
            $this->service(DemoSite::class)->seed(now: $this->now());
            $titles[$run] = $this->connection()->fetchFirstColumn("SELECT title || ' ' || status || ' ' || coalesce(to_char(published_at, 'YYYY-MM-DD HH24:MI'), '') FROM quire.content ORDER BY title, published_at");
            $this->connection()->rollBack();
        }

        self::assertCount(60, $titles[1]);
        self::assertSame($titles[1], $titles[2]);
    }

    public function testOnlyAnEmptySite(): void
    {
        $this->emptySite();
        $this->content('post', 'Mine')->create();

        $this->expectExceptionMessageIsOrContains('The site has content already.');
        $this->service(DemoSite::class)->seed();
    }

    /**
     * Tests that commit leave content and users behind; this test's transaction is rolled back.
     */
    private function emptySite(): void
    {
        $this->connection()->executeStatement('DELETE FROM quire.content');
        $this->connection()->executeStatement("DELETE FROM quire.app_user WHERE username IN ('ada', 'noor', 'grace', 'kim', 'lin') OR email LIKE '%@example.org'");
    }

    /**
     * The start of today: before the real time, as what the demo publishes must be, and with what it
     * schedules (from tomorrow) after it, as the content writer checks. A fixed date would soon be past.
     */
    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today', new \DateTimeZone('Europe/Skopje'));
    }
}
