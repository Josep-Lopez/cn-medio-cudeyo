-- 018: Cargos "Director técnico" y "Entrenador"

ALTER TABLE cargos MODIFY COLUMN cargo ENUM(
    'presidente',
    'secretario',
    'tesorero',
    'vocal',
    'responsable_menores',
    'encargado_redes',
    'director_tecnico',
    'entrenador'
) NOT NULL;
