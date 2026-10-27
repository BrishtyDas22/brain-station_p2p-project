<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Reads the least-privilege password for the ISOLATED P2P **development** MySQL
 * instance (port 3308).
 *
 * The password lives in a file that only the current OS user can read. It is
 * never written into .env, never committed, and never printed to stdout, to a
 * log, or to a terminal.
 *
 * This instance is the browser/runtime database for local development. It is
 * completely separate from:
 *
 *   - the shared workstation `MySQL80` service on port 3306 (never used), and
 *   - the automated-test instance on port 3307 (`p2p_platform_test`).
 *
 * The path is passed in by the caller (from `config/database.php`) so that the
 * environment is read through `config()` rather than `env()` — `env()` returns
 * null under `config:cache`, which would silently break the lookup.
 */
final class P2PDevSecret
{
    public const DEFAULT_PASSWORD_FILE = 'C:\\ProgramData\\MySQL\\MySQL80-P2PDev\\secrets\\p2p_dev_password.txt';

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
