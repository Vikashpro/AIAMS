<?php

namespace App\Services\AI;

use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class RagService
{
    public function __construct(
        private DocumentChunkService $chunkService,
        private EmbeddingClient $embeddingClient,
        private LanguageModelClient $languageModel,
    ) {
    }

    public function generateSummary(Document $document): string
    {
        if (blank($document->document_text)) {
            return $this->fallbackSummary($document->document_text ?? '');
        }

        $chunks = $this->chunkService->ensureChunks($document);

        if ($chunks->isEmpty()) {
            return $this->fallbackSummary($document->document_text);
        }

        $context = $chunks
            ->take(5)
            ->map(fn (DocumentChunk $chunk) => 'Chunk #' . ($chunk->chunk_index + 1) . ":\n" . $chunk->content)
            ->implode("\n\n");

        $messages = [
            [
                'role' => 'system',
                'content' => 'You are an assistant helping government officials quickly understand archival records. '
                    . 'Provide concise summaries highlighting key findings, dates, risks, and recommended follow-up.',
            ],
            [
                'role' => 'user',
                'content' => trim(implode("\n\n", array_filter([
                    'Document title: ' . $document->title,
                    $document->department?->name ? 'Department: ' . $document->department->name : null,
                    $document->fiscal_year ? 'Fiscal year: ' . $document->fiscal_year : null,
                    'Source excerpts:' . "\n" . $context,
                    'Please summarize the document in no more than 180 words. Use bullet points if appropriate.',
                ]))),
            ],
        ];

        try {
            $summary = $this->languageModel->chat($messages);
        } catch (Throwable $exception) {
            report($exception);

            return $this->fallbackSummary($document->document_text);
        }

        return $summary !== '' ? $summary : $this->fallbackSummary($document->document_text);
    }

    /**
     * @return array<string, mixed>
     */
    public function answerQuestion(Document $document, string $question): array
    {
        if (blank($document->document_text)) {
            return [
                'question' => $question,
                'answer' => 'No document text is available to analyse yet.',
                'sources' => [],
                'model' => $this->languageModel->currentModel(),
            ];
        }

        $chunks = $this->chunkService->ensureChunks($document);

        if ($chunks->isEmpty()) {
            return [
                'question' => $question,
                'answer' => 'The document does not have any processed context yet. Refresh the page after OCR or manual text entry.',
                'sources' => [],
                'model' => $this->languageModel->currentModel(),
            ];
        }

        $queryEmbedding = [];

        try {
            $queryEmbedding = $this->embeddingClient->embedText($question);
        } catch (Throwable $exception) {
            report($exception);
        }

        $rankedChunks = $queryEmbedding === []
            ? $this->rankChunksByKeyword($chunks, $question)
            : $this->rankChunksBySimilarity($chunks, $queryEmbedding);

        $topChunks = $rankedChunks->take(4);

        $context = $topChunks
            ->map(fn (array $item) => 'Chunk #' . ($item['chunk']->chunk_index + 1) . ' (score ' . number_format($item['score'], 3) . '):'
                . "\n" . $item['chunk']->content)
            ->implode("\n\n");

        $messages = [
            [
                'role' => 'system',
                'content' => 'You are analysing archival government documents. Answer with clear, factual statements, '
                    . 'citing specific findings where possible. If the information is missing, state that explicitly.',
            ],
            [
                'role' => 'user',
                'content' => trim(implode("\n\n", array_filter([
                    'Document title: ' . $document->title,
                    $document->department?->name ? 'Department: ' . $document->department->name : null,
                    $document->fiscal_year ? 'Fiscal year: ' . $document->fiscal_year : null,
                    'Question: ' . $question,
                    'Relevant excerpts:' . "\n" . $context,
                    'Answer the question using only the provided excerpts.',
                ]))),
            ],
        ];

        try {
            $answer = $this->languageModel->chat($messages);
        } catch (Throwable $exception) {
            report($exception);

            $answer = 'Unable to reach the configured language model. Review the configuration or try again later.';
        }

        return [
            'question' => $question,
            'answer' => $answer,
            'sources' => $topChunks
                ->map(fn (array $item) => [
                    'chunk_index' => $item['chunk']->chunk_index + 1,
                    'score' => $item['score'],
                    'excerpt' => Str::limit($item['chunk']->content, 240),
                ])->values()->all(),
            'model' => $this->languageModel->currentModel(),
        ];
    }

    protected function fallbackSummary(string $text): string
    {
        $cleanText = trim(preg_replace('/\s+/', ' ', $text));

        if ($cleanText === '') {
            return 'Summary generation requires document text.';
        }

        $sentences = preg_split('/(?<=[.!?])\s+/', $cleanText, -1, PREG_SPLIT_NO_EMPTY);

        if (!$sentences) {
            return Str::limit($cleanText, 280);
        }

        $summary = implode(' ', array_slice($sentences, 0, min(3, count($sentences))));

        return Str::limit($summary, 480);
    }

    /**
     * @param  array<int, float>  $queryEmbedding
     */
    protected function rankChunksBySimilarity(Collection $chunks, array $queryEmbedding): Collection
    {
        return $chunks
            ->map(fn (DocumentChunk $chunk) => [
                'chunk' => $chunk,
                'score' => $this->cosineSimilarity($queryEmbedding, $chunk->embedding ?? []),
            ])
            ->sortByDesc('score');
    }

    protected function rankChunksByKeyword(Collection $chunks, string $question): Collection
    {
        $keywords = collect(preg_split('/\s+/', mb_strtolower($question), -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn ($word) => mb_strlen($word) > 2);

        if ($keywords->isEmpty()) {
            return $chunks->map(fn (DocumentChunk $chunk) => [
                'chunk' => $chunk,
                'score' => 0.0,
            ]);
        }

        return $chunks
            ->map(function (DocumentChunk $chunk) use ($keywords) {
                $content = mb_strtolower($chunk->content);
                $count = $keywords->sum(fn ($keyword) => substr_count($content, $keyword));

                return [
                    'chunk' => $chunk,
                    'score' => (float) $count,
                ];
            })
            ->sortByDesc('score');
    }

    /**
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    protected function cosineSimilarity(array $a, array $b): float
    {
        $length = min(count($a), count($b));

        if ($length === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $length; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] ** 2;
            $normB += $b[$i] ** 2;
        }

        $denominator = sqrt($normA) * sqrt($normB);

        if ($denominator == 0.0) {
            return 0.0;
        }

        return $dot / $denominator;
    }
}
