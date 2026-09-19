<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;

class DatabaseSeeder extends Seeder
{
    /**
     * Generated logins, keyed by username, printed once when the seed finishes.
     *
     * Only ever populated outside local and testing — see seededPassword().
     *
     * @var array<string, string>
     */
    private array $issued = [];

    public function run(): void
    {
        $this->call([
            DepartmentSeeder::class,
            LeaveTypeSeeder::class,
        ]);

        $this->seedAdminUsers();
        $this->seedEmployees();
        $this->seedSelfServiceUser();

        // Needs employees: it creates the clients, then splits the workforce
        // between the agency's own staff and its deployments. Safe to re-run,
        // which is also what backfills a database seeded before clients
        // existed.
        $this->call(ClientSeeder::class);

        // Both need employees.
        $this->call(LeaveSeeder::class);
        $this->call(CredentialSeeder::class);

        // Needs employees and reads approved leave; payroll then pays from it.
        $this->call(TimekeepingSeeder::class);

        // Reads the leave and attendance the seeders above just created.
        $this->call(PayrollSeeder::class);
        $this->call(PerformanceSeeder::class);

        // Needs positions, clients, and an employee to point the approved one
        // at — so it runs last.
        $this->call(EndorsementSeeder::class);

        $this->reportIssuedPasswords();
    }

    /**
     * The password a seeded login is created with.
     *
     * `password` on a development machine is deliberate, not an oversight: the
     * seed accounts exist so the system can be signed into without looking
     * anything up, and CLAUDE.md lists them by name.
     *
     * Anywhere else it is indefensible. This is coursework in a repository
     * people read, so a seeded deployment would be publishing its own
     * administrator account — the password is not merely weak, it is already
     * written down in public. Outside local and testing every login therefore
     * gets its own generated password, printed once by
     * reportIssuedPasswords(), and is flagged must_change_password so the
     * console output stops being a working credential the moment it is used.
     */
    private function seededPassword(string $username): string
    {
        if (app()->environment('local', 'testing')) {
            return 'password';
        }

        return $this->issued[$username] ??= User::generatePassword();
    }

    /**
     * Whether a seeded login has to replace its password before it can do
     * anything. False on a development machine, where forcing the change
     * would defeat the point of having fixed seed accounts at all.
     */
    private function passwordIsProvisional(): bool
    {
        return ! app()->environment('local', 'testing');
    }

    /**
     * Prints the generated passwords once, to the console only.
     *
     * Nowhere else can: they are hashed on the way into the database, so this
     * is the single moment they exist in readable form. Whoever ran the seed
     * is the only person who sees them, and each one is spent on first use.
     */
    private function reportIssuedPasswords(): void
    {
        if ($this->issued === []) {
            return;
        }

        $this->command?->newLine();
        $this->command?->warn('Seeded logins — shown once, and each must be changed at first sign-in:');

        foreach ($this->issued as $username => $password) {
            $this->command?->line(sprintf('  %-34s %s', $username, $password));
        }

        $this->command?->newLine();
    }

    private function seedAdminUsers(): void
    {
        $accounts = [
            ['name' => 'System Administrator', 'username' => 'admin@primepower.com', 'role' => User::ROLE_SUPER_ADMIN],
            ['name' => 'Maria Santos', 'username' => 'hrstaff@primepower.com', 'role' => User::ROLE_HR_STAFF],
        ];

        $adminOtpEmail = env('ADMIN_OTP_EMAIL');

        foreach ($accounts as $account) {
            $plain = $this->seededPassword($account['username']);
            $data = [
                'name' => $account['name'],
                'role' => $account['role'],
                'password' => $plain,
                'visible_password' => Crypt::encryptString($plain),
                'is_active' => true,
                'must_change_password' => $this->passwordIsProvisional(),
            ];

            if (in_array($account['role'], [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN], true) && filled($adminOtpEmail)) {
                $data['otp_email'] = strtolower(trim((string) $adminOtpEmail));
            }

            User::updateOrCreate(
                ['username' => $account['username']],
                $data,
            );
        }
    }

    /**
     * A rank-and-file login.
     *
     * The `employee` role is enforced in every policy in the system, but the
     * seeder only ever produced admin, HR, and supervisor accounts — so the
     * self-service half (own payslip, own leave, own 201 file) could not be
     * opened at all without hand-making a user first.
     *
     * Deliberately someone with a supervisor above them, so filing a leave
     * request has an approver to route to.
     */
    private function seedSelfServiceUser(): void
    {
        $existing = User::where('username', 'employee@primepower.com')->first();

        // Already linked. Re-running must not hand the same login a second
        // employee record — one user, one 201 file.
        if ($existing && Employee::where('user_id', $existing->id)->exists()) {
            return;
        }

        $employee = Employee::whereNull('user_id')
            ->whereNotNull('supervisor_id')
            ->orderBy('id')
            ->first();

        if (! $employee) {
            return;
        }

        $plainEmp = $this->seededPassword('employee@primepower.com');
        $user = User::updateOrCreate(
            ['username' => 'employee@primepower.com'],
            [
                'name' => $employee->full_name,
                'role' => User::ROLE_EMPLOYEE,
                'password' => $plainEmp,
                'visible_password' => Crypt::encryptString($plainEmp),
                'is_active' => true,
                'must_change_password' => $this->passwordIsProvisional(),
            ],
        );

        $employee->update(['user_id' => $user->id]);
    }

    private function seedEmployees(): void
    {
        if (Employee::exists()) {
            return;
        }

        $departments = Department::with('positions')->get();

        // Supervisors first so the rest have someone to report to.
        $supervisors = collect();

        foreach ($departments as $department) {
            $position = $department->positions->first();

            $employee = Employee::factory()->create([
                'department_id' => $department->id,
                'position_id' => $position?->id,
                'employment_status' => 'regular',
                'basic_salary' => 65000,
            ]);

            $username = User::usernameFor($employee->first_name, $employee->last_name);
            $plainSup = $this->seededPassword($username);

            $user = User::create([
                'name' => $employee->full_name,
                'username' => $username,
                'role' => User::ROLE_SUPERVISOR,
                'password' => $plainSup,
                'visible_password' => Crypt::encryptString($plainSup),
                'is_active' => true,
                'must_change_password' => $this->passwordIsProvisional(),
            ]);

            $employee->update(['user_id' => $user->id]);
            $department->update(['head_employee_id' => $employee->id]);

            $supervisors->push($employee);
        }

        foreach ($departments as $department) {
            $supervisor = $supervisors->firstWhere('department_id', $department->id);
            $positions = $department->positions;
            $isOperations = str_contains(strtolower($department->name), 'operations');

            $count = 2;

            $factory = Employee::factory()->count($count);

            if ($isOperations) {
                $factory = $factory->driver();
            }

            $factory->create([
                'department_id' => $department->id,
                'position_id' => $positions->skip(1)->random()?->id ?? $positions->first()?->id,
                'supervisor_id' => $supervisor?->id,
            ]);
        }

        // A couple of records in non-active states to exercise the filters.
        Employee::query()->inRandomOrder()->limit(3)->update(['status' => 'on_leave']);

        $this->command?->info('Seeded '.Employee::count().' employees.');
    }
}
