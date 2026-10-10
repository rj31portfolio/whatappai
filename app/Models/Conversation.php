<?php
namespace App\Models;

class Conversation extends TenantRecord
{
    protected function casts(): array { return ['last_inbound_at' => 'datetime']; }
    public function contact() { return $this->belongsTo(Contact::class); }
    public function messages() { return $this->hasMany(Message::class); }
}
