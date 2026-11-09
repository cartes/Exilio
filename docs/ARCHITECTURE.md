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

## Integración de IA Real & Diálogo Introspectivo (Fase 4)

La arquitectura integra Gemini mediante un patrón híbrido de introspección cognitiva anclada (RAG sobre el estado interno del agente):
- **Introspection Context Builder (`backend/app/Services/IntrospectionService.php`):** Compila el estado computacional del agente:
  - Identidad, versión de personalidad actual (`vX.0`) y resumen del último cambio.
  - Rasgos de personalidad clasificados (`consolidated` por hechos vs `tentative` por hipótesis) con sus IDs de evidencia.
  - Intereses activos, relevancia adquirida $R \in [0, 1]$, cálculo de curiosidad $C$ y semillas oníricas con preguntas exploratorias (`exploratory_query`).
  - Memorias recientes organizadas según la diferenciación epistémica (`fact`, `hypothesis` con certeza %, `dream` con advertencia onírica).
  - Auditoría de evidencia obsoleta o purgada.
- **Adaptador Gemini y Compuerta Presupuestaria (`GeminiService` y `cognitive-engine/providers/gemini.py`):**
  - Conexión vía API REST a Gemini (`gemini-2.5-flash` / `gemini-1.5-flash`) usando `GEMINI_API_KEY`.
  - Compuerta de gasto: Verifica el consumo acumulado mensual en `ai_usage_logs`. Si supera los US$5.00/mes o no hay clave, activa un fallback seguro al generador introspectivo local o `MockProvider`.
  - Telemetría: Registra cada llamada con tokens de entrada, salida, coste estimado en USD y timestamp.
- **Endpoints de Diálogo (`backend/routes/api.php`):**
  - `POST /api/agents/{agent}/chat`: Procesa el mensaje del observador, persiste la memoria episódica (`user_input`), ejecuta la respuesta fundamentada con Gemini y devuelve la respuesta junto a la telemetría y metadatos de fundamentación.
  - `GET /api/agents/{agent}/chat`: Retorna el historial de interacción.
  - `GET /api/agents/{agent}/ai-usage`: Consulta el consumo presupuestario del mes actual frente al límite de US$5.00.
- **Consola de Diálogo en Frontend (`frontend/src/App.vue`):**
  - Componente interactivo para conversar con EXILIO-001, con visualizador de consumo de cuota mensual y badges de fuentes referenciadas en cada respuesta.
- **Ciclo de Indagación Cognitiva bajo Demanda (`ExplorationService.php`):**
  - Permite al observador activar un ciclo de exploración guiado por la mayor puntuación de curiosidad $C = 0.30N + 0.25I + 0.30A + 0.15R$.
  - Endpoints:
    - `POST /api/agents/{agent}/explore`: Ejecuta la deliberación cognitiva sobre la pregunta exploratoria activa, formula una hipótesis tentativa (con `confidence` en $[0.10, 0.90]$) y sugiere la siguiente pregunta orientadora.
    - `POST /api/agents/{agent}/explore/persist`: Registra el hallazgo aprobado como memoria autobiográfica en `memories` (`origin: agent_inference`, `epistemic_status: hypothesis`) y actualiza la indagación guiada en el interés.
  - Interfaz: Botones de indagación en el panel de curiosidad y en el chat, con modal dedicado que muestra razonamiento, hipótesis y telemetría para su aprobación explícita por el auditor.

## Motor onírico mínimo (Fase 5)

`DreamService` (`backend/app/Services/DreamService.php`) genera simulaciones internas bajo demanda a partir de memorias vigentes `fact`/`hypothesis`:
- `POST /api/agents/{agent}/dream`: propone un sueño (narrativa, tema y pregunta verificable, fuentes y telemetría) sin persistir. `is_fallback` indica recombinación local en lugar de generación del modelo. Responde 422 con menos de 2 memorias fuente vigentes.
- `POST /api/agents/{agent}/dream/persist`: tras aprobación explícita, crea una memoria `oneiric`/`dream` (`origin: agent_inference`, sin `confidence`) y siembra un interés `oneiric_seed` (R ≤ 0.25) en una nueva versión de aprendizaje. No altera rasgos de personalidad; las fuentes deben ser memorias vigentes no oníricas del agente.
- Reutiliza `GeminiService` (compuerta de US$5/mes y telemetría) y las reglas de curiosidad de `AgentLearningController`.

## Motor cognitivo y proveedores

La configuración vive en variables de entorno con valores predeterminados reproducibles. La curiosidad es una función pura de componentes normalizados y pesos configurables. `AIProvider` define una interfaz asíncrona sin depender de Gemini; un `MockProvider` local sirve para pruebas. El adaptador Gemini futuro deberá mapear el contrato sin contaminar módulos cognitivos.

## Datos e infraestructura

PostgreSQL 16 es la base de datos inicial. Compose usa imagen compatible con pgvector para simplificar una futura habilitación, sin activar extensión ni guardar embeddings en esta fase. La API y el motor se pueden desplegar como procesos separados; el protocolo entre ellos se definirá cuando exista un caso de uso concreto.

## Controles

Las claves se inyectan desde el entorno. El objetivo presupuestario es US$5/mes, pero su aplicación efectiva requiere telemetría y una puerta de gasto antes de habilitar proveedores reales. No se ejecuta código generado ni se permite a agentes mutar políticas, permisos o controles. No se implementan navegación web, autodesarrollo o procesos cognitivos continuos; el motor onírico existe solo en su forma mínima bajo demanda (Fase 5).
