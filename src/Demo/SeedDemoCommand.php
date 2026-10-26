<?php

declare(strict_types=1);

namespace Quire\Testing\Demo;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:demo:seed',
    description: 'Fill an empty site with "Tournament News", the demo site of the admin\'s designs (for development and tests)',
    help: <<<'HELP'
        <info>%command.full_name%</info>          a few dozen posts and people, in seconds
        <info>%command.full_name% --full</info>   about 2,140 posts and 312 people, as in the mockups

        People sign in with their username, e.g. "ada" (admin), "grace" or "kim" (editors), and the
        password "correct-horse-42". The same seed and day give the same site.
        HELP,
)]
final readonly class SeedDemoCommand
{
    public function __construct(
        private DemoSite $site,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'About 2,140 posts and 312 people instead of a few dozen')]
        bool $full = false,
        #[Option(description: 'Another seed gives other posts and dates')]
        int $seed = 2026,
    ): int {
        try {
            $created = $this->site->seed($full, seed: $seed);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Added %d people, %d pages, %d posts and %d entries of activity. Sign in as "ada" with the password "%s".', $created['people'], $created['pages'], $created['posts'], $created['activity'], DemoSite::PASSWORD));

        return Command::SUCCESS;
    }
}
