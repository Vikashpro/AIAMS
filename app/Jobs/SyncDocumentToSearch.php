<?php

namespace App\Jobs;

use App\Models\Document;
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

    public function handle(ElasticsearchService $search): void
    {
        if (!$search->isEnabled()) {
            return;
        }

        $document = Document::with(['department:id,name', 'uploader:id,name'])->find($this->documentId);

        if (!$document) {
            $search->deleteDocument($this->documentId);

            return;
        }

        $search->ensureIndexExists();
        $search->indexDocument($document);
    }
}
