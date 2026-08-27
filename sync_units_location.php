<?php
require_once __DIR__ . '/crest.php';
require_once __DIR__ . '/config.php';

$pdo = getDbConnection();

echo "Синхронизация юнитов (Apartment → District/Building/Type)...\n";

$unitIds = $pdo->query("
    SELECT DISTINCT unit_id
    FROM contracts
    WHERE unit_id IS NOT NULL
    AND unit_id != ''
")->fetchAll(PDO::FETCH_COLUMN);

$unitInsertStmt = $pdo->prepare("
    INSERT INTO units (bitrix_id, name, apartment_id, district, building, apartment_type, synced_at)
    VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
    ON DUPLICATE KEY UPDATE
        name = VALUES(name),
        apartment_id = VALUES(apartment_id),
        district = VALUES(district),
        building = VALUES(building),
        apartment_type = VALUES(apartment_type),
        synced_at = CURRENT_TIMESTAMP
");

$unitsSynced = 0;
$withDistrict = 0;
$withBuilding = 0;
$withType = 0;
$apartmentCache = [];

foreach (array_chunk($unitIds, 50) as $chunk) {
    $result = CRest::call('crm.item.list', [
        'entityTypeId' => UNIT_ENTITY_TYPE_ID,
        'filter' => ['@id' => array_map('intval', $chunk)],
        'select' => ['id', 'title', UNIT_APARTMENT_FIELD],
        'start' => 0
    ]);

    if (isset($result['error'])) {
        echo 'Ошибка API юнитов: ' . ($result['error_information'] ?? $result['error']) . PHP_EOL;
        exit(1);
    }

    $itemsById = [];
    $apartmentIdsToLoad = [];
    foreach ($result['result']['items'] ?? [] as $item) {
        $itemsById[(string)$item['id']] = $item;
        $apartmentId = normalizeBitrixId($item[UNIT_APARTMENT_FIELD] ?? null);
        if ($apartmentId !== null && !isset($apartmentCache[$apartmentId])) {
            $apartmentIdsToLoad[$apartmentId] = true;
        }
    }

    foreach (array_chunk(array_keys($apartmentIdsToLoad), 50) as $apartmentChunk) {
        $apartmentResult = CRest::call('crm.item.list', [
            'entityTypeId' => APARTMENT_ENTITY_TYPE_ID,
            'filter' => ['@id' => array_map('intval', $apartmentChunk)],
            'select' => ['id', APARTMENT_DISTRICT_FIELD, APARTMENT_BUILDING_FIELD, APARTMENT_TYPE_FIELD],
            'start' => 0
        ]);

        if (isset($apartmentResult['error'])) {
            echo 'Ошибка API апартаментов: ' . ($apartmentResult['error_information'] ?? $apartmentResult['error']) . PHP_EOL;
            exit(1);
        }

        foreach ($apartmentResult['result']['items'] ?? [] as $apartment) {
            $apartmentCache[(string)$apartment['id']] = [
                'district' => normalizeDistrictName($apartment[APARTMENT_DISTRICT_FIELD] ?? null),
                'building' => normalizeBitrixString($apartment[APARTMENT_BUILDING_FIELD] ?? null),
                'apartment_type' => getApartmentTypeLabel($apartment[APARTMENT_TYPE_FIELD] ?? null),
            ];
        }
    }

    foreach ($chunk as $unitId) {
        $unitKey = (string)$unitId;
        $item = $itemsById[$unitKey] ?? null;
        $apartmentId = $item ? normalizeBitrixId($item[UNIT_APARTMENT_FIELD] ?? null) : null;
        $district = null;
        $building = null;
        $apartmentType = null;
        if ($apartmentId !== null && isset($apartmentCache[$apartmentId])) {
            $district = $apartmentCache[$apartmentId]['district'];
            $building = $apartmentCache[$apartmentId]['building'];
            $apartmentType = $apartmentCache[$apartmentId]['apartment_type'];
        }
        $unitInsertStmt->execute([
            $unitKey,
            $item['title'] ?? $unitKey,
            $apartmentId,
            $district,
            $building,
            $apartmentType
        ]);
        if ($district !== null) {
            $withDistrict++;
        }
        if ($building !== null) {
            $withBuilding++;
        }
        if ($apartmentType !== null) {
            $withType++;
        }
        $unitsSynced++;
    }

    echo "progress: $unitsSynced / " . count($unitIds) . PHP_EOL;
}

echo "units: $unitsSynced\n";
echo "with district: $withDistrict\n";
echo "with building: $withBuilding\n";
echo "with type: $withType\n";
