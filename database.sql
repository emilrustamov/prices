CREATE DATABASE IF NOT EXISTS bitrix24_reports CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE bitrix24_reports;

CREATE TABLE IF NOT EXISTS contracts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bitrix_id INT NOT NULL,
    title VARCHAR(500),
    start_date DATETIME,
    end_date DATETIME,
    planned_start_date DATETIME,
    planned_end_date DATETIME,
    unit_id VARCHAR(100),
    stage_id VARCHAR(100),
    contract_type_id INT NULL,
    opportunity DECIMAL(15,2),
    currency_id VARCHAR(10),
    is_valid TINYINT(1) DEFAULT 1,
    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_bitrix_id (bitrix_id),
    KEY idx_unit_id (unit_id),
    KEY idx_dates (start_date, end_date),
    KEY idx_valid (is_valid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS units (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bitrix_id VARCHAR(100) NOT NULL,
    name VARCHAR(500),
    apartment_id VARCHAR(100) NULL,
    district VARCHAR(255) NULL,
    building VARCHAR(255) NULL,
    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_bitrix_id (bitrix_id),
    KEY idx_district (district),
    KEY idx_building (building)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monthly_reports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    unit_id VARCHAR(100) NOT NULL,
    contract_type VARCHAR(50) NOT NULL,
    month_key VARCHAR(7) NOT NULL,
    year INT NOT NULL,
    month_num INT NOT NULL,
    occupied_days INT DEFAULT 0,
    total_revenue DECIMAL(15,2) DEFAULT 0.00,
    avg_price_per_day DECIMAL(15,2) DEFAULT 0.00,
    calculated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_unit_month_type (unit_id, month_key, contract_type),
    KEY idx_month (year, month_num),
    KEY idx_unit (unit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
