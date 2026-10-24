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

See [testing your extension](https://github.com/quirecms/framework/blob/main/docs/extensions.md#testing-your-extension).

License: MIT.
