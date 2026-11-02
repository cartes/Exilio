<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agent extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'status', 'personality_version', 'attributes'];

    protected function casts(): array
    {
        return ['attributes' => 'array'];
    }

    public function memories(): HasMany
    {
        return $this->hasMany(Memory::class);
    }

    public function tombstones(): HasMany
    {
        return $this->hasMany(MemoryTombstone::class);
    }
}
