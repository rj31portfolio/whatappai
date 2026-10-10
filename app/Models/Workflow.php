<?php

namespace App\Models;

class Workflow extends TenantRecord
{
    protected function casts(): array
    {
        return ['steps' => 'array', 'active' => 'boolean'];
    }
}
