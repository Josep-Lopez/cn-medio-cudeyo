# Objetivos y comentarios por prueba Implementation Plan

**Goal:** Objetivo completable e hilo de comentarios por prueba inscrita, visibles para el nadador con su resultado.

**Spec:** docs/superpowers/specs/2026-11-24-objetivos-comentarios-competicion-design.md

**Ejecución:** en la sesión, sin commits hasta que se pidan. Sin framework de tests: script temporal con rollback y comprobación HTTP con usuarios desechables.

## Review Focus
- Tiempo objetivo mal escrito («1:05abc», «0»): error y no se inscribe nada.
- Reenviar la inscripción de una prueba ya inscrita: no pisa su objetivo.
- Resultado en otra piscina: el estado automático sigue pendiente.
- Nadador que comenta la inscripción de otro: rechazado.
- Recalcular marcas: conserva objetivo y comentarios.

- [ ] 1. `migrations/035_objetivos_competicion.sql`
- [ ] 2. Helpers en `includes/competicion_entrenador.php`: `ce_datos_objetivo`, `guardar_objetivo_prueba`, `ce_estado_objetivo`, `ce_mejores_resultados`, `obtener_inscripcion`, `listar_comentarios_pruebas`, `agregar_comentario_prueba`, `render_ce_objetivo_badge`, `render_ce_hilo`
- [ ] 3. Admin: alta con objetivo, edición, hilo, confirmación al quitar
- [ ] 4. Socio: resultado, objetivo, hilo y respuesta
- [ ] 5. CSS, CLAUDE.md y verificación
