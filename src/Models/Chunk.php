<?php

namespace IbrahimEnsar\Rag\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Chunk extends Model
{
    protected $table = 'rag_chunks';

    protected $guarded = [];

    protected $casts = [
        'position' => 'integer',
        'token_estimate' => 'integer',
    ];

    /**
     * The vector column is never selected by default. A 1536-dimension
     * embedding is roughly 6 KB of float text per row; hydrating it into
     * Eloquent for a listing query is pure waste.
     */
    protected $hidden = ['embedding'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}
