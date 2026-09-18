<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Takes a copy of the whole database, and says where it lives.
 *
 * Two questions this answers that nothing else did. The first is **where the
 * records actually are** — the Data & Backup screen printed the connection
 * name and the database name and stopped there, which tells the reader nothing
 * about whether they are looking at the copy on their laptop or the one every
 * employee's payslip is filed in. On a system that is developed locally and
 * deployed to a container, that is the difference between a dump somebody can
 * throw away and a dump that is the company's payroll.
 *
 * The second is **whether a backup can be taken at all**. Nothing here ever
 * took one: the screen said "back up with your database server's own dump
 * tooling", which is true and is not a feature — it is a sentence pointing at
 * a terminal, and there is no terminal on the deployment host. That is the
 * same gap `hris:set-admin-password` exists to close for a lost password.
 *
 * **`pg_dump` is detected rather than assumed, and a missing one is reported
 * rather than failed over.** It is not part of PHP and need not be installed
 * beside it — this very machine has a PostgreSQL 16 binary that dies on a
 * missing CRT DLL while the 18 one works. So `unavailableReason()` answers in
 * words the screen can print, and the button is drawn disabled with the reason
 * beside it. A button that fails when pressed is how somebody stops believing
 * the screen; a button that says why it cannot work is a fact.
 *
 * **It never writes the dump into the repository or the public disk.** It goes
 * to a temporary file, is streamed to the person who asked, and is deleted.
 * A database dump sitting in `storage/app` is every government identifier and
 * bank account in the company waiting for the first misconfigured document
 * root — which `docs/DEPLOYMENT.md` already warns about for `.env`.
 */
class DatabaseBackup
{
    /**
     * Only PostgreSQL is supported, deliberately.
     *
     * SQLite is the test connection and MySQL is not what this runs on, so a
     * `mysqldump` branch would be a code path nobody exercises pretending to
     * be a supported one — and a backup path that has never been run is not a
     * backup path. The screen says so rather than offering a button that
     * produces an empty file.
     */
    public const SUPPORTED_DRIVERS = ['pgsql'];

    /**
     * Where the discovered binary is remembered.
     *
     * Ten minutes: long enough that opening the screen does not spawn three
     * processes every time, short enough that somebody who has just installed
     * PostgreSQL and reloaded sees the button come alive.
     */
    private const BINARY_CACHE_KEY = 'database.dump_binary.resolved';

    private const BINARY_CACHE_TTL = 600;

    /**
     * Where the database is, in the words the reader's question is in.
     *
     * "Local" is the loopback address and nothing else: a host that is not
     * this machine is somebody else's server whether it is a cloud provider,
     * a container on the same network, or the box under the desk. Resolving
     * that distinction any more finely would be guessing at a hosting
     * arrangement from an IP address.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}") ?? [];

        $host = $config['host'] ?? null;
        $isLocal = $host === null || in_array($host, ['127.0.0.1', 'localhost', '::1'], true);

        return [
            'driver' => $connection,
            'name' => $config['database'] ?? null,
            'host' => $host,
            'port' => $config['port'] ?? null,
            'is_sqlite' => $connection === 'sqlite',

            // The answer to "local or cloud", which is the thing somebody
            // opening this screen on a deployment most needs to be sure of
            // before they press anything.
            'placement' => $isLocal ? 'local' : 'remote',

            'supported' => in_array($connection, self::SUPPORTED_DRIVERS, true),
            'backup_available' => $this->unavailableReason() === null,
            'unavailable_reason' => $this->unavailableReason(),
        ];
    }

    /**
     * Why a backup cannot be taken, or null when it can.
     *
     * Returned as a sentence rather than a boolean because the two causes need
     * different actions from different people: an unsupported driver is a
     * decision somebody made in `.env`, and a missing binary is something to
     * install on the server. "Backup unavailable" would send both of them
     * looking in the wrong place.
     */
    public function unavailableReason(): ?string
    {
        $connection = config('database.default');

        if (! in_array($connection, self::SUPPORTED_DRIVERS, true)) {
            return "Backups are only built for PostgreSQL, and this is running on {$connection}.";
        }

        if ($this->binary() === null) {
            /*
             * Two different problems, and they are fixed by different people
             * in different places — which is the whole reason this returns a
             * sentence instead of false. A configured path that does not run
             * is somebody's `.env` entry to correct; nothing found at all is
             * something to install on the server.
             */
            if (filled($configured = config('database.dump_binary'))) {
                return "DB_DUMP_BINARY points at {$configured}, which either is not there or will not start. On Windows a pg_dump from a part-installed PostgreSQL exits immediately on a missing CRT DLL — try the newest version's bin folder.";
            }

            return 'No working pg_dump was found on this server. It ships with PostgreSQL rather than with PHP, so it has to be installed alongside the application — or DB_DUMP_BINARY set to one that runs.';
        }

        return null;
    }

    /**
     * The `pg_dump` to use, or null when none of the candidates runs.
     *
     * **Existing is not the same as working, and this machine is the proof.**
     * The first version of this took the first `pg_dump` on the PATH and
     * reported the backup as available; that binary is PostgreSQL 16's, which
     * dies at process start with `0xC0000135` — STATUS_DLL_NOT_FOUND — on a
     * missing CRT DLL. So the screen drew an enabled button that failed the
     * moment it was pressed, which is worse than drawing a disabled one: a
     * control that lies about being usable is how somebody stops believing
     * the rest of the screen.
     *
     * Every candidate is therefore *asked* — `--version`, which fails in
     * exactly the same way a real dump would because the DLL is missing before
     * any argument is read — and the first that answers is the one used.
     *
     * Cached for ten minutes rather than probed on every render: this runs on
     * a screen that is opened rarely, but two or three process spawns per page
     * load is a cost with no reason behind it. Ten minutes is short enough
     * that installing PostgreSQL and reloading finds it.
     */
    public function binary(): ?string
    {
        return Cache::remember(
            self::BINARY_CACHE_KEY,
            self::BINARY_CACHE_TTL,
            fn () => collect($this->candidates())->first(fn (string $path) => $this->runs($path)),
        );
    }

    /** The name the downloaded file carries, so two of them can be told apart. */
    public function filename(): string
    {
        $name = config('database.connections.'.config('database.default').'.database') ?? 'database';

        return $name.'-'.Carbon::now()->format('Y-m-d-His').'.dump';
    }

    /**
     * Writes a dump to a temporary path and hands back the path.
     *
     * The custom format (`-Fc`) rather than plain SQL: it is compressed, and
     * `pg_restore` can read a single table out of it, which is what somebody
     * restoring one accidentally-emptied table actually needs. A plain
     * `.sql` file is all or nothing.
     *
     * **The password goes in the environment, never on the command line.** An
     * argument is visible in the process list to every other user on the
     * machine, which is how a database password leaks without anything being
     * logged.
     *
     * @throws ProcessFailedException when `pg_dump` refuses
     */
    public function dump(): string
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");

        $path = tempnam(sys_get_temp_dir(), 'hris-backup-');

        $process = new Process([
            $this->binary(),
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.($config['port'] ?? 5432),
            '--username='.($config['username'] ?? 'postgres'),
            '--dbname='.$config['database'],
            '--format=custom',
            '--no-password',
            '--file='.$path,
        ], env: ['PGPASSWORD' => (string) ($config['password'] ?? '')]);

        // A dump of a real payroll database is not a two-second job, and the
        // default 60s would cut one off mid-write and hand over a file that
        // looks like a backup and restores to nothing.
        $process->setTimeout(300);

        $process->run();

        if (! $process->isSuccessful()) {
            @unlink($path);

            // The error is logged rather than shown: `pg_dump` names the host
            // and the database it could not reach, and that belongs in a log
            // rather than in a flash message on somebody's screen.
            Log::error('Database backup failed.', [
                'exit_code' => $process->getExitCode(),
                'error' => $process->getErrorOutput(),
            ]);

            throw new ProcessFailedException($process);
        }

        return $path;
    }

    /**
     * Where to look, in order.
     *
     * `DB_DUMP_BINARY` is **the only candidate when it is set**, rather than
     * the first of several. An explicit setting that quietly did not get used
     * would leave somebody reading a config value that is doing nothing, and
     * the reason they set it in the first place is that the automatic choice
     * was wrong.
     *
     * Then the PATH — every line of it, not the first, since `where` on
     * Windows lists each match and the broken one can come first. Then the
     * places a Windows installer puts it, **newest version first**: a newer
     * `pg_dump` reads an older server, and the reverse is what refuses.
     *
     * @return array<int, string>
     */
    private function candidates(): array
    {
        $configured = config('database.dump_binary');

        if (filled($configured)) {
            return [$configured];
        }

        $candidates = [];

        $which = PHP_OS_FAMILY === 'Windows' ? 'where' : 'which';
        $process = Process::fromShellCommandline("{$which} pg_dump");
        $process->run();

        if ($process->isSuccessful()) {
            foreach (preg_split('/\R/', $process->getOutput()) ?: [] as $line) {
                if (($line = trim($line)) !== '') {
                    $candidates[] = $line;
                }
            }
        }

        if (PHP_OS_FAMILY === 'Windows') {
            foreach (range(18, 13) as $version) {
                $candidates[] = "C:/Program Files/PostgreSQL/{$version}/bin/pg_dump.exe";
            }
        }

        return $candidates;
    }

    /**
     * Whether this path is a `pg_dump` that actually starts.
     *
     * `--version` prints a line and exits, touching no database — so it is a
     * safe question to ask, and it is the *same* question a dump asks of the
     * operating system: a missing DLL stops the process before it reads a
     * single argument.
     */
    private function runs(string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }

        $process = new Process([$path, '--version']);
        $process->setTimeout(10);
        $process->run();

        return $process->isSuccessful();
    }
}
