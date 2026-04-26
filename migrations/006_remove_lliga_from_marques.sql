-- Migración 006: eliminar columna lliga de marques (la categoría viene de users)

ALTER TABLE marques DROP COLUMN lliga;
