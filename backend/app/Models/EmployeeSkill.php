<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeSkill extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function proficiencyLabel(): ?string
    {
        return $this->proficiency === null
            ? null
            : config("qualifications.proficiency_levels.{$this->proficiency}", $this->proficiency);
    }
}
