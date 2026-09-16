<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** A day off by proclamation, and which Labor Code premium it carries. */
class Holiday extends Model
{
    use Auditable;

    public const TYPE_REGULAR = 'regular';

    public const TYPE_SPECIAL = 'special';

    public const TYPES = [self::TYPE_REGULAR, self::TYPE_SPECIAL];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
