<?php
require_once(__DIR__ . '/config.php');
require_once(__DIR__ . '/crest.php');

$pdo = getDbConnection();

$entityTypeId = CONTRACT_ENTITY_TYPE_ID;
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

echo "\nСинхронизация юнитов...\n";
passthru('php ' . escapeshellarg(__DIR__ . '/sync_units_location.php'));

echo "\nЗапуск расчета отчетов...\n";
passthru('php ' . escapeshellarg(__DIR__ . '/calculate_reports.php'));

