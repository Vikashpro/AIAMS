<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentActivity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DocumentController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $documents = Document::query()
            ->with(['department:id,name', 'uploader:id,name'])
            ->when($user->isAuditor() || $user->isOfficer(), function ($query) use ($user) {
                if ($user->department_id) {
                    $query->where('department_id', $user->department_id);
                } elseif ($user->isOfficer()) {
                    $query->whereNull('department_id');
                }
            })
            ->when($request->filled('department_id'), function ($query) use ($request, $user) {
                $departmentId = $request->integer('department_id');

                if ($user->isAdmin() || $user->department_id === $departmentId) {
                    $query->where('department_id', $departmentId);
                }
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('status', $request->string('status'));
            })
            ->when($request->filled('fiscal_year'), function ($query) use ($request) {
                $query->where('fiscal_year', $request->string('fiscal_year'));
            })
            ->when($request->filled('keyword'), function ($query) use ($request) {
                $keyword = '%' . $request->string('keyword') . '%';

                $query->where(function ($nested) use ($keyword) {
                    $nested
                        ->where('title', 'like', $keyword)
                        ->orWhere('summary', 'like', $keyword)
                        ->orWhere('document_text', 'like', $keyword)
                        ->orWhere('metadata', 'like', $keyword);
                });
            })
            ->latest()
            ->paginate(10)
            ->withQueryString()
            ->through(function (Document $document) {
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
            });

        return Inertia::render('Documents/Index', [
            'documents' => $documents,
            'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
            'filters' => $request->only(['department_id', 'status', 'fiscal_year', 'keyword']),
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

    public function store(Request $request): RedirectResponse
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

        $filePath = $request->file('file')->store('documents', 'public');
        $document = new Document();
        $document->fill([
            'title' => $data['title'],
            'department_id' => $departmentId,
            'user_id' => $user->id,
            'original_filename' => $request->file('file')->getClientOriginalName(),
            'file_path' => $filePath,
            'status' => $data['status'] ?? ($data['document_text'] ? Document::STATUS_MANUAL : Document::STATUS_PENDING_OCR),
            'fiscal_year' => $data['fiscal_year'],
            'document_text' => $data['document_text'] ?? null,
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
        ]);
    }

    public function update(Request $request, Document $document): RedirectResponse
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

        $document->fill([
            'title' => $data['title'],
            'fiscal_year' => $data['fiscal_year'],
            'document_text' => $data['document_text'],
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
}
