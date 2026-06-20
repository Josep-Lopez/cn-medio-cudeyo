<?php
if (session_status() === PHP_SESSION_NONE) {
    if (!headers_sent()) {
        session_start();
    } else {
        $_SESSION = $_SESSION ?? [];
    }
}

// HTTP Security Headers
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

// ── CSRF ─────────────────────────────────────────────────────────────────────

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

function csrf_verify(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        die('Petición inválida (CSRF). Vuelve atrás y reintenta.');
    }
}

// ── Rate limiting (archivos temporales) ──────────────────────────────────────

function rate_limit_check(string $ip, int $max = 10, int $window = 900): bool
{
    $file = sys_get_temp_dir() . '/cn_rl_' . md5($ip);
    $data = ['count' => 0, 'reset' => time() + $window];
    if (file_exists($file)) {
        $raw = json_decode(file_get_contents($file), true);
        if ($raw && time() < $raw['reset']) {
            $data = $raw;
        }
    }
    if ($data['count'] >= $max) return false;
    $data['count']++;
    file_put_contents($file, json_encode($data), LOCK_EX);
    return true;
}

function rate_limit_reset(string $ip): void
{
    $file = sys_get_temp_dir() . '/cn_rl_' . md5($ip);
    if (file_exists($file)) unlink($file);
}

function require_login(): void
{
    if (empty($_SESSION['user'])) {
        if (!headers_sent()) {
            header('Location: /login');
            exit;
        }
        http_response_code(401);
        die('Debes iniciar sesión para continuar.');
    }

    // Primer login: forzar cambio de contraseña
    if (!empty($_SESSION['user']['must_change_pwd'])) {
        $current = $_SERVER['REQUEST_URI'] ?? '';
        if (!str_starts_with($current, '/socio/cambiar-password')) {
            header('Location: /socio/cambiar-password');
            exit;
        }
    }

    // Tutor legal obligatorio para menores (benjamin, alevin, infantil, junior)
    // Saltar si admin está suplantando al usuario
    $ligas_tutor = ['benjamin', 'alevin', 'infantil', 'junior'];
    if (
        empty($_SESSION['admin_original'])
        && empty($_SESSION['user']['must_change_pwd'])
        && in_array($_SESSION['user']['liga'] ?? '', $ligas_tutor)
        && empty($_SESSION['user']['tutor_email'])
    ) {
        $current = $_SERVER['REQUEST_URI'] ?? '';
        if (!str_starts_with($current, '/socio/autorizacion-tutor')) {
            header('Location: /socio/autorizacion-tutor');
            exit;
        }
    }
}

function requires_tutor(string $liga): bool
{
    return in_array($liga, ['benjamin', 'alevin', 'infantil', 'junior']);
}

function require_admin(): void
{
    require_login();
    if ($_SESSION['user']['rol'] !== 'admin') {
        http_response_code(403);
        render_header('Acceso denegado');
        echo '<div class="container page-content"><div class="alert alert-danger">No tienes permiso para acceder a esta página.</div></div>';
        render_footer();
        exit;
    }
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_admin(): bool
{
    return isset($_SESSION['user']['rol']) && $_SESSION['user']['rol'] === 'admin';
}

function is_nadador_activo(): bool
{
    return !empty($_SESSION['user']['nadador_activo']);
}

// ── Cargos directiva ────────────────────────────────────────────────────────

// Devuelve cargos activos del usuario (['vocal','tesorero', ...]).
// Si $user_id es null, usa el usuario en sesión. Caché estático por request.
function cargos_activos(?int $user_id = null): array
{
    static $cache = [];
    global $pdo;

    if ($user_id === null) {
        $u = current_user();
        if (!$u) return [];
        $user_id = (int)$u['id'];
    }

    if (isset($cache[$user_id])) return $cache[$user_id];
    if (!$pdo) return [];

    $stmt = $pdo->prepare(
        'SELECT cargo FROM cargos
         WHERE user_id = ?
           AND (fecha_fin IS NULL OR fecha_fin > CURDATE())'
    );
    $stmt->execute([$user_id]);
    $cache[$user_id] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return $cache[$user_id];
}

function user_tiene_cargo(string $cargo, ?int $user_id = null): bool
{
    return in_array($cargo, cargos_activos($user_id), true);
}

// Pertenece a la junta directiva (presidente/secretario/tesorero/vocal)
function es_directiva(?int $user_id = null): bool
{
    $cargos = cargos_activos($user_id);
    foreach (['presidente', 'secretario', 'tesorero', 'vocal'] as $c) {
        if (in_array($c, $cargos, true)) return true;
    }
    return false;
}

// Restringe a usuarios con alguno de los cargos dados. Admin pasa siempre.
function require_cargo(array $cargos_validos): void
{
    require_login();
    if (is_admin()) return;
    $cargos = cargos_activos();
    foreach ($cargos_validos as $c) {
        if (in_array($c, $cargos, true)) return;
    }
    http_response_code(403);
    render_header('Acceso denegado');
    echo '<div class="container page-content"><div class="alert alert-danger">No tienes permiso para acceder a esta página.</div></div>';
    render_footer();
    exit;
}

// Límite máximo de titulares activos por cargo
function cargos_limites(): array
{
    return [
        'presidente'          => 1,
        'secretario'          => 1,
        'tesorero'            => 1,
        'responsable_menores' => 1,
        'vocal'               => 5,
        'encargado_redes'     => 3,
    ];
}

// Lista de todos los cargos válidos
function cargos_disponibles(): array
{
    return array_keys(cargos_limites());
}

// Nombre legible del cargo
function cargo_label(string $cargo): string
{
    return match ($cargo) {
        'presidente'          => 'Presidente',
        'secretario'          => 'Secretario',
        'tesorero'            => 'Tesorero',
        'vocal'               => 'Vocal',
        'responsable_menores' => 'Responsable de protección del menor',
        'encargado_redes'     => 'Encargado de redes sociales',
        default               => ucfirst($cargo),
    };
}


function require_nadador_activo(): void
{
    require_login();
    if (!is_nadador_activo()) {
        header('Location: /socio/panel');
        exit;
    }
}

// Convierte "mm:ss.cc" o "ss.cc" a segundos float
function tiempo_a_segundos(string $tiempo): float
{
    $tiempo = trim(str_replace(',', '.', $tiempo));
    if (str_contains($tiempo, ':')) {
        [$min, $rest] = explode(':', $tiempo, 2);
        return (float)$min * 60 + (float)$rest;
    }
    return (float)$tiempo;
}

// Convierte segundos a "mm:ss.cc" (o "ss.cc" si < 60s)
function segundos_a_tiempo(float $seg): string
{
    if ($seg >= 60) {
        $m  = (int)floor($seg / 60);
        $s  = $seg - $m * 60;
        return sprintf('%d:%05.2f', $m, $s);
    }
    return number_format($seg, 2);
}

// Nombre legible de la prueba
function format_prueba(string $codigo): string
{
    $mapa = [
        '50L'   => '50 Libre',   '100L'  => '100 Libre',  '200L'  => '200 Libre',
        '400L'  => '400 Libre',  '800L'  => '800 Libre',  '1500L' => '1500 Libre',
        '50E'   => '50 Espalda', '100E'  => '100 Espalda','200E'  => '200 Espalda',
        '50B'   => '50 Braza',   '100B'  => '100 Braza',  '200B'  => '200 Braza',
        '50M'   => '50 Mariposa','100M'  => '100 Mariposa','200M' => '200 Mariposa',
        '100X'  => '100 Estilos','200X'  => '200 Estilos','400X'  => '400 Estilos',
    ];
    return $mapa[$codigo] ?? $codigo;
}

// Baremo FINA (tiempos base World Aquatics) desde config. Cacheado por request.
// Clave: "<prueba legible en minúsculas>_<sexo>_<piscina>"  p.ej. "50 libre_M_25m".
function aqua_baremo(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    global $pdo;
    $json = $pdo->query("SELECT valor FROM config WHERE clave='fina_times' LIMIT 1")->fetchColumn();
    $cache = $json ? (json_decode($json, true) ?: []) : [];
    return $cache;
}

// Puntos AQUA (World Aquatics) de una marca. Misma fórmula que calculadora.js:
//   puntos = round(1000 * (tiempo_base / tiempo)^3)
// Devuelve null si no hay tiempo base para esa combinación prueba/sexo/piscina.
function calcular_aqua(float $tiempo_seg, string $prueba, string $piscina, string $sexo): ?int
{
    if ($tiempo_seg <= 0) return null;
    $clave = strtolower(format_prueba($prueba)) . '_' . $sexo . '_' . $piscina;
    $base  = aqua_baremo()[$clave] ?? null;
    if (!$base) return null;
    return (int) round(1000 * pow($base / $tiempo_seg, 3));
}

// Nombre legible de la liga
function format_liga(string $liga): string
{
    $mapa = [
        'benjamin' => 'Benjamín',
        'alevin'   => 'Alevín',
        'infantil' => 'Infantil',
        'junior'   => 'Junior',
        'absoluto' => 'Absoluto',
        'master'   => 'Master',
    ];
    return $mapa[$liga] ?? ucfirst($liga);
}

// Edad deportiva FINA/RFEN: año(fecha_marca) - año(fecha_nacimiento).
// No depende de día/mes. Devuelve null si falta algún dato.
function edad_deportiva(?string $fecha_marca, ?string $fecha_nacimiento): ?int
{
    if (!$fecha_marca || !$fecha_nacimiento) return null;
    $y_m = (int)substr($fecha_marca, 0, 4);
    $y_n = (int)substr($fecha_nacimiento, 0, 4);
    if ($y_m <= 0 || $y_n <= 0) return null;
    return $y_m - $y_n;
}

// Quita el último apellido (segundo apellido en formato español).
// "Sergio Ordoñez Zamora" -> "Sergio Ordoñez". Si <=2 palabras, devuelve igual.
function nombre_corto(string $nombre): string
{
    $words = preg_split('/\s+/', trim($nombre)) ?: [];
    if (count($words) <= 2) return $nombre;
    return implode(' ', array_slice($words, 0, -1));
}

// Genera <optgroup> para un select de pruebas
function render_prueba_options(string $selected = '', bool $show_all = false): void
{
    if ($show_all) {
        $sel = $selected === '' ? ' selected' : '';
        echo '<option value=""' . $sel . '>Todas las pruebas</option>';
    }
    $grupos = [
        '🌊 Libre'    => ['50L'=>'50 Libre','100L'=>'100 Libre','200L'=>'200 Libre','400L'=>'400 Libre','800L'=>'800 Libre','1500L'=>'1500 Libre'],
        '↩ Espalda'  => ['50E'=>'50 Espalda','100E'=>'100 Espalda','200E'=>'200 Espalda'],
        '🐸 Braza'    => ['50B'=>'50 Braza','100B'=>'100 Braza','200B'=>'200 Braza'],
        '🦋 Mariposa' => ['50M'=>'50 Mariposa','100M'=>'100 Mariposa','200M'=>'200 Mariposa'],
        '⭐ Estilos'  => ['100X'=>'100 Estilos','200X'=>'200 Estilos','400X'=>'400 Estilos'],
    ];
    foreach ($grupos as $label => $pruebas) {
        echo '<optgroup label="' . htmlspecialchars($label) . '">';
        foreach ($pruebas as $val => $text) {
            $sel = $selected === $val ? ' selected' : '';
            echo '<option value="' . htmlspecialchars($val) . '"' . $sel . '>' . htmlspecialchars($text) . '</option>';
        }
        echo '</optgroup>';
    }
}

// Flash messages de un solo uso
function flash(string $msg, string $type = 'success'): void
{
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}

function get_flash(): ?array
{
    if (isset($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

// Genera HTML del flash si hay uno pendiente
function render_flash(): void
{
    $f = get_flash();
    if ($f) {
        $type = htmlspecialchars($f['type']);
        $msg  = htmlspecialchars($f['msg']);
        echo "<div class=\"alert alert-{$type}\">{$msg}</div>";
    }
}

// Escapado seguro
function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
