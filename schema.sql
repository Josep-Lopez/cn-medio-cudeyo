-- CN Medio Cudeyo — Base de Datos
-- Se ejecuta automáticamente al hacer `docker compose up` la primera vez.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS users (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    nom        VARCHAR(100)  NOT NULL,
    email      VARCHAR(150)  NOT NULL UNIQUE,
    password   VARCHAR(255)  NOT NULL,
    rol        ENUM('soci','admin') NOT NULL DEFAULT 'soci',
    estado     ENUM('pendiente','activo','rechazado') NOT NULL DEFAULT 'pendiente',
    lliga      ENUM('benjamin','alevin','infantil','junior','master') DEFAULT NULL,
    sexe       ENUM('M','F') NOT NULL,
    avatar_url VARCHAR(500)  DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS noticias (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    titol      VARCHAR(255) NOT NULL,
    resum      VARCHAR(500) DEFAULT NULL,
    contingut  TEXT         DEFAULT NULL,
    imatge_url VARCHAR(500) DEFAULT NULL,
    publicat   TINYINT(1)   NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS biblioteca (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    titol      VARCHAR(255) NOT NULL,
    descripcio VARCHAR(500) DEFAULT NULL,
    url_drive  VARCHAR(500) NOT NULL,
    categoria  VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marques (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT         NOT NULL,
    prova      VARCHAR(10) NOT NULL,
    piscina    ENUM('25m','50m') NOT NULL DEFAULT '25m',
    temps      VARCHAR(20) NOT NULL,
    temps_seg  FLOAT       NOT NULL,
    data_marca DATE        NOT NULL,
    temporada  VARCHAR(10) NOT NULL DEFAULT '2025-26',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_marca (user_id, prova, piscina, temporada)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS config (
    clau       VARCHAR(100) PRIMARY KEY,
    valor      LONGTEXT     NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contactos (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    nombre     VARCHAR(100) NOT NULL,
    email      VARCHAR(150) NOT NULL,
    asunto     VARCHAR(255) DEFAULT NULL,
    mensaje    TEXT         NOT NULL,
    leido      TINYINT(1)   NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SEED DATA
-- ============================================================

-- Admin (password: Admin1234!)
INSERT INTO users (nom, email, password, rol, estado, lliga, sexe) VALUES
('Administrador', 'admin@cnmediocudeyo.es',
 '$2y$10$nJd6zNkYTS9Lb6OA8W5eye/OL.KQyev9N3VdLHDDH0Al8Glv5zIh.',
 'admin', 'activo', NULL, 'M')
ON DUPLICATE KEY UPDATE id = id;

-- Temporada activa
INSERT INTO config (clau, valor) VALUES ('temporada_activa', '2025-26')
ON DUPLICATE KEY UPDATE valor = valor;

-- FINA Times (tiempos mundiales de referencia, temporada 2024-25)
INSERT INTO config (clau, valor) VALUES ('fina_times', '{
  "50 libre_M_50m": 20.91, "50 libre_M_25m": 19.9,
  "50 libre_F_50m": 23.61, "50 libre_F_25m": 22.83,
  "100 libre_M_50m": 46.4, "100 libre_M_25m": 44.84,
  "100 libre_F_50m": 51.71, "100 libre_F_25m": 50.25,
  "200 libre_M_50m": 102.0, "200 libre_M_25m": 98.61,
  "200 libre_F_50m": 112.23, "200 libre_F_25m": 110.31,
  "400 libre_M_50m": 219.96, "400 libre_M_25m": 212.25,
  "400 libre_F_50m": 235.38, "400 libre_F_25m": 230.25,
  "800 libre_M_50m": 452.12, "800 libre_M_25m": 440.46,
  "800 libre_F_50m": 484.79, "800 libre_F_25m": 477.42,
  "1500 libre_M_50m": 870.67, "1500 libre_M_25m": 846.88,
  "1500 libre_F_50m": 920.48, "1500 libre_F_25m": 908.24,
  "50 espalda_M_50m": 23.55, "50 espalda_M_25m": 22.11,
  "50 espalda_F_50m": 26.86, "50 espalda_F_25m": 25.23,
  "100 espalda_M_50m": 51.6, "100 espalda_M_25m": 48.33,
  "100 espalda_F_50m": 57.13, "100 espalda_F_25m": 54.02,
  "200 espalda_M_50m": 111.92, "200 espalda_M_25m": 105.63,
  "200 espalda_F_50m": 123.14, "200 espalda_F_25m": 118.04,
  "50 braza_M_50m": 25.95, "50 braza_M_25m": 24.95,
  "50 braza_F_50m": 29.16, "50 braza_F_25m": 28.37,
  "100 braza_M_50m": 56.88, "100 braza_M_25m": 55.28,
  "100 braza_F_50m": 64.13, "100 braza_F_25m": 62.36,
  "200 braza_M_50m": 125.48, "200 braza_M_25m": 120.16,
  "200 braza_F_50m": 137.55, "200 braza_F_25m": 132.5,
  "50 mariposa_M_50m": 22.27, "50 mariposa_M_25m": 21.32,
  "50 mariposa_F_50m": 24.43, "50 mariposa_F_25m": 23.94,
  "100 mariposa_M_50m": 49.45, "100 mariposa_M_25m": 47.71,
  "100 mariposa_F_50m": 54.6, "100 mariposa_F_25m": 52.71,
  "200 mariposa_M_50m": 110.34, "200 mariposa_M_25m": 106.85,
  "200 mariposa_F_50m": 121.81, "200 mariposa_F_25m": 119.32,
  "100 estilos_M_25m": 49.28, "100 estilos_F_25m": 55.11,
  "200 estilos_M_50m": 114.0, "200 estilos_M_25m": 108.88,
  "200 estilos_F_50m": 126.12, "200 estilos_F_25m": 121.63,
  "400 estilos_M_50m": 242.5, "400 estilos_M_25m": 234.41,
  "400 estilos_F_50m": 264.38, "400 estilos_F_25m": 255.48
}')
ON DUPLICATE KEY UPDATE valor = valor;

-- Mínimas RFEN (temporada 2025-26)
INSERT INTO config (clau, valor) VALUES ('minimes_rfen', '{
  "50L":   { "M": { "alevin": 28.10, "infantil": null, "junior": null, "sub20": null, "absoluto": 23.85 }, "F": { "alevin": 29.30, "infantil": null, "junior": null, "sub20": null, "absoluto": 26.85 } },
  "100L":  { "M": { "alevin": 62.00, "infantil": null, "junior": null, "sub20": null, "absoluto": 51.90 }, "F": { "alevin": 63.90, "infantil": null, "junior": null, "sub20": null, "absoluto": 58.50 } },
  "200L":  { "M": { "alevin": 136.00, "infantil": null, "junior": null, "sub20": null, "absoluto": 113.75 }, "F": { "alevin": 140.00, "infantil": null, "junior": null, "sub20": null, "absoluto": 126.95 } },
  "400L":  { "M": { "alevin": 290.00, "infantil": null, "junior": null, "sub20": null, "absoluto": 244.50 }, "F": { "alevin": 294.35, "infantil": null, "junior": null, "sub20": null, "absoluto": 267.30 } },
  "800L":  { "M": { "alevin": 597.00, "infantil": null, "junior": null, "sub20": null, "absoluto": 508.00 }, "F": { "alevin": 607.00, "infantil": null, "junior": null, "sub20": null, "absoluto": 545.90 } },
  "1500L": { "M": { "alevin": null, "infantil": null, "junior": null, "sub20": null, "absoluto": 979.00 }, "F": { "alevin": null, "infantil": null, "junior": null, "sub20": null, "absoluto": 1050.00 } },
  "50E":   { "M": { "alevin": null, "infantil": null, "junior": null, "sub20": null, "absoluto": 27.60  }, "F": { "alevin": null, "infantil": null, "junior": null, "sub20": null, "absoluto": 30.95  } },
  "100E":  { "M": { "alevin": null, "infantil": null, "junior": null, "sub20": null, "absoluto": 58.75  }, "F": { "alevin": null, "infantil": null, "junior": null, "sub20": null, "absoluto": 65.75  } },
  "200E":  { "M": { "alevin": null, "infantil": null, "junior": null, "sub20": null, "absoluto": 129.50 }, "F": { "alevin": null, "infantil": null, "junior": null, "sub20": null, "absoluto": 142.55 } },
  "50B":   { "M": { "alevin": null, "infantil": null, "junior": null, "sub20": null, "absoluto": 29.80  }, "F": { "alevin": null, "infantil": null, "junior": null, "sub20": null, "absoluto": 33.95  } },
  "100B":  { "M": { "alevin": null, "infantil": null, "junior": null, "sub20": null, "absoluto": 65.00  }, "F": { "alevin": null, "infantil": null, "junior": null, "sub20": null, "absoluto": 74.50  } },
  "200B":  { "M": { "alevin": null, "infantil": null, "junior": null, "sub20": null, "absoluto": 144.10 }, "F": { "alevin": null, "infantil": null, "junior": null, "sub20": null, "absoluto": 161.50 } },
  "50M":   { "M": { "alevin": null, "infantil": null, "junior": null, "sub20": null, "absoluto": 25.10  }, "F": { "alevin": null, "infantil": null, "junior": null, "sub20": null, "absoluto": 28.40  } },
  "100M":  { "M": { "alevin": null, "infantil": null, "junior": null, "sub20": null, "absoluto": 56.00  }, "F": { "alevin": null, "infantil": null, "junior": null, "sub20": null, "absoluto": 63.80  } },
  "200M":  { "M": { "alevin": null, "infantil": null, "junior": null, "sub20": null, "absoluto": 128.40 }, "F": { "alevin": null, "infantil": null, "junior": null, "sub20": null, "absoluto": 141.00 } },
  "100X":  { "M": { "alevin": null, "infantil": null, "junior": null, "sub20": null, "absoluto": null   }, "F": { "alevin": null, "infantil": null, "junior": null, "sub20": null, "absoluto": null   } },
  "200X":  { "M": { "alevin": null, "infantil": null, "junior": null, "sub20": null, "absoluto": 129.20 }, "F": { "alevin": null, "infantil": null, "junior": null, "sub20": null, "absoluto": 144.00 } },
  "400X":  { "M": { "alevin": null, "infantil": null, "junior": null, "sub20": null, "absoluto": 278.90 }, "F": { "alevin": null, "infantil": null, "junior": null, "sub20": null, "absoluto": 303.95 } }
}')
ON DUPLICATE KEY UPDATE valor = valor;

-- Noticia de ejemplo
INSERT INTO noticias (titol, resum, contingut, publicat) VALUES
('Bienvenidos a la nueva web del CN Medio Cudeyo',
 'Estrenamos nueva web con sistema de socios, ranking de marcas y calculadora de rendimiento.',
 '<p>Nos complace anunciar el lanzamiento de nuestra nueva plataforma web. Desde ahora los socios podéis acceder a vuestro panel personal, consultar vuestras marcas y el ranking de la liga.</p><p>El administrador gestiona las altas, marcas y noticias desde el panel de administración.</p>',
 1)
ON DUPLICATE KEY UPDATE id = id;
