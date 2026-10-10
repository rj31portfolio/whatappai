<?php

namespace App\Models;

class Subscription extends TenantRecord
{
    protected function casts(): array
    {
        return ['current_end' => 'datetime'];
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }
}
