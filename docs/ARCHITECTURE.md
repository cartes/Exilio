# Arquitectura inicial

## Límites de componentes

- `backend/`: API REST Laravel 13; posee la persistencia transaccional y expone agentes.
- `cognitive-engine/`: biblioteca Python 3.12 independiente; concentra configuración cognitiva, curiosidad y contratos de proveedores.
- `frontend/`: espacio reservado para Vue 3, Vite y Tailwind en una fase posterior.
- `infrastructure/`: espacio para despliegue y configuración futura; Compose inicial vive en la raíz para facilitar el arranque.
- `experiments/`: experimentos reproducibles aislados del runtime.
- `docs/`: decisiones y principios vigentes.

## Entidad Agent

La tabla `agents` almacena identidad estable (`id` UUID, `name`, `status`), versión de personalidad y un documento JSONB para atributos iniciales extensibles. EXILIO-001 se crea mediante migración como identidad fundadora con personalidad y memoria autobiográfica vacías. El esquema evita diseñar aún subsistemas de memoria o ciclo de vida autónomo.

## Persistencia explícita y modelo de memorias (Fase 1)

El modelo de memoria se articula en una tabla unificada `memories` respaldada por `memory_tombstones`:
- **Taxonomía:** `type` restringido a cinco categorías (`episodic`, `semantic`, `social`, `procedural`, `oneiric`).
- **Separación epistémica:** `epistemic_status` (`fact`, `hypothesis`, `dream`). `oneiric` exige y solo admite `dream`. Las demás categorías solo admiten `fact` o `hypothesis`.
- **Procedencia y certeza:** `origin` registra la vía de captura (`user_input`, `system_event`, `manual_entry`, `agent_inference`). Los hechos (`fact`) requieren origen observable y rechazan `agent_inference`. La incertidumbre se modela mediante `confidence` (float en $[0, 1]$), permitido obligatoriamente solo en `hypothesis` y forzosamente `NULL` en hechos y sueños.
- **Inmutabilidad y Rectificación:** No se realizan mutaciones destructivas in-place. La rectificación crea una nueva versión referenciando `supersedes_id` y marca la anterior con `superseded_at`.
- **Eliminación:** La eliminación purga el registro de `memories` y crea un registro mínimo de auditoría en `memory_tombstones` sin retener texto sensible.
- **Restricciones de integridad:** Reglas forzadas tanto a nivel de constraints de base de datos (`CHECK`) como en la capa de validación de la API. Persistencia exclusivamente bajo demanda sin bucles en segundo plano.

## Motor cognitivo y proveedores

La configuración vive en variables de entorno con valores predeterminados reproducibles. La curiosidad es una función pura de componentes normalizados y pesos configurables. `AIProvider` define una interfaz asíncrona sin depender de Gemini; un `MockProvider` local sirve para pruebas. El adaptador Gemini futuro deberá mapear el contrato sin contaminar módulos cognitivos.

## Datos e infraestructura

PostgreSQL 16 es la base de datos inicial. Compose usa imagen compatible con pgvector para simplificar una futura habilitación, sin activar extensión ni guardar embeddings en esta fase. La API y el motor se pueden desplegar como procesos separados; el protocolo entre ellos se definirá cuando exista un caso de uso concreto.

## Controles

Las claves se inyectan desde el entorno. El objetivo presupuestario es US$5/mes, pero su aplicación efectiva requiere telemetría y una puerta de gasto antes de habilitar proveedores reales. No se ejecuta código generado ni se permite a agentes mutar políticas, permisos o controles. No se implementan navegación web, sueños, autodesarrollo o procesos cognitivos continuos en fase 0.
