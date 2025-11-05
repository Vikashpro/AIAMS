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
