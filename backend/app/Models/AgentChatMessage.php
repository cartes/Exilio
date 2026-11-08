<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentChatMessage extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'agent_id',
        'role',
        'content',
        'provider',
        'grounding_snapshot',
        'tokens_used',
        'cost_usd',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'grounding_snapshot' => 'array',
            'tokens_used' => 'integer',
            'cost_usd' => 'float',
            'created_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}

