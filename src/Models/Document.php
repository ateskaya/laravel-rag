<?php

namespace IbrahimEnsar\Rag\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    protected $table = 'rag_documents';

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'chunk_count' => 'integer',
        'chunks_embedded' => 'integer',
    ];

    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class, 'document_id');
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    /**
     * Embedding progress as a fraction, for a progress bar or a status endpoint.
     */
    public function progress(): float
    {
        if ($this->chunk_count === 0) {
            return 0.0;
        }

        return round($this->chunks_embedded / $this->chunk_count, 4);
    }
}
