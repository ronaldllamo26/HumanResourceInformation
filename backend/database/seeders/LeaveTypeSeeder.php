<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Standard Philippine leave entitlements (Module 3).
 */
class LeaveTypeSeeder extends Seeder
{
    private const TYPES = [
        ['SL', 'Sick Leave', 15, true, true, null],
        ['VL', 'Vacation Leave', 15, true, false, 15],
        ['EL', 'Emergency Leave', 3, true, false, 3],
        ['ML', 'Maternity Leave', 105, true, true, 105],
        ['PL', 'Paternity Leave', 7, true, true, 7],
        ['SPL', 'Solo Parent Leave', 7, true, true, 7],
        ['BL', 'Bereavement Leave', 3, true, false, 3],
        ['LWOP', 'Leave Without Pay', 0, false, false, null],
    ];

    public function run(): void
    {
        foreach (self::TYPES as [$code, $name, $credits, $isPaid, $needsAttachment, $maxDays]) {
            DB::table('leave_types')->updateOrInsert(
                ['code' => $code],
                [
                    'name' => $name,
                    'default_credits' => $credits,
                    'is_paid' => $isPaid,
                    'requires_attachment' => $needsAttachment,
                    'max_consecutive_days' => $maxDays,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }
}
