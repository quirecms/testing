<?php

declare(strict_types=1);

namespace Quire\Testing\Factory;

use Quire\Content\Command\CreateContent;
use Quire\Content\Content;
use Quire\Content\ContentWriter;
use Quire\Content\Status;
use Quire\User\User;

/**
 * Creates a content item for a test, in the state it needs, through the same writer as the admin and
 * the command line, so events, revisions and checks apply:
 *
 *     $post = $this->content('post', 'Hello')->body('Hi *there*.')->by($editor)->published()->create();
 *     $page = $this->content('page', 'Team')->parent($about)->scheduled(new \DateTimeImmutable('+1 day'))->create();
 *
 * @api
 */
final class ContentBuilder
{
    private string $body = '';

    private ?string $excerpt = null;

    private ?string $slug = null;

    private ?string $locale = null;

    /** @var array<string, mixed> */
    private array $fields = [];

    private ?string $parentId = null;

    private ?string $authorId = null;

    private ?string $translationOf = null;

    private string $state = Status::DRAFT;

    private ?\DateTimeImmutable $at = null;

    private bool $trashed = false;

    public function __construct(
        private readonly ContentWriter $writer,
        private readonly string $type,
        private readonly string $title,
    ) {
    }

    public function body(string $markdown): self
    {
        $this->body = $markdown;

        return $this;
    }

    public function excerpt(?string $excerpt): self
    {
        $this->excerpt = $excerpt;

        return $this;
    }

    public function slug(string $slug): self
    {
        $this->slug = $slug;

        return $this;
    }

    public function locale(string $locale): self
    {
        $this->locale = $locale;

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

    public function parent(Content|string $parent): self
    {
        $this->parentId = $parent instanceof Content ? $parent->id : $parent;

        return $this;
    }

    public function by(User|string $author): self
    {
        $this->authorId = $author instanceof User ? $author->id : $author;

        return $this;
    }

    public function translationOf(Content|string $item): self
    {
        $this->translationOf = $item instanceof Content ? $item->id : $item;

        return $this;
    }

    /**
     * @param \DateTimeImmutable|null $at When it was published; now by default
     */
    public function published(?\DateTimeImmutable $at = null): self
    {
        [$this->state, $this->at] = [Status::PUBLISHED, $at];

        return $this;
    }

    public function scheduled(\DateTimeImmutable $at): self
    {
        [$this->state, $this->at] = [Status::SCHEDULED, $at];

        return $this;
    }

    public function archived(): self
    {
        $this->state = Status::ARCHIVED;

        return $this;
    }

    public function trashed(): self
    {
        $this->trashed = true;

        return $this;
    }

    public function create(): Content
    {
        $item = $this->writer->create(new CreateContent(
            type: $this->type,
            title: $this->title,
            body: $this->body,
            slug: $this->slug,
            locale: $this->locale,
            excerpt: $this->excerpt,
            fields: $this->fields,
            parentId: $this->parentId,
            authorId: $this->authorId,
            translationOf: $this->translationOf,
        ));

        $item = match ($this->state) {
            Status::PUBLISHED => $this->writer->transition($item->id, $item->version, Status::PUBLISH, $this->at),
            Status::SCHEDULED => $this->writer->transition($item->id, $item->version, Status::SCHEDULE, $this->at),
            Status::ARCHIVED => $this->archive($item),
            default => $item,
        };

        return $this->trashed ? $this->writer->trash($item->id, $item->version) : $item;
    }

    private function archive(Content $item): Content
    {
        $item = $this->writer->transition($item->id, $item->version, Status::PUBLISH);

        return $this->writer->transition($item->id, $item->version, Status::ARCHIVE);
    }
}
