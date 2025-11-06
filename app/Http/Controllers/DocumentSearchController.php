<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\User;
use App\Services\Search\ElasticsearchService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class DocumentSearchController extends Controller
{
    public function __invoke(Request $request, ElasticsearchService $search): Response
    {
        $query = trim((string) $request->input('q', ''));
        $page = max(1, $request->integer('page', 1));
        $perPage = 10;
        $user = $request->user();

        $paginator = $this->emptyPaginator($page, $perPage, $query);

        if ($query !== '') {
            try {
                if ($search->isEnabled()) {
                    $paginator = $search->searchWithHighlights($user, $query, $page, $perPage);
                } else {
                    $paginator = $this->databaseSearch($user, $query, $page, $perPage);
                }
            } catch (Throwable $exception) {
                report($exception);
                $paginator = $this->emptyPaginator($page, $perPage, $query);
            }
        }

        $collection = $paginator->getCollection()->map(function (array $result) {
            /** @var Document $document */
            $document = $result['document'];

            return [
                'id' => $document->id,
                'title' => $document->title,
                'department' => $document->department?->name,
                'fiscal_year' => $document->fiscal_year,
                'status' => $document->status,
                'snippet' => $result['snippet'] ?? '',
                'download_url' => $document->file_path
                    ? Storage::disk('public')->url($document->file_path)
                    : null,
            ];
        });

        $paginator->setCollection($collection);

        return Inertia::render('Documents/Search', [
            'query' => $query,
            'results' => $paginator,
            'searchEnabled' => $search->isEnabled(),
        ]);
    }

    protected function databaseSearch(User $user, string $query, int $page, int $perPage): LengthAwarePaginator
    {
        $builder = Document::query()
            ->with(['department:id,name'])
            ->when($user->isAuditor() || $user->isOfficer(), function ($queryBuilder) use ($user) {
                if ($user->department_id) {
                    $queryBuilder->where('department_id', $user->department_id);
                } elseif ($user->isOfficer()) {
                    $queryBuilder->whereNull('department_id');
                }
            })
            ->where(function ($nested) use ($query) {
                $like = '%' . $query . '%';

                $nested
                    ->where('title', 'like', $like)
                    ->orWhere('summary', 'like', $like)
                    ->orWhere('document_text', 'like', $like)
                    ->orWhere('metadata', 'like', $like);
            })
            ->latest('updated_at');

        $paginator = $builder->paginate($perPage, ['*'], 'page', $page)->withQueryString();

        $paginator->setCollection(
            $paginator->getCollection()->map(function (Document $document) use ($query) {
                $text = $document->summary ?: $document->document_text ?: '';

                return [
                    'document' => $document,
                    'snippet' => $this->buildPlainSnippet($text, $query),
                ];
            })
        );

        return $paginator;
    }

    protected function buildPlainSnippet(string $text, string $query): string
    {
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        $snippetLength = 280;
        $position = mb_stripos($text, $query);
        $start = $position !== false ? max(0, $position - 120) : 0;
        $snippet = mb_substr($text, $start, $snippetLength);

        $prefix = $start > 0 ? '…' : '';
        $suffix = ($start + $snippetLength) < mb_strlen($text) ? '…' : '';

        $snippet = trim($snippet);

        return $this->highlightText($prefix . $snippet . $suffix, $query);
    }

    protected function highlightText(string $text, string $query): string
    {
        $escaped = e($text);

        if ($query === '') {
            return $escaped;
        }

        $pattern = '/' . preg_quote($query, '/') . '/iu';

        return preg_replace($pattern, '<mark>$0</mark>', $escaped) ?: $escaped;
    }

    protected function emptyPaginator(int $page, int $perPage, string $query): LengthAwarePaginator
    {
        return new LengthAwarePaginator(collect(), 0, $perPage, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
            'query' => $query !== '' ? ['q' => $query] : [],
        ]);
    }
}

