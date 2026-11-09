<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResearchFinding extends Model
{
    use HasUuids;

    protected $table = 'research_findings';

    public $timestamps = false;

    protected $fillable = ['scout_id','agent_id','source_document_id','excerpt','excerpt_hash','relevance','status','memory_id'];

    protected function casts(): array
    {
        return ['relevance' => 'float','created_at' => 'datetime'];
    }

    public function scout(): BelongsTo
    {
        return $this->belongsTo(ResearchScout::class, 'scout_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(SourceDocument::class, 'source_document_id');
    }
}
