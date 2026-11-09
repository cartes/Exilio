# Roadmap de Proyecto Exilio

## Criterio de trabajo

Este roadmap ordena el desarrollo por hitos verificables. La Fase 0 es el único alcance activo. Las fases posteriores son una dirección acordada para planificar; cada una requiere revisar su alcance y registrar las decisiones pendientes antes de implementarla. Completar una fase no activa automáticamente la siguiente.

Se mantienen los límites de `FOUNDATION.md`: no asumir conciencia, sentimientos o sufrimiento; mantener inicialmente vacía la memoria autobiográfica; distinguir hechos, hipótesis y sueños; conservar procedencia auditable; no realizar llamadas reales a modelos, incurrir en gastos ni desplegar durante Fase 0.


## Fase 0 — Fundamentos (completada)

**Objetivo:** consolidar arquitectura, identidad persistente de EXILIO-001, curiosidad y pruebas.

**Trabajo previsto:**

- Dejar ejecutable el motor cognitivo local con configuración reproducible y proveedor simulado.
- Completar y comprobar el esqueleto de API Laravel y su persistencia PostgreSQL local.
- Mantener la personalidad y la memoria autobiográfica iniciales vacías.
- Conservar la puntuación de curiosidad `C = 0.30N + 0.25I + 0.30A + 0.15R`, con señales normalizadas y pesos que sumen 1.
- Asegurar que las pruebas cubran los contratos y límites implementados.

**Criterio de salida:** componentes base ejecutables localmente con pruebas passing en Python y Laravel/PHPUnit; identidad `EXILIO-001` persistente y verificada; sin conexiones a proveedores externos ni procesos autónomos.

## Fase 1 — Persistencia explícita (completada)

**Objetivo:** acordar e implementar un modelo de datos mínimo, auditable y corregible.

**Decisiones acordadas:**

- Tabla unificada `memories` con FK a `agents` (cascada al eliminar agente).
- Taxonomía explícita en cinco categorías (`episodic`, `semantic`, `social`, `procedural`, `oneiric`).
- Distinción epistémica rigurosa: `oneiric` exige y solo admite `dream`; otras categorías solo admiten `fact` o `hypothesis`.
- Separación de procedencia y evidencia: `origin` observable obligatorio para `fact` (rechaza inferencias internas como hechos); `evidence_ref` para soporte comprobable.
- `confidence` acotado exclusivamente a `hypothesis` en $[0, 1]$; forzosamente `NULL` en hechos y sueños.
- Ciclo de vida explícito sin mutación destructiva: inmutabilidad por defecto, rectificación por versionado referencial (`supersedes_id`, `superseded_at`), y eliminación con purga de contenido y registro en `memory_tombstones`.
- Persistencia bajo demanda sin bucles autónomos de fondo.
- Restricciones duales: `CHECK` constraints a nivel de base de datos y validación de API.

**Criterio de salida:** los registros persistidos tienen origen y tipo explícitos, pueden inspeccionarse y rectificarse auditadamente, se purgan de forma efectiva al eliminarse, y las restricciones de base de datos y API impiden registrar hipótesis o sueños como hechos. La memoria no se llena automáticamente.

## Fase 2 — Interfaz de observación (completada)

**Objetivo:** ofrecer una interfaz para inspeccionar identidad, configuración y registros persistidos.

**Decisiones acordadas:**

- **Usuarios y permisos:** Rol único de auditor/observador local en desarrollo; sin autenticación multiusuario ni proveedores de identidad externos.
- **Entorno y límites:** Operación restringida a localhost; sin llamadas a servicios externos, CDNs ajenos ni telemetría/analíticas.
- **Datos visibles:** Identidad y estado de EXILIO-001, versión de personalidad, configuración de curiosidad ($C = 0.30N + 0.25I + 0.30A + 0.15R$), registros de memoria clasificados por taxonomía y estado epistémico (`fact`, `hypothesis`, `dream`), procedencia y evidencia (`origin`, `evidence_ref`), niveles de confianza (solo en hipótesis), árbol de versiones/rectificaciones (`supersedes_id`) y registro de auditoría de eliminaciones (`memory_tombstones`).
- **Salvaguardas de presentación:** Prohibición estricta de inferir o presentar estados subjetivos ("ánimo", "sentimientos", "conciencia"); visualización puramente computacional y observable.
- **Acciones permitidas:** Inspección y filtrado de datos; operaciones de escritura disponibles solo en desarrollo local (creación manual con `manual_entry`, rectificación de registros y eliminación con purga hacia tombstones).
- **Stack:** Vue 3 + Vite + Tailwind CSS en `frontend/`, comunicándose con la API local de Laravel (`backend/`).

**Criterio de salida:** la interfaz web se ejecuta en localhost, muestra el estado respaldado por la API de Laravel, permite auditar procedencia y rectificaciones sin inferir estados subjetivos, y permite ejecutar las operaciones de auditoría autorizadas.

## Fase 3 — Aprendizaje controlado (completada)

**Objetivo:** evaluar cambios derivados de experiencias registradas mediante operaciones explícitas y revisables.

**Decisiones acordadas:**

- **Dimensiones que evolucionan:** Tanto rasgos/atributos de personalidad (`personality`) como temas de interés (`interests`) con su relevancia adquirida ($R \in [0, 1]$) y puntuación de curiosidad ($C = 0.30N + 0.25I + 0.30A + 0.15R$). El estado inicial de `EXILIO-001` permanece vacío hasta registrar operaciones con evidencia.
- **Activación y aplicación:** Operación explícita bajo demanda (`POST /api/agents/{agent}/learn`) que aplica directamente una nueva versión (`personality_version`) respaldada por memorias vigentes (`superseded_at IS NULL`), almacenando un snapshot inmutable en `agent_learning_versions`.
- **Diferenciación epistémica en tres niveles (`fact` / `hypothesis` / `dream`):**
  - **Hechos (`fact`):** Consolidan rasgos de personalidad y permiten relevancia adquirida $R$ hasta `1.0`.
  - **Hipótesis (`hypothesis`):** Generan ajustes tentativos ponderados por `confidence`, con tope de relevancia adquirida $R \le 0.60$.
  - **Sueños (`dream` / `oneiric`):** Nunca consolidan hechos ni rasgos de personalidad como realidad vivida, pero **guían la curiosidad del sistema** generando intereses/preguntas exploratorias (`exploratory_query`) y un impulso exploratorio inicial acotado ($R \le 0.25$), siempre identificado explícitamente como de origen onírico (`dream`).
- **Reversión (Rollback):** Operación explícita (`POST /api/agents/{agent}/versions/{version}/revert`) que restaura el estado de cualquier versión previa creando un nuevo registro de versión auditable (`operation_type = revert`) sin borrar el historial.
- **Lógica cognitiva:** Reglas deterministas puras en `cognitive-engine/personality/` (con soporte para `MockProvider` y sin llamadas externas) replicadas en el validador/evaluador transaccional del backend y auditables desde el frontend local.

**Criterio de salida:** cada cambio en personalidad, intereses o relevancia adquirida ($R$) puede rastrearse hasta sus memorias de evidencia, respeta los límites epistémicos de hechos, hipótesis y sueños, incrementa la versión del agente y puede revertirse auditadamente.

## Fase 4 — Integraciones externas & Diálogo introspectivo (en curso)

**Objetivo:** integrar Gemini como proveedor de lenguaje natural y habilitar diálogo interactivo anclado a la introspección del agente con control de gasto verificable.

**Decisiones acordadas:**

- **Proveedor y modelo:** Gemini API (con modelo predeterminado `gemini-2.5-flash` / `gemini-1.5-flash`), configurable vía `GEMINI_API_KEY` en entorno `.env` local.
- **Anclaje introspectivo estricto (Opción B + A):** Antes de cada turno conversacional, el sistema compila un snapshot de introspección con el estado exacto de EXILIO-001 (versión activa, rasgos consolidados y tentativos, intereses, curiosidad $C$, semillas oníricas y memorias recientes clasificadas). El prompt del sistema fuerza al modelo a hablar exclusivamente desde estos datos verificados, prohibiendo inventar memorias o afirmar consciencia subjetiva.
- **Registro conversacional auditable:** Cada mensaje del usuario se persiste como memoria episódica (`user_input`), y la interacción generada por el agente queda trazada y disponible en el historial de conversación.
- **Compuerta presupuestaria y telemetría:** Registro en tabla `ai_usage_logs` con recuento de tokens (prompt, completion) y estimación de coste en USD frente al límite de referencia de US$5.00/mes. Si se supera el límite o no se proporciona `GEMINI_API_KEY`, el sistema conmuta automáticamente al generador introspectivo local o proveedor simulado (`MockProvider`) protegiendo el presupuesto.
- **Consola de diálogo en frontend:** Panel interactivo en Vue 3 para conversar en tiempo real con EXILIO-001, visualizar telemetría de gasto en vivo y auditar las fuentes de datos que fundamentan sus respuestas.

**Criterio de salida:** el usuario puede dialogar interactivamente con EXILIO-001 desde la interfaz; el agente responde en lenguaje natural fundamentándose con precisión en sus memorias y rasgos; el consumo de tokens y presupuesto de US$5/mes se audita y limita efectivamente; y ante ausencia de clave o exceso de presupuesto el sistema degrada de manera segura sin interrumpir la observación.

## Fase 5 — Motor onírico mínimo (en curso)

**Decisión:** el usuario autorizó explícitamente (2026-11-09) abrir el motor onírico como primer paso de la visión de entidad que investiga y evoluciona. Es la única capacidad nueva de esta fase; web, autodesarrollo y multiagente siguen sin autorizar.

**Decisiones acordadas:**

- **Solo bajo demanda y con aprobación:** `POST /api/agents/{agent}/dream` propone un sueño sin persistir; `POST /api/agents/{agent}/dream/persist` lo registra tras aprobación explícita del auditor. Sin bucles de fondo.
- **Material de origen:** recombina memorias vigentes `fact` o `hypothesis` (mínimo 2, máximo 4, priorizando variedad temática). Sin experiencia suficiente responde 422: no se sueña de la nada. Nunca usa sueños ni memorias rectificadas como fuente.
- **Estatus epistémico:** el sueño se guarda como `oneiric` + `dream`, origen `agent_inference`, `confidence = NULL`, con `metadata.source_memory_ids` para auditar la procedencia.
- **Efecto sobre curiosidad:** siembra un interés `oneiric_seed` con R = 0.15 (tope 0.25) y una `exploratory_query` verificable, creando una versión auditable. Si el interés ya existe (por hechos o hipótesis), solo se enlaza la evidencia onírica sin cambiar R. **Nunca** modifica rasgos de personalidad.
- **Proveedor y gasto:** pasa por `GeminiService` y su compuerta de US$5/mes. Sin clave o presupuesto agotado usa una recombinación determinista local, marcada `is_fallback: true`.
- **Pruebas herméticas:** `phpunit.xml` fuerza `AI_PROVIDER=mock` y sin clave, para que la suite nunca llame al proveedor real.

**Criterio de salida:** un sueño aprobado aparece como `dream` auditable con sus fuentes, aumenta la curiosidad hacia una pregunta verificable sin consolidar hechos ni rasgos, y puede revertirse como cualquier versión.

## Fase 6 — Scouts de investigación web (en curso)

**Decisión:** el usuario autorizó (2026-11-09) que EXILIO-001 investigue en internet mediante agentes acotados («scouts»), con estas elecciones: (1) **buscador general** (SearXNG autoalojado, sin clave), (2) **Laravel primero y Python después**, (3) **EXILIO propone** las misiones.

**Decisiones acordadas (Fase 6a):**

- **Solo lectura y sin código generado:** el scout es código fijo parametrizado por una carta de misión (tema, pregunta, máximo de páginas). No ejecuta código producido por agentes ni envía formularios, cookies ni JavaScript.
- **Propone EXILIO, aprueba el auditor:** `propose` elige el interés de mayor curiosidad C (sin IA) y crea una misión `proposed`. Nada corre sin `approve` + `run` explícitos. Sin bucles ni ejecución programada.
- **Puerta web inmutable (`config/research.php` + `WebGate`):** solo http/https en puertos 80/443, bloqueo de IPs privadas/loopback/reservadas (anti-SSRF, con resolución fijada y revalidada en cada redirección), `robots.txt`, tipos de contenido y tamaño/tiempo máximos, lista de dominios bloqueados y topes duros que la carta de misión no puede superar. Botón de cancelación que detiene el scout entre páginas.
- **Búsqueda abierta:** al no haber lista blanca de dominios, el control se apoya en la puerta, el tope de páginas (máx. 2 por dominio) y la aprobación humana de cada hallazgo.
- **Epistemología:** el contenido web es dato no confiable. Un hallazgo aprobado se guarda como `fact` solo en el sentido observable «la fuente X afirma Y» (`origin: system_event`, con URL, fecha y hash). Que lo afirmado sea cierto sigue siendo una hipótesis y queda para la síntesis con modelo (6b).
- **Sin IA en 6a:** la extracción es determinista (texto limpio y frases relevantes por coincidencia con la pregunta); no consume presupuesto.

**Fases siguientes (cada una requiere revisión):** 6b síntesis con modelo bajo la compuerta de US$5/mes (hipótesis con confianza y procedencia); 6c varios scouts y misiones desde las preguntas de los sueños; 6d motor en Python. Ejecución continua/programada y autodesarrollo siguen sin autorizar.

**Criterio de salida 6a:** EXILIO propone una misión; tras aprobarla y lanzarla, el scout busca, descarga páginas por la puerta, guarda las fuentes con procedencia y propone hallazgos que el auditor aprueba o rechaza; ningún control del scout es modificable por el agente.

## Revisión entre fases

Antes de iniciar cada fase posterior, revisar `FOUNDATION.md` y `ARCHITECTURE.md`, resolver las decisiones pendientes pertinentes, acotar el alcance y actualizar documentación. No se implementan bucles autónomos, operación multiagente más allá de los scouts ni autodesarrollo como parte de este roadmap sin una decisión explícita futura. El motor onírico (Fase 5) y la investigación web de solo lectura (Fase 6) quedan autorizados únicamente en la forma mínima descrita en cada fase.
