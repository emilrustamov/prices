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
			$stmt = $pdo->query("SELECT id, name FROM districts ORDER BY name");
			echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
			break;

		case 'units':
			$years = parseCsvInts($_GET['years'] ?? null);
			if (empty($years)) {
				throw new InvalidArgumentException('Параметр years обязателен');
			}
			$months = parseCsvInts($_GET['months'] ?? null);
			$districts = parseCsvInts($_GET['districts'] ?? null);

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

			appendDistrictFilter($query, $params, $districts);

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
			$years = parseCsvInts($_GET['years'] ?? null);
			if (empty($years)) {
				throw new InvalidArgumentException('Параметр years обязателен');
			}
			$months = parseCsvInts($_GET['months'] ?? null);
			$districts = parseCsvInts($_GET['districts'] ?? null);
			$contractType = !empty($_GET['contract_type']) ? $_GET['contract_type'] : null;
			$contractTypeIds = parseCsvInts($_GET['contract_type_ids'] ?? null);
			$units = array_values(array_filter(explode(',', $_GET['units'] ?? '')));

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

			appendDistrictFilter($unitsQuery, $unitsParams, $districts);

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

			$monthDataQuery = "
				SELECT contract_type, month_key, month_num, year, occupied_days, total_revenue, avg_price_per_day
				FROM monthly_reports
				WHERE unit_id = ? AND year IN ($yearPlaceholders)
			";

			if (!empty($months)) {
				$monthPlaceholders = implode(',', array_fill(0, count($months), '?'));
				$monthDataQuery .= " AND month_num IN ($monthPlaceholders)";
			}

			if ($contractType !== null) {
				$monthDataQuery .= " AND contract_type = ?";
			}

			$monthDataQuery .= " ORDER BY year, month_num, contract_type";
			$monthStmt = $pdo->prepare($monthDataQuery);

			$contractsQuery = "
				SELECT bitrix_id, title, start_date, end_date, contract_type_id
				FROM contracts
				WHERE unit_id = ?
				AND is_valid = 1
				AND start_date IS NOT NULL
				AND end_date IS NOT NULL
				AND opportunity > 0
				AND (start_date <= ? AND end_date >= ?)
			";
			$contractsStmt = $pdo->prepare($contractsQuery);

			$reportData = [];
			foreach ($unitsList as $unit) {
				$params = array_merge([$unit['bitrix_id']], $years);
				if (!empty($months)) {
					$params = array_merge($params, $months);
				}
				if ($contractType !== null) {
					$params[] = $contractType;
				}
				$monthStmt->execute($params);
				$monthRows = $monthStmt->fetchAll(PDO::FETCH_ASSOC);

				foreach ($monthRows as $m) {
					$mContractType = $m['contract_type'];
					$monthStart = new DateTime($m['month_key'] . '-01');
					$monthEnd = clone $monthStart;
					$monthEnd->modify('last day of this month');

					$contractsStmt->execute([
						$unit['bitrix_id'],
						$monthEnd->format('Y-m-d H:i:s'),
						$monthStart->format('Y-m-d H:i:s')
					]);
					$allContracts = $contractsStmt->fetchAll();

					$relevantContracts = [];
					$earliestStart = null;
					$latestEnd = null;

					foreach ($allContracts as $contract) {
						$contractTypeName = getContractTypeName($contract['contract_type_id']);
						if ($contractTypeName !== $mContractType) {
							continue;
						}
						if (!empty($contractTypeIds) && !in_array((int)$contract['contract_type_id'], $contractTypeIds, true)) {
							continue;
						}

						$contractStart = new DateTime($contract['start_date']);
						$contractEnd = new DateTime($contract['end_date']);
						$contractTotalDays = $contractStart->diff($contractEnd)->days + 1;

						if ($mContractType === 'долгосрок' && $contractTotalDays >= 30 && $contractTotalDays < 60) {
							if ($contractStart->format('Y-m') !== $m['month_key']) {
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
							'title' => $contract['title'],
							'total_days' => $contractTotalDays,
							'contract_type_id' => $contract['contract_type_id']
						];
					}

					if (!empty($contractTypeIds) && empty($relevantContracts)) {
						continue;
					}

					$m['contracts'] = $relevantContracts;
					$m['contracts_count'] = count($relevantContracts);

					if ($mContractType === 'долгосрок' && $earliestStart !== null && $latestEnd !== null) {
						$m['total_contract_days'] = $earliestStart->diff($latestEnd)->days + 1;
						$m['contract_start_date'] = $earliestStart->format('Y-m-d');
						$m['contract_end_date'] = $latestEnd->format('Y-m-d');
					}

					$reportData[$unit['bitrix_id']][$mContractType][$m['month_key']] = $m;
				}
			}

			echo json_encode([
				'success' => true,
				'data' => [
					'units' => $unitsList,
					'reports' => $reportData
				]
			]);
			break;

		default:
			echo json_encode(['success' => false, 'error' => 'Unknown action']);
	}
} catch (Exception $e) {
	echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
