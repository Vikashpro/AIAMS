<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class LanguageModelClient
{
    public function enabled(): bool
    {
        return filled(config('services.openai.key'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    public function chat(array $messages): string
    {
        if (!$this->enabled()) {
            return $this->fallback($messages);
        }

        $response = Http::withToken(config('services.openai.key'))
            ->timeout(30)
            ->baseUrl(rtrim(config('services.openai.base_url', 'https://api.openai.com/v1'), '/'))
            ->post('/chat/completions', [
                'model' => config('services.openai.chat_model', 'gpt-4o-mini'),
                'messages' => $messages,
                'temperature' => (float) config('services.openai.temperature', 0.2),
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Failed to generate language model response: ' . $response->body());
        }

        return trim((string) $response->json('choices.0.message.content', ''));
    }

    public function currentModel(): string
    {
        return (string) config('services.openai.chat_model', 'gpt-4o-mini');
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    protected function fallback(array $messages): string
    {
        $lastUser = collect($messages)
            ->where('role', 'user')
            ->pluck('content')
            ->last();

        return 'LLM integration is not configured. Last request: ' . ($lastUser ?? '');
    }
}
