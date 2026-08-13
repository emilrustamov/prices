ALTER TABLE units
    ADD COLUMN IF NOT EXISTS apartment_id VARCHAR(100) NULL AFTER name,
    ADD COLUMN IF NOT EXISTS district VARCHAR(255) NULL AFTER apartment_id,
    ADD COLUMN IF NOT EXISTS building VARCHAR(255) NULL AFTER district;

ALTER TABLE units ADD INDEX IF NOT EXISTS idx_district (district);
ALTER TABLE units ADD INDEX IF NOT EXISTS idx_building (building);

DROP TABLE IF EXISTS unit_districts;
DROP TABLE IF EXISTS districts;
