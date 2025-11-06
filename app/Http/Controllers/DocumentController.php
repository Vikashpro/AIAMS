<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessDocumentForRag;
use App\Jobs\SyncDocumentToSearch;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentActivity;
use App\Models\User;
use App\Services\Ingestion\DocumentTextExtractor;
use App\Services\Search\ElasticsearchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class DocumentController extends Controller
{
    public function index(Request $request, ElasticsearchService $search): Response
    {
        $user = $request->user();
        $perPage = 10;
        $filters = $request->only(['department_id', 'status', 'fiscal_year', 'keyword']);

        $paginator = $this->resolvePaginator($request, $user, $search, $filters, $perPage);

        $documents = $paginator->through(fn (Document $document) => $this->transformDocument($document));

        return Inertia::render('Documents/Index', [
            'documents' => $documents,
            'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
            'statusOptions' => [
                ['value' => Document::STATUS_MANUAL, 'label' => 'Manual'],
                ['value' => Document::STATUS_PENDING_OCR, 'label' => 'Pending OCR'],
                ['value' => Document::STATUS_SUMMARIZED, 'label' => 'Summarized'],
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $request->user();

        if ($user->isAuditor()) {
            abort(403, 'Auditors cannot upload documents.');
        }

        return Inertia::render('Documents/Create', [
            'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, DocumentTextExtractor $textExtractor): RedirectResponse
    {
        $user = $request->user();

        if ($user->isAuditor()) {
            abort(403, 'Auditors cannot upload documents.');
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'file' => ['required', 'file'],
            'document_text' => ['nullable', 'string'],
            'fiscal_year' => ['nullable', 'string', 'max:9'],
            'tags' => ['nullable', 'string'],
            'status' => ['nullable', 'string', Rule::in([
                Document::STATUS_MANUAL,
                Document::STATUS_PENDING_OCR,
                Document::STATUS_SUMMARIZED,
            ])],
        ]);

        $departmentId = $data['department_id'] ?? $user->department_id;

        $file = $request->file('file');
        $filePath = $file->store('documents', 'public');

        $providedText = $data['document_text'] ?? null;
        $extractedText = blank($providedText)
            ? $textExtractor->extract('public', $filePath)
            : $providedText;
        $document = new Document();
        $document->fill([
            'title' => $data['title'],
            'department_id' => $departmentId,
            'user_id' => $user->id,
            'original_filename' => $file->getClientOriginalName(),
            'file_path' => $filePath,
            'status' => $data['status'] ?? ($extractedText ? Document::STATUS_MANUAL : Document::STATUS_PENDING_OCR),
            'fiscal_year' => $data['fiscal_year'],
            'document_text' => $extractedText,
        ]);

        $document->metadata = [
            'tags' => $this->parseTags($data['tags'] ?? ''),
        ];
        $document->save();

        $document->activities()->create([
            'user_id' => $user->id,
            'type' => 'uploaded',
            'description' => 'Document uploaded',
        ]);

        SyncDocumentToSearch::dispatchSync($document->id);
        ProcessDocumentForRag::dispatchSync($document->id);

        return redirect()
            ->route('documents.show', $document)
            ->with('success', 'Document uploaded successfully.');
    }

    public function show(Request $request, Document $document): Response
    {
        $user = $request->user();

        if ($user->isAuditor() && $document->department_id !== $user->department_id) {
            abort(403);
        }

        if ($user->isOfficer() && $document->department_id !== $user->department_id) {
            abort(403);
        }

        $document->load(['department:id,name', 'uploader:id,name', 'activities.user:id,name']);
        $analysis = $request->session()->pull('analysis');

        return Inertia::render('Documents/Show', [
            'document' => [
                'id' => $document->id,
                'title' => $document->title,
                'department_id' => $document->department_id,
                'department' => $document->department?->name,
                'status' => $document->status,
                'fiscal_year' => $document->fiscal_year,
                'tags' => $document->tags,
                'metadata' => $document->metadata,
                'summary' => $document->summary,
                'document_text' => $document->document_text,
                'file_path' => Storage::disk('public')->url($document->file_path),
                'uploader' => $document->uploader?->name,
                'updated_at' => $document->updated_at?->toDateTimeString(),
                'created_at' => $document->created_at?->toDateTimeString(),
            ],
            'activities' => $document->activities
                ->sortByDesc('created_at')
                ->map(fn(DocumentActivity $activity) => [
                    'id' => $activity->id,
                    'type' => $activity->type,
                    'description' => $activity->description,
                    'created_at' => $activity->created_at?->diffForHumans(),
                    'user' => $activity->user?->name,
                ])->values(),
            'analysis' => $analysis,
        ]);
    }

    public function update(Request $request, Document $document, DocumentTextExtractor $textExtractor): RedirectResponse
    {
        $user = $request->user();

        if ($user->isAuditor() && $document->department_id !== $user->department_id) {
            abort(403);
        }

        if ($user->isOfficer() && $document->department_id !== $user->department_id) {
            abort(403);
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'fiscal_year' => ['nullable', 'string', 'max:9'],
            'tags' => ['nullable', 'string'],
            'document_text' => ['nullable', 'string'],
            'summary' => ['nullable', 'string'],
            'status' => ['required', 'string', Rule::in([
                Document::STATUS_MANUAL,
                Document::STATUS_PENDING_OCR,
                Document::STATUS_SUMMARIZED,
            ])],
        ]);

        $documentText = $data['document_text'] ?? null;

        if (blank($documentText)) {
            $documentText = $document->document_text ?: $textExtractor->extract('public', $document->file_path);
        }

        $document->fill([
            'title' => $data['title'],
            'fiscal_year' => $data['fiscal_year'],
            'document_text' => $documentText,
            'summary' => $data['summary'],
            'status' => $data['status'],
        ]);

        $metadata = $document->metadata ?? [];
        $metadata['tags'] = $this->parseTags($data['tags'] ?? '');
        $document->metadata = $metadata;
        $document->save();

        $document->activities()->create([
            'user_id' => $user->id,
            'type' => 'updated',
            'description' => 'Document updated',
        ]);

        SyncDocumentToSearch::dispatchSync($document->id);
        ProcessDocumentForRag::dispatchSync($document->id);

        return redirect()
            ->route('documents.show', $document)
            ->with('success', 'Document updated successfully.');
    }

    protected function parseTags(?string $tags): array
    {
        return collect(explode(',', (string) $tags))
            ->map(fn(string $tag) => trim($tag))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function resolvePaginator(Request $request, User $user, ElasticsearchService $search, array $filters, int $perPage): LengthAwarePaginator
    {
        $page = max(1, $request->integer('page', 1));

        if ($search->isEnabled() && ($filters['keyword'] ?? null)) {
            try {
                return $search->searchDocuments($user, $filters, $page, $perPage);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $this->databasePaginator($user, $filters, $perPage);
    }

    protected function databasePaginator(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        return Document::query()
            ->with(['department:id,name', 'uploader:id,name'])
            ->when($user->isAuditor() || $user->isOfficer(), function ($query) use ($user) {
                if ($user->department_id) {
                    $query->where('department_id', $user->department_id);
                } elseif ($user->isOfficer()) {
                    $query->whereNull('department_id');
                }
            })
            ->when($filters['department_id'] ?? null, function ($query, $departmentId) use ($user) {
                $departmentId = (int) $departmentId;

                if ($user->isAdmin() || $user->department_id === $departmentId) {
                    $query->where('department_id', $departmentId);
                }
            })
            ->when($filters['status'] ?? null, function ($query, $status) {
                $query->where('status', $status);
            })
            ->when($filters['fiscal_year'] ?? null, function ($query, $fiscalYear) {
                $query->where('fiscal_year', $fiscalYear);
            })
            ->when($filters['keyword'] ?? null, function ($query, $keyword) {
                $like = '%' . $keyword . '%';

                $query->where(function ($nested) use ($like) {
                    $nested
                        ->where('title', 'like', $like)
                        ->orWhere('summary', 'like', $like)
                        ->orWhere('document_text', 'like', $like)
                        ->orWhere('metadata', 'like', $like);
                });
            })
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    protected function transformDocument(Document $document): array
    {
        return [
            'id' => $document->id,
            'title' => $document->title,
            'department' => $document->department?->name,
            'status' => $document->status,
            'fiscal_year' => $document->fiscal_year,
            'tags' => $document->tags,
            'updated_at' => $document->updated_at?->diffForHumans(),
            'summary' => Str::limit($document->summary, 180),
        ];
    }
}
