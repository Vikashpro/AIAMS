<?php

namespace App\Services\AI;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class EmbeddingClient
{
    public function enabled(): bool
    {
        return filled(config('services.openai.key'));
    }

    /**
     * @param  array<int, string>  $inputs
     * @return array<int, array<int, float>>
     */
    public function embed(array $inputs): array
    {
        $texts = array_values(array_filter($inputs, fn ($text) => trim((string) $text) !== ''));

        if ($texts === []) {
            return [];
        }

        if (!$this->enabled()) {
            return array_map(fn ($text) => $this->fakeEmbedding($text), $texts);
        }

        $response = Http::withToken(config('services.openai.key'))
            ->timeout(20)
            ->baseUrl(rtrim(config('services.openai.base_url', 'https://api.openai.com/v1'), '/'))
            ->post('/embeddings', [
                'model' => config('services.openai.embedding_model', 'text-embedding-3-small'),
                'input' => $texts,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Failed to generate embeddings: ' . $response->body());
        }

        return collect($response->json('data', []))
            ->map(fn ($item) => $this->normalizeEmbedding(Arr::get($item, 'embedding', [])))
            ->all();
    }

    /**
     * @return array<int, float>
     */
    public function embedText(string $text): array
    {
        $results = $this->embed([$text]);

        return $results[0] ?? [];
    }

    /**
     * @param  array<int, mixed>  $embedding
     * @return array<int, float>
     */
    protected function normalizeEmbedding(array $embedding): array
    {
        return array_values(array_map(fn ($value) => (float) $value, $embedding));
    }

    /**
     * @return array<int, float>
     */
    protected function fakeEmbedding(string $text): array
    {
        $hash = hash('sha256', mb_strtolower($text));
        $values = [];

        for ($i = 0; $i < 32; $i++) {
            $segment = substr($hash, $i * 2, 2);

            if ($segment === '') {
                $segment = substr($hash, -2);
            }

            $decimal = hexdec($segment ?? '0');
            $values[] = ($decimal / 255) - 0.5;
        }

        return $values;
    }
}
