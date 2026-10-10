<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tenant extends Model
{
    protected $fillable = ['name', 'slug', 'industry', 'currency', 'timezone', 'is_demo'];

    protected function casts(): array
    {
        return ['is_demo' => 'boolean', 'suspended' => 'boolean', 'trial_ends_at' => 'datetime', 'settings' => 'array'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'memberships')->withPivot('role')->withTimestamps();
    }
}
