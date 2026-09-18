<?php

namespace Tests\Unit;

use App\Services\DatabaseBackup;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Where the database is, and whether a copy of it can be taken.
 *
 * A unit test with no `RefreshDatabase`, and that is not an oversight: this
 * service reads config and asks the operating system, and it touches no table
 * at all. It has to be tested that way, because these cases **switch
 * `database.default` to pgsql** — and with the suite's connection swapped
 * underneath it, anything that then touched a table would go looking for a
 * PostgreSQL database literally named ":memory:". The route, the gate and the
 * screen are covered in `Tests\Feature\Settings\DatabaseBackupTest`, which
 * needs the database and therefore never moves it.
 */
class DatabaseBackupTest extends TestCase
{
    // --- Where the database is --------------------------------------------

    /** The loopback address, and nothing else, is "this machine". */
    public function test_a_loopback_host_reads_as_local(): void
    {
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => '127.0.0.1',
        ]);

        $this->assertSame('local', $this->backup()->describe()['placement']);
    }

    /**
     * Anything that is not this machine is somebody else's server, whether it
     * is a cloud provider, a container on the same network, or the box under
     * the desk. Resolving it any more finely would be guessing at a hosting
     * arrangement from an IP address.
     */
    public function test_a_host_that_is_not_this_machine_reads_as_remote(): void
    {
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => 'db.hostforge.internal',
        ]);

        $described = $this->backup()->describe();

        $this->assertSame('remote', $described['placement']);
        $this->assertSame('db.hostforge.internal', $described['host']);
    }

    // --- Whether a backup can be taken at all -----------------------------

    /**
     * There is no `mysqldump`-shaped branch pretending to support a driver
     * nobody runs this on: a backup path that has never been run is not a
     * backup path. The reason names the driver, so the reader knows which
     * decision to revisit.
     */
    public function test_an_unsupported_driver_says_which_driver_it_is(): void
    {
        config(['database.default' => 'sqlite']);

        $reason = $this->backup()->unavailableReason();

        $this->assertNotNull($reason);
        $this->assertStringContainsString('sqlite', $reason);
    }

    /**
     * Existing is not the same as working, and this is the failure that
     * prompted the check.
     *
     * The first `pg_dump` on this machine's PATH is PostgreSQL 16's, which
     * dies at process start with `0xC0000135` on a missing CRT DLL. The screen
     * reported the backup as available and the button failed the moment it was
     * pressed — worse than a disabled one, because a control that lies about
     * being usable costs the reader their trust in the rest of the screen.
     */
    public function test_a_binary_that_does_not_run_is_not_offered(): void
    {
        config([
            'database.default' => 'pgsql',
            'database.dump_binary' => __DIR__.'/does-not-exist/pg_dump.exe',
        ]);

        $backup = $this->backup();

        $this->assertNull($backup->binary());
        $this->assertFalse($backup->describe()['backup_available']);
    }

    /**
     * An explicitly configured path is the *only* candidate, not the first of
     * several. Falling through to a different binary would leave somebody
     * reading a config value that is quietly doing nothing — and the reason
     * they set it is that the automatic choice was wrong.
     */
    public function test_a_configured_path_that_fails_names_itself_rather_than_falling_through(): void
    {
        config([
            'database.default' => 'pgsql',
            'database.dump_binary' => 'C:/nowhere/pg_dump.exe',
        ]);

        $reason = $this->backup()->unavailableReason();

        $this->assertStringContainsString('DB_DUMP_BINARY', $reason);
        $this->assertStringContainsString('C:/nowhere/pg_dump.exe', $reason);
    }

    /** Two dumps of one database are told apart by the timestamp on the file. */
    public function test_the_filename_carries_the_database_and_the_moment(): void
    {
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => 'primepower_hris',
        ]);

        $this->assertMatchesRegularExpression(
            '/^primepower_hris-\d{4}-\d{2}-\d{2}-\d{6}\.dump$/',
            $this->backup()->filename(),
        );
    }

    /**
     * A candidate is accepted because it *starts*, not because a file is
     * there — and resolved once, so opening the screen does not spawn a
     * process per candidate on every render.
     *
     * PHP's own binary stands in for `pg_dump` here, which is less odd than it
     * looks: the only question `runs()` asks is whether the executable
     * answers `--version` without dying, and that is a question about the
     * operating system rather than about PostgreSQL. It is also the one
     * executable a test can be certain exists on whatever machine the suite is
     * running on.
     */
    public function test_a_working_binary_is_accepted_and_then_remembered(): void
    {
        config([
            'database.default' => 'pgsql',
            'database.dump_binary' => PHP_BINARY,
        ]);

        $this->assertSame(PHP_BINARY, $this->backup()->binary());

        // Pointed at nothing, without clearing the cache: still the first
        // answer, which is what proves it was not looked up a second time.
        config(['database.dump_binary' => 'C:/nowhere/pg_dump.exe']);

        $this->assertSame(PHP_BINARY, $this->backup()->binary());
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The discovered binary is cached for ten minutes, so a case that
        // configures one would otherwise read whatever the previous case
        // resolved.
        Cache::flush();
    }

    private function backup(): DatabaseBackup
    {
        return app(DatabaseBackup::class);
    }
}
