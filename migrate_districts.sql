CREATE TABLE IF NOT EXISTS districts (
    id INT UNSIGNED PRIMARY KEY,
    name VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS unit_districts (
    unit_id VARCHAR(100) NOT NULL,
    district_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (unit_id, district_id),
    KEY idx_district_id (district_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
