<!DOCTYPE html>
<html lang="ru">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Отчет по месяцам - Bitrix24</title>
	<script src="https://cdn.tailwindcss.com"></script>
	<script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
	<style>
		.table-container {
			max-height: 90vh;
			overflow-y: auto;
		}
	</style>
</head>
<body class="bg-gray-50 p-2" id="app" style="font-size: 11px; overflow-y: hidden;">
	<?php
	require_once(__DIR__ . '/config.php');
	$currentYear = (int)date('Y');
	?>

	<div id="vueApp">
		<div class="mb-6 flex gap-3 items-center">
			<button @click="showFiltersModal = true" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded shadow-lg flex items-center gap-2 text-xs">
				<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path>
				</svg>
				Фильтры
				<span v-if="activeFiltersCount > 0" class="bg-blue-800 text-white text-[10px] px-2 py-1 rounded-full">
					{{ activeFiltersCount }}
				</span>
			</button>
			<button @click="showAvgPriceColumn = !showAvgPriceColumn" class="bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded shadow-lg text-xs">
				{{ showAvgPriceColumn ? 'Скрыть' : 'Показать' }} среднюю стоимость
			</button>
			<div v-if="activeFiltersCount > 0" class="text-xs text-gray-600 flex items-center gap-2">
				<span @click="showStats" class="font-semibold cursor-pointer hover:text-blue-700 underline">Активные фильтры:</span>
				<span class="bg-blue-100 text-blue-800 px-2 py-1 rounded text-xs">Годы: {{ appliedYears.join(', ') }}</span>
				<span v-if="appliedMonths.length > 0" class="bg-blue-100 text-blue-800 px-2 py-1 rounded text-xs">Месяцы: {{ selectedMonthsLabels }}</span>
				<span v-if="appliedDistricts.length > 0" class="bg-blue-100 text-blue-800 px-2 py-1 rounded text-xs">District: {{ appliedDistricts.length }}</span>
				<span v-if="selectedContractType" class="bg-blue-100 text-blue-800 px-2 py-1 rounded text-xs">Тип: {{ selectedContractType === 'краткосрок' ? 'Краткосрок' : 'Долгосрок' }}</span>
				<span v-if="selectedContractTypeIds.length > 0" class="bg-blue-100 text-blue-800 px-2 py-1 rounded text-xs">ID типов: {{ selectedContractTypeIds.length }}</span>
				<span v-if="selectedUnits.length > 0" class="bg-blue-100 text-blue-800 px-2 py-1 rounded text-xs">Юнитов: {{ selectedUnits.length }}</span>
			</div>
		</div>

		<div v-if="showFiltersModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50" @click.self="showFiltersModal = false">
			<div class="relative top-4 mx-auto border w-[96%] md:w-[92%] lg:w-5/6 xl:w-4/5 max-w-6xl shadow-lg rounded-md bg-white h-[92vh] max-h-[92vh] flex flex-col">
				<div class="flex justify-between items-center p-4 border-b border-gray-200 flex-shrink-0">
					<h3 class="text-lg font-bold text-gray-900">Фильтры отчета</h3>
					<button @click="showFiltersModal = false" class="text-gray-400 hover:text-gray-600">
						<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
						</svg>
					</button>
				</div>

				<div class="overflow-y-auto flex-1 p-4 space-y-3">
					<div class="grid grid-cols-1 md:grid-cols-2 gap-3">
						<div class="relative">
							<label class="block text-xs font-medium text-gray-700 mb-1">Годы:</label>
							<button
								type="button"
								@click.stop="toggleFilterDropdown('years')"
								class="w-full border border-gray-300 rounded-md px-2 py-1 bg-white cursor-pointer hover:border-blue-400 flex items-center justify-between text-left"
							>
								<span class="text-xs text-gray-700 truncate">{{ yearsDropdownLabel }}</span>
								<svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
								</svg>
							</button>
							<div v-if="openFilterDropdown === 'years'" class="absolute z-20 w-full mt-1 bg-white border border-gray-300 rounded-md shadow-lg max-h-48 overflow-y-auto" @click.stop>
								<label
									v-for="year in years"
									:key="year"
									class="flex items-center px-2 py-1.5 hover:bg-blue-50 cursor-pointer border-b border-gray-100"
								>
									<input
										type="checkbox"
										:value="year"
										v-model="selectedYears"
										:disabled="selectedYears.length === 1 && Number(selectedYears[0]) === year"
										@change="onYearsChange"
										class="rounded border-gray-300 text-blue-600 mr-2"
									>
									<span class="text-xs text-gray-900">{{ year }}</span>
								</label>
							</div>
						</div>

						<div class="relative">
							<label class="block text-xs font-medium text-gray-700 mb-1">Месяцы (пусто = все):</label>
							<button
								type="button"
								@click.stop="toggleFilterDropdown('months')"
								class="w-full border border-gray-300 rounded-md px-2 py-1 bg-white cursor-pointer hover:border-blue-400 flex items-center justify-between text-left"
							>
								<span class="text-xs text-gray-700 truncate">{{ monthsDropdownLabel }}</span>
								<svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
								</svg>
							</button>
							<div v-if="openFilterDropdown === 'months'" class="absolute z-20 w-full mt-1 bg-white border border-gray-300 rounded-md shadow-lg max-h-48 overflow-y-auto" @click.stop>
								<label
									v-for="month in monthOptions"
									:key="month.num"
									class="flex items-center px-2 py-1.5 hover:bg-blue-50 cursor-pointer border-b border-gray-100"
								>
									<input
										type="checkbox"
										:value="month.num"
										v-model="selectedMonths"
										@change="loadUnits"
										class="rounded border-gray-300 text-blue-600 mr-2"
									>
									<span class="text-xs text-gray-900">{{ month.name }}</span>
								</label>
							</div>
						</div>
					</div>

					<div>
						<label class="block text-xs font-medium text-gray-700 mb-1">Тип контракта:</label>
						<select v-model="selectedContractType" @change="selectedContractTypeIds = []; openFilterDropdown = null" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50 px-2 py-1 text-xs">
							<option value="">Все типы</option>
							<option value="краткосрок">Краткосрок</option>
							<option value="долгосрок">Долгосрок</option>
						</select>

						<div v-if="selectedContractType" class="relative mt-2">
							<label class="block text-xs font-medium text-gray-700 mb-1">ID типов ({{ selectedContractType }}):</label>
							<button
								type="button"
								@click.stop="toggleFilterDropdown('contractTypeIds')"
								class="w-full border border-gray-300 rounded-md px-2 py-1 bg-white cursor-pointer hover:border-blue-400 flex items-center justify-between text-left"
							>
								<span class="text-xs text-gray-700 truncate">{{ contractTypeIdsDropdownLabel }}</span>
								<svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
								</svg>
							</button>
							<div v-if="openFilterDropdown === 'contractTypeIds'" class="absolute z-20 w-full mt-1 bg-white border border-gray-300 rounded-md shadow-lg max-h-48 overflow-y-auto" @click.stop>
								<label
									v-for="typeId in contractTypeIds[selectedContractType]"
									:key="typeId.id"
									class="flex items-center px-2 py-1.5 hover:bg-blue-50 cursor-pointer border-b border-gray-100"
								>
									<input
										type="checkbox"
										:value="typeId.id"
										v-model="selectedContractTypeIds"
										class="rounded border-gray-300 text-blue-600 mr-2"
									>
									<span class="text-xs text-gray-900">{{ typeId.name }} (ID: {{ typeId.id }})</span>
								</label>
							</div>
						</div>
					</div>

					<div class="mb-0">
						<label class="block text-xs font-medium text-gray-700 mb-1">District (пусто = все):</label>
						<button
							type="button"
							@click="openFilterDropdown = null; showDistrictsModal = true; districtsSearch = ''"
							class="w-full border border-gray-300 rounded-md px-2 py-1 bg-white cursor-pointer hover:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-500 flex items-center justify-between text-left"
						>
							<div class="flex-1">
								<span v-if="selectedDistricts.length === 0" class="text-gray-500 text-xs">Выберите районы...</span>
								<span v-else-if="selectedDistrictDetails.length === 1" class="text-gray-700 text-xs">{{ selectedDistrictDetails[0].name }}</span>
								<span v-else class="text-gray-700 text-xs">Выбрано: {{ selectedDistricts.length }}</span>
							</div>
							<svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
							</svg>
						</button>
					</div>

					<div class="mb-0">
						<label class="block text-xs font-medium text-gray-700 mb-1">Юниты:</label>
						<button
							type="button"
							@click="openFilterDropdown = null; showUnitsModal = true; unitsSearch = ''"
							class="w-full border border-gray-300 rounded-md px-2 py-1 bg-white cursor-pointer hover:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-500 flex items-center justify-between text-left"
						>
							<div class="flex-1">
								<span v-if="selectedUnitDetails.length === 0" class="text-gray-500 text-xs">Выберите юниты...</span>
								<span v-else-if="selectedUnitDetails.length === 1" class="text-gray-700 text-xs">{{ selectedUnitDetails[0].bitrix_id }} - {{ selectedUnitDetails[0].name }}</span>
								<span v-else-if="selectedUnitDetails.length === availableUnits.length" class="text-gray-700 text-xs">Все юниты ({{ selectedUnitDetails.length }})</span>
								<span v-else class="text-gray-700 text-xs">Выбрано: {{ selectedUnitDetails.length }} из {{ availableUnits.length }}</span>
							</div>
							<svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
							</svg>
						</button>
					</div>
				</div>

				<div class="border-t border-gray-200 p-4 bg-gray-50 flex gap-2 justify-end flex-shrink-0 rounded-b-md">
					<button @click="resetFilters" class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-1 px-4 rounded transition-colors text-xs">Сбросить все</button>
					<button @click="applyFilters" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-1 px-4 rounded transition-colors text-xs">Применить</button>
				</div>
			</div>
		</div>

		<div v-if="showDistrictsModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-[60]" @click.self="closeDistrictsModal">
			<div class="relative top-10 mx-auto border w-11/12 md:w-3/5 lg:w-1/2 shadow-lg rounded-md bg-white h-[80vh] max-h-[90vh] flex flex-col" @click.stop>
				<div class="flex justify-between items-center p-4 border-b border-gray-200 flex-shrink-0">
					<h3 class="text-lg font-bold text-gray-900">Выбор District</h3>
					<button @click="closeDistrictsModal" class="text-gray-400 hover:text-gray-600">
						<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
						</svg>
					</button>
				</div>

				<div class="p-4 border-b border-gray-200 flex-shrink-0">
					<input
						v-model="districtsSearch"
						placeholder="Поиск по районам..."
						class="w-full px-2 py-1 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-xs"
					>
					<div class="mt-2 flex items-center">
						<input type="checkbox" @change="toggleAllDistricts" :checked="allDistrictsSelected" class="rounded border-gray-300 text-blue-600" id="select-all-districts">
						<label for="select-all-districts" class="ml-2 text-xs font-medium text-gray-700 cursor-pointer">Выбрать все</label>
						<span class="ml-auto text-xs text-gray-500">Выбрано: {{ selectedDistricts.length }}</span>
					</div>
				</div>

				<div class="overflow-y-auto flex-1 min-h-0 p-2">
					<label
						v-for="district in filteredDistricts"
						:key="district.id"
						class="flex items-center px-2 py-2 hover:bg-blue-50 cursor-pointer border-b border-gray-100"
					>
						<input
							type="checkbox"
							:value="district.id"
							v-model="selectedDistricts"
							class="rounded border-gray-300 text-blue-600 mr-2"
						>
						<span class="text-xs font-medium text-gray-900">{{ district.name }}</span>
					</label>
					<div v-if="filteredDistricts.length === 0" class="px-2 py-4 text-xs text-gray-500 text-center">
						Районы не найдены
					</div>
				</div>

				<div class="border-t border-gray-200 p-4 bg-gray-50 flex gap-2 justify-end flex-shrink-0 rounded-b-md">
					<button @click="closeDistrictsModal" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-1 px-4 rounded transition-colors text-xs">Готово</button>
				</div>
			</div>
		</div>

		<div v-if="showUnitsModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-[60]" @click.self="showUnitsModal = false">
			<div class="relative top-10 mx-auto border w-11/12 md:w-3/5 lg:w-1/2 shadow-lg rounded-md bg-white h-[80vh] max-h-[90vh] flex flex-col" @click.stop>
				<div class="flex justify-between items-center p-4 border-b border-gray-200 flex-shrink-0">
					<h3 class="text-lg font-bold text-gray-900">Выбор юнитов</h3>
					<button @click="showUnitsModal = false" class="text-gray-400 hover:text-gray-600">
						<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
						</svg>
					</button>
				</div>

				<div class="p-4 border-b border-gray-200 flex-shrink-0">
					<input
						v-model="unitsSearch"
						placeholder="Поиск по юнитам..."
						class="w-full px-2 py-1 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-xs"
					>
					<div class="mt-2 flex items-center">
						<input type="checkbox" @change="toggleAllUnits" :checked="allUnitsSelected" class="rounded border-gray-300 text-blue-600" id="select-all-units">
						<label for="select-all-units" class="ml-2 text-xs font-medium text-gray-700 cursor-pointer">Выбрать все</label>
						<span class="ml-auto text-xs text-gray-500">Выбрано: {{ selectedUnits.length }}</span>
					</div>
				</div>

				<div class="overflow-y-auto flex-1 min-h-0 p-2">
					<label
						v-for="unit in filteredUnits"
						:key="unit.bitrix_id"
						class="flex items-center px-2 py-2 hover:bg-blue-50 cursor-pointer border-b border-gray-100"
					>
						<input
							type="checkbox"
							:value="unit.bitrix_id"
							v-model="selectedUnits"
							class="rounded border-gray-300 text-blue-600 mr-2"
						>
						<div class="flex-1">
							<div class="text-xs font-medium text-gray-900">{{ unit.bitrix_id }} - {{ unit.name }}</div>
							<div class="text-[10px] text-gray-500">Отчетов: {{ unit.reports_count }}</div>
						</div>
					</label>
					<div v-if="filteredUnits.length === 0" class="px-2 py-4 text-xs text-gray-500 text-center">
						Юниты не найдены
					</div>
				</div>

				<div class="border-t border-gray-200 p-4 bg-gray-50 flex gap-2 justify-end flex-shrink-0 rounded-b-md">
					<button @click="showUnitsModal = false" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-1 px-4 rounded transition-colors text-xs">Готово</button>
				</div>
			</div>
		</div>

		<div v-if="showContractsModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50" @click.self="showContractsModal = false">
			<div class="relative top-20 mx-auto p-4 border w-11/12 md:w-1/2 lg:w-1/3 shadow-lg rounded-md bg-white max-h-[90vh] overflow-y-auto">
				<div class="flex justify-between items-center mb-3">
					<h3 class="text-lg font-bold text-gray-900">Контракты</h3>
					<button @click="showContractsModal = false" class="text-gray-400 hover:text-gray-600">
						<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
						</svg>
					</button>
				</div>
				<div class="space-y-1">
					<a
						v-for="contract in contractsModalData"
						:key="contract.id"
						:href="contractUrl(contract.id)"
						target="_blank"
						class="block p-2 border border-gray-300 rounded hover:bg-blue-50 hover:border-blue-400 transition-colors"
					>
						<div class="font-semibold text-gray-900 text-xs">{{ contract.title || `Контракт #${contract.id}` }}</div>
						<div class="text-[10px] text-gray-500">ID: {{ contract.id }}</div>
					</a>
				</div>
			</div>
		</div>

		<div v-if="error" class="bg-red-100 border-l-4 border-red-500 text-red-700 px-3 py-2 mb-3 rounded text-xs">
			{{ error }}
		</div>

		<div v-if="!loading && !error && units.length > 0" class="bg-white rounded-lg shadow-lg mb-6">
			<div class="table-container overflow-x-auto">
				<table class="min-w-full bg-white border-collapse">
					<thead>
						<tr class="bg-gradient-to-r from-blue-600 to-blue-700 text-white">
							<th rowspan="2" class="sticky left-0 z-20 bg-blue-600 border border-blue-500 px-3 py-2 text-left font-bold text-[10px] uppercase tracking-wider shadow-lg">Юнит / Тип контракта</th>
							<th v-for="month in monthsData" :key="month.key" :colspan="showAvgPriceColumn ? 3 : 2" class="border border-blue-500 px-2 py-2 text-center font-bold text-[10px] uppercase tracking-wider bg-blue-600">
								{{ month.label }}
							</th>
						</tr>
						<tr class="bg-gradient-to-r from-blue-700 to-blue-800 text-white">
							<template v-for="month in monthsData" :key="month.key">
								<th class="border border-blue-600 px-1 py-1 text-center font-semibold text-[10px] bg-blue-700">Дни</th>
								<th v-if="showAvgPriceColumn" class="border border-blue-600 px-1 py-1 text-center font-semibold text-[10px] bg-blue-700">Ср. стоимость</th>
								<th class="border border-blue-600 px-1 py-1 text-center font-semibold text-[10px] bg-blue-700">Доход</th>
							</template>
						</tr>
					</thead>
					<tbody class="bg-white divide-y divide-gray-200">
						<template v-for="unit in units" :key="unit.bitrix_id">
							<tr class="bg-gradient-to-r from-indigo-50 to-blue-50 border-b-2 border-indigo-200">
								<td class="sticky left-0 z-10 bg-indigo-50 border-r-2 border-indigo-200 px-3 py-2 font-bold text-indigo-900 text-[10px] whitespace-nowrap">
									🏠 {{ getShortUnitName(unit.name) }}
								</td>
								<td :colspan="showAvgPriceColumn ? monthsData.length * 3 : monthsData.length * 2" class="px-3 py-2"></td>
							</tr>

							<template v-for="contractType in ['краткосрок', 'долгосрок']" :key="contractType">
								<tr v-if="!selectedContractType || selectedContractType === contractType" class="hover:bg-gray-50 transition-colors duration-150">
									<td class="sticky left-0 z-10 bg-white border-r-2 border-gray-300 px-3 py-2 text-[10px] font-semibold text-gray-800 whitespace-nowrap" :class="contractType === 'краткосрок' ? 'bg-green-50' : 'bg-blue-50'">
										{{ contractType === 'краткосрок' ? '📋 Краткосрок' : '📘 Долгосрок' }}
									</td>
									<template v-for="month in monthsData" :key="month.key">
										<td class="border border-gray-300 px-2 py-2 text-center text-[10px] whitespace-nowrap min-w-[60px]">
											<div class="font-semibold text-gray-700">{{ getDays(unit.bitrix_id, contractType, month.key) }}</div>
										</td>
										<td v-if="showAvgPriceColumn" class="border border-gray-300 px-2 py-2 text-center text-[10px] whitespace-nowrap min-w-[90px]">
											<div class="font-semibold text-blue-700">{{ formatMoney(getReport(unit.bitrix_id, contractType, month.key)?.avg_price_per_day) }}</div>
										</td>
										<td class="border border-gray-300 px-2 py-2 text-center text-[10px] whitespace-nowrap min-w-[100px]">
											<div class="flex items-center justify-center gap-1">
												<span @click="handleRevenueClick(unit.bitrix_id, contractType, month.key)" class="font-bold text-green-700 cursor-pointer hover:text-green-800 hover:underline">{{ formatMoney(getReport(unit.bitrix_id, contractType, month.key)?.total_revenue) }}</span>
												<span v-if="getContractsCount(unit.bitrix_id, contractType, month.key) > 1" @click.stop="handleRevenueClick(unit.bitrix_id, contractType, month.key)" class="bg-blue-600 text-white text-[8px] font-bold rounded-full w-4 h-4 flex items-center justify-center cursor-pointer hover:bg-blue-700">{{ getContractsCount(unit.bitrix_id, contractType, month.key) }}</span>
											</div>
										</td>
									</template>
								</tr>
							</template>
						</template>
					</tbody>
				</table>
			</div>
		</div>

		<div v-if="!loading && !error && units.length === 0" class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 px-3 py-2 rounded text-xs">
			Данные не найдены для выбранных фильтров. Проверьте фильтр или запустите расчет: <code class="bg-yellow-800 text-white px-1 py-0.5 rounded text-[10px]">php calculate_reports.php</code>
		</div>
	</div>

	<script>
		const { createApp } = Vue;
		const CURRENT_YEAR = <?php echo $currentYear; ?>;
		const CONTRACT_URL = 'https://colifeae.bitrix24.eu/page/register_of_documents/register_of_tenants_documents/type/183/details/';

		createApp({
			data() {
				return {
					loading: false,
					error: null,
					showFiltersModal: false,
					showUnitsModal: false,
					showDistrictsModal: false,
					showContractsModal: false,
					showAvgPriceColumn: false,
					openFilterDropdown: null,
					years: [],
					districts: [],
					selectedYears: [CURRENT_YEAR],
					selectedMonths: [],
					selectedDistricts: [],
					appliedYears: [CURRENT_YEAR],
					appliedMonths: [],
					appliedDistricts: [],
					selectedContractType: '',
					selectedContractTypeIds: [],
					selectedUnits: [],
					availableUnits: [],
					unitsSearch: '',
					districtsSearch: '',
					units: [],
					reports: {},
					contractsModalData: [],
					contractTypeIds: {
						'краткосрок': [
							{ id: 882, name: 'Airbnb' },
							{ id: 1304, name: 'Booking' },
							{ id: 6578, name: 'Less than a month' },
							{ id: 1306, name: 'Short contract up to 1 month' }
						],
						'долгосрок': [
							{ id: 884, name: 'Short term from 1 to 3 months' },
							{ id: 886, name: 'Long-term 3+ months' },
							{ id: 8672, name: 'Ejari' }
						]
					},
					monthNames: {
						1: 'Янв', 2: 'Фев', 3: 'Мар', 4: 'Апр',
						5: 'Май', 6: 'Июн', 7: 'Июл', 8: 'Авг',
						9: 'Сен', 10: 'Окт', 11: 'Ноя', 12: 'Дек'
					}
				}
			},
			computed: {
				monthOptions() {
					return Object.entries(this.monthNames).map(([num, name]) => ({
						num: Number(num),
						name
					}));
				},
				monthsData() {
					const years = [...this.appliedYears].sort((a, b) => a - b);
					const monthNums = this.appliedMonths.length > 0
						? [...this.appliedMonths].sort((a, b) => a - b)
						: this.monthOptions.map(m => m.num);
					const showYear = years.length > 1;
					const months = [];
					for (const year of years) {
						for (const num of monthNums) {
							months.push({
								key: `${year}-${String(num).padStart(2, '0')}`,
								label: showYear
									? `${this.monthNames[num]} ${year}`
									: `${num} - ${this.monthNames[num]}`
							});
						}
					}
					return months;
				},
				selectedMonthsLabels() {
					return [...this.appliedMonths]
						.sort((a, b) => a - b)
						.map(num => this.monthNames[num])
						.join(', ');
				},
				yearsDropdownLabel() {
					if (this.selectedYears.length === 0) {
						return 'Выберите годы...';
					}
					return [...this.selectedYears].sort((a, b) => a - b).join(', ');
				},
				monthsDropdownLabel() {
					if (this.selectedMonths.length === 0) {
						return 'Все месяцы';
					}
					return [...this.selectedMonths]
						.sort((a, b) => a - b)
						.map(num => this.monthNames[num])
						.join(', ');
				},
				contractTypeIdsDropdownLabel() {
					if (this.selectedContractTypeIds.length === 0) {
						return 'Все ID типов';
					}
					if (this.selectedContractTypeIds.length === 1) {
						return this.getContractTypeIdName(this.selectedContractTypeIds[0]);
					}
					return `Выбрано: ${this.selectedContractTypeIds.length}`;
				},
				filteredUnits() {
					const search = this.unitsSearch.trim().toLowerCase();
					if (!search) {
						return this.availableUnits;
					}
					return this.availableUnits.filter(unit =>
						String(unit.bitrix_id).toLowerCase().includes(search) ||
						(unit.name && unit.name.toLowerCase().includes(search))
					);
				},
				allUnitsSelected() {
					return this.filteredUnits.length > 0 &&
						this.filteredUnits.every(unit => this.selectedUnits.includes(unit.bitrix_id));
				},
				activeFiltersCount() {
					let count = 0;
					if (!(this.appliedYears.length === 1 && Number(this.appliedYears[0]) === CURRENT_YEAR)) count++;
					if (this.appliedMonths.length > 0) count++;
					if (this.appliedDistricts.length > 0) count++;
					if (this.selectedContractType) count++;
					if (this.selectedContractTypeIds.length > 0) count++;
					if (this.selectedUnits.length > 0 && this.selectedUnits.length !== this.availableUnits.length) count++;
					return count;
				},
				allContractTypes() {
					return [...this.contractTypeIds['краткосрок'], ...this.contractTypeIds['долгосрок']];
				},
				selectedUnitDetails() {
					const map = new Map(this.availableUnits.map(u => [u.bitrix_id, u]));
					return this.selectedUnits.map(id => map.get(id)).filter(Boolean);
				},
				filteredDistricts() {
					const search = this.districtsSearch.trim().toLowerCase();
					if (!search) {
						return this.districts;
					}
					return this.districts.filter(d => d.name.toLowerCase().includes(search));
				},
				allDistrictsSelected() {
					return this.filteredDistricts.length > 0 &&
						this.filteredDistricts.every(d => this.selectedDistricts.includes(d.id));
				},
				selectedDistrictDetails() {
					const map = new Map(this.districts.map(d => [d.id, d]));
					return this.selectedDistricts.map(id => map.get(id)).filter(Boolean);
				}
			},
			async mounted() {
				document.addEventListener('click', this.closeFilterDropdowns);
				try {
					await Promise.all([this.loadYears(), this.loadDistricts()]);
					await this.loadUnits();
					await this.loadReport();
				} catch (e) {
					this.error = e.message;
				}
			},
			beforeUnmount() {
				document.removeEventListener('click', this.closeFilterDropdowns);
			},
			methods: {
				toggleFilterDropdown(name) {
					this.openFilterDropdown = this.openFilterDropdown === name ? null : name;
				},
				closeFilterDropdowns() {
					this.openFilterDropdown = null;
				},
				contractUrl(id) {
					return `${CONTRACT_URL}${id}/`;
				},
				async fetchJson(params) {
					const response = await fetch('api.php?' + params);
					return response.json();
				},
				async loadYears() {
					const result = await this.fetchJson(new URLSearchParams({ action: 'years' }));
					if (!result.success) {
						throw new Error(result.error);
					}
					this.years = result.data.map(Number);
				},
				async loadDistricts() {
					const result = await this.fetchJson(new URLSearchParams({ action: 'districts' }));
					if (!result.success) {
						throw new Error(result.error);
					}
					this.districts = result.data.map(d => ({
						id: Number(d.id),
						name: d.name
					}));
				},
				buildFilterParams(action, years, months, districts) {
					const params = new URLSearchParams({
						action,
						years: years.join(',')
					});
					if (months.length > 0) {
						params.append('months', months.join(','));
					}
					if (districts.length > 0) {
						params.append('districts', districts.join(','));
					}
					return params;
				},
				onYearsChange() {
					this.selectedYears = this.selectedYears.map(Number);
					if (this.selectedYears.length === 0) {
						this.selectedYears = [CURRENT_YEAR];
					}
					this.loadUnits();
				},
				async loadUnits() {
					this.loading = true;
					this.error = null;
					try {
						const years = this.selectedYears.map(Number);
						const months = this.selectedMonths.map(Number);
						const districts = this.selectedDistricts.map(Number);
						const result = await this.fetchJson(this.buildFilterParams('units', years, months, districts));
						if (!result.success) {
							throw new Error(result.error);
						}
						this.availableUnits = result.data;
						const availableIds = new Set(result.data.map(u => u.bitrix_id));
						if (this.selectedUnits.length === 0) {
							this.selectedUnits = result.data.map(u => u.bitrix_id);
						} else {
							this.selectedUnits = this.selectedUnits.filter(id => availableIds.has(id));
							if (this.selectedUnits.length === 0) {
								this.selectedUnits = result.data.map(u => u.bitrix_id);
							}
						}
					} catch (e) {
						this.error = e.message;
					} finally {
						this.loading = false;
					}
				},
				async loadReport() {
					this.loading = true;
					this.error = null;
					try {
						const years = this.appliedYears.map(Number);
						const months = this.appliedMonths.map(Number);
						const districts = this.appliedDistricts.map(Number);
						const params = this.buildFilterParams('report', years, months, districts);
						params.append('units', this.selectedUnits.join(','));
						if (this.selectedContractType) {
							params.append('contract_type', this.selectedContractType);
						}
						if (this.selectedContractTypeIds.length > 0) {
							params.append('contract_type_ids', this.selectedContractTypeIds.join(','));
						}
						const result = await this.fetchJson(params);
						if (!result.success) {
							throw new Error(result.error);
						}
						this.units = result.data.units;
						this.reports = result.data.reports;
					} catch (e) {
						this.error = e.message;
						this.units = [];
						this.reports = {};
					} finally {
						this.loading = false;
					}
				},
				toggleAllUnits(event) {
					const filteredIds = this.filteredUnits.map(u => u.bitrix_id);
					if (event.target.checked) {
						this.selectedUnits = [...new Set([...this.selectedUnits, ...filteredIds])];
					} else {
						this.selectedUnits = this.selectedUnits.filter(id => !filteredIds.includes(id));
					}
				},
				toggleAllDistricts(event) {
					const filteredIds = this.filteredDistricts.map(d => d.id);
					if (event.target.checked) {
						this.selectedDistricts = [...new Set([...this.selectedDistricts, ...filteredIds])];
					} else {
						this.selectedDistricts = this.selectedDistricts.filter(id => !filteredIds.includes(id));
					}
				},
				closeDistrictsModal() {
					this.showDistrictsModal = false;
					this.districtsSearch = '';
					this.loadUnits();
				},
				getShortUnitName(fullName) {
					return fullName.split('/')[0].trim();
				},
				getContractTypeIdName(typeId) {
					return this.allContractTypes.find(t => t.id === typeId).name;
				},
				async resetFilters() {
					this.openFilterDropdown = null;
					this.selectedYears = [CURRENT_YEAR];
					this.selectedMonths = [];
					this.selectedDistricts = [];
					this.appliedYears = [CURRENT_YEAR];
					this.appliedMonths = [];
					this.appliedDistricts = [];
					this.selectedContractType = '';
					this.selectedContractTypeIds = [];
					this.selectedUnits = [];
					this.showUnitsModal = false;
					this.showDistrictsModal = false;
					this.showFiltersModal = false;
					await this.loadUnits();
					await this.loadReport();
				},
				async applyFilters() {
					this.openFilterDropdown = null;
					this.selectedYears = this.selectedYears.map(Number);
					this.selectedMonths = this.selectedMonths.map(Number);
					this.selectedDistricts = this.selectedDistricts.map(Number);
					if (this.selectedYears.length === 0) {
						this.selectedYears = [CURRENT_YEAR];
					}
					this.appliedYears = [...this.selectedYears];
					this.appliedMonths = [...this.selectedMonths];
					this.appliedDistricts = [...this.selectedDistricts];
					this.showUnitsModal = false;
					this.showDistrictsModal = false;
					this.showFiltersModal = false;
					await this.loadUnits();
					await this.loadReport();
				},
				async showStats() {
					const result = await this.fetchJson(new URLSearchParams({ action: 'stats' }));
					if (!result.success) {
						alert(result.error);
						return;
					}
					const stats = result.data;
					alert(`Данные из базы:\nВсего контрактов: ${stats.total}\nВалидных: ${stats.valid}\nПоследняя синхронизация: ${stats.last_sync}`);
				},
				getReport(unitId, contractType, monthKey) {
					return this.reports[unitId]?.[contractType]?.[monthKey] ?? null;
				},
				formatMoney(value) {
					if (!value || value <= 0) {
						return '-';
					}
					return Math.round(value).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' AED';
				},
				getDays(unitId, contractType, monthKey) {
					const report = this.getReport(unitId, contractType, monthKey);
					if (!report) {
						return '-';
					}

					if (contractType === 'долгосрок' && report.contract_start_date && report.contract_end_date) {
						const startDate = new Date(report.contract_start_date);
						const endDate = new Date(report.contract_end_date);
						const startYear = startDate.getFullYear();
						const startMonth = startDate.getMonth();
						const startDay = startDate.getDate();
						const endYear = endDate.getFullYear();
						const endMonth = endDate.getMonth();

						let months = (endYear - startYear) * 12 + (endMonth - startMonth);
						const tempDate = new Date(startYear, startMonth + months, startDay);

						if (tempDate > endDate) {
							months--;
							tempDate.setMonth(tempDate.getMonth() - 1);
						}

						const days = Math.floor((endDate - tempDate) / (1000 * 60 * 60 * 24));
						if (days >= 30) {
							months++;
						}

						return months > 0 ? `${months} мес` : '-';
					}

					return report.occupied_days > 0 ? report.occupied_days : '-';
				},
				getContractsCount(unitId, contractType, monthKey) {
					return this.getReport(unitId, contractType, monthKey)?.contracts_count ?? 0;
				},
				handleRevenueClick(unitId, contractType, monthKey) {
					const contracts = this.getReport(unitId, contractType, monthKey)?.contracts ?? [];
					if (contracts.length === 0) {
						return;
					}
					if (contracts.length === 1) {
						window.open(this.contractUrl(contracts[0].id), '_blank');
						return;
					}
					this.contractsModalData = contracts;
					this.showContractsModal = true;
				}
			}
		}).mount('#vueApp');
	</script>
</body>
</html>
