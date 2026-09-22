<?php

namespace IbrahimEnsar\Rag\Models;

use Illuminate\Database\Eloquent\Model;

class QueryLog extends Model
{
    public const OUTCOME_ANSWERED = 'answered';
    public const OUTCOME_REFUSED = 'refused';
    public const OUTCOME_FAILED = 'failed';

    protected $table = 'rag_queries';

    protected $guarded = [];

    protected $casts = [
        'citations' => 'array',
        'pricing_snapshot' => 'array',
        'cost_usd' => 'string', // decimal: keep the string, do not round through float
        'best_distance' => 'float',
    ];
}
