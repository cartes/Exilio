<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResearchScout extends Model
{
    use HasUuids;

    protected $table = 'research_scouts';

    public $timestamps = false;

    protected $fillable = ['agent_id','topic','query','status','origin','proposal_reason','max_pages','pages_fetched','run_log','error','started_at','finished_at'];

    protected function casts(): array
    {
        return ['run_log' => 'array','started_at' => 'datetime','finished_at' => 'datetime','created_at' => 'datetime','max_pages' => 'integer','pages_fetched' => 'integer'];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(SourceDocument::class, 'scout_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(ResearchFinding::class, 'scout_id');
    }
}
