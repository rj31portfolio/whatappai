<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['flags' => 'array'];
    }

    public static function enabled(string $feature): bool
    {
        return (bool) (static::first()?->flags[$feature] ?? ($feature !== 'maintenance'));
    }
}
