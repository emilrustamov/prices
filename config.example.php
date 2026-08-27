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
define('APARTMENT_TYPE_FIELD', 'ufCrm6_1682232863625');
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
 * @param mixed $value
 * @return string|null
 */
function getApartmentTypeLabel($value) {
    static $labels = [
        52 => 'Studio',
        54 => '1br',
        10662 => "1br + maid's",
        10664 => '1br + study',
        56 => '2br',
        10666 => "2br + maid's",
        10668 => '2br + study',
        58 => '3br',
        10670 => "3br + maid's",
        10672 => '3br + study',
        60 => '4br',
        62 => '5br',
        64 => '6br+',
    ];
    if ($value === null || $value === '') {
        return null;
    }
    if (is_array($value)) {
        $value = reset($value);
        if ($value === false || $value === null || $value === '') {
            return null;
        }
    }
    $asString = trim((string)$value);
    if (in_array($asString, $labels, true)) {
        return $asString;
    }
    $typeIdInt = (int)$asString;
    if (isset($labels[$typeIdInt])) {
        return $labels[$typeIdInt];
    }
    return normalizeBitrixString($asString);
}

/**
 * @return string[]
 */
function getApartmentTypeOptions() {
    return [
        'Studio',
        '1br',
        "1br + maid's",
        '1br + study',
        '2br',
        "2br + maid's",
        '2br + study',
        '3br',
        "3br + maid's",
        '3br + study',
        '4br',
        '5br',
        '6br+',
    ];
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
 * @param int $index
 * @return string
 */
function xlsxColumnName($index) {
    $name = '';
    $n = $index + 1;
    while ($n > 0) {
        $mod = ($n - 1) % 26;
        $name = chr(65 + $mod) . $name;
        $n = intdiv($n - 1, 26);
    }
    return $name;
}

/**
 * @param mixed $value
 * @return string
 */
function xlsxXmlEscape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * @param resource $handle
 * @param int $rowNum
 * @param array $values
 * @return void
 */
function writeXlsxSheetRow($handle, $rowNum, array $values) {
    fwrite($handle, '<row r="' . $rowNum . '">');
    foreach ($values as $colIndex => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $ref = xlsxColumnName($colIndex) . $rowNum;
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value) && !preg_match('/^0\d+/', $value))) {
            fwrite($handle, '<c r="' . $ref . '"><v>' . xlsxXmlEscape($value) . '</v></c>');
            continue;
        }
        fwrite(
            $handle,
            '<c r="' . $ref . '" t="inlineStr"><is><t>' . xlsxXmlEscape($value) . '</t></is></c>'
        );
    }
    fwrite($handle, '</row>');
}

/**
 * @param array<int, array<int, mixed>> $rows
 * @param string $filepath
 * @return void
 */
function writeXlsxFile(array $rows, $filepath) {
    $sheetFile = tempnam(sys_get_temp_dir(), 'xlsx_sheet_');
    if ($sheetFile === false) {
        throw new RuntimeException('Не удалось создать временный файл');
    }

    $sheet = fopen($sheetFile, 'wb');
    if ($sheet === false) {
        throw new RuntimeException('Не удалось открыть временный файл');
    }

    fwrite($sheet, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>');
    fwrite($sheet, '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');
    foreach ($rows as $index => $row) {
        writeXlsxSheetRow($sheet, $index + 1, $row);
    }
    fwrite($sheet, '</sheetData></worksheet>');
    fclose($sheet);

    $zip = new ZipArchive();
    if ($zip->open($filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        unlink($sheetFile);
        throw new RuntimeException('Не удалось создать xlsx');
    }

    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>');
    $zip->addFile($sheetFile, 'xl/worksheets/sheet1.xml');
    $zip->close();
    unlink($sheetFile);
}

/**
 * @param array<int, array<int, mixed>> $rows
 * @param string $filename
 * @return void
 */
function streamXlsxDownload(array $rows, $filename) {
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx_out_');
    if ($tmp === false) {
        throw new RuntimeException('Не удалось создать временный файл');
    }
    $xlsxPath = $tmp . '.xlsx';
    rename($tmp, $xlsxPath);

    try {
        writeXlsxFile($rows, $xlsxPath);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($xlsxPath));
        header('Cache-Control: no-store');
        readfile($xlsxPath);
    } finally {
        if (is_file($xlsxPath)) {
            unlink($xlsxPath);
        }
    }
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
