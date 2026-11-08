# Instrucciones para agentes de código

## Contexto obligatorio

Antes de planificar o modificar el proyecto, lee `docs/FOUNDATION.md` y `docs/ARCHITECTURE.md`. El primero consolida un resumen de decisiones y no es una transcripción literal del chat de origen.

Si una solicitud parece contradecir una decisión existente, explica el conflicto y consulta antes de reemplazarla. No presentes posibilidades futuras como capacidades ya aprobadas.

## Alcance actual: Fase 4 (Integraciones externas y diálogo introspectivo)

Limita el trabajo a la integración controlada de Gemini como proveedor de IA real (Fase 4), combinada con el motor de introspección basado en los datos verificados de EXILIO-001 (Fase 3), manteniendo operativas la persistencia explícita (Fase 1), la interfaz de observación local (Fase 2) y el motor cognitivo (Fase 0).

La interacción conversacional debe anclarse estrictamente al estado computacional del agente:
- **Anclaje introspectivo estricto:** El modelo de lenguaje (Gemini) recibe el estado autobiográfico exacto del agente (versión activa, rasgos consolidados y tentativos, intereses, curiosidad $C$ y memorias clasificadas por certeza epistémica). No debe alucinar hechos no vividos ni inventar rasgos ficticios.
- **Diferenciación epistémica:** En el diálogo, EXILIO-001 distingue explícitamente entre hechos (`fact`), hipótesis (`hypothesis`) y simulaciones oníricas (`dream`).
- **Salvaguardas éticas:** No asume consciencia, sentimientos humanos ni sufrimiento; reporta sus estados computacionales de forma honesta.
- **Control presupuestario y telemetría:** Toda llamada a Gemini debe pasar por una compuerta de gasto verificable que respete el presupuesto de referencia de US$5/mes, registrando consumo de tokens y coste estimado. Si no hay clave de API configurada (`GEMINI_API_KEY`) o se supera el presupuesto, el sistema recurre al proveedor simulado (`MockProvider`) o generador introspectivo sin fallar destructivamente.

No realices llamadas reales a Claude u OpenAI; no despliegues en producción; no implementes bucles autónomos sin demanda del usuario, navegación web, operación multiagente ni autodesarrollo de código. No guardes claves ni secretos en Git.

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
