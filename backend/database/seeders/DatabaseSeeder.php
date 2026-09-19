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
        return env('HRIS_ADMIN_PASSWORD', 'Password123!');
    }

    /**
     * Whether a seeded login has to replace its password before it can do
     * anything. False so demo accounts can immediately sign in.
     */
    private function passwordIsProvisional(): bool
    {
        return false;
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
                'email' => $account['username'],
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
                'email' => 'employee@primepower.com',
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

        // Sample Employee 1: Juan Dela Cruz (Supervisor, Fleet & Transportation Management)
        $dept1 = $departments->firstWhere('name', 'Fleet & Transportation Management') ?? $departments->first();
        $pos1 = $dept1->positions->first();

        $employee1 = Employee::factory()->create([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'department_id' => $dept1->id,
            'position_id' => $pos1?->id,
            'employment_status' => 'regular',
            'basic_salary' => 65000,
        ]);

        $username1 = 'jdelacruz@primepower.com';
        $plain1 = $this->seededPassword($username1);
        $user1 = User::create([
            'name' => $employee1->full_name,
            'username' => $username1,
            'email' => $username1,
            'role' => User::ROLE_SUPERVISOR,
            'password' => $plain1,
            'visible_password' => Crypt::encryptString($plain1),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $employee1->update(['user_id' => $user1->id]);
        $dept1->update(['head_employee_id' => $employee1->id]);

        // Sample Employee 2: Maria Clara (Regular Employee, Human Resource Information Management)
        $dept2 = $departments->firstWhere('name', 'Human Resource Information Management') ?? $departments->skip(1)->first();
        $pos2 = $dept2->positions->first();

        $employee2 = Employee::factory()->create([
            'first_name' => 'Maria',
            'last_name' => 'Clara',
            'department_id' => $dept2->id,
            'position_id' => $pos2?->id,
            'employment_status' => 'regular',
            'basic_salary' => 45000,
            'supervisor_id' => $employee1->id,
        ]);

        $username2 = 'mclara@primepower.com';
        $plain2 = $this->seededPassword($username2);
        $user2 = User::create([
            'name' => $employee2->full_name,
            'username' => $username2,
            'email' => $username2,
            'role' => User::ROLE_EMPLOYEE,
            'password' => $plain2,
            'visible_password' => Crypt::encryptString($plain2),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $employee2->update(['user_id' => $user2->id]);

        $this->command?->info('Seeded exactly 2 sample employees.');
    }
}
