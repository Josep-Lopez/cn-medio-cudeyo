<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// HTTP Security Headers
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

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
        header('Location: /login');
        exit;
    }
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

// Convierte "mm:ss.cc" o "ss.cc" a segundos float
function temps_a_segons(string $temps): float
{
    $temps = trim(str_replace(',', '.', $temps));
    if (str_contains($temps, ':')) {
        [$min, $rest] = explode(':', $temps, 2);
        return (float)$min * 60 + (float)$rest;
    }
    return (float)$temps;
}

// Convierte segundos a "mm:ss.cc" (o "ss.cc" si < 60s)
function segons_a_temps(float $seg): string
{
    if ($seg >= 60) {
        $m  = (int)floor($seg / 60);
        $s  = $seg - $m * 60;
        return sprintf('%d:%05.2f', $m, $s);
    }
    return number_format($seg, 2);
}

// Nombre legible de la prueba
function format_prova(string $codi): string
{
    $mapa = [
        '50L'   => '50 Libre',   '100L'  => '100 Libre',  '200L'  => '200 Libre',
        '400L'  => '400 Libre',  '800L'  => '800 Libre',  '1500L' => '1500 Libre',
        '50E'   => '50 Espalda', '100E'  => '100 Espalda','200E'  => '200 Espalda',
        '50B'   => '50 Braza',   '100B'  => '100 Braza',  '200B'  => '200 Braza',
        '50M'   => '50 Mariposa','100M'  => '100 Mariposa','200M' => '200 Mariposa',
        '100X'  => '100 Estilos','200X'  => '200 Estilos','400X'  => '400 Estilos',
    ];
    return $mapa[$codi] ?? $codi;
}

// Nombre legible de la liga
function format_lliga(string $lliga): string
{
    $mapa = [
        'benjamin' => 'Benjamín',
        'alevin'   => 'Alevín',
        'infantil' => 'Infantil',
        'junior'   => 'Junior/Absoluto',
        'master'   => 'Master',
    ];
    return $mapa[$lliga] ?? ucfirst($lliga);
}

// Genera <optgroup> para un select de pruebas
function render_prova_options(string $selected = ''): void
{
    $grupos = [
        '🌊 Libre'    => ['50L'=>'50 Libre','100L'=>'100 Libre','200L'=>'200 Libre','400L'=>'400 Libre','800L'=>'800 Libre','1500L'=>'1500 Libre'],
        '↩ Espalda'  => ['50E'=>'50 Espalda','100E'=>'100 Espalda','200E'=>'200 Espalda'],
        '🐸 Braza'    => ['50B'=>'50 Braza','100B'=>'100 Braza','200B'=>'200 Braza'],
        '🦋 Mariposa' => ['50M'=>'50 Mariposa','100M'=>'100 Mariposa','200M'=>'200 Mariposa'],
        '⭐ Estilos'  => ['100X'=>'100 Estilos','200X'=>'200 Estilos','400X'=>'400 Estilos'],
    ];
    foreach ($grupos as $label => $proves) {
        echo '<optgroup label="' . htmlspecialchars($label) . '">';
        foreach ($proves as $val => $text) {
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
