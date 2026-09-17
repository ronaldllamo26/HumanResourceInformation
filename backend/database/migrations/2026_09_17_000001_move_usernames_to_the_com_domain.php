<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `@primepower.test` becomes `@primepower.com`.
 *
 * The domain was `.test` because that is the reserved suffix for a local site
 * and nothing was ever mailed to it — the username is shaped like an address
 * and is not one. The owner asked for `.com`, and with a personal inbox now
 * attached to each account for sign-in codes there is a second reason to move:
 * a username ending `.test` beside a real Gmail reads as a mistake in the row,
 * and somebody will eventually type the `.test` one into a mail client.
 *
 * Rewritten here rather than left to be corrected by hand because a username
 * *is* the credential: an account whose domain did not move could not sign in
 * at all once `User::USERNAME_DOMAIN` changed.
 *
 * Idempotent, and the `down()` is exact — only the suffix moves, so no account
 * can come back with a different local part than it went in with.
 */
return new class extends Migration
{
    private const WAS = '@primepower.test';

    private const NOW = '@primepower.com';

    public function up(): void
    {
        $this->rewrite(self::WAS, self::NOW);
    }

    public function down(): void
    {
        $this->rewrite(self::NOW, self::WAS);
    }

    private function rewrite(string $from, string $to): void
    {
        // Raw update rather than Eloquent: `User::booted()` fills and reshapes
        // usernames on save, and a migration should move exactly what it says
        // it moves.
        DB::table('users')
            ->where('username', 'like', '%'.$from)
            ->get(['id', 'username'])
            ->each(fn ($row) => DB::table('users')
                ->where('id', $row->id)
                ->update(['username' => str_replace($from, $to, $row->username)]));
    }
};
