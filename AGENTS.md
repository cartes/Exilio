# Instrucciones para agentes de código

## Contexto obligatorio

Antes de planificar o modificar el proyecto, lee `docs/FOUNDATION.md` y `docs/ARCHITECTURE.md`. El primero consolida un resumen de decisiones y no es una transcripción literal del chat de origen.

Si una solicitud parece contradecir una decisión existente, explica el conflicto y consulta antes de reemplazarla. No presentes posibilidades futuras como capacidades ya aprobadas.

## Alcance actual: Fase 2 (Interfaz de observación)

Limita el trabajo a la interfaz de observación y auditoría local para EXILIO-001, su integración con la API de Laravel (Fase 1 completada) y el motor cognitivo, garantizando la inspección de identidad, configuración y memorias persistidas sin inferir estados subjetivos. Operaciones de escritura (creación manual, rectificación y eliminación) restringidas a desarrollo local en localhost sin autenticación multiusuario ni servicios externos.

No realices llamadas reales a Gemini, Claude, OpenAI u Ollama; no despliegues; no generes gastos; no implementes bucles autónomos, navegación web, sueños funcionales, operación multiagente ni autodesarrollo de código. Las interfaces y adaptadores simulados para pruebas sí pueden mantenerse.

Respeta la puntuación de curiosidad `C = 0.30N + 0.25I + 0.30A + 0.15R`, con componentes normalizados y pesos que sumen 1. No precargues rasgos de personalidad ni relevancia adquirida sin evidencia.

## Principios que guían el diseño

- Mantén la identidad persistente y la memoria autobiográfica inicial vacía.
- Distingue y etiqueta hechos, hipótesis y sueños; conserva procedencia suficiente para auditar aprendizajes.
- Modela episódica, semántica, social, procedimental y onírica como categorías conceptuales distintas.
- No programes ni recompenses directamente empatía. Si corresponde, mide comportamientos sociales observables sin afirmar estados subjetivos.
- No asumas conciencia, sentimientos o sufrimiento.
- Trata el acceso web futuro como solo lectura y el autodesarrollo futuro como ejecución aislada en sandbox con controles externos inmutables para el agente.
- No guardes secretos en el repositorio. No ejecutes código producido por agentes.
- Conserva el presupuesto de referencia existente de US$5/mes como límite para una futura operación medida; nunca lo interpretes como permiso para gastar en Fase 2 ni fases previas.

## Forma de trabajar

- Inspecciona el código y la documentación existentes antes de editar; preserva cambios y decisiones previas.
- Mantén el alcance pequeño y coherente con la arquitectura del repositorio. No añadas dependencias ni infraestructura sin necesidad justificada.
- Si detectas una decisión incompatible o una ambigüedad que cambie el alcance, describe el conflicto y pregunta antes de sustituir la decisión.
- Actualiza documentación cuando una modificación cambie una decisión, contrato o límite del sistema.
- No afirmes que una funcionalidad está implementada o verificada si no lo está; distingue hechos, hipótesis y trabajo pendiente.
