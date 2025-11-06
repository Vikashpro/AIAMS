<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\AI\DocumentChunkService;
use App\Services\Ingestion\DocumentTextExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessDocumentForRag implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $documentId)
    {
    }

    public function handle(DocumentChunkService $chunkService, DocumentTextExtractor $textExtractor): void
    {
        $document = Document::find($this->documentId);

        if (!$document) {
            return;
        }

        if (blank($document->document_text)) {
            $extracted = $textExtractor->extract('public', $document->file_path);

            if ($extracted !== null) {
                $document->document_text = $extracted;
                $document->save();
            }
        }

        $chunkService->refresh($document);
    }
}
