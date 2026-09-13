<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    use HasFactory;

    public const TYPE_REGULAR = 'regular';

    public const TYPE_SPECIAL = 'special_non_working';

    protected $guarded = ['id'];

    /** Premium multiplier applied to holiday work (Philippine Labor Code). */
    public function payMultiplier(): float
    {
        return $this->type === self::TYPE_REGULAR ? 2.0 : 1.3;
    }

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_nationwide' => 'boolean',
        ];
    }
}
