<?php
require_once(__DIR__ . '/config.php');

$pdo = getDbConnection();
$currentYear = date('Y');

echo "=== Расчет месячных отчетов ===\n";

$pdo->exec("TRUNCATE TABLE monthly_reports");
echo "Таблица monthly_reports очищена\n";

$stmt = $pdo->query("
    SELECT 
        bitrix_id, unit_id, start_date, end_date, opportunity, contract_type_id, stage_id
    FROM contracts 
    WHERE is_valid = 1 
    AND start_date IS NOT NULL 
    AND end_date IS NOT NULL 
    AND opportunity > 0 
    AND unit_id IS NOT NULL 
    AND unit_id != ''
    AND (stage_id IS NULL OR stage_id NOT LIKE '%:FAIL%')
    ORDER BY unit_id, start_date, end_date
");

$contracts = $stmt->fetchAll();

echo "Обработка " . count($contracts) . " валидных контрактов...\n";

$insertStmt = $pdo->prepare("
    INSERT INTO monthly_reports (unit_id, contract_type, month_key, year, month_num, occupied_days, total_revenue, avg_price_per_day)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        occupied_days = occupied_days + VALUES(occupied_days),
        total_revenue = total_revenue + VALUES(total_revenue),
        avg_price_per_day = (total_revenue + VALUES(total_revenue)) / (occupied_days + VALUES(occupied_days)),
        calculated_at = CURRENT_TIMESTAMP
");

$processedUnits = [];

$processed = 0;
$processedContracts = [];
$skippedNoType = 0;
$skippedDuplicates = 0;
$skippedInvalidDays = 0;
$skippedErrors = 0;

foreach ($contracts as $contract) {
    try {
        $contractType = getContractTypeName($contract['contract_type_id']);
        if ($contractType === null) {
            $skippedNoType++;
            continue;
        }
        
        $contractKey = $contract['unit_id'] . '|' . $contract['start_date'] . '|' . $contract['end_date'];
        
        if (isset($processedContracts[$contractKey])) {
            $skippedDuplicates++;
            continue;
        }
        
        $startDate = new DateTime($contract['start_date']);
        $endDate = new DateTime($contract['end_date']);
        $monthlyPrice = floatval($contract['opportunity']);
        
        $totalDays = $startDate->diff($endDate)->days + 1;
        if ($totalDays <= 0) {
            $skippedInvalidDays++;
            continue;
        }
        
        $currentMonth = clone $startDate;
        $currentMonth->modify('first day of this month');
        $isFirstMonth = true;
        
        while ($currentMonth <= $endDate) {
            if ($contractType === 'долгосрок' && $totalDays >= 30 && $totalDays < 60 && !$isFirstMonth) {
                $isFirstMonth = false;
                $currentMonth->modify('+1 month');
                continue;
            }
            
            $monthKey = $currentMonth->format('Y-m');
            $year = (int)$currentMonth->format('Y');
            $monthNum = (int)$currentMonth->format('m');
            
            $monthStart = new DateTime($currentMonth->format('Y-m-01'));
            $monthEnd = clone $monthStart;
            $monthEnd->modify('last day of this month');
            $monthEnd->setTime(23, 59, 59);
            
            $periodStart = $startDate > $monthStart ? $startDate : $monthStart;
            $periodEnd = $endDate < $monthEnd ? $endDate : $monthEnd;
            
            if ($periodStart <= $periodEnd) {
                $daysInMonth = $periodStart->diff($periodEnd)->days + 1;
                $daysInCalendarMonth = (int)$monthEnd->format('j');
                
                if ($daysInMonth > 0) {
                    if ($contractType === 'долгосрок' && $totalDays >= 30 && $daysInMonth < $daysInCalendarMonth && !$isFirstMonth) {
                        $isFirstMonth = false;
                        $currentMonth->modify('+1 month');
                        continue;
                    }
                    
                    if ($contractType === 'долгосрок') {
                        if ($totalDays >= 30 && $totalDays < 60 && $isFirstMonth) {
                            $revenue = $monthlyPrice;
                        } elseif ($totalDays >= 30) {
                            $revenue = $monthlyPrice;
                        } else {
                            $pricePerDay = $monthlyPrice / $daysInCalendarMonth;
                            $revenue = $pricePerDay * $daysInMonth;
                        }
                    } else {
                        $pricePerDay = $monthlyPrice / $totalDays;
                        $revenue = $pricePerDay * $daysInMonth;
                    }
                    
                    $avgPrice = $revenue / $daysInMonth;
                    
                    $insertStmt->execute([
                        $contract['unit_id'],
                        $contractType,
                        $monthKey,
                        $year,
                        $monthNum,
                        $daysInMonth,
                        $revenue,
                        $avgPrice
                    ]);
                }
            }
            
            $isFirstMonth = false;
            $currentMonth->modify('+1 month');
        }
        
        $processedContracts[$contractKey] = true;
        $processed++;
        if ($processed % 100 == 0) {
            echo "Обработано контрактов: $processed / " . count($contracts) . "\n";
        }
    } catch (Exception $e) {
        $skippedErrors++;
        continue;
    }
}

$pdo->exec("
    UPDATE monthly_reports 
    SET avg_price_per_day = CASE 
        WHEN occupied_days > 0 THEN total_revenue / occupied_days 
        ELSE 0 
    END
");

$reportsCount = $pdo->query("SELECT COUNT(*) as cnt FROM monthly_reports")->fetch()['cnt'];

echo "\nРасчет завершен!\n";
echo "Обработано контрактов: $processed / " . count($contracts) . "\n";
echo "Пропущено:\n";
echo "  - Без типа контракта: $skippedNoType\n";
echo "  - Дубликаты: $skippedDuplicates\n";
echo "  - Неверные даты (<= 0 дней): $skippedInvalidDays\n";
echo "  - Ошибки обработки: $skippedErrors\n";
$totalSkipped = $skippedNoType + $skippedDuplicates + $skippedInvalidDays + $skippedErrors;
echo "Всего пропущено: $totalSkipped\n";
echo "Создано записей в monthly_reports: $reportsCount\n";

