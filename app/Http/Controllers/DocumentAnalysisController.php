<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\AI\RagService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DocumentAnalysisController extends Controller
{
    public function __invoke(Request $request, Document $document, RagService $ragService): RedirectResponse
    {
        $user = $request->user();

        if ($user->isAuditor() && $document->department_id !== $user->department_id) {
            abort(403);
        }

        if ($user->isOfficer() && $document->department_id !== $user->department_id) {
            abort(403);
        }

        $data = $request->validate([
            'question' => ['required', 'string', 'max:500'],
        ]);

        $analysis = $ragService->answerQuestion($document, $data['question']);

        $document->activities()->create([
            'user_id' => $user->id,
            'type' => 'analysis_requested',
            'description' => 'Asked: ' . Str::limit($data['question'], 100),
        ]);

        return back()->with('analysis', $analysis);
    }
}
