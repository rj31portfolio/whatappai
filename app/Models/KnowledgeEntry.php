<?php
namespace App\Models;

class KnowledgeEntry extends TenantRecord
{
    protected function casts(): array { return ['approved' => 'boolean']; }
}
