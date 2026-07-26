# Entrenador: grupos de entrenamiento + competiciones con parciales y foro

Fecha: 2026-07-26

## Contexto

El cargo `entrenador` ya existe (migración 018) pero no tiene área propia en la web
(solo `director_tecnico` tiene acceso a `/admin/marcas.php`). El entrenador pide dos
capacidades nuevas:

1. Organizar a sus nadadores en grupos de entrenamiento y verlos listados.
2. Registrar los tiempos que hacen sus nadadores en competiciones (con parciales
   libres), y que cada nadador pueda entrar a ver su tiempo y comentarlo con el
   entrenador, tipo hilo/foro.

Son dos subsistemas independientes que se agrupan bajo una nueva área `/entrenador/*`,
siguiendo el patrón ya usado por `/directiva/*` (sidebar propio vía
`render_directiva_layout()`, acceso por cargo).

Explícitamente fuera de alcance: estos tiempos de competición **no** alimentan la
tabla `marcas` ni el ranking/records oficiales del club — es un sistema aparte,
centrado en seguimiento del entrenador y parciales. Tampoco se da acceso a tutores
de menores en esta fase (igual que en incidencias, donde no se ha implementado).

## Sección 1 — Grupos de entrenamiento

### Datos

```sql
CREATE TABLE grupos_entrenamiento (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    descripcion VARCHAR(255) DEFAULT NULL,
    creado_por INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (creado_por) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE grupo_nadadores (
    grupo_id INT NOT NULL,
    user_id INT NOT NULL,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (grupo_id, user_id),
    FOREIGN KEY (grupo_id) REFERENCES grupos_entrenamiento(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Relación N:N confirmada: un nadador puede pertenecer a varios grupos a la vez
(ej. "Junior" y "Selección"). `grupo_nadadores` es tabla puente sin campos extra.

### Páginas

- `/entrenador/grupos.php`
  - Listado de grupos existentes (nombre, nº de nadadores).
  - Crear / editar / borrar grupo (nombre + descripción opcional).
  - Dentro de cada grupo: listado de nadadores asignados mostrando nombre + liga
    (categoría) + sexo; buscador para añadir socios activos; botón quitar por fila.
  - Accesible por `entrenador` y `admin` (mismo criterio: entrenador y admin pueden
    crear/gestionar grupos, no solo el admin).

### Integración con asistencia (existente)

`/admin/asistencia.php` gana un filtro dropdown por grupo, que hace join contra
`grupo_nadadores` para acotar la lista de socios a pasar lista. No se crea tabla
nueva para esto, es un filtro sobre datos ya existentes.

## Sección 2 — Competiciones: tiempos, parciales y foro

### Datos

```sql
CREATE TABLE competiciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    lugar VARCHAR(255) DEFAULT NULL,
    fecha DATE NOT NULL,
    creado_por INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (creado_por) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE competicion_tiempos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    competicion_id INT NOT NULL,
    user_id INT NOT NULL,
    prueba VARCHAR(10) NOT NULL,
    piscina ENUM('25m','50m') NOT NULL DEFAULT '25m',
    tiempo VARCHAR(20) NOT NULL,
    tiempo_seg FLOAT NOT NULL,
    parciales VARCHAR(255) DEFAULT NULL,
    registrado_por INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (competicion_id) REFERENCES competiciones(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (registrado_por) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE competicion_comentarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tiempo_id INT NOT NULL,
    user_id INT NOT NULL,
    contenido TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tiempo_id) REFERENCES competicion_tiempos(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_tiempo (tiempo_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- `prueba`: mismo catálogo de 18 pruebas ya usado en `marcas` (50L...400X).
- `tiempo` / `tiempo_seg`: mismo formato `mm:ss.cc` que `marcas`, reutilizando
  `tiempo_a_segundos()` / `segundos_a_tiempo()` de `includes/auth.php`.
- `parciales`: texto libre (ej. `"28.5 / 1:01.2 / 1:35.8"`), sin estructura —
  decisión explícita para mantenerlo simple, el entrenador escribe como quiera.
- Patrón calcado de `incidencias` / `incidencia_comentarios`, ya validado en el repo.
- Sin FK a `marcas`: sistema independiente del ranking/records oficiales.

### Páginas

- `/entrenador/competiciones.php`
  - Listado de competiciones (nombre, lugar, fecha), crear nueva.
  - Dentro de cada competición: tabla de tiempos registrados (nadador, prueba,
    piscina, tiempo), botón "Añadir tiempo" (selector nadador + prueba + piscina +
    tiempo + parciales libres).
- `/entrenador/competicion_tiempo.php?id=X`
  - Detalle de un tiempo (nadador, prueba, tiempo, parciales) + hilo de comentarios
    (textarea + listado cronológico, igual que `incidencia_comentarios`).
- `/socio/competiciones.php`
  - Listado de los propios tiempos de competición del socio logueado (todas las
    competiciones en que aparece). Siempre visible en el menú aunque esté vacío
    (mensaje "Aún no tienes tiempos de competición registrados"), igual criterio
    que `socio/incidencias.php`.
  - Click en un tiempo abre el mismo detalle+hilo que `/entrenador/competicion_tiempo.php`,
    reutilizando la misma vista con chequeo de propiedad (`user_id === current_user id`).

### Visibilidad del hilo (foro)

Privado entrenador↔nadador: solo puede ver y comentar el hilo de un tiempo
concreto quien cumpla `is_admin() || es_registrado_por || es_user_id_dueño`.
Mismo patrón de chequeo que ya usa `socio/incidencia_descargar.php` para adjuntos
propios. Ningún otro nadador ve los comentarios de otro, aunque estén en el mismo
grupo.

## Sección 3 — Permisos, sidebar y comportamiento

- **Acceso `/entrenador/*`**: `require_cargo(['entrenador'])` al inicio de cada
  página (admin pasa siempre, mismo criterio que `require_cargo` ya implementado).
- **Sidebar**: nueva función `render_entrenador_layout(string $activePage, callable $content)`
  en `includes/layout.php`, calco de `render_directiva_layout()`, con entradas
  "Grupos" y "Competiciones".
- **Navbar/menú socio**: nuevo enlace "Competiciones" en el panel de socio, visible
  siempre (no condicionado a tener datos).
- **Cascadas de borrado**:
  - Borrar grupo → borra filas de `grupo_nadadores` (`ON DELETE CASCADE`).
  - Borrar competición → borra `competicion_tiempos` y sus `competicion_comentarios`
    en cascada.
- **Validación de tiempo**: mismo formato/regex que ya usa `admin/marcas.php` para
  el campo `tiempo`, calculando `tiempo_seg` en servidor.
- **Menores con tutor**: `tutor_user_id` no gana acceso automático a este módulo en
  esta fase — consistente con que incidencias tampoco lo implementa. Fuera de
  alcance.

## Migraciones

Una migración nueva `021_entrenador_grupos_competiciones.sql` con las 4 tablas
(`grupos_entrenamiento`, `grupo_nadadores`, `competiciones`, `competicion_tiempos`,
`competicion_comentarios`) — 5 tablas en total.

## Testing manual (no hay suite automatizada en el proyecto)

- Login como socio con cargo `entrenador`: crear grupo, añadir/quitar nadadores,
  confirmar listado con liga/sexo.
- Filtrar `/admin/asistencia.php` por grupo y confirmar que solo aparecen los
  nadadores del grupo.
- Crear competición, añadir tiempo con parciales en texto libre.
- Login como el nadador dueño del tiempo: confirmar que ve su tiempo en
  `/socio/competiciones.php`, puede comentar, y que otro socio (no dueño) recibe
  403/no ve ese hilo.
- Borrar grupo/competición y confirmar cascada (nadadores/tiempos/comentarios
  desaparecen sin error).
