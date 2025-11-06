<?php

namespace App\Services\Search;

use App\Models\Document;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Throwable;

class ElasticsearchService
{
    protected string $host;

    protected string $index;

    protected ?string $username;

    protected ?string $password;

    protected int $timeout;

    public function __construct()
    {
        $this->host = rtrim((string) config('search.host'), '/');
        $this->index = (string) config('search.index', 'aiams_documents');
        $this->username = config('search.username');
        $this->password = config('search.password');
        $this->timeout = (int) config('search.timeout', 5);
    }

    public function isEnabled(): bool
    {
        return $this->host !== '' && $this->index !== '';
    }

    public function ensureIndexExists(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        try {
            $response = $this->request('HEAD', $this->index);

            if ($response->status() !== 404) {
                return;
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        $payload = [
            'settings' => [
                'analysis' => [
                    'analyzer' => [
                        'default' => [
                            'type' => 'standard',
                        ],
                    ],
                ],
            ],
            'mappings' => [
                'properties' => [
                    'title' => ['type' => 'text'],
                    'summary' => ['type' => 'text'],
                    'document_text' => ['type' => 'text'],
                    'tags' => ['type' => 'keyword'],
                    'department_id' => ['type' => 'integer'],
                    'department_name' => ['type' => 'keyword'],
                    'fiscal_year' => ['type' => 'keyword'],
                    'status' => ['type' => 'keyword'],
                    'metadata' => ['type' => 'object', 'enabled' => true],
                    'updated_at' => ['type' => 'date'],
                ],
            ],
        ];

        $this->request('PUT', $this->index, $payload);
    }

    public function deleteIndex(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->request('DELETE', $this->index);
    }

    public function indexDocument(Document $document): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $payload = [
            'title' => $document->title,
            'summary' => $document->summary,
            'document_text' => $document->document_text,
            'tags' => $document->tags,
            'department_id' => $document->department_id,
            'department_name' => $document->department?->name,
            'fiscal_year' => $document->fiscal_year,
            'status' => $document->status,
            'metadata' => $document->metadata,
            'updated_at' => optional($document->updated_at)->toAtomString(),
        ];

        $this->request('PUT', $this->index . '/_doc/' . $document->id, $payload);
    }

    public function deleteDocument(int $documentId): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->request('DELETE', $this->index . '/_doc/' . $documentId);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function searchDocuments(User $user, array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $this->ensureIndexExists();

        $query = $filters['keyword'] ?? null;
        $departmentId = $filters['department_id'] ?? null;
        $status = $filters['status'] ?? null;
        $fiscalYear = $filters['fiscal_year'] ?? null;

        $bool = [];
        $must = [];
        $filter = [];

        if ($query) {
            $must[] = [
                'multi_match' => [
                    'query' => $query,
                    'fields' => ['title^3', 'summary^2', 'document_text', 'metadata.tags'],
                    'type' => 'best_fields',
                    'fuzziness' => 'AUTO',
                ],
            ];
        }

        if ($departmentId) {
            $filter[] = ['term' => ['department_id' => (int) $departmentId]];
        }

        if ($status) {
            $filter[] = ['term' => ['status' => $status]];
        }

        if ($fiscalYear) {
            $filter[] = ['term' => ['fiscal_year' => $fiscalYear]];
        }

        if ($user->isAuditor() || $user->isOfficer()) {
            if ($user->department_id) {
                $filter[] = ['term' => ['department_id' => $user->department_id]];
            } elseif ($user->isOfficer()) {
                $filter[] = ['bool' => ['must_not' => ['exists' => ['field' => 'department_id']]]];
            }
        }

        if ($must !== []) {
            $bool['must'] = $must;
        }

        if ($filter !== []) {
            $bool['filter'] = $filter;
        }

        $body = [
            'from' => ($page - 1) * $perPage,
            'size' => $perPage,
            'query' => $bool !== [] ? ['bool' => $bool] : ['match_all' => (object) []],
        ];

        $response = $this->request('POST', $this->index . '/_search', $body);

        $hits = collect($response->json('hits.hits', []));
        $total = (int) Arr::get($response->json(), 'hits.total.value', $hits->count());
        $ids = $hits->pluck('_id')->map(fn ($id) => (int) $id)->filter();

        if ($ids->isEmpty()) {
            return new LengthAwarePaginator(collect(), $total, $perPage, $page, [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
            ]);
        }

        $documents = Document::query()
            ->with(['department:id,name', 'uploader:id,name'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $ordered = $ids
            ->map(fn (int $id) => $documents->get($id))
            ->filter()
            ->values();

        return new LengthAwarePaginator($ordered, $total, $perPage, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
            'query' => array_filter($filters, fn ($value) => $value !== null && $value !== ''),
        ]);
    }

    public function searchWithHighlights(User $user, string $query, int $page, int $perPage): LengthAwarePaginator
    {
        $this->ensureIndexExists();

        $bool = [
            'must' => [
                [
                    'multi_match' => [
                        'query' => $query,
                        'fields' => ['title^3', 'summary^2', 'document_text', 'metadata.tags'],
                        'type' => 'best_fields',
                        'fuzziness' => 'AUTO',
                    ],
                ],
            ],
            'filter' => [],
        ];

        if ($user->isAuditor() || $user->isOfficer()) {
            if ($user->department_id) {
                $bool['filter'][] = ['term' => ['department_id' => $user->department_id]];
            } elseif ($user->isOfficer()) {
                $bool['filter'][] = ['bool' => ['must_not' => ['exists' => ['field' => 'department_id']]]];
            }
        }

        if ($bool['filter'] === []) {
            unset($bool['filter']);
        }

        $body = [
            'from' => ($page - 1) * $perPage,
            'size' => $perPage,
            'query' => ['bool' => $bool],
            'highlight' => [
                'pre_tags' => ['<mark>'],
                'post_tags' => ['</mark>'],
                'fields' => [
                    'summary' => ['fragment_size' => 180, 'number_of_fragments' => 1],
                    'document_text' => ['fragment_size' => 280, 'number_of_fragments' => 1],
                ],
            ],
        ];

        $response = $this->request('POST', $this->index . '/_search', $body);

        $hits = collect($response->json('hits.hits', []));
        $total = (int) Arr::get($response->json(), 'hits.total.value', $hits->count());

        $documents = Document::query()
            ->with(['department:id,name'])
            ->whereIn('id', $hits->pluck('_id')->map(fn ($id) => (int) $id)->filter())
            ->get()
            ->keyBy('id');

        $results = $hits->map(function (array $hit) use ($documents, $query) {
            $id = (int) ($hit['_id'] ?? 0);
            $document = $documents->get($id);

            if (!$document) {
                return null;
            }

            return [
                'document' => $document,
                'snippet' => $this->resolveSnippet($hit, $document, $query),
            ];
        })->filter()->values();

        return new LengthAwarePaginator($results, $total, $perPage, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
            'query' => ['q' => $query],
        ]);
    }

    protected function resolveSnippet(array $hit, Document $document, string $query): string
    {
        $highlight = Arr::get($hit, 'highlight', []);

        foreach (['summary', 'document_text'] as $field) {
            $fragment = Arr::get($highlight, $field . '.0');

            if (is_string($fragment) && $fragment !== '') {
                return $fragment;
            }
        }

        $sourceSummary = Arr::get($hit, '_source.summary');
        $sourceDocumentText = Arr::get($hit, '_source.document_text');

        $text = $sourceSummary ?? $sourceDocumentText ?? $document->summary ?? $document->document_text ?? '';

        if (!is_string($text) || trim($text) === '') {
            return '';
        }

        return $this->buildPlainSnippet($text, $query);
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

    protected function request(string $method, string $path, ?array $payload = null)
    {
        $url = $this->endpoint($path);

        $request = Http::timeout($this->timeout);

        if ($this->username) {
            $request = $request->withBasicAuth($this->username, (string) $this->password);
        }

        if ($payload === null) {
            return $request->send($method, $url);
        }

        return $request->send($method, $url, ['json' => $payload]);
    }

    protected function endpoint(string $path): string
    {
        return rtrim($this->host, '/') . '/' . ltrim($path, '/');
    }
}
