# Gestiones del club — Módulo de Equipación (pedidos + pago online)

**Fecha:** 2026-07-24
**Estado:** Aprobado, pendiente de plan de implementación

## Contexto

Primer módulo de una futura sección de "gestiones del club" (equipación, y más adelante salidas/eventos como módulos independientes). Este spec cubre solo **equipación**: socios piden camisetas/gorros/chándal etc. por talla, pagan online con Stripe, y la directiva gestiona catálogo, stock y entregas.

Sigue el patrón ya usado en `cuotas`/`incidencias`: tabla de gestión + roles + estados, con `require_cargo()` (admin siempre pasa) para las páginas de directiva.

## Alcance

- ✅ Catálogo de items (nombre, descripción, precio) con variantes por talla y stock.
- ✅ Carrito multi-item en sesión, checkout único vía Stripe Checkout (tarjeta/Bizum).
- ✅ Reserva atómica de stock al pedir, reposición al cancelar/expirar.
- ✅ Estados de pedido: `pendiente_pago` → `pagado` → `entregado`, o `cancelado`.
- ✅ Recogida física en el club (sin dirección de envío).
- ✅ Gestión de catálogo y pedidos: admin + cualquier cargo de directiva.
- ❌ Fuera de alcance: envíos a domicilio, reembolsos automáticos, módulo de salidas/eventos, notificaciones por email.

## Modelo de datos (migración `018_equipacion.sql`)

```sql
CREATE TABLE equipacion_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    descripcion TEXT NULL,
    precio DECIMAL(8,2) NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_por INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (creado_por) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE equipacion_variantes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_id INT NOT NULL,
    talla VARCHAR(10) NOT NULL,
    stock INT NOT NULL DEFAULT 0,
    FOREIGN KEY (item_id) REFERENCES equipacion_items(id) ON DELETE CASCADE,
    UNIQUE KEY uq_item_talla (item_id, talla)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE equipacion_pedidos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    estado ENUM('pendiente_pago','pagado','entregado','cancelado') NOT NULL DEFAULT 'pendiente_pago',
    total DECIMAL(8,2) NOT NULL,
    stripe_session_id VARCHAR(255) NULL,
    stripe_payment_intent VARCHAR(255) NULL,
    entregado_por INT NULL,
    entregado_at TIMESTAMP NULL,
    cancelado_por INT NULL,
    cancelado_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (entregado_por) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (cancelado_por) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_estado (estado),
    INDEX idx_user (user_id),
    INDEX idx_stripe_session (stripe_session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE equipacion_pedido_lineas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pedido_id INT NOT NULL,
    variante_id INT NOT NULL,
    cantidad INT NOT NULL,
    precio_unitario DECIMAL(8,2) NOT NULL,
    FOREIGN KEY (pedido_id) REFERENCES equipacion_pedidos(id) ON DELETE CASCADE,
    FOREIGN KEY (variante_id) REFERENCES equipacion_variantes(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Notas:
- Precio a nivel de `item` (no varía por talla). `precio_unitario` en la línea es un snapshot al momento del pedido — cambios posteriores de precio en el catálogo no afectan pedidos ya creados.
- `equipacion_variantes.variante_id` usa `ON DELETE RESTRICT`: no se puede borrar una variante con pedidos históricos; para retirarla del catálogo se pone `activo=0` a nivel de item o `stock=0`.

## Páginas

```
public/socio/equipacion.php              -- catálogo (items+variantes activos, stock>0), carrito en sesión
public/socio/equipacion_pedidos.php      -- historial propio, cancelar si pendiente_pago
public/directiva/equipacion.php          -- CRUD catálogo (items+variantes+stock) — require_cargo(todos) + admin
public/directiva/equipacion_pedidos.php  -- listado global, filtro por estado, marcar entregado, cancelar
public/stripe_checkout.php               -- crea Checkout Session y redirige (requiere sesión socio)
public/stripe_webhook.php                -- endpoint público sin auth de sesión, verifica firma Stripe
```

Permisos: páginas de `directiva/` usan `require_cargo(['presidente','secretario','tesorero','vocal','responsable_menores','encargado_redes'])` (mismo patrón que `actas.php`/`cuestiones.php`; `is_admin()` pasa siempre vía el helper existente).

Carrito: `$_SESSION['carrito_equipacion']` = array de `[variante_id => cantidad]`. Se limpia al completar o cancelar el checkout. No se persiste en BD.

## Flujo de pago (transacción crítica)

**Checkout (`stripe_checkout.php`):**
1. Lee carrito de sesión, valida que socio esté logueado y carrito no vacío.
2. Transacción SQL: por cada línea, `UPDATE equipacion_variantes SET stock = stock - ? WHERE id = ? AND stock >= ?` (reserva atómica). Si alguna actualización afecta 0 filas → `ROLLBACK`, flash "sin stock suficiente en [item] talla [talla]", redirige a carrito.
3. Si todas las reservas OK: `INSERT` en `equipacion_pedidos` (estado `pendiente_pago`, total = suma de líneas) + `INSERT` en `equipacion_pedido_lineas` (precio snapshot). `COMMIT`.
4. Llamada a Stripe API (SDK `stripe/stripe-php`): crea `Checkout Session` con `line_items` desde las líneas del pedido, `success_url`/`cancel_url` apuntando a `equipacion_pedidos.php`, `client_reference_id` = id del pedido.
5. Guarda `stripe_session_id` en el pedido, `header('Location: ' . $session->url)`.

**Webhook (`stripe_webhook.php`):**
- Verifica firma con `STRIPE_WEBHOOK_SECRET` (helper del SDK); si falla, responde 400 y no toca BD.
- `checkout.session.completed`: busca pedido por `stripe_session_id` con `SELECT ... FOR UPDATE`; si estado ya es `pagado` (evento duplicado, Stripe reintenta), responde 200 sin hacer nada (idempotente); si no, marca `pagado` + guarda `payment_intent`.
- `checkout.session.expired` (sesión caducada sin pagar): mismo `SELECT ... FOR UPDATE`; si sigue en `pendiente_pago`, marca `cancelado` y repone stock (`stock = stock + cantidad` por cada línea).

**Cancelación manual** (socio su propio pedido en `pendiente_pago` desde `equipacion_pedidos.php`, o directiva cualquier pedido en `pendiente_pago` desde `directiva/equipacion_pedidos.php`): misma transacción con `SELECT ... FOR UPDATE`, repone stock, marca `cancelado`, guarda `cancelado_por`/`cancelado_at`.

Un pedido `pagado` nunca se cancela desde la web — reembolsos se gestionan fuera (Stripe Dashboard + ajuste manual si hiciera falta), fuera de alcance de este módulo.

**Entrega:** desde `directiva/equipacion_pedidos.php`, marcar `pagado` → `entregado` (guarda `entregado_por`/`entregado_at`). No aplica a otros estados.

## Configuración / secretos

- Añadir a `.env` / `.env.example`: `STRIPE_SECRET_KEY`, `STRIPE_PUBLISHABLE_KEY`, `STRIPE_WEBHOOK_SECRET`.
- `config/stripe.php` nuevo: inicializa el SDK con la secret key desde entorno.
- Dockerfile: instalar Composer (`curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer`), añadir `composer.json` con `stripe/stripe-php`, `composer install` en build.

## Edge cases

- Doble-submit del botón "Pagar": deshabilitar vía JS al enviar; server-side la reserva de stock solo ocurre una vez por request de checkout (no hay reintento automático).
- Webhook duplicado: idempotente por el chequeo de estado antes de escribir (ver arriba).
- Firma de webhook inválida: 400, sin cambios en BD, sin exponer detalles del error.
- Race entre webhook de pago y cancelación manual simultánea: `SELECT ... FOR UPDATE` sobre el pedido serializa ambos caminos.
- Desactivar un item del catálogo (`activo=0`) no rompe pedidos históricos (línea tiene snapshot de precio y FK con `ON DELETE RESTRICT` sobre variante).
- Nunca commitear valores reales de `.env` (ya cubierto por `.gitignore` existente).

## Testing (manual — el proyecto no tiene suite automatizada)

1. Levantar Stripe CLI en local: `stripe listen --forward-to localhost:8080/stripe_webhook.php` para recibir eventos en dev.
2. Casos a probar:
   - Pedido normal: carrito → pago con tarjeta test de Stripe → webhook marca `pagado` → directiva marca `entregado`.
   - Sin stock: intentar pedir más cantidad que stock disponible → error, sin crear pedido ni pagar.
   - Cancelación en `pendiente_pago` (por socio y por directiva) → stock repuesto.
   - Expiración de sesión de Stripe (o simular con `stripe trigger checkout.session.expired`) → pedido pasa a `cancelado`, stock repuesto.
   - Webhook reenviado dos veces (Stripe reintenta) → segundo evento no duplica el efecto.
