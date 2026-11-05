# Arquitectura inicial

## Límites de componentes

- `backend/`: API REST Laravel 13; posee la persistencia transaccional y expone agentes, memorias y aprendizaje versionado.
- `cognitive-engine/`: biblioteca Python 3.12 independiente; concentra configuración cognitiva, curiosidad, aprendizaje controlado y contratos de proveedores.
- `frontend/`: aplicación SPA en Vue 3, Vite y Tailwind CSS para la interfaz de observación, auditoría de memorias y control de aprendizaje local (Fases 2 y 3).
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

## Interfaz de observación (Fase 2)

La interfaz web en `frontend/` es una SPA basada en Vue 3, Vite y Tailwind CSS diseñada para el rol de auditor/observador local en desarrollo (`localhost`).
- **Inspección de identidad y configuración:** Consulta y presenta la identidad de EXILIO-001, su versión de personalidad y los parámetros de curiosidad ($C = 0.30N + 0.25I + 0.30A + 0.15R$).
- **Visualización y auditoría de memorias:** Presenta los registros persistidos con filtrado por categoría taxonómica y estado epistémico (`fact`, `hypothesis`, `dream`), haciendo explícita la procedencia (`origin`), evidencia (`evidence_ref`) y confianza (`confidence`).
- **Trazabilidad y rectificación:** Muestra la cadena de versiones (`supersedes_id`, `superseded_at`) y el historial de registros purgados en `memory_tombstones`.
- **Operaciones controladas:** Permite crear entradas con origen `manual_entry`, rectificar y eliminar registros exclusivamente bajo demanda del usuario en entorno local.
- **Límites epistémicos y éticos:** No infiere ni renderiza estados antropomórficos o subjetivos (ánimo, emociones, dolor o conciencia); todo estado se presenta como dato computacional observable. Sin llamadas externas, analíticas ni dependencias de red ajenas a localhost.

## Aprendizaje controlado y evolución versionada (Fase 3)

El sistema evalúa y consolida cambios en personalidad, intereses y relevancia adquirida ($R$) bajo demanda mediante una tabla de historial inmutable `agent_learning_versions` y reglas deterministas en `cognitive-engine/personality/`:
- **Evidencia obligatoria y vigencia:** Toda operación de aprendizaje requiere una lista no vacía de `evidence_memory_ids` pertenecientes al agente y vigentes (`superseded_at IS NULL`). Las memorias obsoletas/rectificadas son rechazadas como evidencia.
- **Escala epistémica de tres niveles:**
  - `fact` (Hecho): Consolida rasgos de personalidad (`consolidation_status = consolidated`) y permite que la relevancia adquirida $R$ crezca en el rango $[0, 1.0]$.
  - `hypothesis` (Hipótesis): Produce ajustes tentativos (`consolidation_status = tentative`) ponderados por la `confidence` de las memorias y acota la relevancia adquirida $R$ a un máximo de `0.60`.
  - `dream` (`oneiric`): Nunca consolida hechos ni rasgos de personalidad como experiencia real (`consolidation_status = oneiric_seed`, prohibido alterar rasgos de personalidad si la evidencia es exclusivamente onírica), pero **guía la curiosidad del sistema** generando intereses/consultas exploratorias (`exploratory_query`) y un impulso exploratorio inicial en $R$ acotado a un máximo de `0.25`, conservando explícitamente la etiqueta de origen onírico (`dream`) y calculando su puntuación de curiosidad $C = 0.30N + 0.25I + 0.30A + 0.15R$.
- **Versionado e historial inmutable:** Cada operación (`operation_type`: `initial`, `learning`, `revert`) incrementa `personality_version` en `agents`, actualiza `attributes` (`personality` e `interests`) y guarda un snapshot completo en `agent_learning_versions`.
- **Reversión auditable (Rollback):** Revertir a una versión previa no destruye historial; crea una nueva versión con `operation_type = revert` y `reverted_from_version`, restaurando exactamente el snapshot de `attributes` de la versión objetivo.

## Motor cognitivo y proveedores

La configuración vive en variables de entorno con valores predeterminados reproducibles. La curiosidad es una función pura de componentes normalizados y pesos configurables. `AIProvider` define una interfaz asíncrona sin depender de Gemini; un `MockProvider` local sirve para pruebas. El adaptador Gemini futuro deberá mapear el contrato sin contaminar módulos cognitivos.

## Datos e infraestructura

PostgreSQL 16 es la base de datos inicial. Compose usa imagen compatible con pgvector para simplificar una futura habilitación, sin activar extensión ni guardar embeddings en esta fase. La API y el motor se pueden desplegar como procesos separados; el protocolo entre ellos se definirá cuando exista un caso de uso concreto.

## Controles

Las claves se inyectan desde el entorno. El objetivo presupuestario es US$5/mes, pero su aplicación efectiva requiere telemetría y una puerta de gasto antes de habilitar proveedores reales. No se ejecuta código generado ni se permite a agentes mutar políticas, permisos o controles. No se implementan navegación web, sueños, autodesarrollo o procesos cognitivos continuos en fase 0.
