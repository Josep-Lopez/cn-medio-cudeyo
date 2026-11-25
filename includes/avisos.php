<?php
// Avisos de comentarios sin leer en pruebas (inscripciones) y tiempos de competición.
//  · Al nadador: lo que escribe otra persona en sus hilos.
//  · Al staff (admin, director técnico, entrenador): lo que escribe el nadador en los suyos.
// Cada usuario tiene sus propios leídos (competicion_entrenador_vistos).
// Requiere es_staff_entrenos() de includes/entrenos.php.

// Al staff no se le avisa de lo anterior (evita una avalancha a quien acaba de recibir el cargo)
const AVISOS_STAFF_DIAS = 30;

// Hilos con comentarios sin leer, el más reciente primero:
// [tipo, ref_id, competicion_id, competicion, prueba, dueno, nadador, n, visto, ultima]
function avisos_comentarios(PDO $pdo, int $user_id): array
{
    $staff = es_staff_entrenos() ? 1 : 0;
    $sql = function (string $tipo, string $comentarios, string $fk, string $padre): string {
        return "
            SELECT '$tipo' AS tipo, p.id AS ref_id, p.competicion_id, c.nombre AS competicion, p.prueba,
                   p.user_id AS dueno, u.nombre AS nadador, COUNT(*) AS n,
                   MAX(COALESCE(v.ultimo_id, 0)) AS visto, MAX(k.created_at) AS ultima
            FROM $comentarios k
            JOIN $padre p ON p.id = k.$fk
            JOIN competicion_entrenador c ON c.id = p.competicion_id
            JOIN users u ON u.id = p.user_id
            LEFT JOIN competicion_entrenador_vistos v ON v.user_id = ? AND v.tipo = '$tipo' AND v.ref_id = p.id
            WHERE k.id > COALESCE(v.ultimo_id, 0)
              AND ((p.user_id = ? AND k.user_id <> ?)
                OR (? = 1 AND p.user_id <> ? AND k.user_id = p.user_id
                    AND k.created_at >= DATE_SUB(NOW(), INTERVAL " . AVISOS_STAFF_DIAS . " DAY)))
            GROUP BY p.id, p.competicion_id, c.nombre, p.prueba, p.user_id, u.nombre";
    };
    $params = [$user_id, $user_id, $user_id, $staff, $user_id];
    try {
        $stmt = $pdo->prepare(
            $sql('prueba', 'competicion_entrenador_prueba_comentarios', 'inscripcion_id', 'competicion_entrenador_pruebas')
            . ' UNION ALL '
            . $sql('tiempo', 'competicion_entrenador_comentarios', 'tiempo_id', 'competicion_entrenador_tiempos')
            . ' ORDER BY ultima DESC'
        );
        $stmt->execute(array_merge($params, $params));
        return $stmt->fetchAll();
    } catch (PDOException $ex) {
        return []; // Migración 037 pendiente: sin avisos
    }
}

// [ref_id => último comentario leído] de los hilos de un tipo con algo sin leer
function avisos_por_hilo(array $avisos, string $tipo): array
{
    $out = [];
    foreach ($avisos as $a) if ($a['tipo'] === $tipo) $out[(int)$a['ref_id']] = (int)$a['visto'];
    return $out;
}

// Marca leído un hilo hasta el último comentario mostrado. Un admin suplantando a un socio no marca nada.
function marcar_hilo_visto(PDO $pdo, int $user_id, string $tipo, int $ref_id, array $comentarios): void
{
    if (!$comentarios || !empty($_SESSION['admin_original'])) return;
    $ultimo = max(array_map(fn($c) => (int)$c['id'], $comentarios));
    try {
        $pdo->prepare('
            INSERT INTO competicion_entrenador_vistos (user_id, tipo, ref_id, ultimo_id) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE ultimo_id = GREATEST(ultimo_id, VALUES(ultimo_id))
        ')->execute([$user_id, $tipo, $ref_id, $ultimo]);
    } catch (PDOException $ex) {
        // Migración 037 pendiente
    }
}

function aviso_texto(array $a, int $user_id): string
{
    $n = (int)$a['n'];
    $donde = format_prueba($a['prueba']) . ' · ' . $a['competicion'];
    if ((int)$a['dueno'] === $user_id) return ($n > 1 ? "$n comentarios nuevos" : 'Comentario nuevo') . " en $donde";
    return $a['nadador'] . ' ha comentado' . ($n > 1 ? " ($n)" : '') . " en $donde";
}

function aviso_url(array $a, int $user_id): string
{
    $id = (int)$a['ref_id'];
    $mio = (int)$a['dueno'] === $user_id;
    if ($a['tipo'] === 'tiempo') return ($mio ? '/socio' : '/admin') . '/competicion_tiempo?id=' . $id;
    return $mio
        ? "/socio/competiciones?abrir=$id#i$id"
        : '/admin/competiciones?ver=' . (int)$a['competicion_id'] . "&tab=convocatoria&abrir=$id#i$id";
}

// Campanita del navbar con su lista: comentarios sin leer y comunicados.
function render_campana(array $avisos, int $user_id, int $comunicados): void
{
    $total = array_sum(array_column($avisos, 'n')) + $comunicados;
    if ($total < 1) return;
    $max = 8;
?>
<div class="navbar-campana">
  <button type="button" class="navbar-notif" aria-label="Avisos: <?= $total ?> sin leer" title="<?= $total ?> sin leer"
          onclick="this.parentNode.classList.toggle('open')">
    <i class="bi bi-bell-fill"></i>
    <span class="navbar-notif-badge"><?= $total > 9 ? '9+' : $total ?></span>
  </button>
  <div class="navbar-dropdown-menu">
    <?php foreach (array_slice($avisos, 0, $max) as $a): ?>
      <a href="<?= e(aviso_url($a, $user_id)) ?>"><i class="bi bi-chat-dots"></i> <span><?= e(aviso_texto($a, $user_id)) ?></span></a>
    <?php endforeach; ?>
    <?php if (count($avisos) > $max): ?>
      <div class="navbar-campana__mas">y <?= count($avisos) - $max ?> más</div>
    <?php endif; ?>
    <?php if ($comunicados): ?>
      <a href="/socio/comunicaciones"><i class="bi bi-envelope"></i> <span><?= $comunicados ?> comunicado<?= $comunicados > 1 ? 's' : '' ?> sin leer</span></a>
    <?php endif; ?>
  </div>
</div>
<?php
}
