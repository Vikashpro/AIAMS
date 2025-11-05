<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    use HasFactory;

    public const STATUS_MANUAL = 'manual';
    public const STATUS_PENDING_OCR = 'pending_ocr';
    public const STATUS_SUMMARIZED = 'summarized';

    protected $fillable = [
        'department_id',
        'user_id',
        'title',
        'original_filename',
        'file_path',
        'status',
        'fiscal_year',
        'metadata',
        'document_text',
        'summary',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    protected $appends = [
        'tags',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(DocumentActivity::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class);
    }

    public function getTagsAttribute(): array
    {
        $metadata = $this->metadata ?? [];

        return array_values(array_filter($metadata['tags'] ?? []));
    }

    public function setTagsAttribute($value): void
    {
        $metadata = $this->metadata ?? [];
        $metadata['tags'] = array_values(array_filter((array) $value));
        $this->metadata = $metadata;
    }

    public function markSummarized(): void
    {
        $this->status = self::STATUS_SUMMARIZED;
        $this->save();
    }
}
