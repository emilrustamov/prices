<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/crest.php';

$pdo = getDbConnection();

echo "Синхронизация юнитов (Apartment → District/Building)...\n";

$unitsStmt = $pdo->query("
    SELECT DISTINCT unit_id
    FROM contracts
    WHERE unit_id IS NOT NULL
    AND unit_id != ''
");
$unitIds = $unitsStmt->fetchAll(PDO::FETCH_COLUMN);

$unitInsertStmt = $pdo->prepare("
    INSERT INTO units (bitrix_id, name, apartment_id, district, building, synced_at)
    VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
    ON DUPLICATE KEY UPDATE
        name = VALUES(name),
        apartment_id = VALUES(apartment_id),
        district = VALUES(district),
        building = VALUES(building),
        synced_at = CURRENT_TIMESTAMP
");

$unitsSynced = 0;
$withDistrict = 0;
$withBuilding = 0;
$chunks = array_chunk($unitIds, 50);
$apartmentCache = [];

foreach ($chunks as $chunk) {
    $result = CRest::call('crm.item.list', [
        'entityTypeId' => UNIT_ENTITY_TYPE_ID,
        'filter' => [
            '@id' => array_map('intval', $chunk)
        ],
        'select' => [
            'id',
            'title',
            UNIT_APARTMENT_FIELD,
            'parentId144'
        ],
        'start' => 0
    ]);

    if (isset($result['error'])) {
        echo 'Ошибка API юнитов: ' . ($result['error_information'] ?? $result['error']) . PHP_EOL;
        exit(1);
    }

    $items = $result['result']['items'] ?? [];
    $itemsById = [];
    $apartmentIdsToLoad = [];
    foreach ($items as $item) {
        $itemsById[(string)$item['id']] = $item;
        $apartmentId = normalizeBitrixId($item[UNIT_APARTMENT_FIELD] ?? null)
            ?? normalizeBitrixId($item['parentId144'] ?? null);
        if ($apartmentId !== null && !isset($apartmentCache[$apartmentId])) {
            $apartmentIdsToLoad[$apartmentId] = true;
        }
    }

    foreach (array_chunk(array_keys($apartmentIdsToLoad), 50) as $apartmentChunk) {
        $apartmentResult = CRest::call('crm.item.list', [
            'entityTypeId' => APARTMENT_ENTITY_TYPE_ID,
            'filter' => [
                '@id' => array_map('intval', $apartmentChunk)
            ],
            'select' => [
                'id',
                APARTMENT_DISTRICT_FIELD,
                APARTMENT_BUILDING_FIELD
            ],
            'start' => 0
        ]);

        if (isset($apartmentResult['error'])) {
            echo 'Ошибка API апартаментов: ' . ($apartmentResult['error_information'] ?? $apartmentResult['error']) . PHP_EOL;
            exit(1);
        }

        foreach ($apartmentResult['result']['items'] ?? [] as $apartment) {
            $apartmentCache[(string)$apartment['id']] = [
                'district' => normalizeDistrictName(normalizeBitrixString($apartment[APARTMENT_DISTRICT_FIELD] ?? null)),
                'building' => normalizeBitrixString($apartment[APARTMENT_BUILDING_FIELD] ?? null),
            ];
        }
    }

    foreach ($chunk as $unitId) {
        $unitKey = (string)$unitId;
        $item = $itemsById[$unitKey] ?? null;
        $unitName = $item['title'] ?? $unitKey;
        $apartmentId = $item
            ? (normalizeBitrixId($item[UNIT_APARTMENT_FIELD] ?? null)
                ?? normalizeBitrixId($item['parentId144'] ?? null))
            : null;
        $district = null;
        $building = null;
        if ($apartmentId !== null && isset($apartmentCache[$apartmentId])) {
            $district = $apartmentCache[$apartmentId]['district'];
            $building = $apartmentCache[$apartmentId]['building'];
        }
        $unitInsertStmt->execute([$unitKey, $unitName, $apartmentId, $district, $building]);
        if ($district !== null) {
            $withDistrict++;
        }
        if ($building !== null) {
            $withBuilding++;
        }
        $unitsSynced++;
    }

    echo "progress: $unitsSynced / " . count($unitIds) . PHP_EOL;
}

echo "units: $unitsSynced\n";
echo "with district: $withDistrict\n";
echo "with building: $withBuilding\n";
echo 'distinct districts: ' . $pdo->query("SELECT COUNT(DISTINCT district) FROM units WHERE district IS NOT NULL AND district != ''")->fetchColumn() . PHP_EOL;
echo 'distinct buildings: ' . $pdo->query("SELECT COUNT(DISTINCT building) FROM units WHERE building IS NOT NULL AND building != ''")->fetchColumn() . PHP_EOL;
