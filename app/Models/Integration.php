<?php
namespace App\Models;

class Integration extends TenantRecord
{
    protected $hidden = ['credentials'];
    protected function casts(): array { return ['credentials' => 'encrypted:array', 'config' => 'array', 'tested_at' => 'datetime']; }
}
