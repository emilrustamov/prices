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
    $shortTermIds = [882, 1304, 6578, 1306];
    $longTermIds = [884, 886, 8672];
    if (in_array($typeIdInt, $shortTermIds, true)) {
        return 'краткосрок';
    }
    if (in_array($typeIdInt, $longTermIds, true)) {
        return 'долгосрок';
    }
    return null;
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
        if (!empty($_POST)) {
            $input = array_merge($input, $_POST);
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

