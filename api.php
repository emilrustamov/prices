<?php
require_once(__DIR__ . '/config.php');

$pdo = getDbConnection();
$action = $_GET['action'] ?? '';

if ($action !== 'export') {
	header('Content-Type: application/json');
}

try {
	switch ($action) {
		case 'years':
			$stmt = $pdo->query("SELECT DISTINCT year FROM monthly_reports WHERE year >= 2023 ORDER BY year DESC");
			echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_COLUMN)]);
			break;

		case 'districts':
			$stmt = $pdo->query("
				SELECT DISTINCT district AS name
				FROM units
				WHERE district IS NOT NULL AND district != ''
				ORDER BY district
			");
			echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_COLUMN)]);
			break;

		case 'buildings':
			$stmt = $pdo->query("
				SELECT DISTINCT building AS name
				FROM units
				WHERE building IS NOT NULL AND building != ''
				ORDER BY building
			");
			echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_COLUMN)]);
			break;

		case 'units':
			$years = parseCsvInts($_GET['years'] ?? null);
			if (empty($years)) {
				throw new InvalidArgumentException('Параметр years обязателен');
			}
			$months = parseCsvInts($_GET['months'] ?? null);
			$districts = parseCsvStrings($_GET['districts'] ?? null);
			$buildings = parseCsvStrings($_GET['buildings'] ?? null);

			$yearPlaceholders = implode(',', array_fill(0, count($years), '?'));
			$query = "
				SELECT u.bitrix_id, u.name, u.district, u.building, COUNT(mr.id) as reports_count
				FROM units u
				INNER JOIN monthly_reports mr ON u.bitrix_id = mr.unit_id AND mr.year IN ($yearPlaceholders)
			";
			$params = $years;

			if (!empty($months)) {
				$monthPlaceholders = implode(',', array_fill(0, count($months), '?'));
				$query .= " AND mr.month_num IN ($monthPlaceholders)";
				$params = array_merge($params, $months);
			}

			appendUnitStringFilter($query, $params, 'district', $districts);
			appendUnitStringFilter($query, $params, 'building', $buildings);
			$query .= " GROUP BY u.bitrix_id, u.name, u.district, u.building HAVING reports_count > 0 ORDER BY u.bitrix_id";

			$stmt = $pdo->prepare($query);
			$stmt->execute($params);
			echo json_encode(['success' => true, 'data' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
			break;

		case 'stats':
			$lastSync = $pdo->query("SELECT MAX(synced_at) as last_sync FROM contracts")->fetch()['last_sync'];
			$lockFile = __DIR__ . '/cache/sync.lock';
			echo json_encode([
				'success' => true,
				'data' => [
					'total' => (int)$pdo->query("SELECT COUNT(*) FROM contracts")->fetchColumn(),
					'valid' => (int)$pdo->query("SELECT COUNT(*) FROM contracts WHERE is_valid = 1")->fetchColumn(),
					'last_sync' => $lastSync ? date('d.m.Y H:i:s', strtotime($lastSync)) : null,
					'sync_running' => is_file($lockFile) && filemtime($lockFile) > time() - 7200
				]
			]);
			break;

		case 'sync_start':
			$dir = __DIR__ . '/cache';
			if (!is_dir($dir)) {
				mkdir($dir, 0775, true);
			}
			$lockFile = $dir . '/sync.lock';
			if (is_file($lockFile) && filemtime($lockFile) > time() - 7200) {
				echo json_encode(['success' => true, 'data' => ['started' => false, 'message' => 'Синхронизация уже выполняется']]);
				break;
			}
			file_put_contents($lockFile, (string)getmypid());
			$cmd = sprintf(
				'nohup php %s >> %s 2>&1 &',
				escapeshellarg(__DIR__ . '/run_sync_background.php'),
				escapeshellarg($dir . '/sync.log')
			);
			exec($cmd);
			echo json_encode(['success' => true, 'data' => ['started' => true, 'message' => 'Синхронизация запущена']]);
			break;

		case 'month_contracts':
			$unitId = (string)($_GET['unit_id'] ?? '');
			$monthKey = (string)($_GET['month_key'] ?? '');
			$contractType = (string)($_GET['contract_type'] ?? '');
			$contractTypeIds = parseCsvInts($_GET['contract_type_ids'] ?? null);

			if ($unitId === '' || $monthKey === '' || ($contractType !== 'краткосрок' && $contractType !== 'долгосрок')) {
				throw new InvalidArgumentException('unit_id, month_key и contract_type обязательны');
			}

			$monthStart = new DateTime($monthKey . '-01');
			$monthEnd = (clone $monthStart)->modify('last day of this month')->setTime(23, 59, 59);
			$contractsStmt = $pdo->prepare("
				SELECT bitrix_id, title, start_date, end_date, contract_type_id
				FROM contracts
				WHERE unit_id = ?
				AND is_valid = 1
				AND start_date IS NOT NULL
				AND end_date IS NOT NULL
				AND opportunity > 0
				AND start_date <= ?
				AND end_date >= ?
			");
			$contractsStmt->execute([
				$unitId,
				$monthEnd->format('Y-m-d H:i:s'),
				$monthStart->format('Y-m-d H:i:s')
			]);
			$matched = matchContractsForMonth(
				$contractsStmt->fetchAll(PDO::FETCH_ASSOC),
				$contractType,
				$monthKey,
				$monthStart,
				$monthEnd,
				$contractTypeIds
			);
			echo json_encode(['success' => true, 'data' => $matched['items']], JSON_UNESCAPED_UNICODE);
			break;

		case 'report':
			$input = getRequestInput();
			$years = parseCsvInts($input['years'] ?? null);
			if (empty($years)) {
				throw new InvalidArgumentException('Параметр years обязателен');
			}
			$months = parseCsvInts($input['months'] ?? null);
			$districts = parseCsvStrings($input['districts'] ?? null);
			$buildings = parseCsvStrings($input['buildings'] ?? null);
			$contractType = !empty($input['contract_type']) ? $input['contract_type'] : null;
			$contractTypeIds = parseCsvInts($input['contract_type_ids'] ?? null);
			$units = parseCsvStrings($input['units'] ?? null);
			$offset = max(0, (int)($input['offset'] ?? 0));
			$limit = max(1, min(50, (int)($input['limit'] ?? 20)));

			$cacheKey = md5(json_encode([
				$years, $months, $districts, $buildings, $contractType, $contractTypeIds, $units, $offset, $limit
			], JSON_UNESCAPED_UNICODE));
			$cached = readReportCache($cacheKey);
			if ($cached !== null) {
				$cached['cached'] = true;
				echo json_encode(['success' => true, 'data' => $cached], JSON_UNESCAPED_UNICODE);
				break;
			}

			$yearPlaceholders = implode(',', array_fill(0, count($years), '?'));
			$unitsQuery = "
				SELECT DISTINCT u.bitrix_id, u.name, u.district, u.building
				FROM units u
				INNER JOIN monthly_reports mr ON u.bitrix_id = mr.unit_id
				WHERE mr.year IN ($yearPlaceholders)
			";
			$unitsParams = $years;

			if (!empty($months)) {
				$monthPlaceholders = implode(',', array_fill(0, count($months), '?'));
				$unitsQuery .= " AND mr.month_num IN ($monthPlaceholders)";
				$unitsParams = array_merge($unitsParams, $months);
			}

			appendUnitStringFilter($unitsQuery, $unitsParams, 'district', $districts);
			appendUnitStringFilter($unitsQuery, $unitsParams, 'building', $buildings);

			if ($contractType !== null) {
				$unitsQuery .= " AND mr.contract_type = ?";
				$unitsParams[] = $contractType;
			}

			if (!empty($contractTypeIds)) {
				[$rangeStartForUnits, $rangeEndForUnits] = getReportDateRange($years, $months);
				$typePlaceholders = implode(',', array_fill(0, count($contractTypeIds), '?'));
				$unitsQuery .= "
					AND EXISTS (
						SELECT 1
						FROM contracts c
						WHERE c.unit_id = u.bitrix_id
						AND c.is_valid = 1
						AND c.contract_type_id IN ($typePlaceholders)
						AND c.start_date IS NOT NULL
						AND c.end_date IS NOT NULL
						AND c.opportunity > 0
						AND c.start_date <= ?
						AND c.end_date >= ?
					)
				";
				$unitsParams = array_merge(
					$unitsParams,
					$contractTypeIds,
					[$rangeEndForUnits->format('Y-m-d H:i:s'), $rangeStartForUnits->format('Y-m-d H:i:s')]
				);
			}

			if (!empty($units)) {
				$placeholders = implode(',', array_fill(0, count($units), '?'));
				$unitsQuery .= " AND u.bitrix_id IN ($placeholders)";
				$unitsParams = array_merge($unitsParams, $units);
			}

			$unitsQuery .= " ORDER BY u.bitrix_id";
			$stmt = $pdo->prepare($unitsQuery);
			$stmt->execute($unitsParams);
			$allUnits = $stmt->fetchAll();
			$total = count($allUnits);
			$unitsList = array_slice($allUnits, $offset, $limit);

			if (empty($unitsList)) {
				$payload = [
					'units' => [],
					'reports' => new stdClass(),
					'total' => $total,
					'offset' => $offset,
					'limit' => $limit,
					'cached' => false
				];
				writeReportCache($cacheKey, $payload);
				echo json_encode(['success' => true, 'data' => $payload]);
				break;
			}

			$unitIds = array_column($unitsList, 'bitrix_id');
			$unitPlaceholders = implode(',', array_fill(0, count($unitIds), '?'));

			$monthDataQuery = "
				SELECT unit_id, contract_type, month_key, year, occupied_days, total_revenue, avg_price_per_day
				FROM monthly_reports
				WHERE unit_id IN ($unitPlaceholders) AND year IN ($yearPlaceholders)
			";
			$monthParams = array_merge($unitIds, $years);

			if (!empty($months)) {
				$monthPlaceholders = implode(',', array_fill(0, count($months), '?'));
				$monthDataQuery .= " AND month_num IN ($monthPlaceholders)";
				$monthParams = array_merge($monthParams, $months);
			}
			if ($contractType !== null) {
				$monthDataQuery .= " AND contract_type = ?";
				$monthParams[] = $contractType;
			}
			$monthDataQuery .= " ORDER BY unit_id, year, month_key, contract_type";

			$monthStmt = $pdo->prepare($monthDataQuery);
			$monthStmt->execute($monthParams);
			$monthRows = $monthStmt->fetchAll(PDO::FETCH_ASSOC);

			[$rangeStart, $rangeEnd] = getReportDateRange($years, $months);
			$contractsStmt = $pdo->prepare("
				SELECT unit_id, bitrix_id, title, start_date, end_date, contract_type_id
				FROM contracts
				WHERE unit_id IN ($unitPlaceholders)
				AND is_valid = 1
				AND start_date IS NOT NULL
				AND end_date IS NOT NULL
				AND opportunity > 0
				AND start_date <= ?
				AND end_date >= ?
			");
			$contractsStmt->execute(array_merge(
				$unitIds,
				[$rangeEnd->format('Y-m-d H:i:s'), $rangeStart->format('Y-m-d H:i:s')]
			));

			$contractsByUnit = [];
			foreach ($contractsStmt->fetchAll(PDO::FETCH_ASSOC) as $contract) {
				$contractsByUnit[$contract['unit_id']][] = $contract;
			}

			$monthBounds = [];
			$reportData = [];
			foreach ($monthRows as $m) {
				$unitId = $m['unit_id'];
				$mContractType = $m['contract_type'];
				$monthKey = $m['month_key'];

				if (!isset($monthBounds[$monthKey])) {
					$monthStart = new DateTime($monthKey . '-01');
					$monthEnd = (clone $monthStart)->modify('last day of this month')->setTime(23, 59, 59);
					$monthBounds[$monthKey] = [$monthStart, $monthEnd];
				}
				[$monthStart, $monthEnd] = $monthBounds[$monthKey];

				$matched = matchContractsForMonth(
					$contractsByUnit[$unitId] ?? [],
					$mContractType,
					$monthKey,
					$monthStart,
					$monthEnd,
					$contractTypeIds
				);

				if (!empty($contractTypeIds) && empty($matched['items'])) {
					continue;
				}

				unset($m['unit_id']);
				$m['contracts_count'] = count($matched['items']);
				if (count($matched['items']) === 1) {
					$m['contract_id'] = $matched['items'][0]['id'];
				}
				if ($mContractType === 'долгосрок' && $matched['earliestStart'] !== null && $matched['latestEnd'] !== null) {
					$m['contract_start_date'] = $matched['earliestStart']->format('Y-m-d');
					$m['contract_end_date'] = $matched['latestEnd']->format('Y-m-d');
				}

				$reportData[$unitId][$mContractType][$monthKey] = $m;
			}

			$reportsPayload = empty($reportData) ? new stdClass() : $reportData;
			$payload = [
				'units' => $unitsList,
				'reports' => $reportsPayload,
				'total' => $total,
				'offset' => $offset,
				'limit' => $limit,
				'cached' => false
			];
			writeReportCache($cacheKey, $payload);

			echo json_encode(['success' => true, 'data' => $payload], JSON_UNESCAPED_UNICODE);
			break;

		case 'export':
			$input = getRequestInput();
			$years = parseCsvInts($input['years'] ?? null);
			if (empty($years)) {
				throw new InvalidArgumentException('Параметр years обязателен');
			}
			$months = parseCsvInts($input['months'] ?? null);
			$districts = parseCsvStrings($input['districts'] ?? null);
			$buildings = parseCsvStrings($input['buildings'] ?? null);
			$contractType = !empty($input['contract_type']) ? $input['contract_type'] : null;
			$contractTypeIds = parseCsvInts($input['contract_type_ids'] ?? null);
			$units = parseCsvStrings($input['units'] ?? null);

			$yearPlaceholders = implode(',', array_fill(0, count($years), '?'));
			$unitsQuery = "
				SELECT DISTINCT u.bitrix_id
				FROM units u
				INNER JOIN monthly_reports mr ON u.bitrix_id = mr.unit_id
				WHERE mr.year IN ($yearPlaceholders)
			";
			$unitsParams = $years;

			if (!empty($months)) {
				$monthPlaceholders = implode(',', array_fill(0, count($months), '?'));
				$unitsQuery .= " AND mr.month_num IN ($monthPlaceholders)";
				$unitsParams = array_merge($unitsParams, $months);
			}

			appendUnitStringFilter($unitsQuery, $unitsParams, 'district', $districts);
			appendUnitStringFilter($unitsQuery, $unitsParams, 'building', $buildings);

			if ($contractType !== null) {
				$unitsQuery .= " AND mr.contract_type = ?";
				$unitsParams[] = $contractType;
			}

			if (!empty($contractTypeIds)) {
				[$rangeStartForUnits, $rangeEndForUnits] = getReportDateRange($years, $months);
				$typePlaceholders = implode(',', array_fill(0, count($contractTypeIds), '?'));
				$unitsQuery .= "
					AND EXISTS (
						SELECT 1
						FROM contracts c
						WHERE c.unit_id = u.bitrix_id
						AND c.is_valid = 1
						AND c.contract_type_id IN ($typePlaceholders)
						AND c.start_date IS NOT NULL
						AND c.end_date IS NOT NULL
						AND c.opportunity > 0
						AND c.start_date <= ?
						AND c.end_date >= ?
					)
				";
				$unitsParams = array_merge(
					$unitsParams,
					$contractTypeIds,
					[$rangeEndForUnits->format('Y-m-d H:i:s'), $rangeStartForUnits->format('Y-m-d H:i:s')]
				);
			}

			if (!empty($units)) {
				$placeholders = implode(',', array_fill(0, count($units), '?'));
				$unitsQuery .= " AND u.bitrix_id IN ($placeholders)";
				$unitsParams = array_merge($unitsParams, $units);
			}

			$unitsQuery .= " ORDER BY u.bitrix_id";
			$stmt = $pdo->prepare($unitsQuery);
			$stmt->execute($unitsParams);
			$unitIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

			$monthKeys = buildReportMonthKeys($years, $months);
			$monthLabels = [
				1 => 'Янв', 2 => 'Фев', 3 => 'Мар', 4 => 'Апр',
				5 => 'Май', 6 => 'Июн', 7 => 'Июл', 8 => 'Авг',
				9 => 'Сен', 10 => 'Окт', 11 => 'Ноя', 12 => 'Дек'
			];
			$showYear = count($years) > 1;

			$header = ['Unit ID', 'Contract ID', 'Тип', 'Подтип'];
			foreach ($monthKeys as $monthKey) {
				[$y, $m] = array_map('intval', explode('-', $monthKey));
				$label = $showYear
					? ($monthLabels[$m] . ' ' . $y)
					: ($m . ' - ' . $monthLabels[$m]);
				$header[] = $label . ' дни';
				$header[] = $label . ' выручка';
			}

			$filename = 'report_' . date('Y-m-d_H-i-s') . '.csv';
			header('Content-Type: text/csv; charset=utf-8');
			header('Content-Disposition: attachment; filename="' . $filename . '"');
			header('Cache-Control: no-store');

			$out = fopen('php://output', 'w');
			fwrite($out, "\xEF\xBB\xBF");
			fwrite($out, implode(';', array_map('csvExportCell', $header)) . "\r\n");

			if (!empty($unitIds)) {
				$unitPlaceholders = implode(',', array_fill(0, count($unitIds), '?'));
				[$rangeStart, $rangeEnd] = getReportDateRange($years, $months);
				$contractsStmt = $pdo->prepare("
					SELECT bitrix_id, title, unit_id, start_date, end_date, opportunity, contract_type_id
					FROM contracts
					WHERE unit_id IN ($unitPlaceholders)
					AND is_valid = 1
					AND start_date IS NOT NULL
					AND end_date IS NOT NULL
					AND opportunity > 0
					AND start_date <= ?
					AND end_date >= ?
					ORDER BY unit_id, start_date, end_date, bitrix_id
				");
				$contractsStmt->execute(array_merge(
					$unitIds,
					[$rangeEnd->format('Y-m-d H:i:s'), $rangeStart->format('Y-m-d H:i:s')]
				));

				$processedContracts = [];

				while ($contract = $contractsStmt->fetch(PDO::FETCH_ASSOC)) {
					$typeName = getContractTypeName($contract['contract_type_id']);
					if ($typeName === null) {
						continue;
					}
					if ($contractType !== null && $typeName !== $contractType) {
						continue;
					}
					if (!empty($contractTypeIds) && !in_array((int)$contract['contract_type_id'], $contractTypeIds, true)) {
						continue;
					}

					$contractKey = $contract['unit_id'] . '|' . $contract['start_date'] . '|' . $contract['end_date'];
					if (isset($processedContracts[$contractKey])) {
						continue;
					}
					$processedContracts[$contractKey] = true;

					$breakdown = buildContractMonthlyBreakdown($contract, $typeName);
					$hasData = false;
					foreach ($monthKeys as $monthKey) {
						if (isset($breakdown[$monthKey])) {
							$hasData = true;
							break;
						}
					}
					if (!$hasData) {
						continue;
					}

					$row = [
						$contract['unit_id'],
						$contract['bitrix_id'],
						$typeName,
						getContractTypeIdLabel($contract['contract_type_id']),
					];
					foreach ($monthKeys as $monthKey) {
						if (isset($breakdown[$monthKey])) {
							$row[] = $breakdown[$monthKey]['days'];
							$row[] = round($breakdown[$monthKey]['revenue'], 2);
						} else {
							$row[] = '';
							$row[] = '';
						}
					}

					fwrite($out, implode(';', array_map('csvExportCell', $row)) . "\r\n");
				}
			}

			fclose($out);
			exit;

		default:
			echo json_encode(['success' => false, 'error' => 'Unknown action']);
	}
} catch (Exception $e) {
	if ($action === 'export' && !headers_sent()) {
		header('Content-Type: application/json');
	}
	echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
