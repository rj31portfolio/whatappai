<?php

namespace App\Models;

class Campaign extends TenantRecord
{
    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime'];
    }
}
