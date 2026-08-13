<?php
require_once(__DIR__ . '/config.php');

header('Content-Type: application/json');

$pdo = getDbConnection();
$action = $_GET['action'] ?? '';

try {
	switch ($action) {
		case 'years':
			$stmt = $pdo->query("SELECT DISTINCT year FROM monthly_reports WHERE year >= 2023 AND year <= 2026 ORDER BY year DESC");
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
				SELECT DISTINCT u.bitrix_id, u.name, COUNT(mr.id) as reports_count
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

			$query .= " GROUP BY u.bitrix_id, u.name HAVING reports_count > 0 ORDER BY u.bitrix_id";

			$stmt = $pdo->prepare($query);
			$stmt->execute($params);
			echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
			break;

		case 'stats':
			$totalContracts = $pdo->query("SELECT COUNT(*) as total FROM contracts")->fetch()['total'];
			$validContracts = $pdo->query("SELECT COUNT(*) as total FROM contracts WHERE is_valid = 1")->fetch()['total'];
			$lastSync = $pdo->query("SELECT MAX(synced_at) as last_sync FROM contracts")->fetch()['last_sync'];

			echo json_encode([
				'success' => true,
				'data' => [
					'total' => $totalContracts,
					'valid' => $validContracts,
					'last_sync' => $lastSync ? date('d.m.Y H:i:s', strtotime($lastSync)) : null
				]
			]);
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
			if (isset($input['units']) && is_array($input['units'])) {
				$units = array_values(array_filter(array_map('strval', $input['units'])));
			} else {
				$units = array_values(array_filter(explode(',', (string)($input['units'] ?? ''))));
			}

			$yearPlaceholders = implode(',', array_fill(0, count($years), '?'));
			$unitsQuery = "
				SELECT DISTINCT u.bitrix_id, u.name
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

			if (!empty($units)) {
				$placeholders = implode(',', array_fill(0, count($units), '?'));
				$unitsQuery .= " AND u.bitrix_id IN ($placeholders)";
				$unitsParams = array_merge($unitsParams, $units);
			}

			$unitsQuery .= " ORDER BY u.bitrix_id";

			$stmt = $pdo->prepare($unitsQuery);
			$stmt->execute($unitsParams);
			$unitsList = $stmt->fetchAll();

			if (empty($unitsList)) {
				echo json_encode([
					'success' => true,
					'data' => [
						'units' => [],
						'reports' => new stdClass()
					]
				]);
				break;
			}

			$unitIds = array_column($unitsList, 'bitrix_id');
			$unitPlaceholders = implode(',', array_fill(0, count($unitIds), '?'));

			$monthDataQuery = "
				SELECT unit_id, contract_type, month_key, month_num, year, occupied_days, total_revenue, avg_price_per_day
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

			$monthDataQuery .= " ORDER BY unit_id, year, month_num, contract_type";
			$monthStmt = $pdo->prepare($monthDataQuery);
			$monthStmt->execute($monthParams);
			$monthRows = $monthStmt->fetchAll(PDO::FETCH_ASSOC);

			[$rangeStart, $rangeEnd] = getReportDateRange($years, $months);
			$contractsQuery = "
				SELECT unit_id, bitrix_id, title, start_date, end_date, contract_type_id
				FROM contracts
				WHERE unit_id IN ($unitPlaceholders)
				AND is_valid = 1
				AND start_date IS NOT NULL
				AND end_date IS NOT NULL
				AND opportunity > 0
				AND start_date <= ?
				AND end_date >= ?
			";
			$contractsStmt = $pdo->prepare($contractsQuery);
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

				$relevantContracts = [];
				$earliestStart = null;
				$latestEnd = null;

				foreach ($contractsByUnit[$unitId] ?? [] as $contract) {
					$contractTypeName = getContractTypeName($contract['contract_type_id']);
					if ($contractTypeName !== $mContractType) {
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

					if ($mContractType === 'долгосрок' && $contractTotalDays >= 30 && $contractTotalDays < 60) {
						if ($contractStart->format('Y-m') !== $monthKey) {
							continue;
						}
					}

					$periodStart = $contractStart > $monthStart ? $contractStart : $monthStart;
					$periodEnd = $contractEnd < $monthEnd ? $contractEnd : $monthEnd;
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

				if (!empty($contractTypeIds) && empty($relevantContracts)) {
					continue;
				}

				unset($m['unit_id']);
				$m['contracts'] = $relevantContracts;
				$m['contracts_count'] = count($relevantContracts);

				if ($mContractType === 'долгосрок' && $earliestStart !== null && $latestEnd !== null) {
					$m['total_contract_days'] = $earliestStart->diff($latestEnd)->days + 1;
					$m['contract_start_date'] = $earliestStart->format('Y-m-d');
					$m['contract_end_date'] = $latestEnd->format('Y-m-d');
				}

				$reportData[$unitId][$mContractType][$monthKey] = $m;
			}

			if (!empty($contractTypeIds)) {
				$unitsList = array_values(array_filter($unitsList, static function ($unit) use ($reportData) {
					return isset($reportData[$unit['bitrix_id']]);
				}));
			}

			echo json_encode([
				'success' => true,
				'data' => [
					'units' => $unitsList,
					'reports' => empty($reportData) ? new stdClass() : $reportData
				]
			], JSON_UNESCAPED_UNICODE);
			break;

		default:
			echo json_encode(['success' => false, 'error' => 'Unknown action']);
	}
} catch (Exception $e) {
	echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
