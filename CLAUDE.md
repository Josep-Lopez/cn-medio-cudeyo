# CN Medio Cudeyo — Guía para Claude Code

## Stack
- **PHP 8.4** + Apache (via Docker)
- **MySQL 8** (via Docker)
- **Sin frameworks** — PHP puro, PDO, sessions nativas
- **Docker Compose** para entorno local

## Arrancar el proyecto
```bash
cd /home/lou/web-natacion-medio-cudeyo/cn-medio-cudeyo
docker compose up -d

# App:        http://localhost:8080
# phpMyAdmin: http://localhost:8081
# Admin:      admin@cnmediocudeyo.es / Admin1234!
```

## Estructura de directorios
```
cn-medio-cudeyo/
├── docker-compose.yml     ← PHP:8080, MySQL, phpMyAdmin:8081
├── Dockerfile             ← php:8.4-apache, pdo_mysql, mod_rewrite
├── schema.sql             ← Se ejecuta automáticamente al primer `up`
├── .env                   ← Credenciales BD (NO subir a git)
├── config/
│   └── db.php             ← PDO connection ($pdo global)
├── includes/
│   ├── auth.php           ← Helpers auth, session, flash, utils
│   ├── layout.php         ← render_header(), render_footer(), render_admin_layout()
│   └── rfen.php           ← Helpers importación RFEN
└── public/                ← DocumentRoot de Apache
    ├── assets/
    │   ├── css/main.css   ← CSS global (variables, componentes, responsive)
    │   └── js/calculadora.js ← FINA_TIMES, MINIMAS, calcularAQUA(), etc.
    ├── index.php           ← Homepage pública
    ├── login.php           ← Acceso socios
    ├── logout.php          ← Destruye sesión
    ├── register.php        ← Solicitud de alta (estado=pendiente)
    ├── sobre-nosotros.php  ← Página estática
    ├── calculadoras.php    ← 3 calculadoras JS (AQUA, Mínimas, Parciales)
    ├── biblioteca.php      ← Carpetas colapsables con documentos
    ├── noticias/
    │   ├── index.php       ← Listado paginado (12/página)
    │   └── detall.php      ← Detalle noticia (?id=X)
    ├── admin/
    │   ├── usuarios.php    ← CRUD usuarios + aprobar/rechazar
    │   ├── marcas.php      ← Gestión marcas por liga > nadador
    │   ├── ranking.php     ← Ranking con filtros liga/prueba/piscina
    │   ├── noticias.php    ← CRUD noticias
    │   ├── config.php      ← Configuración (temporada, FINA, mínimas)
    │   ├── contacto.php    ← Mensajes de contacto
    │   ├── rfen_buscar.php ← API búsqueda deportistas RFEN
    │   ├── rfen_importar.php ← Importar marcas desde RFEN
    │   ├── incidencias.php ← Listado + nueva + detalle + edit + eliminar
    │   └── incidencia_descargar.php ← Descarga de adjuntos
    └── socio/
        ├── panel.php       ← Marcas personales + calendario
        ├── perfil.php      ← Perfil del socio + cambio contraseña
        ├── ranking.php     ← Ranking de la liga (con filtros)
        ├── incidencias.php ← Listado + nueva + detalle (solo propias visibles)
        └── incidencia_descargar.php ← Descarga de adjuntos (con check)
```

## Convenciones PHP

### Includes desde subdirectorios
```php
// Desde public/*.php (1 nivel de public/)
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/layout.php';

// Desde public/admin/*.php o public/socio/*.php (2 niveles)
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
```

### Session y Auth helpers (`includes/auth.php`)
```php
require_login()           // Redirige a /login.php si no logueado
require_admin()           // 403 si no es admin
current_user()            // Array de sesión o null
is_admin()                // bool

tiempo_a_segundos("1:05.43") // → 65.43 (float)
segundos_a_tiempo(65.43)     // → "1:05.43" (string)
format_prueba("50L")         // → "50 Libre"
format_liga("alevin")        // → "Alevín"

flash("Mensaje", "success")   // Guardar flash (success/danger/info/warning)
get_flash()                    // Recuperar y borrar flash
render_flash()                 // Imprimir HTML del flash
e($string)                     // htmlspecialchars() shorthand
```

### Layout helpers (`includes/layout.php`)
```php
render_header($title, $activePage)    // HTML head + navbar
render_footer()                        // footer + mobile menu JS
render_admin_layout($activePage, fn)  // Sidebar admin + main
```

### $pdo (PDO global de config/db.php)
- Charset: utf8mb4
- Mode: ERRMODE_EXCEPTION, FETCH_ASSOC
- Siempre usar prepared statements

## Base de datos

**Nombre:** `cn_medio_cudeyo`

| Tabla | Descripción |
|-------|-------------|
| `users` | Socios y admins (bcrypt, estado, liga, sexo) |
| `noticias` | Noticias publicables |
| `biblioteca` | Documentos por categoría |
| `marcas` | Marcas de natación (UNIQUE: user+prueba+piscina+temporada) |
| `config` | Par clave-valor (fina_times, minimas_rfen, temporada_activa) |
| `contactos` | Mensajes del formulario de contacto |
| `incidencias` | Incidencias del club (lesiones/conducta/operativas/justificantes) con estados y visibilidad por socio |
| `incidencia_adjuntos` | Ficheros adjuntos por incidencia (PDF/JPG/PNG, máx 5 MB, máx 5/incidencia) |
| `incidencia_comentarios` | Hilo de comentarios bidireccional (admin + socio asociado) |

**Tiempo format:** Almacenado como `mm:ss.cc` string + `tiempo_seg` float para ordenar.

## Diseño

### Colores
```css
--blue:  #093FB4   /* Principal, botones */
--red:   #BF4646   /* Peligro, error */
--green: #16a34a   /* Éxito */
--text:  #111
--gray:  #888
--bg:    #f5f5f5
```

### Font
Inter (Google Fonts) + Arial fallback

### Breakpoints responsive
- 991px → Tablet (navbar hamburger, admin sidebar oculto)
- 767px → Mobile landscape
- 479px → Mobile

## Flujo de autenticación

1. Socio se registra → `estado = pendiente`
2. Admin aprueba desde `/admin/usuarios.php` → `estado = activo`
3. Login → sesión `$_SESSION['user']` con: id, nombre, email, rol, liga, sexo, estado
4. Admin → redirige a `/admin/usuarios`
5. Socio → redirige a `/socio/panel`

## Ligas (categorías)
`benjamin` · `alevin` · `infantil` · `junior` · `absoluto` · `master`

## Pruebas (18 pruebas)
`50L 100L 200L 400L 800L 1500L` (Libre)
`50E 100E 200E` (Espalda)
`50B 100B 200B` (Braza)
`50M 100M 200M` (Mariposa)
`100X 200X 400X` (Estilos)

## Estado de páginas

| Página | Archivo | Estado |
|--------|---------|--------|
| Inicio | `public/index.php` | ✅ |
| Login | `public/login.php` | ✅ |
| Registro | `public/register.php` | ✅ |
| Sobre nosotros | `public/sobre-nosotros.php` | ✅ |
| Calculadoras | `public/calculadoras.php` | ✅ |
| Biblioteca | `public/biblioteca.php` | ✅ |
| Noticias — Lista | `public/noticias/index.php` | ✅ |
| Noticias — Detalle | `public/noticias/detall.php` | ✅ |
| Admin — Usuarios | `public/admin/usuarios.php` | ✅ |
| Admin — Marcas | `public/admin/marcas.php` | ✅ |
| Admin — Ranking | `public/admin/ranking.php` | ✅ |
| Admin — Noticias | `public/admin/noticias.php` | ✅ |
| Admin — Config | `public/admin/config.php` | ✅ |
| Admin — Contacto | `public/admin/contacto.php` | ✅ |
| Socio — Panel | `public/socio/panel.php` | ✅ |
| Socio — Ranking | `public/socio/ranking.php` | ✅ |
| Socio — Perfil | `public/socio/perfil.php` | ✅ |
| Admin — Incidencias | `public/admin/incidencias.php` | ✅ |
| Socio — Incidencias | `public/socio/incidencias.php` | ✅ |

## Pendiente / futuro
- [ ] Calendari: confirmar que el Google Calendar embed funciona
- [ ] Deploy en Hostinger/Railway/VPS
- [ ] Exportar ranking a CSV/Excel
