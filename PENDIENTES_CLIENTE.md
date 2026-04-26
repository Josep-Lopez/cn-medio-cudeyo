# Pendientes del Proyecto

Documento de trabajo con mejoras y funcionalidades pendientes, ordenadas por prioridad y agrupadas por área.

## Prioridad Alta

### Marcas y RFEN

- [x] Revisar la gestión de categorías en las marcas — RESUELTO: eliminada columna `lliga` de `marques`, la categoría viene de `users`.
- [x] Permitir importar marcas RFEN desde 2012 — RESUELTO: selector de temporadas ampliado fins 2012-13.
- [x] Poder importar marcas RFEN de todos los usuarios — RESUELTO: script CLI `scripts/rfen_import_all.php`.
- [x] Hacer que la importación de marcas sea automática (semanal) — RESUELTO: crontab dimecres 6h (`0 6 * * 3`).

### Rankings

- [x] Al cambiar de temporada, el ranking debe ordenarse automáticamente siempre por tiempo — RESUELTO: sort per defecte canviat a temps ASC.
- [x] Añadir un ranking de mejores marcas — RESUELTO: "Mejores marcas" mostra totes les marques de totes les temporades ordenades per temps.

## Prioridad Media

### Filtros y navegación

- [x] Añadir en todos los filtros la opción de mostrar "todos" — RESUELTO: temporada "Todas", categoria "Todas", prova "Todas" als rankings. Temporades des de 2012.

### Marcas

- [x] En la pantalla de marcas, añadir acceso al ranking del club — ja existia el botó "Ver ranking".

### Categorías

- [x] Separar `junior` de `absoluto` en todos los desplegables de categoría — ya estaba implementado en el código.

## Prioridad Media-Baja

### Nuevas páginas o secciones

- [ ] Añadir una página o bloque con:
  - récords del club (basados en las marcas existentes)
  - tops 10
  - histórico de medallas
  - palmarés

### Información pública del club

- [ ] Mostrar las redes sociales del club (Instagram, Gmail). **Pendiente de recibir URLs del cliente.**

## Orden de Implementación

1. Revisar categorías (separar junior/absoluto) y la lógica de marcas/RFEN.
2. Mejorar importación de marcas: años históricos, todos los usuarios y automatización semanal.
3. Ajustar rankings: orden por tiempo y ranking de temporada.
4. Unificar filtros con opción "todos".
5. Añadir acceso al ranking desde marcas.
6. Añadir páginas de récords/tops/palmarés (basados en marcas).
7. Incorporar redes sociales y datos públicos de contacto.

## Notas

- Algunas tareas están relacionadas entre sí, especialmente las de categorías, RFEN, marcas y rankings.
- Conviene cerrar primero la lógica de categorías antes de automatizar la importación completa.
- La página de récords se genera a partir de las marcas ya existentes en BD, no necesita datos externos.
