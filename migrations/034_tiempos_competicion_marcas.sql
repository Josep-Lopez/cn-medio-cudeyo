-- 034: Los tiempos manuales de competición pasan a la tabla marcas (ranking, perfil, récords)
--  · marca_id = marca creada desde ese tiempo; al borrar el tiempo se borra la marca.
--  · Si luego llega de RFEN la marca oficial de esas fechas, la manual se borra (marca_id queda NULL).
--  · Los tiempos manuales que ya existían se copian ahora a marcas.

ALTER TABLE competicion_entrenador_tiempos
    ADD COLUMN marca_id INT NULL AFTER origen,
    ADD CONSTRAINT fk_cet_marca FOREIGN KEY (marca_id) REFERENCES marcas(id) ON DELETE SET NULL;

-- Copiar los manuales existentes (si ya hay una marca con la misma clave no se toca y no se enlaza)
INSERT IGNORE INTO marcas (user_id, prueba, piscina, tiempo, tiempo_seg, fecha_marca, lugar)
SELECT t.user_id, t.prueba, t.piscina, t.tiempo, t.tiempo_seg, c.fecha, COALESCE(NULLIF(c.lugar, ''), c.nombre)
FROM competicion_entrenador_tiempos t
JOIN competicion_entrenador c ON c.id = t.competicion_id
WHERE t.origen = 'manual'
  AND NOT EXISTS (SELECT 1 FROM marcas m WHERE m.user_id = t.user_id AND m.prueba = t.prueba AND m.piscina = t.piscina
                    AND m.fecha_marca BETWEEN c.fecha AND DATE_ADD(c.fecha, INTERVAL 2 DAY));

UPDATE competicion_entrenador_tiempos t
JOIN competicion_entrenador c ON c.id = t.competicion_id
JOIN marcas m ON m.user_id = t.user_id AND m.prueba = t.prueba AND m.piscina = t.piscina
             AND m.fecha_marca = c.fecha AND m.lugar = COALESCE(NULLIF(c.lugar, ''), c.nombre)
             AND m.tiempo_seg = t.tiempo_seg AND m.created_at >= NOW() - INTERVAL 1 MINUTE
SET t.marca_id = m.id
WHERE t.origen = 'manual' AND t.marca_id IS NULL;
