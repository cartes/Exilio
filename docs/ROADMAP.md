# Roadmap de Proyecto Exilio

## Criterio de trabajo

Este roadmap ordena el desarrollo por hitos verificables. La Fase 0 es el único alcance activo. Las fases posteriores son una dirección acordada para planificar; cada una requiere revisar su alcance y registrar las decisiones pendientes antes de implementarla. Completar una fase no activa automáticamente la siguiente.

Se mantienen los límites de `FOUNDATION.md`: no asumir conciencia, sentimientos o sufrimiento; mantener inicialmente vacía la memoria autobiográfica; distinguir hechos, hipótesis y sueños; conservar procedencia auditable; no realizar llamadas reales a modelos, incurrir en gastos ni desplegar durante Fase 0.

## Fase 0 — Fundamentos (actual)

**Objetivo:** consolidar arquitectura, identidad persistente de EXILIO-001, curiosidad y pruebas.

**Trabajo previsto:**

- Dejar ejecutable el motor cognitivo local con configuración reproducible y proveedor simulado.
- Completar y comprobar el esqueleto de API Laravel y su persistencia PostgreSQL local.
- Mantener la personalidad y la memoria autobiográfica iniciales vacías.
- Conservar la puntuación de curiosidad `C = 0.30N + 0.25I + 0.30A + 0.15R`, con señales normalizadas y pesos que sumen 1.
- Asegurar que las pruebas cubran los contratos y límites implementados.

**Criterio de salida:** los componentes base pueden ejecutarse localmente siguiendo instrucciones documentadas; la identidad fundadora se representa de forma estable; los contratos y la fórmula de curiosidad tienen cobertura reproducible; no hay conexiones a proveedores reales ni procesos autónomos.

## Fase 1 — Persistencia explícita

**Objetivo:** acordar e implementar un modelo de datos mínimo, auditable y corregible.

**Decisiones previas:** esquema y retención por categoría de memoria; procedencia; mecanismos de corrección y eliminación; distinción entre hechos e hipótesis; límites de acceso.

**Criterio de salida propuesto:** los registros persistidos tienen origen y tipo explícitos, pueden inspeccionarse y corregirse, y no se confunden con hechos verificados cuando son hipótesis. La memoria no se llena automáticamente.

## Fase 2 — Interfaz de observación

**Objetivo:** ofrecer una interfaz para inspeccionar identidad, configuración y registros persistidos.

**Decisiones previas:** usuarios y permisos, datos visibles, acciones permitidas y requisitos de privacidad.

**Criterio de salida propuesto:** la interfaz muestra el estado respaldado por la API y permite revisar procedencia sin inferir estados subjetivos.

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
