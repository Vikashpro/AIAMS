<?php

namespace App\Jobs;

use App\Services\Search\ElasticsearchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RemoveDocumentFromSearch implements ShouldQueue
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

        $search->deleteDocument($this->documentId);
    }
}
