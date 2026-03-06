<?php
require_once dirname(__DIR__) . '/config/params.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/layout.php';

require_login();

// ── Drive API ────────────────────────────────────────────────────────────────

// Retorna array de ficheros, array vacío si carpeta vacía, o string con error
function drive_list(string $folder_id): array|string
{
    if (!DRIVE_API_KEY || !$folder_id) return [];
    $url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query([
        'q'        => "'{$folder_id}' in parents and trashed=false",
        'key'      => DRIVE_API_KEY,
        'fields'   => 'files(id,name,mimeType,webViewLink)',
        'orderBy'  => 'folder,name',
        'pageSize' => 1000,
    ]);
    $json = @file_get_contents($url);
    if ($json === false) return 'No se ha podido conectar con Google Drive.';
    $data = json_decode($json, true);
    if (isset($data['error'])) return 'Error Drive: ' . ($data['error']['message'] ?? 'Error desconocido');
    return $data['files'] ?? [];
}

function drive_icon(string $mime): string
{
    return match(true) {
        $mime === 'application/vnd.google-apps.folder'       => 'bi-folder2-fill',
        $mime === 'application/pdf'                           => 'bi-file-earmark-pdf-fill',
        $mime === 'application/vnd.google-apps.document'     => 'bi-file-earmark-word-fill',
        $mime === 'application/vnd.google-apps.spreadsheet'  => 'bi-file-earmark-spreadsheet-fill',
        $mime === 'application/vnd.google-apps.presentation' => 'bi-file-earmark-slides-fill',
        str_starts_with($mime, 'image/')                     => 'bi-file-earmark-image-fill',
        default                                              => 'bi-file-earmark-fill',
    };
}

function drive_icon_color(string $mime): string
{
    return match(true) {
        $mime === 'application/vnd.google-apps.folder'       => 'var(--blue)',
        $mime === 'application/pdf'                           => '#e74c3c',
        $mime === 'application/vnd.google-apps.document'     => '#4285F4',
        $mime === 'application/vnd.google-apps.spreadsheet'  => '#0F9D58',
        $mime === 'application/vnd.google-apps.presentation' => '#F4B400',
        str_starts_with($mime, 'image/')                     => '#8e44ad',
        default                                              => 'var(--gray)',
    };
}

// ── Navegación por sesión (URL siempre limpia: /biblioteca) ──────────────────

if (!isset($_SESSION['biblioteca'])) {
    $_SESSION['biblioteca'] = [
        'id'    => DRIVE_FOLDER_ID,
        'name'  => 'Biblioteca de documentos',
        'trail' => [],
    ];
}

$nav = &$_SESSION['biblioteca'];

// Entrar en subcarpeta
if (isset($_GET['go']) && preg_match('/^[a-zA-Z0-9_-]+$/', $_GET['go'])) {
    $nav['trail'][] = ['id' => $nav['id'], 'name' => $nav['name']];
    $nav['id']   = $_GET['go'];
    $nav['name'] = $_GET['gn'] ?? 'Carpeta';
    header('Location: /biblioteca');
    exit;
}

// Volver atrás
if (isset($_GET['back']) && $nav['trail']) {
    $parent      = array_pop($nav['trail']);
    $nav['id']   = $parent['id'];
    $nav['name'] = $parent['name'];
    header('Location: /biblioteca');
    exit;
}

// Volver a la raíz
if (isset($_GET['root'])) {
    $nav['id']    = DRIVE_FOLDER_ID;
    $nav['name']  = 'Biblioteca de documentos';
    $nav['trail'] = [];
    header('Location: /biblioteca');
    exit;
}

$current_id   = $nav['id'];
$current_name = $nav['name'];
$is_root      = empty($nav['trail']);
$back_name    = $is_root ? null : $nav['trail'][count($nav['trail']) - 1]['name'];

$files = drive_list($current_id);

// ── Render ───────────────────────────────────────────────────────────────────

render_header('Biblioteca', 'biblioteca');
?>

<div class="container page-content">

  <div class="mb-6" style="display:flex;align-items:center;gap:16px;">
    <?php if (!$is_root): ?>
      <a href="?back=1" class="btn btn-gray btn-sm" title="Volver a <?= e($back_name) ?>">
        <i class="bi bi-arrow-left"></i>
      </a>
    <?php endif; ?>
    <div>
      <h1 style="margin-bottom:4px;"><?= e($current_name) ?></h1>
      <?php if ($is_root): ?>
        <p class="text-muted">Documentación, reglamentos y recursos del club</p>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!DRIVE_API_KEY): ?>
    <div class="card text-center" style="padding:60px;">
      <div style="font-size:48px;margin-bottom:16px;color:var(--blue);"><i class="bi bi-key-fill"></i></div>
      <p class="text-muted">La biblioteca no está configurada.<br>Añade la <code>DRIVE_API_KEY</code> en <code>config/params.php</code>.</p>
    </div>

  <?php elseif (is_string($files)): ?>
    <div class="card text-center" style="padding:60px;">
      <div style="font-size:48px;margin-bottom:16px;color:var(--red);"><i class="bi bi-exclamation-triangle-fill"></i></div>
      <p class="text-muted"><?= e($files) ?></p>
    </div>

  <?php elseif (!$files): ?>
    <div class="card text-center" style="padding:60px;">
      <div style="font-size:48px;margin-bottom:16px;color:var(--gray);"><i class="bi bi-folder2"></i></div>
      <p class="text-muted">Esta carpeta está vacía.</p>
    </div>

  <?php else: ?>
    <div class="card" style="padding:0;overflow:hidden;">
      <?php foreach ($files as $i => $f): ?>
        <?php
          $is_folder = $f['mimeType'] === 'application/vnd.google-apps.folder';
          $icon      = drive_icon($f['mimeType']);
          $color     = drive_icon_color($f['mimeType']);
          $href      = $is_folder
              ? '?go=' . urlencode($f['id']) . '&gn=' . urlencode($f['name'])
              : $f['webViewLink'];
          $border    = $i < count($files) - 1 ? 'border-bottom:1px solid #eee;' : '';
        ?>
        <a href="<?= e($href) ?>"
           <?= $is_folder ? '' : 'target="_blank" rel="noopener"' ?>
           style="display:flex;align-items:center;gap:14px;padding:14px 20px;text-decoration:none;color:inherit;<?= $border ?>">
          <span style="font-size:22px;color:<?= $color ?>;flex-shrink:0;">
            <i class="bi <?= $icon ?>"></i>
          </span>
          <span style="flex:1;font-weight:<?= $is_folder ? '600' : '400' ?>;"><?= e($f['name']) ?></span>
          <span style="color:var(--gray);font-size:16px;">
            <i class="bi <?= $is_folder ? 'bi-chevron-right' : 'bi-box-arrow-up-right' ?>"></i>
          </span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>

<?php render_footer(); ?>
