<?php
require_once(__DIR__ . '/config.php');

header('Content-Type: application/json');

$pdo = getDbConnection();

$action = $_GET['action'] ?? '';

try {
	switch ($action) {
		case 'years':
			$stmt = $pdo->query("SELECT DISTINCT year FROM monthly_reports WHERE year >= 2023 AND year <= 2026 ORDER BY year DESC");
			$yearsData = $stmt->fetchAll(PDO::FETCH_COLUMN);
			if (empty($yearsData)) {
				$yearsData = [date('Y')];
			}
			echo json_encode(['success' => true, 'data' => $yearsData]);
			break;
			
		case 'units':
			$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
			$month = isset($_GET['month']) && !empty($_GET['month']) ? intval($_GET['month']) : null;
			
			$query = "
				SELECT DISTINCT u.bitrix_id, u.name, COUNT(mr.id) as reports_count
				FROM units u
				INNER JOIN monthly_reports mr ON u.bitrix_id = mr.unit_id AND mr.year = ?
			";
			$params = [$year];
			
			if ($month !== null) {
				$query .= " AND mr.month_num = ?";
				$params[] = $month;
			}
			
			$query .= " GROUP BY u.bitrix_id, u.name HAVING reports_count > 0 ORDER BY u.bitrix_id";
			
			$stmt = $pdo->prepare($query);
			$stmt->execute($params);
			$units = $stmt->fetchAll();
			
			echo json_encode(['success' => true, 'data' => $units]);
			break;
			
		case 'stats':
			$stmt = $pdo->query("SELECT COUNT(*) as total FROM contracts");
			$totalContracts = $stmt->fetch()['total'];
			
			$stmt = $pdo->query("SELECT COUNT(*) as total FROM contracts WHERE is_valid = 1");
			$validContracts = $stmt->fetch()['total'];
			
			$stmt = $pdo->query("SELECT MAX(synced_at) as last_sync FROM contracts");
			$lastSync = $stmt->fetch()['last_sync'];
			
			echo json_encode([
				'success' => true,
				'data' => [
					'total' => $totalContracts,
					'valid' => $validContracts,
					'last_sync' => $lastSync ? date('d.m.Y H:i:s', strtotime($lastSync)) : 'Нет данных'
				]
			]);
			break;
			
		case 'report':
			$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
			$month = isset($_GET['month']) && !empty($_GET['month']) ? intval($_GET['month']) : null;
			$contractType = isset($_GET['contract_type']) && !empty($_GET['contract_type']) ? $_GET['contract_type'] : null;
			$contractTypeIdsParam = isset($_GET['contract_type_ids']) && !empty($_GET['contract_type_ids']) ? $_GET['contract_type_ids'] : null;
			$contractTypeIds = [];
			if ($contractTypeIdsParam !== null) {
				$contractTypeIds = array_filter(array_map('intval', explode(',', $contractTypeIdsParam)));
			}
			$unitsParam = isset($_GET['units']) ? $_GET['units'] : '';
			$units = !empty($unitsParam) ? explode(',', $unitsParam) : [];
			$units = array_filter($units);
			
			$unitsQuery = "
				SELECT DISTINCT u.bitrix_id, u.name
				FROM units u
				INNER JOIN monthly_reports mr ON u.bitrix_id = mr.unit_id
				WHERE mr.year = ?
			";
			$unitsParams = [$year];
			
			if ($month !== null) {
				$unitsQuery .= " AND mr.month_num = ?";
				$unitsParams[] = $month;
			}
			
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
				SELECT contract_type, month_key, month_num, occupied_days, total_revenue, avg_price_per_day
				FROM monthly_reports
				WHERE unit_id = ? AND year = ?
			";
			
			if ($month !== null && $month > 0) {
				$monthDataQuery .= " AND month_num = ?";
			}
			
			if ($contractType !== null) {
				$monthDataQuery .= " AND contract_type = ?";
			}
			
			$monthDataQuery .= " ORDER BY month_num, contract_type";
			
			$stmt = $pdo->prepare($monthDataQuery);
			
			function getContractTypeName($typeId) {
				$shortTermIds = [882, 1304, 6578, 1306];
				$longTermIds = [884, 886, 8672];
				if ($typeId === null) {
					return null;
				}
				$typeIdInt = (int)$typeId;
				if (in_array($typeIdInt, $shortTermIds)) {
					return 'краткосрок';
				} elseif (in_array($typeIdInt, $longTermIds)) {
					return 'долгосрок';
				}
				return null;
			}
			
			$reportData = [];
			foreach ($unitsList as $unit) {
				$params = [$unit['bitrix_id'], $year];
				if ($month !== null && $month > 0) {
					$params[] = $month;
				}
				if ($contractType !== null) {
					$params[] = $contractType;
				}
				$stmt->execute($params);
				$months = $stmt->fetchAll(PDO::FETCH_ASSOC);
				foreach ($months as $m) {
					if ($month === null || $month === '' || $month == 0 || $m['month_num'] == $month) {
						$mContractType = $m['contract_type'] ?? 'unknown';
						if ($contractType === null || $mContractType === $contractType) {
							$monthStart = new DateTime($m['month_key'] . '-01');
							$monthEnd = clone $monthStart;
							$monthEnd->modify('last day of this month');
							
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
							$contractsParams = [
								$unit['bitrix_id'],
								$monthEnd->format('Y-m-d H:i:s'),
								$monthStart->format('Y-m-d H:i:s')
							];
							
							$contractsStmt = $pdo->prepare($contractsQuery);
							$contractsStmt->execute($contractsParams);
							$allContracts = $contractsStmt->fetchAll();
							
							$relevantContracts = [];
							$totalContractDays = 0;
							$earliestStart = null;
							$latestEnd = null;
							
							foreach ($allContracts as $contract) {
								$contractTypeName = getContractTypeName($contract['contract_type_id']);
								if ($contractTypeName === $mContractType) {
									if (!empty($contractTypeIds) && !in_array((int)$contract['contract_type_id'], $contractTypeIds)) {
										continue;
									}
									
									$contractStart = new DateTime($contract['start_date']);
									$contractEnd = new DateTime($contract['end_date']);

									// Если долгосрок длится ровно 1 месяц (30-59 дней), показываем только в месяц начала
									if ($mContractType === 'долгосрок') {
										$contractTotalDays = $contractStart->diff($contractEnd)->days + 1;
										if ($contractTotalDays >= 30 && $contractTotalDays < 60) {
											if ($contractStart->format('Y-m') !== $m['month_key']) {
												continue;
											}
										}
									}
									
									$periodStart = $contractStart > $monthStart ? $contractStart : $monthStart;
									$periodEnd = $contractEnd < $monthEnd ? $contractEnd : $monthEnd;
									
									if ($periodStart <= $periodEnd) {
										$contractTotalDays = $contractStart->diff($contractEnd)->days + 1;
										
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
								}
							}
							
							if (empty($contractTypeIds) || !empty($relevantContracts)) {
								$m['contracts'] = $relevantContracts;
								$m['contracts_count'] = count($relevantContracts);
								
								if ($mContractType === 'долгосрок' && $earliestStart !== null && $latestEnd !== null) {
									$totalDays = $earliestStart->diff($latestEnd)->days + 1;
									$m['total_contract_days'] = $totalDays;
									$m['contract_start_date'] = $earliestStart->format('Y-m-d');
									$m['contract_end_date'] = $latestEnd->format('Y-m-d');
								}
								
								$reportData[$unit['bitrix_id']][$mContractType][$m['month_key']] = $m;
							}
						}
					}
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

