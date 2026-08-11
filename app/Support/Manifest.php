<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class Manifest
{
    /** @var array<string, string>|null */
    private static ?array $entries = null;

    public static function asset(string $entry): string
    {
        self::$entries ??= self::load();

        return self::$entries[$entry]
            ?? throw new RuntimeException("Asset [{$entry}] is not in the build manifest. Run `npm run build`.");
    }

    /**
     * @return array<string, string>
     */
    private static function load(): array
    {
        $path = public_path('build/manifest.json');

        if (! is_file($path)) {
            throw new RuntimeException('Build manifest missing. Run `npm run build`.');
        }

        /** @var array<string, string> $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
