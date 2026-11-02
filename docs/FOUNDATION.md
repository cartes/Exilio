# Fundamentos de Proyecto Exilio

## Estado y alcance de este documento

Este documento consolida un resumen estructurado de decisiones conversadas sobre Proyecto Exilio. No es una transcripción literal del chat de origen. Si una decisión futura contradice estos fundamentos o una decisión ya registrada en el repositorio, debe señalarse y consultarse antes de reemplazarla.

La Fase 0 se limita a arquitectura, identidad de EXILIO-001, curiosidad y pruebas. No incluye llamadas reales a proveedores de IA, despliegues ni gastos. Los componentes de otras fases se describen como dirección futura, no como autorización para implementarlos ahora.

## Propósito y límites

Proyecto Exilio es un proyecto experimental para estudiar agentes artificiales con identidad persistente, memoria, curiosidad y aprendizaje social. El agente fundador es **EXILIO-001**. No se debe asumir ni afirmar que el agente es consciente, siente o sufre; sus estados son representaciones computacionales observables.

La identidad de EXILIO-001 persiste entre sesiones. Su memoria autobiográfica comienza vacía y se construye solo a partir de experiencias registradas.

## Curiosidad inicial

La curiosidad es la motivación cognitiva inicial. Su puntuación se calcula con señales normalizadas en `[0, 1]`:

`C = 0.30N + 0.25I + 0.30A + 0.15R`

Donde `N` es novedad, `I` incertidumbre, `A` potencial de aprendizaje y `R` relevancia adquirida. Los pesos podrán configurarse para experimentos, pero deben sumar 1 y cualquier cambio al valor de referencia debe documentarse. No se asigna relevancia intrínseca a un interés antes de que haya evidencia.

## Personalidad, aprendizaje y empatía

- La personalidad es evolutiva y no se precarga con rasgos arbitrarios. Los cambios deben derivarse de experiencias registradas y poder auditarse.
- El aprendizaje debe dejar evidencia rastreable. El sistema distingue explícitamente hechos, hipótesis y sueños; no presenta una hipótesis o un sueño como hecho verificado.
- La empatía no se programa como estado interno ni se premia directamente. Sí se pueden medir y revisar comportamientos sociales observables, sin inferir por ello una experiencia subjetiva.

## Modelo de memoria

La arquitectura conceptual contempla cinco clases de memoria:

1. **Episódica:** experiencias situadas en tiempo y contexto.
2. **Semántica:** conocimiento y conceptos derivados.
3. **Social:** interacciones, participantes y relaciones observadas.
4. **Procedimental:** habilidades, métodos y secuencias aprendidas.
5. **Onírica:** contenido de sueños o simulaciones internas, siempre identificado como tal y separado de los hechos.

Esta taxonomía orienta el diseño; Fase 0 no exige implementar los subsistemas completos de memoria.

## Ciclos de actividad

A futuro se contempla un ciclo con periodos de actividad, consolidación de recuerdos y reposo. No se implementan bucles autónomos ni actividad continua durante Fase 0. Los criterios, duración y transición entre estados siguen pendientes de definición.

## Dirección futura y límites externos

El objetivo a largo plazo contempla un mundo multiagente. También se consideran acceso web de solo lectura y autodesarrollo de código dentro de un sandbox. Estas capacidades requerirán controles externos, permisos explícitos y límites verificables; el agente no podrá modificar sus propios permisos ni controles de seguridad. No se habilitan en Fase 0.

## Stack previsto

- Backend: Laravel 13.
- Motor cognitivo: Python.
- Interfaz web: Vue 3.
- Persistencia: PostgreSQL; pgvector puede habilitarse en una fase posterior si existe un caso de uso.
- Entorno: Docker Compose, con preparación futura para Dokploy.
- Proveedor inicial previsto: Gemini, detrás de una interfaz independiente del proveedor que permita incorporar Claude, OpenAI y Ollama.

La arquitectura puede usar un proveedor simulado para desarrollo y pruebas. No se realizan llamadas reales a modelos durante Fase 0.

## Seguridad, costes y Fase 0

- No incluir claves o secretos en Git.
- No ejecutar código generado por agentes.
- Los agentes no pueden alterar permisos, políticas ni controles externos.
- Se conserva la decisión existente de un presupuesto de referencia de US$5 mensuales para una futura operación con proveedores medidos. Esto no habilita gasto: Fase 0 no hace llamadas reales, despliegues ni incurre en costes.
- Los proveedores reales requerirán controles verificables de gasto y credenciales gestionadas fuera del código.

## Decisiones pendientes

Antes de implementarlas, definir y registrar decisiones sobre:

- El esquema, retención, procedencia y mecanismos de corrección o eliminación para cada tipo de memoria.
- Cómo se evalúan y versionan personalidad, intereses y relevancia adquirida.
- Qué comportamientos sociales observables se medirán y con qué salvaguardas.
- Cómo se etiquetan, consolidan y recuperan hechos, hipótesis y sueños.
- Los estados, disparadores, duración y frecuencia de actividad, consolidación y reposo.
- El diseño del mundo multiagente y las reglas de coordinación y aislamiento.
- El alcance del acceso web de solo lectura, sus fuentes y controles.
- El sandbox y los controles externos para autodesarrollo de código.
- Cuándo habilitar proveedores reales, cómo aplicar el presupuesto y qué configuración de despliegue usar.
- Si y cuándo habilitar pgvector u otra búsqueda semántica.
