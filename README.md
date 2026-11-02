# Proyecto Exilio

Monorepo experimental para explorar agentes artificiales con memoria persistente. Esta fase implementa únicamente los cimientos; no incluye bucles autónomos ni ejecución de código generado.

## Requisitos

- PHP 8.3+ y Composer (Laravel 13 requiere PHP 8.3+).
- Python 3.12+.
- Docker Compose v2.
- Node.js 20+ solo cuando se inicialice el frontend.

## Inicio local

1. Copia `.env.example` a `.env` y ajusta `DB_PASSWORD` para tu entorno local.
2. Inicia PostgreSQL: `docker compose up -d postgres`.
3. Instala backend: `cd backend && composer install && cp .env.example .env && php artisan key:generate && php artisan migrate`.
4. Ejecuta pruebas de backend: `cd backend && composer test`.
5. Ejecuta API: `cd backend && php artisan serve`.
6. Verifica motor cognitivo: `cd cognitive-engine && python3.12 main.py`.
7. Ejecuta pruebas del motor: `cd cognitive-engine && python3.12 -m unittest discover -s tests -v`.

El servicio PostgreSQL usa la imagen pgvector para dejar disponible la futura extensión; todavía no se habilita ni se usa vectorización. El frontend está reservado para una fase posterior.

## Costes y secretos

El presupuesto de referencia es US$5 mensuales (`AI_MONTHLY_BUDGET_USD`). La fase 0 no realiza llamadas a modelos. Guarda las claves únicamente en `.env` local o en el gestor de secretos del despliegue; nunca las commits. Los adaptadores reales deberán validar gasto acumulado y respetar el presupuesto antes de cada solicitud.

## Estado de instalación

El esqueleto Laravel está fijado a `^13.0`. Si Composer no puede alcanzar Packagist durante la instalación, reintenta `composer install` cuando haya conectividad. Python usa solo biblioteca estándar.

Consulta [docs/FOUNDATION.md](docs/FOUNDATION.md) y [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

El orden de hitos está en [docs/ROADMAP.md](docs/ROADMAP.md). La Fase 0 es el alcance activo; las fases posteriores requieren revisión antes de implementarse.
