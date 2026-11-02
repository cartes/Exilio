<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modelo Memory
 *
 * Representa un registro de memoria explícito, auditable y tipificado de un agente.
 *
 * Principios y garantías epistemológicas (Fase 1):
 * - Taxonomía conceptual fija: cinco tipos (`episodic`, `semantic`, `social`, `procedural`, `oneiric`).
 * - Distinción epistemológica: `epistemic_status` (`fact`, `hypothesis`, `dream`).
 *   - `oneiric` exige y solo admite `dream`.
 *   - Otras categorías solo admiten `fact` o `hypothesis`.
 * - Procedencia de captura vs Evidencia: `origin` indica el canal (`user_input`, `system_event`,
 *   `manual_entry`, `agent_inference`). Los hechos (`fact`) requieren orígenes observables y nunca
 *   admiten `agent_inference`.
 * - Confianza acotada: `confidence` expresa incertidumbre numérica [0.0, 1.0] única y exclusivamente
 *   para hipótesis. En hechos y sueños es obligatoriamente `NULL`.
 * - Inmutabilidad y Versionado: la corrección no altera registros in-place, sino que genera una nueva
 *   versión que referencia `supersedes_id`, sellando la versión original con `superseded_at`.
 */
class Memory extends Model
{
    use HasUuids;

    /**
     * Categorías conceptuales permitidas.
     */
    public const TYPES = [
        'episodic',
        'semantic',
        'social',
        'procedural',
        'oneiric',
    ];

    /**
     * Estatus epistemológicos de certeza.
     */
    public const EPISTEMIC_STATUSES = [
        'fact',
        'hypothesis',
        'dream',
    ];

    /**
     * Vías de origen/captura de la memoria.
     */
    public const ORIGINS = [
        'user_input',
        'system_event',
        'manual_entry',
        'agent_inference',
    ];

    /**
     * Atributos asignables en masa.
     *
     * @var list<string>
     */
    protected $fillable = [
        'agent_id',
        'type',
        'epistemic_status',
        'origin',
        'content',
        'confidence',
        'evidence_ref',
        'occurred_at',
        'supersedes_id',
        'superseded_at',
        'metadata',
    ];

    /**
     * Conversión de tipos de atributos.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'occurred_at' => 'datetime',
            'superseded_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * Agente al que pertenece la memoria.
     *
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * Memoria previa a la que este registro rectifica o reemplaza.
     *
     * @return BelongsTo<Memory, $this>
     */
    public function supersededMemory(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /**
     * Memorias posteriores que han rectificado a este registro.
     *
     * @return HasMany<Memory, $this>
     */
    public function supersedingMemories(): HasMany
    {
        return $this->hasMany(self::class, 'supersedes_id');
    }

    /**
     * Scope para filtrar únicamente registros vigentes (no rectificados).
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('superseded_at');
    }

    /**
     * Scope para filtrar por categoría conceptual.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Scope para filtrar por estatus epistemológico.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOfEpistemicStatus(Builder $query, string $status): Builder
    {
        return $query->where('epistemic_status', $status);
    }
}
