<?php

namespace App\Services;

use App\Models\Document;
use App\Services\AI\RagService;

class SummaryGenerator
{
    public function __construct(private RagService $ragService)
    {
    }

    public function generate(Document $document): string
    {
        return $this->ragService->generateSummary($document);
    }
}
