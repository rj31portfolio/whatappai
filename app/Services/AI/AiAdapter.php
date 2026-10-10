<?php
namespace App\Services\AI;

interface AiAdapter
{
    public function complete(string $key, string $model, string $system, string $question): array;
}
