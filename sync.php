<?php
require_once(__DIR__ . '/config.php');
require_once(__DIR__ . '/crest.php');

$pdo = getDbConnection();

$entityTypeId = 183;
$allContracts = [];
$start = 0;
$pageCount = 0;
$limit = 50;

echo "=== Синхронизация контрактов ===\n";

while (true) {
    $pageCount++;
    echo "Страница $pageCount (start=$start)...\n";
    
    $result = CRest::call('crm.item.list', [
        'entityTypeId' => $entityTypeId,
        'select' => [
            'id',
            'title',
            'ufCrm20ContractEndDate',
            'ufCrm20ContractStartDate',
            'ufCrm20_1744800159',
            'ufCrm20_1744800193',
            'ufCrm20_1693919019',
            'stageId',
            'ufCrm20_1693561495',
            'opportunity',
            'currencyId'
        ],
        'start' => $start
    ]);

    if (isset($result['error'])) {
        echo "Ошибка API: " . ($result['error_information'] ?? $result['error']) . "\n";
        break;
    }

    if (!isset($result['result']['items']) || empty($result['result']['items'])) {
        echo "Нет данных на странице $pageCount\n";
        break;
    }

    $itemsCount = count($result['result']['items']);
    $allContracts = array_merge($allContracts, $result['result']['items']);
    echo "Получено $itemsCount контрактов. Всего: " . count($allContracts) . "\n";

    foreach ($result['result']['items'] as $contract) {
        $startDate = $contract['ufCrm20ContractStartDate'] ?? null;
        $endDate = $contract['ufCrm20ContractEndDate'] ?? null;
        $stageId = $contract['stageId'] ?? null;
        $contractTypeId = $contract['ufCrm20_1693561495'] ?? null;
        
        $isValid = 1;
        if ($startDate && $endDate) {
            try {
                $startDateTime = new DateTime($startDate);
                $endDateTime = new DateTime($endDate);
                if ($endDateTime <= $startDateTime) {
                    $isValid = 0;
                }
            } catch (Exception $e) {
                $isValid = 0;
            }
        } else {
            $isValid = 0;
        }
        
        if ($stageId && strpos($stageId, ':FAIL') !== false) {
            $isValid = 0;
        }

        $stmt = $pdo->prepare("
            INSERT INTO contracts (
                bitrix_id, title, start_date, end_date, planned_start_date, 
                planned_end_date, unit_id, stage_id, contract_type_id, opportunity, currency_id, is_valid
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                start_date = VALUES(start_date),
                end_date = VALUES(end_date),
                planned_start_date = VALUES(planned_start_date),
                planned_end_date = VALUES(planned_end_date),
                unit_id = VALUES(unit_id),
                stage_id = VALUES(stage_id),
                contract_type_id = VALUES(contract_type_id),
                opportunity = VALUES(opportunity),
                currency_id = VALUES(currency_id),
                is_valid = VALUES(is_valid),
                synced_at = CURRENT_TIMESTAMP
        ");

        $stmt->execute([
            $contract['id'] ?? null,
            $contract['title'] ?? null,
            $startDate ? (new DateTime($startDate))->format('Y-m-d H:i:s') : null,
            $endDate ? (new DateTime($endDate))->format('Y-m-d H:i:s') : null,
            $contract['ufCrm20_1744800159'] ? (new DateTime($contract['ufCrm20_1744800159']))->format('Y-m-d H:i:s') : null,
            $contract['ufCrm20_1744800193'] ? (new DateTime($contract['ufCrm20_1744800193']))->format('Y-m-d H:i:s') : null,
            $contract['ufCrm20_1693919019'] ?? null,
            $contract['stageId'] ?? null,
            $contractTypeId,
            $contract['opportunity'] ?? 0,
            $contract['currencyId'] ?? 'AED',
            $isValid
        ]);
    }

    if ($itemsCount < $limit) {
        break;
    }
    
    $start += $limit;
}

$validCount = $pdo->query("SELECT COUNT(*) as cnt FROM contracts WHERE is_valid = 1")->fetch()['cnt'];

echo "\nСинхронизация завершена!\n";
echo "Всего контрактов получено: " . count($allContracts) . "\n";
echo "Валидных контрактов в БД: $validCount\n";

echo "\nЗапуск расчета отчетов...\n";
$calculateScript = __DIR__ . '/calculate_reports.php';
if (file_exists($calculateScript)) {
    passthru("php " . escapeshellarg($calculateScript));
} else {
    echo "Ошибка: файл calculate_reports.php не найден\n";
}

echo "\nСинхронизация юнитов (Apartment → District/Building)...\n";

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
        echo "Ошибка API юнитов: " . ($result['error_information'] ?? $result['error']) . "\n";
        break;
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

    $apartmentChunks = array_chunk(array_keys($apartmentIdsToLoad), 50);
    foreach ($apartmentChunks as $apartmentChunk) {
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
            echo "Ошибка API апартаментов: " . ($apartmentResult['error_information'] ?? $apartmentResult['error']) . "\n";
            break 2;
        }

        foreach ($apartmentResult['result']['items'] ?? [] as $apartment) {
            $apartmentCache[(string)$apartment['id']] = [
                'district' => normalizeBitrixString($apartment[APARTMENT_DISTRICT_FIELD] ?? null),
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
}

echo "Синхронизировано юнитов: $unitsSynced\n";
echo "С district: $withDistrict\n";
echo "С building: $withBuilding\n";

