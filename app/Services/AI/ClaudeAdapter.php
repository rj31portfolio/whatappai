<?php
namespace App\Services\AI;

use App\Services\Integrations\ProviderHttp;

class ClaudeAdapter implements AiAdapter
{
    public function complete(string $key, string $model, string $system, string $question): array
    {
        $data = app(ProviderHttp::class)->request('POST', 'https://api.anthropic.com/v1/messages', ['x-api-key' => $key, 'anthropic-version' => '2023-06-01'], [
            'model' => $model, 'system' => $system, 'messages' => [['role' => 'user', 'content' => $question]], 'max_tokens' => 512,
        ]);
        return ['text' => collect($data['content'] ?? [])->where('type', 'text')->pluck('text')->implode("\n"), 'input' => $data['usage']['input_tokens'] ?? 0, 'output' => $data['usage']['output_tokens'] ?? 0];
    }
}
