<?php

declare(strict_types=1);

namespace Quire\Testing\Tests;

use PHPUnit\Framework\TestCase;
use Quire\Extension\InstalledExtensions;
use Quire\Testing\TestInstallation;

final class TestInstallationTest extends TestCase
{
    public function testProjectDirIsTheComposerRoot(): void
    {
        self::assertFileExists(TestInstallation::projectDir().'/composer.json');
        self::assertDirectoryExists(TestInstallation::projectDir().'/vendor');
    }

    public function testWrittenInstallPathsStillResolve(): void
    {
        $targetDir = TestInstallation::projectDir().'/var/cache/quire-test/installation-test';
        $path = TestInstallation::writeInstalledJson($targetDir);

        $data = json_decode((string) file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['packages']);

        $core = array_first(array_filter($data['packages'], static fn (mixed $p): bool => \is_array($p) && 'quire/core' === ($p['name'] ?? null))) ?? null;
        self::assertIsArray($core);
        self::assertIsString($core['install-path']);
        self::assertSame(realpath(TestInstallation::projectDir().'/packages/core'), realpath($targetDir.'/'.$core['install-path']));

        // The monorepo itself is a project, not an extension, so nothing is added for it.
        self::assertSame([], new InstalledExtensions($path)->all());
    }
}
