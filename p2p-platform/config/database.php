<?php

use App\Support\P2PDevSecret;
use App\Support\P2PTestSecret;
use Illuminate\Support\Str;
use Pdo\Mysql;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    | The project owns TWO isolated MySQL instances, and the browser runtime
    | must never touch either the shared workstation server or the automated
    | test instance:
    |
    |   - 3308  mysql_p2pdev   the DEVELOPMENT instance. This is the default.
    |   - 3307  mysql_p2ptest  the TEST instance, used only by the test suite.
    |
    | `P2P_DB_CONNECTION` is the explicit override. It wins over everything so
    | a process-level override (`P2P_DB_CONNECTION=mysql_p2ptest php artisan
    | test`) works without touching `.env`.
    |
    | The legacy `mysql` connection (the shared workstation server on 3306) is
    | still defined and still reachable by name, but it is NOT the default:
    | that server cannot create the mandatory ledger triggers, so a schema
    | installed there would only be partially enforced.
    |
    */

    'default' => env('P2P_DB_CONNECTION', 'mysql_p2pdev'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        /*
        |--------------------------------------------------------------------------
        | Isolated P2P Test Instance
        |--------------------------------------------------------------------------
        |
        | Points at the SEPARATE MySQL 8.0 instance that this project owns and
        | runs on port 3307. That instance has its own datadir, its own config
        | (my.ini) and its own test database, so the shared workstation
        | MySQL80 service on port 3306 is never touched by the test suite.
        |
        | The instance runs with log_bin_trust_function_creators=1 so that the
        | mandatory financial-integrity triggers can actually be installed —
        | which is what lets the ledger migrations fail CLOSED rather than
        | skipping enforcement.
        |
        | The password is read from a file that only the current OS user can
        | read. It is never written into this file, into .env, or printed.
        |
        */

        'mysql_p2ptest' => [
            'driver' => 'mysql',
            'url' => null,
            'host' => env('P2PTEST_DB_HOST', '127.0.0.1'),
            'port' => env('P2PTEST_DB_PORT', '3307'),
            'database' => env('P2PTEST_DB_DATABASE', 'p2p_platform_test'),
            'username' => env('P2PTEST_DB_USERNAME', 'p2p_test'),
            'password' => (function (): ?string {
                $fromEnv = env('P2PTEST_DB_PASSWORD');

                if (is_string($fromEnv) && $fromEnv !== '') {
                    return $fromEnv;
                }

                return P2PTestSecret::password(
                    (string) env('P2PTEST_DB_PASSWORD_FILE', P2PTestSecret::DEFAULT_PASSWORD_FILE)
                );
            })(),
            'unix_socket' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        /*
        |----------------------------------------------------------------------
        | The ISOLATED P2P DEVELOPMENT instance (port 3308)
        |----------------------------------------------------------------------
        |
        | This is the browser/runtime database for local development.
        |
        | It exists as a THIRD MySQL instance, separate from both:
        |
        |   - the shared workstation `MySQL80` service on port 3306, which
        |     CANNOT create the ledger's append-only triggers (error 1419:
        |     log_bin=ON with log_bin_trust_function_creators=OFF and no
        |     SUPER), and which this project does not own; and
        |   - the automated-TEST instance on port 3307 (`p2p_platform_test`),
        |     which is reserved for the test suite and is never used for a
        |     browser session.
        |
        | Changing the shared server's global settings to enable triggers
        | there would be a machine-wide change to a service this project
        | does not own, so it is NOT done. Instead this project owns its
        | own instance for development.
        |
        | The password is read from a file that only the current OS user can
        | read. It is never written into this file, into .env, or printed.
        |
        */

        'mysql_p2pdev' => [
            'driver' => 'mysql',
            'url' => null,
            'host' => env('P2PDEV_DB_HOST', '127.0.0.1'),
            'port' => env('P2PDEV_DB_PORT', '3308'),
            'database' => env('P2PDEV_DB_DATABASE', 'p2p_platform_dev'),
            'username' => env('P2PDEV_DB_USERNAME', 'p2p_dev'),
            'password' => (function (): ?string {
                $fromEnv = env('P2PDEV_DB_PASSWORD');

                if (is_string($fromEnv) && $fromEnv !== '') {
                    return $fromEnv;
                }

                return P2PDevSecret::password(
                    (string) env('P2PDEV_DB_PASSWORD_FILE', P2PDevSecret::DEFAULT_PASSWORD_FILE)
                );
            })(),
            'unix_socket' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
