# Quire testing

Tests for [Quire](https://quirecms.com) extensions and themes: each test class runs a small Quire application with only the extensions it lists, against a test database, and every test is rolled back afterwards.

```bash
composer require --dev quire/testing
```

```php
final class ReviewsTest extends Quire\Testing\ExtensionTestCase
{
    protected static array $extensions = ['acme-reviews'];

    public function testListsReviews(): void
    {
        $this->client->request('GET', '/reviews');

        self::assertResponseIsSuccessful();
    }
}
```

Helpers sign people in (`signIn('editor')`), create content in any state (`content('post', 'Hello')->published()->create()`) and changes waiting for review (`changes($post)->submitted()->create()`), and show what the audit log recorded during a test. `AdminTestCase` adds helpers for admin pages, and `quire:demo:seed` fills a site with a realistic demo, "Tournament News".

See [testing your extension](https://github.com/quirecms/framework/blob/main/docs/extensions.md#testing-your-extension).

License: MIT.
