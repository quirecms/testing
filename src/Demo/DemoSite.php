<?php

declare(strict_types=1);

namespace Quire\Testing\Demo;

use Doctrine\DBAL\Connection;
use Quire\Content\Command\CreateContent;
use Quire\Content\Content;
use Quire\Content\ContentWriter;
use Quire\Content\Revision\PendingRevisionRepository;
use Quire\Content\Revision\PendingRevisionWriter;
use Quire\Content\Status;
use Quire\Permission\Permissions;
use Quire\Permission\SiteRoles;
use Quire\Settings\Settings;
use Quire\Testing\Factory\ChangesBuilder;
use Quire\User\CreateUser;
use Quire\User\Invitations;
use Quire\User\User;
use Quire\User\UserWriter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * "Tournament News", the site of the admin's design mockups: people in every role and state, posts in
 * two languages over two years, this week's schedule, changes waiting for review, a scheduled change
 * that failed, pages, and a month of activity. Deterministic for a seed and a time, so screenshots
 * and browser tests see the same site every run.
 *
 * Only for an empty site: it adds to what's there and never deletes anything but the audit entries it
 * caused itself (they're replaced by a realistic history).
 *
 * @api
 */
final class DemoSite
{
    public const string PASSWORD = 'correct-horse-42';

    private const array TEAMS = ['FC Harbour', 'Northside United', 'Port Rovers', 'Eastfield', 'Millbrook', 'Old Town', 'Riverside', 'Hill Athletic', 'Bay City', 'Westgate', 'Lakeside', 'Kingsbridge', 'Moor Lane', 'Stonehaven', 'Ashford', 'Crown Park'];

    private const array VENUES = ['Harbour Arena', 'Northside Park', 'the old town hall', 'Riverside Ground', 'Bay City Stadium'];

    private const array FIRST = ['Ana', 'Ben', 'Chloe', 'Dmitri', 'Elif', 'Farah', 'Gustav', 'Hana', 'Ivan', 'Jun', 'Kofi', 'Lea', 'Marta', 'Nikos', 'Olga', 'Pavel', 'Rosa', 'Sven', 'Tara', 'Uma', 'Vera', 'Wen', 'Yara', 'Zoran', 'Aiko', 'Bruno', 'Clara', 'Diego', 'Emil', 'Freya'];

    private const array LAST = ['Novak', 'Berg', 'Costa', 'Doyle', 'Eriksen', 'Fischer', 'Garcia', 'Horvat', 'Ivanova', 'Jensen', 'Kowalski', 'Lindqvist', 'Moreau', 'Nakamura', 'Okafor', 'Petrovic', 'Quinn', 'Rossi', 'Schmidt', 'Tanaka'];

    private const array SENTENCES = [
        'The stands were full long before kick-off, and the noise did not stop until the final whistle.',
        'Both coaches named unchanged sides, trusting the players who got them this far.',
        'A late equaliser changed the shape of the group, and nothing is decided yet.',
        'Tickets for the next round go on sale on Monday at 10:00, online and at the ground.',
        'The pitch was in excellent condition despite the rain earlier in the week.',
        'Volunteers will be at every gate to help visitors find their seats.',
        'The referees for the round were announced after a meeting of the cup committee.',
        'Trams 4 and 9 stop right outside the ground and run until midnight on match days.',
        'Supporters are asked to arrive early; gates open ninety minutes before kick-off.',
        'The youth teams play their own tournament on the morning of the final.',
    ];

    private const array SENTENCES_DE = [
        'Die Tribünen waren lange vor dem Anpfiff voll, und es wurde bis zum Schlusspfiff nicht leise.',
        'Beide Trainer setzten auf dieselbe Elf wie in der Vorrunde.',
        'Ein später Ausgleich hat die Gruppe noch einmal offen gemacht.',
        'Karten für die nächste Runde gibt es ab Montag um 10 Uhr, online und am Stadion.',
        'Die Straßenbahnlinien 4 und 9 halten direkt am Stadion und fahren an Spieltagen bis Mitternacht.',
    ];

    private \Random\Randomizer $random;

    private \DateTimeImmutable $now;

    /** @var array<string, User> By username */
    private array $people = [];

    /** @var list<array{Content, \DateTimeImmutable}> Live posts and when they went live */
    private array $live = [];

    /** The demo password, hashed once: hashing it for each of 312 people takes minutes */
    private ?string $passwordHash = null;

    /** @var array{spring: Content, venueUpdate: Content, venueGuide: Content} */
    private array $specials;

    public function __construct(
        private readonly Connection $connection,
        private readonly ContentWriter $content,
        private readonly PendingRevisionWriter $pendingWriter,
        private readonly PendingRevisionRepository $pending,
        private readonly UserWriter $users,
        private readonly Invitations $invitations,
        private readonly SiteRoles $roles,
        private readonly Permissions $permissions,
        private readonly Settings $settings,
        private readonly PasswordHasherFactoryInterface $hashers,
        // Like a worker between messages: drops what services collected, e.g. the profiler's queries.
        #[Autowire(service: 'services_resetter')]
        private readonly ?ResetInterface $resetter = null,
    ) {
    }

    /**
     * @param bool $full About 2,140 posts and 312 people, as in the mockups; otherwise a few dozen, for tests
     *
     * @return array<string, int> What was created, e.g. ["posts" => 2140]
     */
    public function seed(bool $full = false, ?\DateTimeImmutable $now = null, int $seed = 2026): array
    {
        // All or nothing; one commit is also much faster than thousands.
        return $this->connection->transactional(fn (): array => $this->fill($full, $now, $seed));
    }

    /**
     * @return array<string, int>
     */
    private function fill(bool $full, ?\DateTimeImmutable $now, int $seed): array
    {
        if (0 !== (int) $this->scalar($this->connection->fetchOne('SELECT count(*) FROM quire.content'))) {
            throw new \RuntimeException('The site has content already. The demo site is only added to an empty site.');
        }

        $this->random = new \Random\Randomizer(new \Random\Engine\Xoshiro256StarStar($seed));
        $this->now = $now ?? new \DateTimeImmutable();
        $this->people = [];
        $this->live = [];
        $mark = $this->scalar($this->connection->fetchOne('SELECT uuidv7()'));

        $this->siteSettings();
        $this->addPeople($full);
        $pages = $this->addPages();
        $posts = $this->addPosts($full);
        $this->addChangesAndReviews();
        // A month of activity, as people made it, instead of the entries the seeding itself caused.
        $this->connection->executeStatement('DELETE FROM quire.audit_entry WHERE id > ?', [$mark]);
        $activity = $this->addActivity();

        return ['people' => \count($this->people), 'pages' => $pages, 'posts' => $posts, 'activity' => $activity];
    }

    private function siteSettings(): void
    {
        $this->settings->set('site.title', 'Tournament News');
        $this->settings->set('site.description', 'News, draws and results of the spring cup');
        $this->settings->set('site.timezone', 'Europe/Skopje');
    }

    private function addPeople(bool $full): void
    {
        foreach ([['Ada Lovelace', 'ada', ['admin']], ['Noor Haddad', 'noor', ['admin']], ['Grace Hopper', 'grace', ['editor']], ['Kim Ono', 'kim', ['editor']]] as [$name, $username, $roles]) {
            $this->person($name, $username, $roles);
        }

        $this->roles->create('translator', 'Translator', from: 'editor');
        $this->permissions->revoke('translator', ['content.post.publish', 'content.page.publish']);
        $this->people['grace'] = $this->users->setRoles($this->people['grace']->id, ['editor', 'translator']);
        // Editors may write pages, but not publish them (D-20's example).
        $this->permissions->revoke('editor', ['content.page.publish']);

        if ($full) {
            foreach ([['Tomás Reyes', 'tomas'], ['Priya Nair', 'priya'], ['Omar Said', 'omar']] as [$name, $username]) {
                $this->person($name, $username, ['editor']);
            }
        }

        // Invited, not joined yet: no password until they choose one.
        $this->invite($this->person('Lin Tao', 'lin', ['editor'], password: null));

        $members = $full ? 304 : 10;

        for ($i = 0; $i < $members; ++$i) {
            $name = self::FIRST[$i % \count(self::FIRST)].' '.self::LAST[intdiv($i, \count(self::FIRST)) % \count(self::LAST)];
            // 30 × 20 names: unique for the 304 members.
            $username = strtolower((string) preg_replace('/[^a-z]+/i', '-', $name));
            $user = $this->person($name, $username, ['member'], password: $i < 3 ? null : self::PASSWORD);

            if ($i < 3) {
                $this->invite($user);
            } elseif ($i < 5) {
                $this->people[$username] = $this->users->suspend($user->id);
            }
        }

        // When they last signed in; people invited haven't.
        foreach ($this->people as $user) {
            if (null !== $user->passwordHash) {
                $this->connection->executeStatement('UPDATE quire.app_user SET last_login_at = ?, created_at = ? WHERE id = ?', [
                    $this->ago($this->random->getInt(0, 60 * 24 * 20))->format(\DATE_ATOM),
                    $this->ago($this->random->getInt(60 * 24 * 30, 60 * 24 * 700))->format(\DATE_ATOM),
                    $user->id,
                ]);
            }
        }

        // Kim joined today.
        $this->connection->executeStatement('UPDATE quire.app_user SET created_at = ?, last_login_at = ? WHERE id = ?', [$this->ago(25)->format(\DATE_ATOM), $this->ago(20)->format(\DATE_ATOM), $this->people['kim']->id]);
    }

    private function addPages(): int
    {
        $about = $this->publish($this->create('page', 'About', "The spring cup brings sixteen clubs from the region together, every year since 1998.\n\nIt's run by volunteers.", 'ada'), $this->ago(60 * 24 * 600));
        $venues = $this->publish($this->create('page', 'Venues', 'Where the matches are played, and how to get there.', 'ada'), $this->ago(60 * 24 * 500));

        foreach (['Harbour Arena', 'Northside Park'] as $venue) {
            $this->publish($this->create('page', $venue, \sprintf("%s holds 8,000 people.\n\n%s", $venue, self::SENTENCES[7]), 'grace', parent: $venues), $this->ago(60 * 24 * 400));
        }

        $this->publish($this->create('page', 'Tickets', self::SENTENCES[3], 'ada', parent: $about), $this->ago(60 * 24 * 300));
        $this->create('page', 'Rules', 'The rules of the cup, as agreed by the committee. (Being rewritten.)', 'kim');

        return 6;
    }

    private function addPosts(bool $full): int
    {
        // The posts the mockups show, around now.
        $spring = $this->publish($this->create('post', 'Spring draw: all groups', "Sixteen teams, four groups, one evening in the old town hall.\n\n![The stage](https://tournament.example/images/draw-stage.jpg)\n\n## Group A\n\nHosts **FC Harbour** open against Northside on Friday at 18:00.\n\nGroup D: TBC", 'grace', excerpt: 'All 16 teams and their groups, drawn on Friday.'), $this->ago(60 * 48));
        $this->publish($this->create('post', 'Group stage recap', self::SENTENCES[0]."\n\n".self::SENTENCES[2], 'grace', excerpt: 'What happened in the group stage.'), $this->ago(120));
        $this->publish($this->create('post', 'Draw ceremony', self::SENTENCES[6], 'ada', excerpt: 'The ceremony, in pictures.'), $this->ago(300));
        $venueUpdate = $this->publish($this->create('post', 'Venue update', 'Match day 2 moves to '.self::VENUES[1].'.', 'kim', excerpt: 'A change of venue.'), $this->ago(60 * 24 * 3));
        $venueGuide = $this->publish($this->create('post', 'Venue guide', self::SENTENCES[7]."\n\n".self::SENTENCES[8], 'kim', excerpt: 'Getting to the grounds.'), $this->ago(60 * 24 * 6));
        $this->create('post', 'Referee assignments explained', "How referees are chosen for each round.\n\nSee [the rules of 2019](/blog/rules-2019) for the old process.", 'ada');
        $this->create('post', 'How seeding works', self::SENTENCES[6]."\n\n".self::SENTENCES[1], 'grace', excerpt: 'Pots, rankings and the draw.');

        foreach ([[1, '09:00', 'Final ticket sales', "Last chance.\n\n![](https://tournament.example/images/tickets.jpg)"], [3, '12:00', 'Referee list', self::SENTENCES[6]], [4, '18:00', 'Match day 1', self::SENTENCES[8]], [6, '20:00', 'Results', self::SENTENCES[2]]] as [$days, $time, $title, $body]) {
            $item = $this->create('post', $title, $body, 'ada', excerpt: $title.'.');
            $this->content->transition($item->id, $item->version, Status::SCHEDULE, $this->localTime($days, $time));
        }

        $this->specials = ['spring' => $spring, 'venueUpdate' => $venueUpdate, 'venueGuide' => $venueGuide];

        [$live, $drafts, $archived] = $full ? [1916, 210, 3] : [35, 8, 0];
        $authors = $full ? ['grace', 'kim', 'tomas', 'priya', 'omar', 'ada'] : ['grace', 'kim', 'ada'];

        for ($i = 0; $i < $live + $drafts + $archived; ++$i) {
            $german = 0 === $i % 5;
            [$title, $body] = $this->article($i, $german);
            $item = $this->create('post', $title, $body, $authors[$i % \count($authors)], locale: $german ? 'de' : null, excerpt: 0 === $i % 7 ? null : $this->sentence($german));

            if (0 === $i % 200) {
                $this->resetter?->reset();
            }

            if ($i < $live) {
                // Spread over two years, more of them recently.
                $this->publish($item, $this->ago((int) (60 * 24 * 730 * ($i / max(1, $live)) ** 1.6) + 60 * 24));
            } elseif ($i >= $live + $drafts) {
                $item = $this->publish($item, $this->ago(60 * 24 * 600));
                $this->content->transition($item->id, $item->version, Status::ARCHIVE);
            }
        }

        return 11 + $live + $drafts + $archived;
    }

    private function addChangesAndReviews(): void
    {
        $this->settings->set(ContentWriter::APPROVAL_SETTING, true);

        // Grace's changes to the draw, waiting for Ada's review.
        new ChangesBuilder($this->pendingWriter, $this->pending, $this->specials['spring'])
            ->by($this->people['grace'])
            ->message('Added the stream numbers and Group D now that it’s confirmed.')
            ->body("Sixteen teams, four groups, one evening in the old town hall. The draw was streamed live and watched by more than 3,000 people.\n\n![The stage](https://tournament.example/images/draw-stage.jpg)\n\n## Group A\n\nHosts **FC Harbour** open against Northside United on Friday at 18:00, a rematch of last year’s final.\n\nGroup D: Eastfield, Port Rovers, Millbrook, Old Town")
            ->submitted()
            ->commentedBy($this->people['ada'], '“United” is the full name now, right?')
            ->create();

        // Kim's changes to the guide, sent for review.
        new ChangesBuilder($this->pendingWriter, $this->pending, $this->specials['venueGuide'])
            ->by($this->people['kim'])
            ->title('Venue guide: getting there')
            ->submitted()
            ->create();

        // Scheduled changes that couldn't go live: someone edited the live post first.
        $failed = new ChangesBuilder($this->pendingWriter, $this->pending, $this->specials['venueUpdate'])
            ->by($this->people['kim'])
            ->body('Match day 2 moves to '.self::VENUES[1].', kick-off 18:30.')
            ->submitted()
            ->approvedBy($this->people['ada'])
            ->create();
        $this->pendingWriter->failScheduled($failed->id, 'Someone changed the live version after these changes were made: choose what to keep.');

        // Strict for alt text from now on: the scheduled ticket post has something to fix.
        $this->settings->set('quire.content.check_levels', ['core.alt-text' => 'error']);
    }

    private function addActivity(): int
    {
        $editors = array_values(array_filter($this->people, static fn (User $user): bool => \in_array('editor', $user->roles, true) || \in_array('admin', $user->roles, true)));
        $count = 0;

        for ($day = 29; $day >= 0; --$day) {
            foreach ($editors as $editor) {
                if (null !== $editor->passwordHash && $this->random->getInt(0, 3) > 0) {
                    $this->entry($day, $this->random->getInt(7 * 60, 9 * 60), 'user.signed_in', $editor, 'user', $editor->id, $editor->displayName, ['firewall' => 'main'], null, 'quire_login');
                    ++$count;
                }
            }
        }

        foreach ($this->live as [$item, $at]) {
            $minutes = (int) (($this->now->getTimestamp() - $at->getTimestamp()) / 60);

            if ($minutes > 60 * 24 * 30) {
                continue;
            }

            $author = $this->author($item);
            $this->entryAt($at->modify('-40 minutes'), 'content.updated', $author, 'content', $item->id, $item->title, ['type' => $item->type, 'changed' => ['title', 'body']], null, 'quire_admin_content_save', repeats: $this->random->getInt(2, 9));
            $this->entryAt($at, 'content.transitioned', $author, 'content', $item->id, $item->title, ['type' => $item->type, 'transition' => 'publish', 'from' => 'draft', 'to' => 'published'], 'bin/console quire:content:transition '.$item->id.' publish', 'quire_admin_content_save');
            $count += 2;
        }

        $ada = $this->people['ada'];
        $this->entryAt($this->ago(60 * 24 + 120), 'setting.changed', $ada, 'setting', 'site.title', 'Site title', ['from' => 'Cup News', 'to' => 'Tournament News'], "bin/console quire:settings:set site.title 'Tournament News'", 'quire_admin_settings_save');
        $this->entryAt($this->ago(150), 'role.abilities_changed', $this->people['noor'], 'role', 'editor', 'Editor', ['granted' => [], 'revoked' => ['content.page.publish']], 'bin/console quire:permissions:revoke editor content.page.publish', 'quire:permissions:revoke', origin: 'console');
        $this->entryAt($this->ago(25), 'user.created', $ada, 'user', $this->people['kim']->id, 'Kim Ono', ['roles' => ['editor']], 'bin/console quire:user:create kim@tournament.example --name=\'Kim Ono\' --username=kim --no-password --role=editor', 'quire_admin_user_new');

        return $count + 3;
    }

    /**
     * @param array<string, mixed> $details
     */
    private function entry(int $daysAgo, int $minuteOfDay, string $action, User $actor, string $subjectType, string $subjectId, string $label, array $details, ?string $command, string $via): void
    {
        $day = $this->now->setTimezone(new \DateTimeZone('Europe/Skopje'))->modify(\sprintf('-%d days', $daysAgo))->setTime(0, 0)->modify(\sprintf('+%d minutes', $minuteOfDay));

        if ($day < $this->now) {
            $this->entryAt($day, $action, $actor, $subjectType, $subjectId, $label, $details, $command, $via);
        }
    }

    /**
     * @param array<string, mixed> $details
     */
    private function entryAt(\DateTimeImmutable $at, string $action, User $actor, string $subjectType, string $subjectId, string $label, array $details, ?string $command, string $via, int $repeats = 1, string $origin = 'web'): void
    {
        $first = 1 === $repeats ? $at : $at->modify(\sprintf('-%d minutes', 5 * $repeats));
        $this->connection->executeStatement(
            'INSERT INTO quire.audit_entry (occurred_at, last_at, repeats, action, actor_id, actor_name, origin, via, subject_type, subject_id, subject_label, details, command, correlation_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?)',
            [$first->format(\DATE_ATOM), $at->format(\DATE_ATOM), $repeats, $action, $actor->id, $actor->displayName, $origin, $via, $subjectType, $subjectId, $label, json_encode((object) $details, \JSON_THROW_ON_ERROR), $command, $this->uuid()],
        );
    }

    /**
     * @param list<string> $roles
     */
    private function person(string $name, string $username, array $roles, ?string $password = self::PASSWORD): User
    {
        $email = $username.('member' === ($roles[0] ?? '') ? '@example.org' : '@tournament.example');
        $user = $this->users->create(new CreateUser($name, $email, $username, null, $roles));

        if (null !== $password) {
            $this->passwordHash ??= $this->hashers->getPasswordHasher(User::class)->hash($password);
            $this->connection->executeStatement('UPDATE quire.app_user SET password_hash = ? WHERE id = ?', [$this->passwordHash, $user->id]);
            $user = new User($user->id, $user->email, $user->username, $user->displayName, $this->passwordHash, $user->roles, $user->status, $user->attributes, $user->createdAt, $user->updatedAt, $user->lastLoginAt, $user->emailVerifiedAt);
        }

        return $this->people[$username] = $user;
    }

    private function invite(User $user): void
    {
        $this->invitations->invite($user, $this->people['ada']);
    }

    private function create(string $type, string $title, string $body, string $author, ?string $locale = null, ?string $excerpt = null, ?Content $parent = null): Content
    {
        return $this->content->create(new CreateContent($type, $title, $body, locale: $locale, excerpt: $excerpt, parentId: $parent?->id, authorId: $this->people[$author]->id));
    }

    private function publish(Content $item, \DateTimeImmutable $at): Content
    {
        $item = $this->content->transition($item->id, $item->version, Status::PUBLISH, $at);
        // Made a little before it went live.
        $this->connection->executeStatement('UPDATE quire.content SET created_at = ?, updated_at = ? WHERE id = ?', [$at->modify('-2 days')->format(\DATE_ATOM), $at->format(\DATE_ATOM), $item->id]);
        $this->connection->executeStatement('UPDATE quire.content_revision SET created_at = ? WHERE content_id = ?', [$at->format(\DATE_ATOM), $item->id]);
        $this->live[] = [$item, $at];

        return $item;
    }

    /**
     * @return array{string, string}
     */
    private function article(int $i, bool $german): array
    {
        $a = self::TEAMS[$i % 16];
        $b = self::TEAMS[($i * 7 + 3) % 16 === $i % 16 ? ($i + 1) % 16 : ($i * 7 + 3) % 16];
        $round = intdiv($i, 8) + 1;
        $title = $german
            ? [\sprintf('Spieltag %d: %s gegen %s', $round, $a, $b), \sprintf('%s schlägt %s', $a, $b), \sprintf('Vorschau auf Runde %d', $round)][$i % 3]
            : [\sprintf('Match day %d: %s vs %s', $round, $a, $b), \sprintf('%s beat %s %d–%d', $a, $b, $this->random->getInt(1, 4), $this->random->getInt(0, 1)), \sprintf('Round %d preview', $round), \sprintf('Interview: the captain of %s', $a), \sprintf('Ticket news for round %d', $round)][$i % 5];
        $paragraphs = [];

        for ($p = 0, $n = $this->random->getInt(2, 4); $p < $n; ++$p) {
            $paragraphs[] = $this->sentence($german).' '.$this->sentence($german);
        }

        if (0 === $i % 31) {
            $paragraphs[] = '![](https://tournament.example/images/match-'.$i.'.jpg)';
        } elseif (0 === $i % 97) {
            $paragraphs[] = 'More in [last year’s report](/blog/report-'.$i.').';
        }

        return [$title, implode("\n\n", $paragraphs)];
    }

    private function sentence(bool $german): string
    {
        $pool = $german ? self::SENTENCES_DE : self::SENTENCES;

        return $pool[$this->random->getInt(0, \count($pool) - 1)];
    }

    private function author(Content $item): User
    {
        foreach ($this->people as $user) {
            if ($user->id === $item->authorId) {
                return $user;
            }
        }

        return $this->people['ada'];
    }

    private function ago(int $minutes): \DateTimeImmutable
    {
        return $this->now->modify(\sprintf('-%d minutes', $minutes));
    }

    /**
     * A time in the site's time zone, some days from now, e.g. (3, "12:00").
     */
    private function localTime(int $days, string $time): \DateTimeImmutable
    {
        [$hour, $minute] = array_map(intval(...), explode(':', $time));

        return $this->now->setTimezone(new \DateTimeZone('Europe/Skopje'))->modify(\sprintf('+%d days', $days))->setTime($hour, $minute);
    }

    private function uuid(): string
    {
        $bytes = $this->random->getBytes(16);
        $bytes[6] = \chr(\ord($bytes[6]) & 0x0F | 0x40);
        $bytes[8] = \chr(\ord($bytes[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function scalar(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
