# Objetivos y comentarios por prueba en competiciones

Estado: aprobado el 2026-11-24.

## Intención
El entrenador apunta al nadador a una prueba y le deja un objetivo y comentarios. El deportista los ve con su resultado en «Mis competiciones». El objetivo se puede marcar como completado.

## Objetivos
- Un objetivo por nadador × prueba: texto libre (máx. 255) y tiempo objetivo opcional. Se puede poner al inscribir y editar después.
- Estado: pendiente / conseguido / no conseguido.
- Hilo de comentarios por prueba inscrita, entre entrenador (staff) y nadador, antes y después de nadar.
- Panel del socio: objetivo, estado, comentarios, resultado, diferencia con la marca de inscripción y enlace «Ver».

## No objetivos
- Varios objetivos por prueba; que el nadador marque el objetivo; objetivos en relevos; avisos de comentario nuevo.
- No se modifica el hilo existente de cada tiempo.

## Enfoques
- A: objetivo y nota única en la inscripción. Más simple, pero el nadador no puede responder.
- **B (elegido por el usuario):** objetivo en la inscripción + hilo por prueba.
- C: lista de objetivos por prueba + hilo. Más pantallas al apuntar a muchos nadadores.

## Diseño
- Migración 035 (el módulo no está en `schema.sql`):
  - `competicion_entrenador_pruebas`: `objetivo`, `objetivo_tiempo`, `objetivo_seg`, `objetivo_estado` ENUM('conseguido','no_conseguido') NULL.
  - `competicion_entrenador_prueba_comentarios`: `inscripcion_id` (FK, cascada), `user_id`, `contenido`, `created_at`.
- `objetivo_estado` es la decisión manual y siempre manda. Con NULL («Automático»): si hay tiempo objetivo y resultado en la piscina de la competición, conseguido si resultado <= objetivo y no conseguido si es mayor; en otro caso, pendiente.
- El resultado es el mejor tiempo del nadador en esa prueba y competición, con preferencia por la piscina de la competición.
- Recalcular marcas no toca objetivos ni comentarios.
- Admin, pestaña Convocatoria: campos opcionales al añadir la prueba; debajo de cada prueba, el objetivo con su estado, la edición y el hilo (plegados para no alargar la lista).
- Quitar una prueba con objetivo o comentarios pide confirmación en modal propio. El aviso de quitar de la convocatoria también los menciona.
- Socio: los resultados sin inscripción, si está convocado, salen como filas propias sin objetivo. «Mis tiempos de competición» se mantiene.
- Permisos: el nadador solo ve y comenta sus inscripciones; el objetivo solo lo edita el staff. CSRF en todos los formularios.

## A criterio de quien lo construya
Estilos, textos e iconos, dentro del diseño actual y en móvil.
