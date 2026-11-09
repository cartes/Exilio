<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agent extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'status', 'personality_version', 'attributes'];

    protected $appends = ['evidence_audit'];

    protected function casts(): array
    {
        return ['attributes' => 'array'];
    }

    /**
     * Audita si las memorias de evidencia que respaldan los rasgos e intereses actuales
     * siguen vigentes o si alguna fue posteriormente rectificada (superseded) o purgada.
     *
     * @return array{
     *   referenced_memory_ids: list<string>,
     *   superseded_memory_ids: list<string>,
     *   purged_memory_ids: list<string>,
     *   stale_memory_ids: list<string>,
     *   has_stale_evidence: bool
     * }
     */
    public function getEvidenceAuditAttribute(): array
    {
        $attrs = $this->attributes['attributes'] ?? null;
        if (is_string($attrs)) {
            $attrs = json_decode($attrs, true) ?: [];
        } elseif (!is_array($attrs)) {
            $attrs = [];
        }

        $referenced = [];
        foreach (($attrs['personality'] ?? []) as $trait) {
            foreach (($trait['evidence_memory_ids'] ?? []) as $id) {
                $referenced[] = (string) $id;
            }
        }
        foreach (($attrs['interests'] ?? []) as $interest) {
            foreach (($interest['evidence_memory_ids'] ?? []) as $id) {
                $referenced[] = (string) $id;
            }
        }

        $referenced = array_values(array_unique($referenced));
        if (empty($referenced)) {
            return [
                'referenced_memory_ids' => [],
                'superseded_memory_ids' => [],
                'purged_memory_ids' => [],
                'stale_memory_ids' => [],
                'has_stale_evidence' => false,
            ];
        }

        $existingMemories = $this->memories()
            ->whereIn('id', $referenced)
            ->get(['id', 'superseded_at'])
            ->keyBy('id');

        $superseded = [];
        $purged = [];

        foreach ($referenced as $memId) {
            $mem = $existingMemories->get($memId);
            if (!$mem) {
                $purged[] = $memId;
            } elseif ($mem->superseded_at !== null) {
                $superseded[] = $memId;
            }
        }

        $stale = array_values(array_unique(array_merge($superseded, $purged)));

        return [
            'referenced_memory_ids' => $referenced,
            'superseded_memory_ids' => $superseded,
            'purged_memory_ids' => $purged,
            'stale_memory_ids' => $stale,
            'has_stale_evidence' => !empty($stale),
        ];
    }

    public function memories(): HasMany
    {
        return $this->hasMany(Memory::class);
    }

    public function tombstones(): HasMany
    {
        return $this->hasMany(MemoryTombstone::class);
    }

    public function learningVersions(): HasMany
    {
        return $this->hasMany(AgentLearningVersion::class);
    }

    public function aiUsageLogs(): HasMany
    {
        return $this->hasMany(AiUsageLog::class);
    }

    public function chatMessages(): HasMany
    {
        return $this->hasMany(AgentChatMessage::class);
    }

    public function researchScouts(): HasMany
    {
        return $this->hasMany(ResearchScout::class);
    }
}
