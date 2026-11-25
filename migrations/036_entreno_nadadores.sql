-- 036: Entrenos enviados a nadadores concretos, además de (o en vez de) a grupos.

CREATE TABLE IF NOT EXISTS entreno_nadadores (
    entreno_id INT NOT NULL,
    user_id INT NOT NULL,
    PRIMARY KEY (entreno_id, user_id),
    FOREIGN KEY (entreno_id) REFERENCES entrenos(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
