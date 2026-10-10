<?php

namespace App\Models;

class TeamInvitation extends TenantRecord
{
    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'accepted_at' => 'datetime'];
    }
}
