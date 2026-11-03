-- 033: Resultados de competición importados desde RFEN
--  · Los tiempos guardan su origen: manual (entrenador) o rfen (copiados de marcas tras el cron).
--  · Si el entrenador borra un tiempo importado, se anota como descarte para que el cron no lo vuelva a meter.

ALTER TABLE competicion_entrenador_tiempos
    ADD COLUMN origen ENUM('manual','rfen') NOT NULL DEFAULT 'manual' AFTER parciales;

CREATE TABLE IF NOT EXISTS competicion_entrenador_rfen_descartes (
    competicion_id INT NOT NULL,
    user_id INT NOT NULL,
    prueba VARCHAR(10) NOT NULL,
    piscina ENUM('25m','50m') NOT NULL,
    PRIMARY KEY (competicion_id, user_id, prueba, piscina),
    FOREIGN KEY (competicion_id) REFERENCES competicion_entrenador(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
