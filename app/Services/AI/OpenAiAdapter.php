<?php
namespace App\Services\AI;

use App\Services\Integrations\ProviderHttp;

class OpenAiAdapter implements AiAdapter
{
    public function complete(string $key, string $model, string $system, string $question): array
    {
        $data = app(ProviderHttp::class)->request('POST', 'https://api.openai.com/v1/chat/completions', ['Authorization' => 'Bearer '.$key], [
            'model' => $model, 'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $question]], 'max_completion_tokens' => 512,
        ]);
        return ['text' => $data['choices'][0]['message']['content'] ?? '', 'input' => $data['usage']['prompt_tokens'] ?? 0, 'output' => $data['usage']['completion_tokens'] ?? 0];
    }
}
