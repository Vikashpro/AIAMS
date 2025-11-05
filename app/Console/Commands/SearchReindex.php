<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\Search\ElasticsearchService;
use Illuminate\Console\Command;

class SearchReindex extends Command
{
    protected $signature = 'search:reindex {--fresh : Drop and recreate the index before syncing}';

    protected $description = 'Synchronize all documents with the configured search index';

    public function handle(ElasticsearchService $search): int
    {
        if (!$search->isEnabled()) {
            $this->warn('Search service is disabled. Set ELASTICSEARCH_HOST and ELASTICSEARCH_INDEX first.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->info('Dropping existing index...');
            $search->deleteIndex();
        }

        $search->ensureIndexExists();

        $this->info('Re-indexing documents...');

        Document::query()
            ->with(['department:id,name', 'uploader:id,name'])
            ->orderBy('id')
            ->chunk(100, function ($documents) use ($search) {
                foreach ($documents as $document) {
                    $search->indexDocument($document);
                }
            });

        $this->info('Sync complete.');

        return self::SUCCESS;
    }
}
