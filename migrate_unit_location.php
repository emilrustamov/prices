<?php
require_once __DIR__ . '/config.php';

$pdo = getDbConnection();
$cols = $pdo->query('SHOW COLUMNS FROM units')->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('apartment_id', $cols, true)) {
    $pdo->exec('ALTER TABLE units ADD COLUMN apartment_id VARCHAR(100) NULL AFTER name');
}
if (!in_array('district', $cols, true)) {
    $pdo->exec('ALTER TABLE units ADD COLUMN district VARCHAR(255) NULL AFTER apartment_id');
}
if (!in_array('building', $cols, true)) {
    $pdo->exec('ALTER TABLE units ADD COLUMN building VARCHAR(255) NULL AFTER district');
}

$indexes = $pdo->query('SHOW INDEX FROM units')->fetchAll(PDO::FETCH_ASSOC);
$indexNames = array_column($indexes, 'Key_name');
if (!in_array('idx_district', $indexNames, true)) {
    $pdo->exec('ALTER TABLE units ADD INDEX idx_district (district)');
}
if (!in_array('idx_building', $indexNames, true)) {
    $pdo->exec('ALTER TABLE units ADD INDEX idx_building (building)');
}

$pdo->exec('DROP TABLE IF EXISTS unit_districts');
$pdo->exec('DROP TABLE IF EXISTS districts');

echo "OK: units.apartment_id/district/building ready\n";
