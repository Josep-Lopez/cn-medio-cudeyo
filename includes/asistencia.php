<?php
// Sábados y domingos: la asistencia suma (cuenta como día entrenado) pero la falta no resta.
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
        if ($presente) {
            $presentes++;
            $computables++;
        } elseif (!es_fin_de_semana($fecha)) {
            $computables++;
        }
    }
    $pct = $computables > 0 ? (int)round(($presentes / $computables) * 100) : null;
    return [$presentes, $computables, $pct];
}

// Pinta un calendario mensual de asistencia (grid Lun-Dom).
// $dias_mes: array de fechas 'Y-m-d' del mes.
// $asistencia_data: [fecha => ['presente'=>0|1, 'observaciones'=>?string]]
function render_calendario_asistencia(array $dias_mes, array $asistencia_data): void
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
        if ($registro === null || (!$registro['presente'] && $finde)) {
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
      ?>
        <div class="<?= $cls ?>"<?= $title ? ' title="' . $title . '"' : '' ?>>
          <span class="cal-daynum"><?= $dayNum ?></span>
          <span class="cal-icon"><?= $icon ?></span>
        </div>
      <?php endforeach; ?>
    </div>
<?php
}
