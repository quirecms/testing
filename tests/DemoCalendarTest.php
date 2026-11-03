<?php

declare(strict_types=1);

namespace Quire\Testing\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quire\Testing\Demo\DemoCalendar;

final class DemoCalendarTest extends TestCase
{
    /**
     * The 1st just after midnight, the middle of a month, the last evening of one, the 1st of a March
     * after February's 28 days, and a month that starts at a change of the clocks.
     *
     * @return iterable<string, array{string}>
     */
    public static function days(): iterable
    {
        yield 'the 1st, just after midnight' => ['2026-11-01 00:30'];
        yield 'the 1st, a minute after midnight' => ['2026-11-01 00:01'];
        yield 'the 3rd' => ['2026-11-03 09:00'];
        yield 'the middle of a month' => ['2026-11-15 12:00'];
        yield 'the last evening of a month' => ['2026-12-31 22:00'];
        yield 'the 1st of a March' => ['2027-03-01 08:00'];
        yield 'the clocks change in the month' => ['2027-03-29 10:00'];
    }

    #[DataProvider('days')]
    public function testThePostsListHasTheSameShapeWhateverTheDay(string $day): void
    {
        self::assertSame($this->shape('2026-11-15 12:00'), $this->shape($day));
    }

    #[DataProvider('days')]
    public function testNothingIsInTheFuture(string $day): void
    {
        $now = new \DateTimeImmutable($day, new \DateTimeZone('Europe/Skopje'));
        $calendar = new DemoCalendar($now, new \DateTimeZone('Europe/Skopje'));

        foreach ([120, 300, 60 * 48, 60 * 24 * 3, 60 * 24 * 6] as $minutes) {
            self::assertLessThanOrEqual($now, $calendar->recent($minutes));
        }

        self::assertLessThan($now, $calendar->monthsAgo(1, 27));
    }

    #[DataProvider('days')]
    public function testLiveThisWeekHasTheNewestPostsOnly(string $day): void
    {
        $now = new \DateTimeImmutable($day, new \DateTimeZone('Europe/Skopje'));
        $calendar = new DemoCalendar($now, new \DateTimeZone('Europe/Skopje'));
        $week = $now->modify('-7 days');

        self::assertGreaterThan($week, $calendar->recent(60 * 24 * 6), 'The oldest of the newest.');

        for ($i = 0; $i < 1916; ++$i) {
            self::assertLessThan($week, $calendar->spread($i, 1916), 'Post '.$i);
        }
    }

    public function testLaterInTheMonthTheNewestAreAsOldAsTheySay(): void
    {
        $now = new \DateTimeImmutable('2026-11-20 12:00', new \DateTimeZone('Europe/Skopje'));

        self::assertEquals($now->modify('-2 days'), new DemoCalendar($now, new \DateTimeZone('Europe/Skopje'))->recent(60 * 48));
    }

    /**
     * The posts as the list shows them, newest first, each with its month counted back from this one:
     * the list's shape, without the months' names. As DemoSite dates them.
     *
     * @return list<string>
     */
    private function shape(string $day): array
    {
        $timezone = new \DateTimeZone('Europe/Skopje');
        $now = new \DateTimeImmutable($day, $timezone);
        $calendar = new DemoCalendar($now, $timezone);
        $posts = [];

        foreach ([120, 300, 60 * 48, 60 * 24 * 3, 60 * 24 * 6] as $n => $minutes) {
            $posts['recent '.$n] = $calendar->recent($minutes);
        }

        for ($i = 0; $i < 35; ++$i) {
            $posts['post '.$i] = $calendar->spread($i, 35);
        }

        uasort($posts, static fn (\DateTimeImmutable $a, \DateTimeImmutable $b): int => $b <=> $a);
        $month = static fn (\DateTimeImmutable $at): int => (int) $at->setTimezone($timezone)->format('Y') * 12 + (int) $at->setTimezone($timezone)->format('n');

        return array_map(static fn (string $post, \DateTimeImmutable $at): string => $post.' '.($month($now) - $month($at)), array_keys($posts), array_values($posts));
    }
}
