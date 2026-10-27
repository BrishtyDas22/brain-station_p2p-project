<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Reads the least-privilege password for the ISOLATED P2P test MySQL instance.
 *
 * The password lives in a file that only the current OS user can read. It is
 * never written into .env, never committed, and never printed to stdout, to a
 * log, or to a terminal.
 *
 * The path is passed in by the caller (from `config/database.php`) so that the
 * environment is read through `config()` rather than `env()` — `env()` returns
 * null under `config:cache`, which would silently break the lookup.
 */
final class P2PTestSecret
{
    public const DEFAULT_PASSWORD_FILE = 'C:\\ProgramData\\MySQL\\MySQL80-P2PTest\\secrets\\p2p_test_password.txt';

    /**
     * Read the stored password from the given file.
     *
     * Returns null when the secret file is absent, unreadable or empty — the
     * caller decides whether that is fatal. The value is never echoed anywhere.
     */
    public static function password(string $path = self::DEFAULT_PASSWORD_FILE): ?string
    {
        if ($path === '' || ! is_readable($path)) {
            return null;
        }

        $contents = trim((string) file_get_contents($path));

        return $contents === '' ? null : $contents;
    }
}
