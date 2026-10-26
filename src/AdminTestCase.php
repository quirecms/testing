<?php

declare(strict_types=1);

namespace Quire\Testing;

use Symfony\Component\DomCrawler\Crawler;

/**
 * Base class for tests of admin pages, e.g. those an extension adds: quire/admin is enabled, and
 * helpers read what the admin shows after an action.
 *
 *     final class ReviewsAdminTest extends AdminTestCase
 *     {
 *         protected static array $extensions = ['quire-admin', 'acme-reviews'];
 *
 *         public function testModeratorsSeeTheQueue(): void
 *         {
 *             $this->signIn('editor');
 *             $this->client->request('GET', '/admin/reviews');
 *
 *             self::assertResponseIsSuccessful();
 *         }
 *     }
 *
 * @api
 */
abstract class AdminTestCase extends ExtensionTestCase
{
    #[\Override]
    protected static array $extensions = ['quire-admin'];

    /**
     * The CSRF token of the first form on the page with one, for requests a test builds by hand.
     */
    protected static function formToken(Crawler $page): string
    {
        $token = $page->filter('main input[name="_token"]');
        self::assertGreaterThan(0, $token->count(), 'The page has no form with a CSRF token.');

        return (string) $token->first()->attr('value');
    }

    /**
     * Asserts the message the admin shows after an action, e.g. ('success', 'Saved.').
     */
    protected static function assertNotice(string $type, string $text): void
    {
        self::assertSelectorTextContains('.q-notice--'.$type, $text);
    }

    /**
     * Asserts that the page shows the console command that does what was just done.
     */
    protected static function assertCommandShown(Crawler $page, string $command): void
    {
        $shown = $page->filter('.q-command__text')->each(static fn (Crawler $node): string => $node->text(normalizeWhitespace: false));
        self::assertContains($command, array_merge(...array_map(static fn (string $text): array => explode("\n", $text), $shown)), [] === $shown ? 'No command is shown.' : 'Shown: '.implode(' | ', $shown));
    }
}
