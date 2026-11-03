<?php

declare(strict_types=1);

namespace Quire\Testing\Demo;

/**
 * When the demo site's posts went live, from when it's made. The posts list groups by month, so the
 * list must have the same shape whatever the day of the month the site is made on (its screenshots
 * are compared with approved ones): the newest posts stay in this month, the others are on fixed days
 * of the months before it. Likewise the board's "Live this week" (the last 7 days): the newest posts
 * are always in it, the others never.
 */
final readonly class DemoCalendar
{
    /** The oldest of the newest posts is 6 days old */
    private const int WEEK = 60 * 60 * 24 * 7;

    /** The start of the month $now is in, in the site's time zone */
    private \DateTimeImmutable $monthStart;

    public function __construct(
        private \DateTimeImmutable $now,
        \DateTimeZone $timezone,
    ) {
        $this->monthStart = $now->setTimezone($timezone)->modify('first day of this month')->setTime(0, 0);
    }

    /**
     * Some minutes ago, but in this month. Early in the month, the times are squeezed into what has
     * passed of it, in the same order; in seconds, so that they still don't share a time.
     */
    public function recent(int $minutes): \DateTimeImmutable
    {
        $passed = $this->now->getTimestamp() - $this->monthStart->getTimestamp();

        if ($passed >= self::WEEK) {
            return $this->now->modify(\sprintf('-%d minutes', $minutes));
        }

        return $this->now->modify(\sprintf('-%d seconds', intdiv($minutes * 60 * $passed, self::WEEK)));
    }

    /**
     * Post $i of $count, spread over the two years before this month, more of them recently.
     */
    public function spread(int $i, int $count): \DateTimeImmutable
    {
        return $this->monthsAgo(1 + (int) (23 * ($i / max(1, $count)) ** 1.6), $i);
    }

    /**
     * A day of a month before this one, the same for the same post ($i) whatever today's date is: the
     * 1st to the 21st, so more than 7 days before this month (February has 28).
     */
    public function monthsAgo(int $months, int $i): \DateTimeImmutable
    {
        return $this->monthStart->modify(\sprintf('-%d months', $months))->modify(\sprintf('+%d days', $i * 11 % 21))->setTime(9 + $i % 9, $i * 13 % 60);
    }
}
