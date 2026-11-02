<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo MemoryTombstone
 *
 * Registra una traza mínima e inmutable de auditoría ("lápida" o tombstone) cuando
 * un registro de memoria es purgado físicamente del sistema.
 *
 * Propósito y garantías arquitectónicas (Fase 1):
 * - Cumplimiento del derecho de supresión y eliminación efectiva: en lugar de un soft-delete
 *   que mantiene el contenido sensible retenido indefinidamente, la memoria original se elimina
 *   de forma destructiva de la tabla `memories`.
 * - Trazabilidad y auditoría sin fuga de datos: este modelo preserva únicamente el identificador
 *   original de la memoria, el agente al que pertenecía, el motivo de la supresión y la marca
 *   temporal del evento, sin retener el texto, hipótesis, hechos o metadata purgados.
 * - Inmutabilidad: una vez emitido un tombstone, no es modificable.
 */
class MemoryTombstone extends Model
{
    use HasUuids;

    /**
     * Indica si el modelo debe gestionar timestamps automáticos (created_at y updated_at).
     * Dado que un tombstone es un evento puntual inmutable, solo utiliza `created_at`.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Atributos asignables en masa.
     *
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'memory_id',
        'agent_id',
        'reason',
        'created_at',
    ];

    /**
     * Conversión de tipos de atributos.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * Agente asociado al que pertenecía la memoria purgada.
     *
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
