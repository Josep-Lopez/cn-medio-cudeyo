-- 037: Avisos de comentarios en pruebas y tiempos de competición
--  · Por usuario e hilo se guarda el último comentario leído.
--  · tipo 'prueba' → ref_id = competicion_entrenador_pruebas.id; 'tiempo' → competicion_entrenador_tiempos.id
--  · Los comentarios ya existentes quedan como leídos (nadador dueño y staff actual).

CREATE TABLE IF NOT EXISTS competicion_entrenador_vistos (
    user_id INT NOT NULL,
    tipo ENUM('prueba','tiempo') NOT NULL,
    ref_id INT NOT NULL,
    ultimo_id INT NOT NULL,
    PRIMARY KEY (user_id, tipo, ref_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO competicion_entrenador_vistos (user_id, tipo, ref_id, ultimo_id)
SELECT u.id, h.tipo, h.ref_id, h.ultimo_id
FROM (
    SELECT 'prueba' AS tipo, p.id AS ref_id, p.user_id AS dueno, MAX(k.id) AS ultimo_id
    FROM competicion_entrenador_prueba_comentarios k
    JOIN competicion_entrenador_pruebas p ON p.id = k.inscripcion_id
    GROUP BY p.id, p.user_id
    UNION ALL
    SELECT 'tiempo', t.id, t.user_id, MAX(k.id)
    FROM competicion_entrenador_comentarios k
    JOIN competicion_entrenador_tiempos t ON t.id = k.tiempo_id
    GROUP BY t.id, t.user_id
) h
JOIN users u ON u.id = h.dueno OR u.rol = 'admin'
    OR EXISTS (SELECT 1 FROM cargos g WHERE g.user_id = u.id AND g.cargo IN ('director_tecnico', 'entrenador')
               AND (g.fecha_fin IS NULL OR g.fecha_fin > CURDATE()));
