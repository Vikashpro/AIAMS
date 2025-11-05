<?php

namespace App\Services\AI;

use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Support\Collection;
use Throwable;

class DocumentChunkService
{
    public function __construct(private Chunker $chunker, private EmbeddingClient $embeddingClient)
    {
    }

    public function refresh(Document $document): Collection
    {
        if (blank($document->document_text)) {
            $document->chunks()->delete();

            return collect();
        }

        $chunks = $this->chunker->chunk($document->document_text);

        if ($chunks === []) {
            $document->chunks()->delete();

            return collect();
        }

        try {
            $embeddings = $this->embeddingClient->embed($chunks);
        } catch (Throwable $exception) {
            report($exception);
            $embeddings = [];
        }

        DocumentChunk::query()->where('document_id', $document->id)->delete();

        foreach ($chunks as $index => $content) {
            DocumentChunk::create([
                'document_id' => $document->id,
                'chunk_index' => $index,
                'content' => $content,
                'embedding' => $embeddings[$index] ?? null,
                'token_count' => str_word_count($content),
            ]);
        }

        return $this->getChunks($document);
    }

    public function getChunks(Document $document): Collection
    {
        return $document->chunks()->orderBy('chunk_index')->get();
    }

    public function ensureChunks(Document $document): Collection
    {
        $chunks = $this->getChunks($document);

        if ($chunks->isNotEmpty()) {
            return $chunks;
        }

        return $this->refresh($document);
    }
}
