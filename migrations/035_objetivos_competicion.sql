-- 035: Objetivos y comentarios por prueba en las competiciones del entrenador
--  · objetivo_estado es la decisión manual del entrenador; NULL = automático (resultado vs. tiempo objetivo).

ALTER TABLE competicion_entrenador_pruebas
    ADD COLUMN objetivo VARCHAR(255) DEFAULT NULL AFTER marca_fecha,
    ADD COLUMN objetivo_tiempo VARCHAR(20) DEFAULT NULL AFTER objetivo,
    ADD COLUMN objetivo_seg DECIMAL(8,2) DEFAULT NULL AFTER objetivo_tiempo,
    ADD COLUMN objetivo_estado ENUM('conseguido','no_conseguido') DEFAULT NULL AFTER objetivo_seg;

CREATE TABLE IF NOT EXISTS competicion_entrenador_prueba_comentarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inscripcion_id INT NOT NULL,
    user_id INT NOT NULL,
    contenido TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (inscripcion_id) REFERENCES competicion_entrenador_pruebas(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
