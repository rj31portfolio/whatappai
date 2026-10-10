<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contact extends Model
{
    use BelongsToTenant;

    public const SOURCES = ['website', 'referral', 'whatsapp', 'email', 'event', 'other'];

    public const SCORES = ['cold', 'warm', 'hot'];

    protected $fillable = ['name', 'email', 'phone', 'company_id', 'source', 'score', 'score_reason', 'notes', 'email_consent', 'whatsapp_consent'];

    protected function casts(): array
    {
        return ['email_consent' => 'boolean', 'whatsapp_consent' => 'boolean', 'tags' => 'array', 'custom_fields' => 'array'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
