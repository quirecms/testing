<?php

declare(strict_types=1);

namespace Quire\Testing\Factory;

use Quire\Content\Command\UpdatePendingRevision;
use Quire\Content\Content;
use Quire\Content\Revision\PendingRevision;
use Quire\Content\Revision\PendingRevisionRepository;
use Quire\Content\Revision\PendingRevisionWriter;
use Quire\Content\Revision\Verdict;
use Quire\User\User;

/**
 * Creates changes to a published item that aren't live yet (a pending revision) for a test, sent
 * for review and reviewed if it needs that:
 *
 *     $changes = $this->changes($post)->by($grace)->title('Hello again')->submitted()->approvedBy($ada)->create();
 *
 * @api
 */
final class ChangesBuilder
{
    private ?string $authorId = null;

    private ?string $message = null;

    private ?string $title = null;

    private ?string $body = null;

    /** @var array<string, mixed> */
    private array $fields = [];

    private bool $submitted = false;

    /** @var list<array{Verdict, ?string, ?string}> */
    private array $reviews = [];

    private ?\DateTimeImmutable $publishAt = null;

    public function __construct(
        private readonly PendingRevisionWriter $writer,
        private readonly PendingRevisionRepository $repository,
        private readonly Content $content,
    ) {
    }

    public function by(User|string $author): self
    {
        $this->authorId = $author instanceof User ? $author->id : $author;

        return $this;
    }

    public function message(string $message): self
    {
        $this->message = $message;

        return $this;
    }

    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function body(string $markdown): self
    {
        $this->body = $markdown;

        return $this;
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function fields(array $fields): self
    {
        $this->fields = [...$this->fields, ...$fields];

        return $this;
    }

    public function submitted(): self
    {
        $this->submitted = true;

        return $this;
    }

    public function approvedBy(User|string $reviewer, ?string $message = null): self
    {
        $this->reviews[] = [Verdict::Approve, $reviewer instanceof User ? $reviewer->id : $reviewer, $message];

        return $this;
    }

    public function changesRequestedBy(User|string $reviewer, string $message): self
    {
        $this->reviews[] = [Verdict::RequestChanges, $reviewer instanceof User ? $reviewer->id : $reviewer, $message];

        return $this;
    }

    public function commentedBy(User|string $reviewer, string $message): self
    {
        $this->reviews[] = [Verdict::Comment, $reviewer instanceof User ? $reviewer->id : $reviewer, $message];

        return $this;
    }

    public function scheduled(\DateTimeImmutable $at): self
    {
        $this->publishAt = $at;

        return $this;
    }

    public function create(): PendingRevision
    {
        $pending = $this->writer->create($this->content->id, $this->authorId, $this->message);
        $update = new UpdatePendingRevision($pending->id, $pending->version);

        if (null !== $this->title) {
            $update->title($this->title);
        }

        if (null !== $this->body) {
            $update->body($this->body);
        }

        if ([] !== $this->fields) {
            $update->fields($this->fields);
        }

        if (!$update->isEmpty()) {
            $pending = $this->writer->update($update);
        }

        if ($this->submitted || [] !== $this->reviews) {
            $pending = $this->writer->submit($pending->id, $pending->version, $this->authorId);
        }

        foreach ($this->reviews as [$verdict, $reviewerId, $message]) {
            $this->writer->review($pending->id, $pending->version, $verdict, $reviewerId, $message);
            $pending = $this->repository->get($pending->id);
        }

        if ($this->publishAt instanceof \DateTimeImmutable) {
            $pending = $this->writer->schedule($pending->id, $pending->version, $this->publishAt, $this->authorId);
        }

        return $this->repository->get($pending->id);
    }
}
