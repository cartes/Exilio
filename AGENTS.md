# Instrucciones para agentes de código

## Contexto obligatorio

Antes de planificar o modificar el proyecto, lee `docs/FOUNDATION.md` y `docs/ARCHITECTURE.md`. El primero consolida un resumen de decisiones y no es una transcripción literal del chat de origen.

Si una solicitud parece contradecir una decisión existente, explica el conflicto y consulta antes de reemplazarla. No presentes posibilidades futuras como capacidades ya aprobadas.

## Alcance actual: Fase 3 (Aprendizaje controlado)

Limita el trabajo al aprendizaje controlado, auditable y reversible de EXILIO-001 (Fase 3), manteniendo operativas la persistencia explícita (Fase 1), la interfaz de observación local (Fase 2) y el motor cognitivo (Fase 0). Todo cambio en personalidad, intereses o relevancia adquirida (`R`) debe ejecutarse bajo demanda a partir de memorias vigentes (`superseded_at IS NULL`), quedar respaldado por evidencia trazable (`evidence_memory_ids`), incrementar `personality_version` y poder revertirse a cualquier versión previa.

Aplica la diferenciación epistémica de tres niveles en el aprendizaje:
- **Hechos (`fact`):** consolidan rasgos de personalidad y relevancia adquirida (`R` en `[0, 1]`).
- **Hipótesis (`hypothesis`):** producen ajustes tentativos ponderados por su `confidence` (con tope acotado en `R` de `0.60`).
- **Sueños (`dream` / `oneiric`):** nunca consolidan hechos ni rasgos de personalidad como realidad vivida, pero sí guían la curiosidad generando intereses/preguntas exploratorias (`exploratory_query`) con contribución acotada a `R` (tope `0.25`) e identificación explícita de origen onírico.

No realices llamadas reales a Gemini, Claude, OpenAI u Ollama; no despliegues; no generes gastos; no implementes bucles autónomos, navegación web, operación multiagente ni autodesarrollo de código. Las interfaces y adaptadores simulados para pruebas sí pueden mantenerse.

Respeta la puntuación de curiosidad `C = 0.30N + 0.25I + 0.30A + 0.15R`, con componentes normalizados y pesos que sumen 1. No precargues rasgos de personalidad ni relevancia adquirida sin evidencia.

## Principios que guían el diseño

- Mantén la identidad persistente y la memoria autobiográfica inicial vacía.
- Distingue y etiqueta hechos, hipótesis y sueños; conserva procedencia suficiente para auditar aprendizajes.
- Modela episódica, semántica, social, procedimental y onírica como categorías conceptuales distintas.
- No programes ni recompenses directamente empatía. Si corresponde, mide comportamientos sociales observables sin afirmar estados subjetivos.
- No asumas conciencia, sentimientos o sufrimiento.
- Trata el acceso web futuro como solo lectura y el autodesarrollo futuro como ejecución aislada en sandbox con controles externos inmutables para el agente.
- No guardes secretos en el repositorio. No ejecutes código producido por agentes.
- Conserva el presupuesto de referencia existente de US$5/mes como límite para una futura operación medida; nunca lo interpretes como permiso para gastar en Fase 3 ni fases previas.

## Forma de trabajar

- Inspecciona el código y la documentación existentes antes de editar; preserva cambios y decisiones previas.
- Mantén el alcance pequeño y coherente con la arquitectura del repositorio. No añadas dependencias ni infraestructura sin necesidad justificada.
- Si detectas una decisión incompatible o una ambigüedad que cambie el alcance, describe el conflicto y pregunta antes de sustituir la decisión.
- Actualiza documentación cuando una modificación cambie una decisión, contrato o límite del sistema.
- No afirmes que una funcionalidad está implementada o verificada si no lo está; distingue hechos, hipótesis y trabajo pendiente.
