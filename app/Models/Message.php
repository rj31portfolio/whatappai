<?php

namespace App\Models;

class Message extends TenantRecord
{
    protected function casts(): array
    {
        return ['metadata' => 'array', 'sent_at' => 'datetime'];
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }
}
