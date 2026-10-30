-- 032: Relevo 8×50 libre (M/F y mixto 4+4)
--  · Los relevos de competición guardan una fila por posta: no necesitan cambios.
--  · records_relevos tiene columnas por posta: se añaden las postas 5 a 8 (NULL en relevos de 4).

ALTER TABLE records_relevos
    ADD COLUMN nadador5 VARCHAR(100) DEFAULT NULL AFTER nadador4,
    ADD COLUMN nadador6 VARCHAR(100) DEFAULT NULL AFTER nadador5,
    ADD COLUMN nadador7 VARCHAR(100) DEFAULT NULL AFTER nadador6,
    ADD COLUMN nadador8 VARCHAR(100) DEFAULT NULL AFTER nadador7,
    ADD COLUMN parcial5 VARCHAR(20) DEFAULT NULL AFTER parcial4,
    ADD COLUMN parcial6 VARCHAR(20) DEFAULT NULL AFTER parcial5,
    ADD COLUMN parcial7 VARCHAR(20) DEFAULT NULL AFTER parcial6,
    ADD COLUMN parcial8 VARCHAR(20) DEFAULT NULL AFTER parcial7,
    ADD COLUMN user5_id INT DEFAULT NULL AFTER user4_id,
    ADD COLUMN user6_id INT DEFAULT NULL AFTER user5_id,
    ADD COLUMN user7_id INT DEFAULT NULL AFTER user6_id,
    ADD COLUMN user8_id INT DEFAULT NULL AFTER user7_id,
    ADD FOREIGN KEY (user5_id) REFERENCES users(id) ON DELETE SET NULL,
    ADD FOREIGN KEY (user6_id) REFERENCES users(id) ON DELETE SET NULL,
    ADD FOREIGN KEY (user7_id) REFERENCES users(id) ON DELETE SET NULL,
    ADD FOREIGN KEY (user8_id) REFERENCES users(id) ON DELETE SET NULL;
