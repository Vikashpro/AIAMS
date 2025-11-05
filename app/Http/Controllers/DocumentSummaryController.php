<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessDocumentForRag;
use App\Jobs\SyncDocumentToSearch;
use App\Models\Document;
use App\Services\SummaryGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DocumentSummaryController extends Controller
{
    public function __invoke(Request $request, Document $document, SummaryGenerator $summaryGenerator): RedirectResponse
    {
        $user = $request->user();

        if ($user->isAuditor() && $document->department_id !== $user->department_id) {
            abort(403);
        }

        if ($user->isOfficer() && $document->department_id !== $user->department_id) {
            abort(403);
        }

        $summary = $summaryGenerator->generate($document);

        $document->summary = $summary;
        $document->status = Document::STATUS_SUMMARIZED;
        $document->save();

        SyncDocumentToSearch::dispatchSync($document->id);
        ProcessDocumentForRag::dispatchSync($document->id);

        $document->activities()->create([
            'user_id' => $user->id,
            'type' => 'summary_generated',
            'description' => 'Summary generated',
        ]);

        return back()->with('success', 'Summary updated successfully.');
    }
}
