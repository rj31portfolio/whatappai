<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deal extends Model
{
    use BelongsToTenant;

    public const STAGES = ['new', 'qualified', 'proposal', 'won', 'lost'];

    protected $fillable = ['contact_id', 'title', 'amount', 'stage', 'expected_close_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'expected_close_at' => 'date'];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
