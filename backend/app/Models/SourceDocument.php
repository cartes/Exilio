<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourceDocument extends Model
{
    use HasUuids;

    protected $table = 'source_documents';

    public $timestamps = false;

    protected $fillable = ['scout_id','agent_id','url','title','http_status','content_hash','text','fetched_at'];

    protected function casts(): array
    {
        return ['fetched_at' => 'datetime','http_status' => 'integer'];
    }

    public function scout(): BelongsTo
    {
        return $this->belongsTo(ResearchScout::class, 'scout_id');
    }
}
