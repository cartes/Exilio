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

## Motor cognitivo y proveedores

La configuración vive en variables de entorno con valores predeterminados reproducibles. La curiosidad es una función pura de componentes normalizados y pesos configurables. `AIProvider` define una interfaz asíncrona sin depender de Gemini; un `MockProvider` local sirve para pruebas. El adaptador Gemini futuro deberá mapear el contrato sin contaminar módulos cognitivos.

## Datos e infraestructura

PostgreSQL 16 es la base de datos inicial. Compose usa imagen compatible con pgvector para simplificar una futura habilitación, sin activar extensión ni guardar embeddings en esta fase. La API y el motor se pueden desplegar como procesos separados; el protocolo entre ellos se definirá cuando exista un caso de uso concreto.

## Controles

Las claves se inyectan desde el entorno. El objetivo presupuestario es US$5/mes, pero su aplicación efectiva requiere telemetría y una puerta de gasto antes de habilitar proveedores reales. No se ejecuta código generado ni se permite a agentes mutar políticas, permisos o controles. No se implementan navegación web, sueños, autodesarrollo o procesos cognitivos continuos en fase 0.
