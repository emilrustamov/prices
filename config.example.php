<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'bitrix24_reports');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('DB_CHARSET', 'utf8mb4');

define('C_REST_CLIENT_ID', 'your_bitrix_client_id');
define('C_REST_CLIENT_SECRET', 'your_bitrix_client_secret');

define('UNIT_ENTITY_TYPE_ID', 167);
define('UNIT_DISTRICT_FIELD', 'ufCrm6_1682239464105');

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
 * @param string|null $value
 * @return int[]
 */
function parseCsvInts($value) {
    if ($value === null || $value === '') {
        return [];
    }
    return array_values(array_filter(array_map('intval', explode(',', $value))));
}

/**
 * @return array<int, string>
 */
function getDistrictCatalog() {
    return [
        9222 => 'Al Barsha South',
        9224 => 'Al Bastakiya',
        9226 => 'Al Furjan',
        9228 => 'Al Jadaf',
        9230 => 'Al Karama',
        9232 => 'Al Kifaf',
        9234 => 'Al Merkad',
        9236 => 'Al Rigga',
        9238 => 'Bur Dubai',
        9240 => 'Business Bay',
        9242 => 'DAMAC Hills',
        9244 => 'Downtown Dubai',
        9246 => 'Dubai Hills',
        9248 => 'Dubai Hills Estate',
        9250 => 'Dubai International Financial Center DIFC',
        9252 => 'Dubai Marina',
        9254 => 'Dubai South',
        9256 => 'Dubai Sports City',
        9258 => 'Hadaeq Sheikh Mohammed Bin Rashid City District One',
        9260 => 'International Media Production Zone',
        9262 => 'Jebel Ali',
        9264 => 'Jebel Ali 1',
        9266 => 'Jebel Ali Village',
        9268 => 'Jumeirah',
        9270 => 'JLT',
        9272 => 'Jumeirah 1',
        9274 => 'Jumeirah Beach Residence JBR',
        9276 => 'Jumeirah Lakes Towers',
        9278 => 'Jumeirah Village',
        9280 => 'Jumeirah Village Circle',
        9282 => 'Jumeirah Village Circle District 10',
        9284 => 'Jumeirah Village Circle District 11',
        9286 => 'Jumeirah Village Circle District 12',
        9288 => 'Jumeirah Village Circle District 13',
        9290 => 'Jumeirah Village Triangle District 2',
        9292 => 'Madinat Jumeirah Living',
        9294 => 'Nadd Al Sheba 1',
        9296 => 'Ras Al Khor',
        9298 => 'The Greens',
        9300 => 'The Palm Jumeirah',
        9302 => 'Umm Suqeim 3',
        9304 => 'Wadi Al Safa 5',
        9306 => 'Zaabeel',
        9308 => 'Zaabeel 1',
        9310 => 'Zaabeel 2',
    ];
}

/**
 * @param string $query
 * @param array $params
 * @param int[] $districts
 * @return void
 */
function appendDistrictFilter(&$query, &$params, array $districts) {
    if (empty($districts)) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($districts), '?'));
    $query .= " AND EXISTS (
        SELECT 1 FROM unit_districts ud
        WHERE ud.unit_id = u.bitrix_id AND ud.district_id IN ($placeholders)
    )";
    $params = array_merge($params, $districts);
}

