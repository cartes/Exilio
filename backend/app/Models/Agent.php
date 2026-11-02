<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Agent extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'status', 'personality_version', 'attributes'];

    protected function casts(): array
    {
        return ['attributes' => 'array'];
    }
}
