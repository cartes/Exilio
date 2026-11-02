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

## Fase 2 — Interfaz de observación (actual)

**Objetivo:** ofrecer una interfaz para inspeccionar identidad, configuración y registros persistidos.

**Decisiones acordadas:**

- **Usuarios y permisos:** Rol único de auditor/observador local en desarrollo; sin autenticación multiusuario ni proveedores de identidad externos.
- **Entorno y límites:** Operación restringida a localhost; sin llamadas a servicios externos, CDNs ajenos ni telemetría/analíticas.
- **Datos visibles:** Identidad y estado de EXILIO-001, versión de personalidad, configuración de curiosidad ($C = 0.30N + 0.25I + 0.30A + 0.15R$), registros de memoria clasificados por taxonomía y estado epistémico (`fact`, `hypothesis`, `dream`), procedencia y evidencia (`origin`, `evidence_ref`), niveles de confianza (solo en hipótesis), árbol de versiones/rectificaciones (`supersedes_id`) y registro de auditoría de eliminaciones (`memory_tombstones`).
- **Salvaguardas de presentación:** Prohibición estricta de inferir o presentar estados subjetivos ("ánimo", "sentimientos", "conciencia"); visualización puramente computacional y observable.
- **Acciones permitidas:** Inspección y filtrado de datos; operaciones de escritura disponibles solo en desarrollo local (creación manual con `manual_entry`, rectificación de registros y eliminación con purga hacia tombstones).
- **Stack:** Vue 3 + Vite + Tailwind CSS en `frontend/`, comunicándose con la API local de Laravel (`backend/`).

**Criterio de salida propuesto:** la interfaz web se ejecuta en localhost, muestra el estado respaldado por la API de Laravel, permite auditar procedencia y rectificaciones sin inferir estados subjetivos, y permite ejecutar las operaciones de auditoría autorizadas.

## Fase 3 — Aprendizaje controlado

**Objetivo:** evaluar cambios derivados de experiencias registradas mediante operaciones explícitas y revisables.

**Decisiones previas:** definición de aprendizaje, evaluación, versionado de personalidad e intereses, reversión y salvaguardas. Cualquier relevancia adquirida debe apoyarse en evidencia registrada.

**Criterio de salida propuesto:** cada cambio puede rastrearse hasta su evidencia, compararse con criterios documentados y revertirse.

## Fase 4 — Evaluación de integraciones externas

**Objetivo:** decidir, sin activación automática, si se habilitan servicios externos.

**Decisiones previas:** necesidad concreta, privacidad, permisos, telemetría y controles efectivos de gasto. El presupuesto de referencia de US$5/mes no es autorización para gastar. El acceso web futuro sería solo de lectura y cualquier proveedor real requeriría aprobación y controles verificables.

**Criterio de salida propuesto:** una decisión documentada por integración, con pruebas y controles externos antes de habilitarla. El despliegue y las llamadas reales permanecen fuera de Fase 0.

## Revisión entre fases

Antes de iniciar cada fase posterior, revisar `FOUNDATION.md` y `ARCHITECTURE.md`, resolver las decisiones pendientes pertinentes, acotar el alcance y actualizar documentación. No se implementan bucles autónomos, navegación web, sueños funcionales, operación multiagente ni autodesarrollo como parte de este roadmap sin una decisión explícita futura.
