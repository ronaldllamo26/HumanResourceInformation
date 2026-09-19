<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Sample client companies PrimePower deploys to.
 *
 * Two placeholder records kept for demo purposes.
 * Replace the names here with the real accounts; nothing downstream reads
 * them, only the codes and the client ids.
 */
class ClientSeeder extends Seeder
{
    public function run(): void
    {
        $today = Carbon::today()->toDateString();
        $twoYearsOut = Carbon::today()->addYears(2)->toDateString();

        $clients = [
            [
                'code' => 'MTL',
                'name' => 'Metro Logistics Corporation',
                'industry' => 'Freight & Distribution',
                'wage_region' => 'NCR',
                'contact_person' => 'Ramon Villanueva',
                'contact_email' => 'operations@metrologistics.example',
                'contact_number' => '(02) 8845 1120',
                'address' => 'Cabrera Rd, Parañaque City, Metro Manila',
                'contract_start' => $today,
                'contract_end' => $twoYearsOut,
            ],
            [
                'code' => 'SRM',
                'name' => 'Southern Retail Mart Inc.',
                'industry' => 'Retail Distribution',
                'wage_region' => 'R4A',
                'contact_person' => 'Liza Bautista',
                'contact_email' => 'hr@southernretail.example',
                'contact_number' => '(049) 502 8833',
                'address' => 'Maharlika Highway, Calamba, Laguna',
                'contract_start' => $today,
                'contract_end' => $twoYearsOut,
            ],
        ];

        foreach ($clients as $client) {
            Client::updateOrCreate(['code' => $client['code']], $client);
        }

        $this->deploy();
    }

    /**
     * Splits the workforce into the agency's own staff and its deployed
     * employees.
     *
     * Written to be safe to re-run, and to work on a database that already
     * has employees: the migration defaults everyone to `internal`, so this
     * is also what backfills an existing installation rather than needing a
     * one-off script that then has to be kept around.
     *
     * The split follows the business. Fleet Operations and Fleet Maintenance
     * are the billable trades an agency deploys, so most of them go out to
     * clients. HR, Finance, Administration, Safety & Compliance, and every
     * department head stay in — they run and audit the agency itself. A few
     * fleet staff are kept internal because PrimePower runs its own vehicles
     * too, which is what the leader meant by listing "Driver" under internal
     * staff.
     */
    private function deploy(): void
    {
        $clients = Client::orderBy('id')->get();

        if ($clients->isEmpty()) {
            return;
        }

        // Department heads run the agency, wherever they sit.
        $headIds = Department::whereNotNull('head_employee_id')->pluck('head_employee_id');

        $deployable = Employee::query()
            ->whereNotIn('id', $headIds)
            ->whereNull('client_id')
            ->whereHas('department', fn ($query) => $query->where('name', 'like', '%Fleet%'))
            ->orderBy('id')
            ->get();

        // Four in five go out; the rest crew PrimePower's own vehicles. Taken
        // in id order rather than at random so re-running the seeder on the
        // same data produces the same split.
        $toDeploy = $deployable->take((int) ceil($deployable->count() * 0.8));

        foreach ($toDeploy->values() as $index => $employee) {
            $employee->update([
                'employment_category' => Employee::CATEGORY_EXTERNAL,
                // Round-robin so every client has people; a random draw
                // leaves one client empty often enough to look like a bug.
                'client_id' => $clients[$index % $clients->count()]->id,
            ]);
        }

        $this->command?->info(
            'Deployed '.$toDeploy->count().' employees across '.$clients->count().' clients; '
            .Employee::where('employment_category', Employee::CATEGORY_INTERNAL)->count().' internal.',
        );
    }
}
