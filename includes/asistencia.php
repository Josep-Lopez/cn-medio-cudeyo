<?php
// Sábados y domingos son extra: la asistencia suma presentes pero no cuenta como día
// computable, así que el % puede superar el 100%. La falta en fin de semana se ignora.
function es_fin_de_semana(string $fecha): bool
{
    return (int)date('N', strtotime($fecha)) >= 6;
}

// $presencias: [fecha => bool|int presente]. Devuelve [presentes, computables, pct|null].
function resumen_asistencia(array $presencias): array
{
    $presentes = 0;
    $computables = 0;
    foreach ($presencias as $fecha => $presente) {
        if ($presente) $presentes++;
        if (!es_fin_de_semana($fecha)) $computables++;
    }
    $pct = $computables > 0 ? (int)round(($presentes / $computables) * 100) : null;
    return [$presentes, $computables, $pct];
}

// Solo lo verificado cuenta: filtra $asistencia_data ([fecha => fila]) a [fecha => presente].
function presencias_verificadas(array $asistencia_data): array
{
    $out = [];
    foreach ($asistencia_data as $fecha => $r) {
        if (!isset($r['verificada']) || $r['verificada']) $out[$fecha] = (int)$r['presente'];
    }
    return $out;
}

// Resumen del año natural (solo verificado) por usuario: [user_id => [presentes, computables, pct|null]].
function resumen_asistencia_anio(PDO $pdo, array $user_ids, int $anio): array
{
    $presencias = array_fill_keys($user_ids, []);
    if ($user_ids) {
        $ph = implode(',', array_fill(0, count($user_ids), '?'));
        $stmt = $pdo->prepare("SELECT user_id, fecha, presente FROM asistencia
                               WHERE user_id IN ($ph) AND fecha BETWEEN ? AND ? AND verificada = 1");
        $stmt->execute(array_merge($user_ids, ["$anio-01-01", "$anio-12-31"]));
        foreach ($stmt->fetchAll() as $r) {
            $presencias[$r['user_id']][$r['fecha']] = (int)$r['presente'];
        }
    }
    return array_map('resumen_asistencia', $presencias);
}

// El deportista puede apuntarse a sí mismo: masters cualquier día, el resto solo fines
// de semana. Nunca días futuros. Lo apuntado queda pendiente de verificar.
function puede_autoregistrar_dia(string $fecha, ?string $liga): bool
{
    if ($fecha > date('Y-m-d')) return false;
    return $liga === 'master' || es_fin_de_semana($fecha);
}

// Pinta un calendario mensual de asistencia (grid Lun-Dom).
// $dias_mes: array de fechas 'Y-m-d' del mes.
// $asistencia_data: [fecha => ['presente'=>0|1, 'verificada'=>0|1, 'observaciones'=>?string]]
// $editable: true, o fn(string $fecha, ?array $registro): bool para decidir por día. Los días
// editables llaman a abrirModalAsistencia(this) al hacer clic (la página define el modal).
function render_calendario_asistencia(array $dias_mes, array $asistencia_data, bool|callable $editable = false): void
{
    if (!$dias_mes) return;
    $dias_semana = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
    $offset = (int)date('N', strtotime($dias_mes[0])) - 1; // 1=Lun..7=Dom
?>
    <div class="calendario-asistencia">
      <?php foreach ($dias_semana as $ds): ?>
        <div class="cal-dow"><?= $ds ?></div>
      <?php endforeach; ?>
      <?php for ($i = 0; $i < $offset; $i++): ?>
        <div class="cal-cell cal-empty"></div>
      <?php endfor; ?>
      <?php foreach ($dias_mes as $dia):
        $registro = $asistencia_data[$dia] ?? null;
        $dayNum   = (int)date('j', strtotime($dia));
        $finde    = es_fin_de_semana($dia);
        $pendiente = $registro !== null && isset($registro['verificada']) && !$registro['verificada'];
        if ($pendiente) {
            $cls = 'cal-cell cal-pendiente';
            $icon = '<i class="bi bi-hourglass-split"></i>';
        } elseif ($registro === null || (!$registro['presente'] && $finde)) {
            $cls = 'cal-cell cal-sin-registrar';
            $icon = '';
        } elseif ($registro['presente'] && $finde) {
            $cls = 'cal-cell cal-extra';
            $icon = '<i class="bi bi-plus-circle-fill"></i>';
        } elseif ($registro['presente']) {
            $cls = 'cal-cell cal-presente';
            $icon = '<i class="bi bi-check-circle-fill"></i>';
        } else {
            $cls = 'cal-cell cal-ausente';
            $icon = '<i class="bi bi-x-circle-fill"></i>';
        }
        $title = ($registro && $registro['observaciones']) ? e($registro['observaciones']) : '';
        if ($pendiente) $title = 'Pendiente de verificar' . ($title ? ' · ' . $title : '');
        $attrs = '';
        if (is_callable($editable) ? $editable($dia, $registro) : $editable) {
            $cls  .= ' cal-editable';
            $estado = $registro === null ? '' : ($pendiente ? 'pendiente' : ($registro['presente'] ? 'presente' : 'ausente'));
            $attrs = ' role="button" tabindex="0" onclick="abrirModalAsistencia(this)"'
                   . ' onkeydown="if(event.key===\'Enter\')abrirModalAsistencia(this)"'
                   . ' data-fecha="' . $dia . '" data-estado="' . $estado . '"'
                   . ' data-obs="' . e($registro['observaciones'] ?? '') . '"';
        }
      ?>
        <div class="<?= $cls ?>"<?= $title ? ' title="' . $title . '"' : '' ?><?= $attrs ?>>
          <span class="cal-daynum"><?= $dayNum ?></span>
          <span class="cal-icon"><?= $icon ?></span>
        </div>
      <?php endforeach; ?>
    </div>
<?php
}
