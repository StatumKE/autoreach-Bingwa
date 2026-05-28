<?php

namespace App\Providers;

use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class SqlitePerformanceServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * Applies additional SQLite PRAGMAs that are not exposed as first-class
     * Laravel config options. These are applied once per connection open, so
     * the overhead is minimal.
     *
     * Rationale for each PRAGMA:
     *
     * - mmap_size=134217728 (128 MB)
     *     Enables memory-mapped I/O so the OS page cache handles reads without
     *     read(2) syscall overhead. Dramatically reduces latency on flash storage.
     *     Safe: the WAL journal guarantees consistency even with mmap enabled.
     *
     * - cache_size=-8192 (8 MB)
     *     Gives SQLite's internal page cache 8 MB of RAM. The default of -2000
     *     (2 MB) is too small for a transactional mobile app with multiple tables.
     *     Negative values are interpreted as kibibytes.
     *
     * - temp_store=MEMORY
     *     Keeps temporary tables and indices in RAM instead of on-disk temp files.
     *     Avoids extra I/O for ORDER BY / GROUP BY operations inside complex queries.
     *
     * References:
     * - https://www.sqlite.org/pragma.html#pragma_mmap_size
     * - https://www.sqlite.org/pragma.html#pragma_cache_size
     * - https://www.sqlite.org/pragma.html#pragma_temp_store
     */
    public function boot(): void
    {
        if (config('database.default') !== 'sqlite') {
            return;
        }

        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            if ($event->connection->getDriverName() !== 'sqlite') {
                return;
            }

            $pdo = $event->connection->getPdo();
            $pdo->exec('PRAGMA mmap_size = 134217728');  // 128 MB memory-mapped I/O
            $pdo->exec('PRAGMA cache_size = -8192');      // 8 MB page cache (in KiB)
            $pdo->exec('PRAGMA temp_store = MEMORY');     // temp tables in RAM
        });
    }
}
