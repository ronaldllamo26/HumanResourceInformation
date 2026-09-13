<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Encrypts every government-issued identifier and the bank account at rest.
 *
 * These are the fields that make a stolen database dump worth stealing. A
 * name and a department are what a colleague already knows; an SSS number, a
 * TIN and a bank account are what somebody opens a loan with. Under RA 10173
 * they are sensitive personal information, and "we gated the screen properly"
 * is an answer about the application, not about the file the database sits in.
 *
 * **The columns are widened first, and that is not incidental.** Laravel's
 * `encrypted` cast produces base64 ciphertext of a few hundred characters, so
 * a twelve-digit TIN no longer fits `string(32)` — writing into the old width
 * would truncate the ciphertext and lose the value with no error worth reading.
 *
 * **Everything here runs through the query builder rather than Eloquent.** The
 * model already declares the casts by the time this runs, so reading through
 * it would try to decrypt plaintext and throw on the first row.
 *
 * Checked in this codebase before it was written: nothing filters, sorts or
 * groups by these columns in SQL. `RecordIntegrityChecker::sharedNumbers()`
 * finds duplicates by loading the rows and comparing *in PHP*, which keeps
 * working, and `Employee::scopeSearch` never touched them. The one SQL use is
 * a `whereNotNull` on the licence, and a null stays null.
 *
 * The cost, stated: an encrypted column cannot be searched or indexed. Nothing
 * needs that today. If "find the employee with this TIN" is ever wanted, the
 * answer is a blind index — a second column holding a keyed hash — not
 * undoing this.
 */
return new class extends Migration
{
    /**
     * Widened to `text` rather than to a bigger `string`, because the
     * ciphertext length moves with the key and the payload and a guessed
     * ceiling is a truncation waiting for the wrong input.
     *
     * @var array<int, string>
     */
    private const FIELDS = [
        'sss_number',
        'philhealth_number',
        'pagibig_number',
        'tin',
        'bank_account_number',
        'drivers_license_number',
    ];

    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            foreach (self::FIELDS as $field) {
                $table->text($field)->nullable()->change();
            }
        });

        $this->rewrite(fn (string $value) => Crypt::encryptString($value));
    }

    public function down(): void
    {
        $this->rewrite(function (string $value) {
            try {
                return Crypt::decryptString($value);
            } catch (Throwable) {
                // Already plaintext — a half-applied migration re-run, or a row
                // written before this ran. Left as it is rather than mangled.
                return $value;
            }
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->string('sss_number', 32)->nullable()->change();
            $table->string('philhealth_number', 32)->nullable()->change();
            $table->string('pagibig_number', 32)->nullable()->change();
            $table->string('tin', 32)->nullable()->change();
            $table->string('bank_account_number', 64)->nullable()->change();
            $table->string('drivers_license_number', 32)->nullable()->change();
        });
    }

    /**
     * Rewrites every non-empty value through the given transform.
     *
     * Chunked because this touches whole rows of a table that grows with the
     * workforce, and soft-deleted employees are included on purpose: an
     * archived record holds the same numbers, and leaving those in plaintext
     * would encrypt only the half somebody is currently looking at.
     */
    private function rewrite(callable $transform): void
    {
        DB::table('employees')
            ->select(array_merge(['id'], self::FIELDS))
            ->orderBy('id')
            ->chunk(200, function ($rows) use ($transform) {
                foreach ($rows as $row) {
                    $changes = [];

                    foreach (self::FIELDS as $field) {
                        $value = $row->{$field};

                        if ($value === null || $value === '') {
                            continue;
                        }

                        $changes[$field] = $transform((string) $value);
                    }

                    if ($changes !== []) {
                        DB::table('employees')->where('id', $row->id)->update($changes);
                    }
                }
            });
    }
};
