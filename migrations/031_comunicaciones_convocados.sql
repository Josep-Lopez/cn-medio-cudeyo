-- 031: Comunicaciones dirigidas a los convocados de una competición del entrenador
--  · destinatario_valor = competicion_entrenador.id; se resuelve en vivo contra
--    competicion_entrenador_convocados (si se añade un convocado, también lo ve).

ALTER TABLE comunicaciones
    MODIFY destinatario_tipo ENUM('todos','liga','individual','convocados') NOT NULL DEFAULT 'todos';
