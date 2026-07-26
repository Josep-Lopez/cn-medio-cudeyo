-- 020: Equipación — foto de producto + artículos bajo pedido

ALTER TABLE equipacion_items
  ADD COLUMN imagen_url VARCHAR(255) NULL AFTER descripcion,
  ADD COLUMN bajo_pedido TINYINT(1) NOT NULL DEFAULT 0 AFTER precio;
