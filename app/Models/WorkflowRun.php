<?php

namespace App\Models;

class WorkflowRun extends TenantRecord
{
    protected function casts(): array
    {
        return ['steps' => 'array', 'next_at' => 'datetime'];
    }
}
