<?php

declare(strict_types=1);

namespace Quire\Testing\Tests;

use PHPUnit\Framework\AssertionFailedError;
use Quire\Content\Revision\ReviewState;
use Quire\Content\Status;
use Quire\Testing\ExtensionTestCase;
use Quire\Testing\TestInstallation;

/**
 * The helpers tests of extensions use: signing in, users, content in any state, changes waiting for
 * review, and what the audit log recorded.
 */
final class HelpersTest extends ExtensionTestCase
{
    #[\Override]
    protected static array $extensions = ['quire-fixture-hello'];

    protected static function installedJson(): string
    {
        return TestInstallation::projectDir().'/tests/Fixtures/installed.json';
    }

    public function testSigningInAsARoleOrAUser(): void
    {
        $editor = $this->signIn('editor');
        self::assertSame(['Editor', 'editor', ['editor']], [$editor->displayName, $editor->username, $editor->roles]);
        $this->client->request('GET', '/_fixture/whoami');
        self::assertSame('editor', $this->client->getResponse()->getContent());

        self::assertSame($editor->id, $this->signIn('editor')->id, 'The same user again.');

        $grace = $this->createUser('Grace Hopper', ['member']);
        $this->signIn($grace);
        $this->client->request('GET', '/_fixture/whoami');
        self::assertSame('grace-hopper', $this->client->getResponse()->getContent());

        $this->signOut();
        $this->client->request('GET', '/_fixture/whoami');
        self::assertSame('anonymous', $this->client->getResponse()->getContent());
    }

    public function testContentInEachState(): void
    {
        $author = $this->createUser('Ada', ['editor']);
        $draft = $this->content('post', 'Draft')->body('Hi.')->by($author)->create();
        $live = $this->content('post', 'Live')->published(new \DateTimeImmutable('-2 days'))->create();
        $later = $this->content('post', 'Later')->scheduled(new \DateTimeImmutable('+1 day'))->create();
        $old = $this->content('post', 'Old')->archived()->create();
        $about = $this->content('page', 'About')->create();
        $child = $this->content('page', 'Team')->parent($about)->create();
        $gone = $this->content('page', 'Gone')->trashed()->create();

        self::assertSame([Status::DRAFT, $author->id, 'Hi.'], [$draft->status, $draft->authorId, $draft->body]);
        self::assertSame(Status::PUBLISHED, $live->status);
        self::assertSame(new \DateTimeImmutable('-2 days')->format('Y-m-d'), $live->publishedAt?->format('Y-m-d'));
        self::assertSame([Status::SCHEDULED, Status::ARCHIVED], [$later->status, $old->status]);
        self::assertTrue($gone->isTrashed());
        self::assertSame($about->id, $child->parentId);
    }

    public function testChangesWaitingForReview(): void
    {
        $grace = $this->createUser('Grace', ['editor']);
        $ada = $this->createUser('Ada', ['editor']);
        $post = $this->content('post', 'Live')->published()->create();

        $changes = $this->changes($post)->by($grace)->title('Live, changed')->submitted()->approvedBy($ada)->scheduled(new \DateTimeImmutable('+1 hour'))->create();

        self::assertSame(['Live, changed', ReviewState::Approved, $grace->id], [$changes->proposed->title, $changes->state, $changes->authorId]);
        self::assertNotNull($changes->publishAt);
    }

    public function testWhatTheAuditLogRecordedDuringTheTest(): void
    {
        self::assertSame([], $this->audited(), 'Nothing yet, whatever earlier tests left.');

        $post = $this->content('post', 'Audited')->published()->create();

        self::assertSame(['content.created', 'content.transitioned'], array_map(static fn (\Quire\Audit\AuditEntry $entry): string => $entry->action, $this->audited(subjectId: $post->id)));
        self::assertSame('publish', $this->assertAudited('content.transitioned', $post->id)->details['transition'] ?? null);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIsOrContains('"content.purged" wasn\'t recorded for '.$post->id.'. Recorded: content.created, content.transitioned.');
        $this->assertAudited('content.purged', $post->id);
    }
}
