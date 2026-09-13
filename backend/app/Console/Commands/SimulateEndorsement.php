<?php

namespace App\Console\Commands;

use App\Services\EndorsementService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Puts a hire in the Core 1 inbox without Core 1.
 *
 * The handover only works when both systems are running, and one of them is
 * not this one's to start. This makes the receiving half demonstrable on its
 * own — and it goes through `EndorsementService::receive()` rather than
 * inserting a row, so what appears in the queue arrived the same way a real
 * endorsement does. A shortcut that wrote straight to the table would prove
 * the screen renders and nothing else.
 *
 * It prints the equivalent HTTP call afterwards, because that is what Core 1
 * will actually send and it is the thing worth showing beside the result.
 */
class SimulateEndorsement extends Command
{
    protected $signature = 'endorsements:simulate
                            {--name= : Full name, e.g. "Juan Dela Cruz"}
                            {--position= : Job title recruitment hired them for}
                            {--client= : Client they are being deployed to}
                            {--reference= : Core 1 reference; generated when omitted}';

    protected $description = 'File a pending endorsement as though Core 1 had sent it';

    public function handle(EndorsementService $endorsements): int
    {
        $name = $this->option('name') ?: fake()->firstName().' '.fake()->lastName();
        $parts = preg_split('/\s+/', trim($name));

        // Everything but the last token is the given name — two-word surnames
        // exist and this is a demo aid, not the name parser the scanner uses.
        $last = count($parts) > 1 ? array_pop($parts) : '';
        $first = implode(' ', $parts);

        $payload = array_filter([
            'reference' => $this->option('reference')
                ?: 'C1-'.now()->year.'-'.Str::upper(Str::random(6)),
            'first_name' => $first,
            'last_name' => $last ?: $first,
            'email' => Str::slug($name, '.').'@example.com',
            'mobile_number' => '09'.fake()->numerify('#########'),
            'birth_date' => fake()->dateTimeBetween('-50 years', '-22 years')->format('Y-m-d'),
            'gender' => fake()->randomElement(['male', 'female']),
            'civil_status' => 'single',
            'nationality' => 'Filipino',
            'present_address' => fake()->address(),
            'position_title' => $this->option('position') ?: 'Driver',
            'client_name' => $this->option('client'),
            'date_hired' => now()->addWeek()->toDateString(),
            'remarks' => 'Cleared final interview. Endorsed for onboarding.',
        ]);

        $endorsement = $endorsements->receive($payload);

        $this->newLine();
        $this->info("Endorsement {$endorsement->reference} is waiting at /hr/endorsements");
        $this->line("  {$endorsement->fullName()} · {$endorsement->position_title}");
        $this->newLine();

        $this->comment('What Core 1 sends to produce this:');
        $this->line('  curl -X POST '.config('app.url').'/api/v1/endorsements \\');
        $this->line('    -H "Authorization: Bearer <token>" \\');
        $this->line('    -H "Content-Type: application/json" \\');
        $this->line("    -d '".json_encode($payload)."'");
        $this->newLine();

        return self::SUCCESS;
    }
}
