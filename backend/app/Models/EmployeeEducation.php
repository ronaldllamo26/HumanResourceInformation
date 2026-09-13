<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of somebody's schooling.
 *
 * Auditable like everything else HR edits: an attainment is a claim a hiring
 * decision was made on, so who changed it matters as much as what it says.
 */
class EmployeeEducation extends Model
{
    use Auditable;

    /*
     * Stated, because Laravel treats "education" as uncountable and would look
     * for `employee_education`. Renaming the table to match the guess would
     * leave one table in the schema that is singular while every other is not.
     */
    protected $table = 'employee_educations';

    protected $guarded = ['id'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Where this level sits on the ladder in config/qualifications.php.
     *
     * Used to pick the highest attainment out of a set. An unknown level
     * sorts last rather than first, so a level removed from config cannot
     * silently outrank a real one.
     */
    public function rank(): int
    {
        $order = array_keys(config('qualifications.education_levels', []));
        $index = array_search($this->level, $order, true);

        return $index === false ? -1 : $index;
    }

    public function levelLabel(): string
    {
        return config("qualifications.education_levels.{$this->level}", $this->level);
    }
}
