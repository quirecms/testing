<?php

declare(strict_types=1);

namespace Quire\Testing;

use Composer\Autoload\ClassLoader;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Locates the Composer project under test and describes what's installed in it.
 *
 * Composer lists dependencies in vendor/composer/installed.json, but never the project itself. When the
 * project is an extension being developed in its own repository, it must count as installed too, so
 * this writes a combined list: the installed packages plus the project.
 *
 * @internal
 */
final class TestInstallation
{
    /**
     * The Composer project root: the directory holding vendor/.
     */
    public static function projectDir(): string
    {
        return \dirname(self::vendorComposerDir(), 2);
    }

    /**
     * Writes the combined installed.json and returns its path.
     */
    public static function writeInstalledJson(string $targetDir): string
    {
        $filesystem = new Filesystem();
        $source = self::vendorComposerDir();
        $data = json_decode((string) file_get_contents($source.'/installed.json'), true, flags: \JSON_THROW_ON_ERROR);

        // Install paths are relative to installed.json, which now lives elsewhere.
        $toSource = $filesystem->makePathRelative($source, $targetDir);
        $packages = [];

        foreach (\is_array($data) && \is_array($data['packages'] ?? null) ? $data['packages'] : [] as $package) {
            if (\is_array($package) && \is_string($package['install-path'] ?? null)) {
                $package['install-path'] = $toSource.$package['install-path'];
            }

            $packages[] = $package;
        }

        $root = self::rootPackage($filesystem->makePathRelative(self::projectDir(), $targetDir));

        if (null !== $root) {
            $packages[] = $root;
        }

        $path = $targetDir.'/installed.json';
        $filesystem->dumpFile($path, json_encode(['packages' => $packages], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));

        return $path;
    }

    /**
     * @return array<string, mixed>|null The project as an installed.json entry, when it's an extension or theme
     */
    private static function rootPackage(string $relativePath): ?array
    {
        $file = self::projectDir().'/composer.json';
        $json = is_file($file) ? json_decode((string) file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR) : null;

        if (!\is_array($json) || !\in_array($json['type'] ?? null, ['quire-extension', 'quire-theme'], true)) {
            return null;
        }

        return [
            'name' => $json['name'] ?? null,
            'type' => $json['type'],
            'version' => 'dev-local',
            'pretty_version' => 'dev-local',
            'description' => $json['description'] ?? null,
            'extra' => $json['extra'] ?? [],
            'install-path' => rtrim($relativePath, '/'),
        ];
    }

    private static function vendorComposerDir(): string
    {
        $file = new \ReflectionClass(ClassLoader::class)->getFileName();

        if (false === $file) {
            throw new \LogicException('Quire must be installed with Composer.');
        }

        return \dirname($file);
    }
}
