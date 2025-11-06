<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\Ingestion\DocumentTextExtractor;
use App\Services\Search\ElasticsearchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncDocumentToSearch implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $documentId)
    {
    }

    public function handle(ElasticsearchService $search, DocumentTextExtractor $textExtractor): void
    {
        if (!$search->isEnabled()) {
            return;
        }

        $document = Document::with(['department:id,name', 'uploader:id,name'])->find($this->documentId);

        if (!$document) {
            $search->deleteDocument($this->documentId);

            return;
        }

        if (blank($document->document_text)) {
            $extracted = $textExtractor->extract('public', $document->file_path);

            if ($extracted !== null) {
                $document->document_text = $extracted;
                $document->save();
            }
        }

        $search->ensureIndexExists();
        $search->indexDocument($document);
    }
}
