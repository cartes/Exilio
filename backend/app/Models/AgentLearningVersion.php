<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentLearningVersion extends Model
{
    use HasUuids;

    public $timestamps = false;

    public const OPERATION_TYPES = [
        'initial',
        'learning',
        'revert',
    ];

    protected $fillable = [
        'agent_id',
        'version',
        'operation_type',
        'summary',
        'evidence_memory_ids',
        'attributes_snapshot',
        'reverted_from_version',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'evidence_memory_ids' => 'array',
            'attributes_snapshot' => 'array',
            'reverted_from_version' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}

