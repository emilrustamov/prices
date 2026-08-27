<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'bitrix24_reports');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('DB_CHARSET', 'utf8mb4');

define('C_REST_CLIENT_ID', 'your_bitrix_client_id');
define('C_REST_CLIENT_SECRET', 'your_bitrix_client_secret');

define('UNIT_ENTITY_TYPE_ID', 167);
define('APARTMENT_ENTITY_TYPE_ID', 144);
define('UNIT_APARTMENT_FIELD', 'ufCrm8_1684429208');
define('APARTMENT_DISTRICT_FIELD', 'ufCrm6District');
define('APARTMENT_BUILDING_FIELD', 'ufCrm6_1682232363193');
define('REPORT_CACHE_TTL', 300);
define('CONTRACT_ENTITY_TYPE_ID', 183);

function getDbConnection() {
    static $pdo = null;

    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die("Ошибка подключения к БД: " . $e->getMessage());
        }
    }

    return $pdo;
}

/**
 * @param mixed $typeId
 * @return string|null
 */
function getContractTypeName($typeId) {
    if ($typeId === null || $typeId === '') {
        return null;
    }
    $typeIdInt = (int)$typeId;
    if (in_array($typeIdInt, [882, 1304, 6578], true)) {
        return 'краткосрок';
    }
    if (in_array($typeIdInt, [884, 886, 8672], true)) {
        return 'долгосрок';
    }
    return null;
}

/**
 * @param mixed $typeId
 * @return string
 */
function getContractTypeIdLabel($typeId) {
    static $labels = [
        882 => 'Airbnb',
        1304 => 'Booking',
        6578 => 'Short term (less than a month)',
        884 => 'Long term (1 month)',
        886 => 'Long term (2+ months)',
        8672 => 'Ejari',
    ];
    $typeIdInt = (int)$typeId;
    return $labels[$typeIdInt] ?? (string)$typeId;
}

/**
 * @param int[] $years
 * @param int[] $months
 * @return string[]
 */
function buildReportMonthKeys(array $years, array $months) {
    $years = array_values(array_unique(array_map('intval', $years)));
    sort($years);
    $monthNums = !empty($months)
        ? array_values(array_unique(array_map('intval', $months)))
        : range(1, 12);
    sort($monthNums);
    $keys = [];
    foreach ($years as $year) {
        foreach ($monthNums as $monthNum) {
            $keys[] = sprintf('%04d-%02d', $year, $monthNum);
        }
    }
    return $keys;
}

/**
 * @param array $contract
 * @param string $contractType
 * @return array<string, array{days:int,revenue:float,avg:float,year:int,month_num:int}>
 */
function buildContractMonthlyBreakdown(array $contract, $contractType) {
    $startDate = new DateTime($contract['start_date']);
    $endDate = new DateTime($contract['end_date']);
    $monthlyPrice = floatval($contract['opportunity']);
    $totalDays = $startDate->diff($endDate)->days + 1;
    if ($totalDays <= 0) {
        return [];
    }

    $result = [];
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
                    if ($totalDays >= 30) {
                        $revenue = $monthlyPrice;
                    } else {
                        $pricePerDay = $monthlyPrice / $daysInCalendarMonth;
                        $revenue = $pricePerDay * $daysInMonth;
                    }
                } else {
                    $pricePerDay = $monthlyPrice / $totalDays;
                    $revenue = $pricePerDay * $daysInMonth;
                }

                $result[$monthKey] = [
                    'days' => $daysInMonth,
                    'revenue' => $revenue,
                    'avg' => $revenue / $daysInMonth,
                    'year' => $year,
                    'month_num' => $monthNum,
                ];
            }
        }

        $isFirstMonth = false;
        $currentMonth->modify('+1 month');
    }

    return $result;
}

/**
 * @param mixed $value
 * @return string
 */
function csvExportCell($value) {
    $value = (string)$value;
    if (strpbrk($value, ";\"\r\n") !== false) {
        return '"' . str_replace('"', '""', $value) . '"';
    }
    return $value;
}

/**
 * @param mixed $value
 * @return int[]
 */
function parseCsvInts($value) {
    if ($value === null || $value === '') {
        return [];
    }
    if (is_array($value)) {
        return array_values(array_filter(array_map('intval', $value)));
    }
    return array_values(array_filter(array_map('intval', explode(',', (string)$value))));
}

/**
 * @param mixed $value
 * @return string[]
 */
function parseCsvStrings($value) {
    if ($value === null || $value === '') {
        return [];
    }
    if (is_array($value)) {
        return array_values(array_filter(array_map(static function ($item) {
            return trim((string)$item);
        }, $value), static function ($item) {
            return $item !== '';
        }));
    }
    return array_values(array_filter(array_map('trim', explode(',', (string)$value)), static function ($item) {
        return $item !== '';
    }));
}

/**
 * @param string $query
 * @param array $params
 * @param string $column
 * @param string[] $values
 * @return void
 */
function appendUnitStringFilter(&$query, &$params, $column, array $values) {
    if (empty($values)) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($values), '?'));
    $query .= " AND u.$column IN ($placeholders)";
    $params = array_merge($params, $values);
}

/**
 * @param mixed $value
 * @return string|null
 */
function normalizeBitrixString($value) {
    if (is_array($value)) {
        $value = reset($value);
    }
    if ($value === null || $value === false) {
        return null;
    }
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

/**
 * @param mixed $value
 * @return string|null
 */
function normalizeBitrixId($value) {
    if (is_array($value)) {
        $value = reset($value);
    }
    if ($value === null || $value === false || $value === '') {
        return null;
    }
    if (is_string($value) && preg_match('/(\d+)$/', $value, $matches)) {
        return $matches[1];
    }
    $id = (int)$value;
    return $id > 0 ? (string)$id : null;
}

/**
 * @return array
 */
function getRequestInput() {
    static $input = null;
    if ($input !== null) {
        return $input;
    }
    $input = $_GET;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $raw = file_get_contents('php://input');
        if ($raw !== false && $raw !== '') {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $input = array_merge($input, $json);
            }
        }
    }
    return $input;
}

/**
 * @param int[] $years
 * @param int[] $months
 * @return array{0: DateTime, 1: DateTime}
 */
function getReportDateRange(array $years, array $months) {
    $monthNums = !empty($months) ? $months : range(1, 12);
    $rangeStart = null;
    $rangeEnd = null;
    foreach ($years as $year) {
        foreach ($monthNums as $monthNum) {
            $start = new DateTime(sprintf('%04d-%02d-01 00:00:00', $year, $monthNum));
            $end = (clone $start)->modify('last day of this month')->setTime(23, 59, 59);
            if ($rangeStart === null || $start < $rangeStart) {
                $rangeStart = $start;
            }
            if ($rangeEnd === null || $end > $rangeEnd) {
                $rangeEnd = $end;
            }
        }
    }
    return [$rangeStart, $rangeEnd];
}

/**
 * @param string|null $name
 * @return string|null
 */
function normalizeDistrictName($name) {
    $name = normalizeBitrixString($name);
    if ($name === null) {
        return null;
    }
    static $aliases = [
        'downtown dubai' => 'Downtown',
        'burj khalifa' => 'Downtown',
        'al jaddaf' => 'Al Jadaf',
        'beach front' => 'Beachfront',
        'discovery garden' => 'Discovery Gardens',
        'dubai creek harbour' => 'Creek Harbour',
        'creek' => 'Creek Harbour',
        'jvc' => 'Jumeirah Village Circle',
        'jumeirah lakes towers' => 'JLT',
        'meydan city' => 'Meydan',
        'shobha' => 'Sobha',
        'the palm' => 'Palm Jumeirah',
        'the palm jumeirah' => 'Palm Jumeirah',
        'prodaction city' => 'Production City',
        'dubai international financial center difc' => 'DIFC',
    ];
    return $aliases[mb_strtolower($name)] ?? $name;
}

/**
 * @param string $key
 * @return mixed|null
 */
function readReportCache($key) {
    $file = __DIR__ . '/cache/report_' . $key . '.json';
    if (!is_file($file) || filemtime($file) < time() - REPORT_CACHE_TTL) {
        return null;
    }
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

/**
 * @param string $key
 * @param array $payload
 * @return void
 */
function writeReportCache($key, array $payload) {
    $dir = __DIR__ . '/cache';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    file_put_contents($dir . '/report_' . $key . '.json', json_encode($payload, JSON_UNESCAPED_UNICODE));
}

/**
 * @return void
 */
function clearReportCache() {
    foreach (glob(__DIR__ . '/cache/report_*.json') ?: [] as $file) {
        unlink($file);
    }
}

/**
 * @param array $contracts
 * @param string $mContractType
 * @param string $monthKey
 * @param DateTime $monthStart
 * @param DateTime $monthEnd
 * @param int[] $contractTypeIds
 * @return array{items: array<int, array{id:mixed,title:mixed}>, earliestStart: ?DateTime, latestEnd: ?DateTime}
 */
function matchContractsForMonth(array $contracts, $mContractType, $monthKey, DateTime $monthStart, DateTime $monthEnd, array $contractTypeIds) {
    $relevantContracts = [];
    $earliestStart = null;
    $latestEnd = null;

    foreach ($contracts as $contract) {
        if (getContractTypeName($contract['contract_type_id']) !== $mContractType) {
            continue;
        }
        if (!empty($contractTypeIds) && !in_array((int)$contract['contract_type_id'], $contractTypeIds, true)) {
            continue;
        }

        $contractStart = new DateTime($contract['start_date']);
        $contractEnd = new DateTime($contract['end_date']);
        if ($contractStart > $monthEnd || $contractEnd < $monthStart) {
            continue;
        }

        $contractTotalDays = $contractStart->diff($contractEnd)->days + 1;
        if ($mContractType === 'долгосрок' && $contractTotalDays >= 30 && $contractTotalDays < 60
            && $contractStart->format('Y-m') !== $monthKey) {
            continue;
        }

        if ($contractStart > $monthStart) {
            $periodStart = $contractStart;
        } else {
            $periodStart = $monthStart;
        }
        if ($contractEnd < $monthEnd) {
            $periodEnd = $contractEnd;
        } else {
            $periodEnd = $monthEnd;
        }
        if ($periodStart > $periodEnd) {
            continue;
        }

        if ($mContractType === 'долгосрок') {
            if ($earliestStart === null || $contractStart < $earliestStart) {
                $earliestStart = clone $contractStart;
            }
            if ($latestEnd === null || $contractEnd > $latestEnd) {
                $latestEnd = clone $contractEnd;
            }
        }

        $relevantContracts[] = [
            'id' => $contract['bitrix_id'],
            'title' => $contract['title']
        ];
    }

    return [
        'items' => $relevantContracts,
        'earliestStart' => $earliestStart,
        'latestEnd' => $latestEnd,
    ];
}
