<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\AI\DocumentChunkService;
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

    public function handle(DocumentChunkService $chunkService): void
    {
        $document = Document::find($this->documentId);

        if (!$document) {
            return;
        }

        $chunkService->refresh($document);
    }
}
