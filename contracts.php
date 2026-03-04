<!DOCTYPE html>
<html lang="ru">
<head>
	<meta charset="UTF-8">
	<title>Контракты Bitrix24</title>
	<style>
		body { font-family: Arial, sans-serif; margin: 20px; }
		table { border-collapse: collapse; width: 100%; margin-top: 20px; }
		th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
		th { background-color: #f2f2f2; }
		.error { color: red; }
		.info { margin: 10px 0; }
		.contract-valid { background-color: #d4edda; }
		.contract-invalid { background-color: #f8d7da; }
	</style>
</head>
<body>
	<h1>Данные по контрактам</h1>
	<?php
	require_once(__DIR__ . '/crestcurrent.php');

	$entityTypeId = 183;
	$allContracts = [];
	$start = 0;
	$hasMore = true;

	while ($hasMore) {
		$result = CRestCurrent::call('crm.item.list', [
			'entityTypeId' => $entityTypeId,
			'select' => [
				'id',
				'title',
				'ufCrm20ContractEndDate',
				'ufCrm20ContractStartDate',
				'ufCrm20_1744800159',
				'ufCrm20_1744800193',
				'ufCrm20_1693919019',
				'stageId',
				'opportunity',
				'currencyId'
			],
			'start' => $start
		]);

		if (isset($result['error'])) {
			echo '<div class="error">Ошибка API: ' . htmlspecialchars($result['error_information'] ?? $result['error']) . '</div>';
			break;
		}

		if (isset($result['result']['items']) && !empty($result['result']['items'])) {
			$allContracts = array_merge($allContracts, $result['result']['items']);
			$start = $result['result']['next'] ?? 0;
			$hasMore = $start > 0;
		} else {
			$hasMore = false;
		}
	}

	echo '<div class="info">Всего контрактов получено: ' . count($allContracts) . '</div>';

	$validContracts = [];
	$invalidContracts = [];

	foreach ($allContracts as $contract) {
		$startDate = $contract['ufCrm20ContractStartDate'] ?? null;
		$endDate = $contract['ufCrm20ContractEndDate'] ?? null;

		$isValid = true;
		if ($startDate && $endDate) {
			$startDateTime = new DateTime($startDate);
			$endDateTime = new DateTime($endDate);
			if ($endDateTime <= $startDateTime) {
				$isValid = false;
			}
		}

		if ($isValid) {
			$validContracts[] = $contract;
		} else {
			$invalidContracts[] = $contract;
		}
	}

	echo '<div class="info">Валидных контрактов: ' . count($validContracts) . '</div>';
	echo '<div class="info">Некорректных контрактов (дата выселения <= дате заселения): ' . count($invalidContracts) . '</div>';

	if (!empty($allContracts)) {
		echo '<h2>Все контракты</h2>';
		echo '<table>';
		echo '<tr>';
		echo '<th>ID</th>';
		echo '<th>Название</th>';
		echo '<th>Факт. дата заселения</th>';
		echo '<th>Факт. дата выселения</th>';
		echo '<th>План. дата заселения</th>';
		echo '<th>План. дата выселения</th>';
		echo '<th>ID комнаты</th>';
		echo '<th>Стадия</th>';
		echo '<th>Сумма</th>';
		echo '<th>Валюта</th>';
		echo '<th>Статус</th>';
		echo '</tr>';

		foreach ($allContracts as $contract) {
			$startDate = $contract['ufCrm20ContractStartDate'] ?? null;
			$endDate = $contract['ufCrm20ContractEndDate'] ?? null;

			$isValid = true;
			if ($startDate && $endDate) {
				$startDateTime = new DateTime($startDate);
				$endDateTime = new DateTime($endDate);
				if ($endDateTime <= $startDateTime) {
					$isValid = false;
				}
			}

			$rowClass = $isValid ? 'contract-valid' : 'contract-invalid';
			$statusText = $isValid ? 'Валидный' : 'Некорректный';

			echo '<tr class="' . $rowClass . '">';
			echo '<td>' . htmlspecialchars($contract['id'] ?? '') . '</td>';
			echo '<td>' . htmlspecialchars($contract['title'] ?? '') . '</td>';
			echo '<td>' . htmlspecialchars($startDate ?? '-') . '</td>';
			echo '<td>' . htmlspecialchars($endDate ?? '-') . '</td>';
			echo '<td>' . htmlspecialchars($contract['ufCrm20_1744800159'] ?? '-') . '</td>';
			echo '<td>' . htmlspecialchars($contract['ufCrm20_1744800193'] ?? '-') . '</td>';
			echo '<td>' . htmlspecialchars($contract['ufCrm20_1693919019'] ?? '-') . '</td>';
			echo '<td>' . htmlspecialchars($contract['stageId'] ?? '-') . '</td>';
			echo '<td>' . htmlspecialchars($contract['opportunity'] ?? '0') . '</td>';
			echo '<td>' . htmlspecialchars($contract['currencyId'] ?? '') . '</td>';
			echo '<td>' . $statusText . '</td>';
			echo '</tr>';
		}

		echo '</table>';
	} else {
		echo '<div class="info">Контракты не найдены</div>';
	}
	?>
</body>
</html>

