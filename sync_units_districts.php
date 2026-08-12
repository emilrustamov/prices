<?php
require_once __DIR__ . '/crest.php';

$pdo = getDbConnection();

echo 'UNIT_ENTITY_TYPE_ID=' . UNIT_ENTITY_TYPE_ID . ' field=' . UNIT_DISTRICT_FIELD . PHP_EOL;
echo "Синхронизация районов и юнитов..." . PHP_EOL;

$districtInsertStmt = $pdo->prepare('
	INSERT INTO districts (id, name) VALUES (?, ?)
	ON DUPLICATE KEY UPDATE name = VALUES(name)
');
foreach (getDistrictCatalog() as $districtId => $districtName) {
	$districtInsertStmt->execute([$districtId, $districtName]);
}
echo 'districts catalog: ' . count(getDistrictCatalog()) . PHP_EOL;

$unitIds = $pdo->query('
	SELECT DISTINCT unit_id FROM contracts
	WHERE unit_id IS NOT NULL AND unit_id != ""
')->fetchAll(PDO::FETCH_COLUMN);
echo 'unit ids from contracts: ' . count($unitIds) . PHP_EOL;

$unitInsertStmt = $pdo->prepare('
	INSERT INTO units (bitrix_id, name, synced_at)
	VALUES (?, ?, CURRENT_TIMESTAMP)
	ON DUPLICATE KEY UPDATE name = VALUES(name), synced_at = CURRENT_TIMESTAMP
');
$unitDistrictDeleteStmt = $pdo->prepare('DELETE FROM unit_districts WHERE unit_id = ?');
$unitDistrictInsertStmt = $pdo->prepare('INSERT IGNORE INTO unit_districts (unit_id, district_id) VALUES (?, ?)');

$unitsSynced = 0;
$withDistrict = 0;
$districtLinks = 0;
$chunks = array_chunk($unitIds, 50);
$totalChunks = count($chunks);

foreach ($chunks as $chunkNum => $chunk) {
	$result = CRest::call('crm.item.list', [
		'entityTypeId' => UNIT_ENTITY_TYPE_ID,
		'filter' => [
			'@id' => array_map('intval', $chunk)
		],
		'select' => [
			'id',
			'title',
			UNIT_DISTRICT_FIELD
		],
		'start' => 0
	]);

	if (isset($result['error'])) {
		echo 'Ошибка API: ' . ($result['error_information'] ?? $result['error']) . PHP_EOL;
		exit(1);
	}

	$itemsById = [];
	foreach (($result['result']['items'] ?? []) as $item) {
		$itemsById[(string)$item['id']] = $item;
	}

	foreach ($chunk as $unitId) {
		$unitKey = (string)$unitId;
		$item = $itemsById[$unitKey] ?? null;
		$unitName = $item['title'] ?? $unitKey;
		$unitInsertStmt->execute([$unitKey, $unitName]);

		$unitDistrictDeleteStmt->execute([$unitKey]);
		$districtValues = $item[UNIT_DISTRICT_FIELD] ?? [];
		if (!is_array($districtValues)) {
			$districtValues = ($districtValues === null || $districtValues === '') ? [] : [$districtValues];
		}
		if (!empty($districtValues)) {
			$withDistrict++;
		}
		foreach ($districtValues as $districtId) {
			$districtId = (int)$districtId;
			if ($districtId > 0) {
				$unitDistrictInsertStmt->execute([$unitKey, $districtId]);
				$districtLinks++;
			}
		}
		$unitsSynced++;
	}

	echo 'chunk ' . ($chunkNum + 1) . '/' . $totalChunks . ' done' . PHP_EOL;
}

echo PHP_EOL . 'Готово' . PHP_EOL;
echo "units synced: $unitsSynced" . PHP_EOL;
echo "units with district: $withDistrict" . PHP_EOL;
echo "unit_district links: $districtLinks" . PHP_EOL;
echo 'unit_districts rows: ' . $pdo->query('SELECT COUNT(*) FROM unit_districts')->fetchColumn() . PHP_EOL;
