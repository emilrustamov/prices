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
	$pdo = getDbConnection();
	$currentYear = date('Y');
	$lastSync = $pdo->query("SELECT MAX(synced_at) as last_sync FROM contracts")->fetch()['last_sync'] ?? null;
	$totalContracts = $pdo->query("SELECT COUNT(*) as cnt FROM contracts")->fetch()['cnt'] ?? 0;
	$validContracts = $pdo->query("SELECT COUNT(*) as cnt FROM contracts WHERE is_valid = 1")->fetch()['cnt'] ?? 0;
	?>
	
	<!-- <div class="bg-blue-100 border-l-4 border-blue-500 text-blue-800 px-4 py-3 mb-4 rounded">
		<strong>Данные из базы:</strong> 
		Всего контрактов: <?php echo $totalContracts; ?>, 
		Валидных: <?php echo $validContracts; ?>. 
		<?php if ($lastSync): ?>
			Последняя синхронизация: <?php echo date('d.m.Y H:i:s', strtotime($lastSync)); ?>
		<?php endif; ?>
	</div> -->

	<div id="vueApp">
		<div class="mb-6 flex gap-3 items-center">
			<button @click="showFiltersModal = true" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded shadow-lg flex items-center gap-2 text-xs">
				<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path>
				</svg>
				Фильтры
				<span v-if="selectedUnits.length > 0 || selectedYear != <?php echo $currentYear; ?> || selectedMonth || selectedContractType || selectedContractTypeIds.length > 0" class="bg-blue-800 text-white text-[10px] px-2 py-1 rounded-full">
					{{ getActiveFiltersCount() }}
				</span>
			</button>
			<button @click="showAvgPriceColumn = !showAvgPriceColumn" class="bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded shadow-lg text-xs">
				{{ showAvgPriceColumn ? 'Скрыть' : 'Показать' }} среднюю стоимость
			</button>
			<div v-if="selectedYear || selectedMonth || selectedUnits.length > 0 || selectedContractType || selectedContractTypeIds.length > 0" class="text-xs text-gray-600 flex items-center gap-2">
				<span @click="showStats" class="font-semibold cursor-pointer hover:text-blue-700 underline">Активные фильтры:</span>
				<span v-if="selectedYear" class="bg-blue-100 text-blue-800 px-2 py-1 rounded text-xs">Год: {{ selectedYear }}</span>
				<span v-if="selectedMonth" class="bg-blue-100 text-blue-800 px-2 py-1 rounded text-xs">Месяц: {{ monthNames[selectedMonth] }}</span>
				<span v-if="selectedContractType" class="bg-blue-100 text-blue-800 px-2 py-1 rounded text-xs">Тип: {{ selectedContractType === 'краткосрок' ? 'Краткосрок' : 'Долгосрок' }}</span>
				<span v-if="selectedContractTypeIds.length > 0" class="bg-blue-100 text-blue-800 px-2 py-1 rounded text-xs">ID типов: {{ selectedContractTypeIds.length }}</span>
				<span v-if="selectedUnits.length > 0" class="bg-blue-100 text-blue-800 px-2 py-1 rounded text-xs">Юнитов: {{ selectedUnits.length }}</span>
			</div>
		</div>

		<div v-if="showFiltersModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50" @click.self="showFiltersModal = false">
			<div class="relative top-10 mx-auto border w-11/12 md:w-4/5 lg:w-3/4 xl:w-2/3 shadow-lg rounded-md bg-white max-h-[90vh] flex flex-col">
				<div class="flex justify-between items-center p-4 border-b border-gray-200 flex-shrink-0">
					<h3 class="text-lg font-bold text-gray-900">Фильтры отчета</h3>
					<button @click="showFiltersModal = false" class="text-gray-400 hover:text-gray-600">
						<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
						</svg>
					</button>
				</div>
				
				<div class="overflow-y-auto flex-1 p-4">
					<div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
						<div>
							<label class="block text-xs font-medium text-gray-700 mb-1">Год:</label>
							<select v-model="selectedYear" @change="loadData" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50 px-2 py-1 text-xs">
								<option v-for="year in years" :key="year" :value="year">{{ year }}</option>
							</select>
						</div>
						
						<div>
							<label class="block text-xs font-medium text-gray-700 mb-1">Месяц (опционально):</label>
							<select v-model="selectedMonth" @change="loadData" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50 px-2 py-1 text-xs">
								<option value="">Все месяцы</option>
								<option v-for="(name, num) in monthNames" :key="num" :value="num">{{ name }}</option>
							</select>
						</div>
					</div>
					
					<div class="mb-4">
						<label class="block text-xs font-medium text-gray-700 mb-1">Тип контракта:</label>
						<select v-model="selectedContractType" @change="onContractTypeChange" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50 px-2 py-1 text-xs">
							<option value="">Все типы</option>
							<option value="краткосрок">Краткосрок</option>
							<option value="долгосрок">Долгосрок</option>
						</select>
						
						<div v-if="selectedContractType" class="mt-2">
							<label class="block text-xs font-medium text-gray-700 mb-1">ID типов контрактов ({{ selectedContractType }}):</label>
							<div class="space-y-1 border border-gray-300 rounded-md p-2 max-h-64 overflow-y-auto">
								<label 
									v-for="typeId in getAvailableContractTypeIds(selectedContractType)" 
									:key="typeId.id"
									class="flex items-center cursor-pointer hover:bg-blue-50 p-1 rounded"
								>
									<input 
										type="checkbox" 
										:value="typeId.id" 
										v-model="selectedContractTypeIds" 
										@change="loadReport"
										class="rounded border-gray-300 text-blue-600 mr-2"
									>
									<span class="text-xs font-medium text-gray-900">{{ typeId.name }} (ID: {{ typeId.id }})</span>
								</label>
							</div>
							<div v-if="selectedContractTypeIds.length > 0" class="mt-2 flex flex-wrap gap-1">
								<span 
									v-for="typeId in selectedContractTypeIds" 
									:key="typeId" 
									class="inline-flex items-center px-2 py-1 bg-blue-100 text-blue-800 rounded text-xs"
								>
									{{ getContractTypeIdName(typeId) }} ({{ typeId }})
									<button @click.stop="removeContractTypeId(typeId)" class="ml-1 text-blue-600 hover:text-blue-800">
										<svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
											<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
										</svg>
									</button>
								</span>
							</div>
						</div>
					</div>
					
					<div class="mb-4">
						<label class="block text-xs font-medium text-gray-700 mb-1">Юниты:</label>
						<div class="relative" ref="unitsDropdown">
							<div @click="unitsDropdownOpen = !unitsDropdownOpen" class="w-full border border-gray-300 rounded-md px-2 py-1 bg-white cursor-pointer hover:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-500 flex items-center justify-between">
								<div class="flex-1 flex flex-wrap gap-1">
									<span v-if="selectedUnits.length === 0" class="text-gray-500 text-xs">Выберите юниты...</span>
									<span v-else-if="selectedUnits.length === 1" class="text-gray-700 text-xs">{{ getUnitName(selectedUnits[0]) }}</span>
									<span v-else class="text-gray-700 text-xs">Выбрано: {{ selectedUnits.length }} юнитов</span>
								</div>
								<svg class="w-4 h-4 text-gray-400 transition-transform" :class="{ 'transform rotate-180': unitsDropdownOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
								</svg>
							</div>
							
							<div v-if="unitsDropdownOpen" class="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-md shadow-lg max-h-96 overflow-hidden" @click.stop>
								<div class="p-2 border-b border-gray-200">
									<input 
										v-model="unitsSearch" 
										@input="filterUnits" 
										placeholder="Поиск по юнитам..." 
										class="w-full px-2 py-1 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-xs"
										@click.stop
									>
									<div class="mt-1 flex items-center">
										<input type="checkbox" @change="toggleAllUnits" :checked="allUnitsSelected" class="rounded border-gray-300 text-blue-600" id="select-all-units" @click.stop>
										<label for="select-all-units" class="ml-2 text-xs font-medium text-gray-700 cursor-pointer">Выбрать все</label>
									</div>
								</div>
								<div class="overflow-y-auto max-h-64">
									<label 
										v-for="unit in filteredUnits" 
										:key="unit.bitrix_id" 
										class="flex items-center px-2 py-1 hover:bg-blue-50 cursor-pointer border-b border-gray-100"
										@click.stop
									>
										<input 
											type="checkbox" 
											:value="unit.bitrix_id" 
											v-model="selectedUnits" 
											@change="loadReport"
											class="rounded border-gray-300 text-blue-600 mr-2"
										>
										<div class="flex-1">
											<div class="text-xs font-medium text-gray-900">{{ unit.bitrix_id }} - {{ unit.name }}</div>
											<div class="text-[10px] text-gray-500">Отчетов: {{ unit.reports_count }}</div>
										</div>
									</label>
									<div v-if="filteredUnits.length === 0" class="px-2 py-1 text-xs text-gray-500 text-center">
										Юниты не найдены
									</div>
								</div>
							</div>
						</div>
						
						<div v-if="selectedUnits.length > 0" class="mt-2 flex flex-wrap gap-1">
							<span 
								v-for="unitId in selectedUnits" 
								:key="unitId" 
								class="inline-flex items-center px-2 py-1 bg-blue-100 text-blue-800 rounded text-xs"
							>
								{{ getUnitName(unitId) }}
								<button @click.stop="removeUnit(unitId)" class="ml-1 text-blue-600 hover:text-blue-800">
									<svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
									</svg>
								</button>
							</span>
						</div>
					</div>
				</div>
				
				<div class="border-t border-gray-200 p-4 bg-gray-50 flex gap-2 justify-end flex-shrink-0 rounded-b-md">
					<button @click="resetFilters" class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-1 px-4 rounded transition-colors text-xs">Сбросить все</button>
					<button @click="applyFilters" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-1 px-4 rounded transition-colors text-xs">Применить</button>
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
						:href="`https://colifeae.bitrix24.eu/page/register_of_documents/register_of_tenants_documents/type/183/details/${contract.id}/`"
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
								{{ month.num }} - {{ monthNames[month.num] }}
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
											<div class="font-semibold text-blue-700">{{ getAvgPrice(unit.bitrix_id, contractType, month.key) }} <span v-if="getAvgPrice(unit.bitrix_id, contractType, month.key) !== '-'">AED</span></div>
										</td>
										<td class="border border-gray-300 px-2 py-2 text-center text-[10px] whitespace-nowrap min-w-[100px]">
											<div class="flex items-center justify-center gap-1">
												<span @click="handleRevenueClick(unit.bitrix_id, contractType, month.key)" class="font-bold text-green-700 cursor-pointer hover:text-green-800 hover:underline">{{ getRevenue(unit.bitrix_id, contractType, month.key) }} <span v-if="getRevenue(unit.bitrix_id, contractType, month.key) !== '-'">AED</span></span>
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

		createApp({
			data() {
				return {
					loading: false,
					error: null,
					showFiltersModal: false,
					showAvgPriceColumn: false,
					years: [],
					selectedYear: <?php echo $currentYear; ?>,
					selectedMonth: '',
					selectedContractType: '',
					selectedContractTypeIds: [],
					selectedUnits: [],
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
					availableUnits: [],
					filteredUnits: [],
					unitsSearch: '',
					unitsDropdownOpen: false,
					units: [],
					reports: {},
					showContractsModal: false,
					contractsModalData: [],
					monthNames: {
						1: 'Янв', 2: 'Фев', 3: 'Мар', 4: 'Апр',
						5: 'Май', 6: 'Июн', 7: 'Июл', 8: 'Авг',
						9: 'Сен', 10: 'Окт', 11: 'Ноя', 12: 'Дек'
					}
				}
			},
			computed: {
				monthsData() {
					if (this.selectedMonth && this.selectedMonth !== '') {
						const monthNum = parseInt(this.selectedMonth);
						return [{
							key: this.selectedYear + '-' + String(monthNum).padStart(2, '0'),
							num: monthNum
						}];
					}
					const months = [];
					for (let m = 1; m <= 12; m++) {
						months.push({
							key: this.selectedYear + '-' + String(m).padStart(2, '0'),
							num: m
						});
					}
					return months;
				},
				allUnitsSelected() {
					if (this.filteredUnits.length === 0) return false;
					return this.filteredUnits.every(unit => this.selectedUnits.includes(unit.bitrix_id));
				}
			},
			mounted() {
				this.loadYears();
				this.loadData();
				document.addEventListener('click', (e) => {
					if (this.$refs.unitsDropdown && !this.$refs.unitsDropdown.contains(e.target)) {
						this.unitsDropdownOpen = false;
					}
				});
			},
			methods: {
				async loadYears() {
					try {
						const response = await fetch('api.php?action=years');
						const result = await response.json();
						if (result.success) {
							this.years = result.data;
						}
					} catch (e) {
						console.error('Error loading years:', e);
					}
				},
				async loadData() {
					this.loading = true;
					this.error = null;
					
					try {
						const params = new URLSearchParams({
							action: 'units',
							year: this.selectedYear
						});
						if (this.selectedMonth) {
							params.append('month', this.selectedMonth);
						}
						
						const response = await fetch('api.php?' + params);
						const result = await response.json();
						
						if (result.success) {
							this.availableUnits = result.data;
							this.filteredUnits = result.data;
							if (this.selectedUnits.length === 0) {
								this.selectedUnits = result.data.map(u => u.bitrix_id);
							} else {
								this.selectedUnits = this.selectedUnits.filter(id => 
									result.data.some(u => u.bitrix_id === id)
								);
							}
							if (!this.showFiltersModal) {
								this.loadReport();
							}
						} else {
							this.error = result.error || 'Ошибка загрузки данных';
						}
					} catch (e) {
						this.error = 'Ошибка подключения: ' + e.message;
					} finally {
						this.loading = false;
					}
				},
				async loadReport() {
					this.loading = true;
					this.error = null;
					
					try {
						const params = new URLSearchParams({
							action: 'report',
							year: this.selectedYear,
							units: this.selectedUnits.length > 0 ? this.selectedUnits.join(',') : ''
						});
						if (this.selectedMonth && this.selectedMonth !== '') {
							params.append('month', this.selectedMonth);
						}
						if (this.selectedContractType) {
							params.append('contract_type', this.selectedContractType);
						}
						if (this.selectedContractTypeIds.length > 0) {
							params.append('contract_type_ids', this.selectedContractTypeIds.join(','));
						}
						
						const response = await fetch('api.php?' + params);
						const result = await response.json();
						
						if (result.success) {
							this.units = result.data.units || [];
							this.reports = result.data.reports || {};
						} else {
							this.error = result.error || 'Ошибка загрузки отчета';
							this.units = [];
							this.reports = {};
						}
					} catch (e) {
						this.error = 'Ошибка подключения: ' + e.message;
						this.units = [];
						this.reports = {};
					} finally {
						this.loading = false;
					}
				},
				toggleAllUnits(event) {
					if (event.target.checked) {
						const filteredIds = this.filteredUnits.map(u => u.bitrix_id);
						const newUnits = [...new Set([...this.selectedUnits, ...filteredIds])];
						this.selectedUnits = newUnits;
					} else {
						const filteredIds = this.filteredUnits.map(u => u.bitrix_id);
						this.selectedUnits = this.selectedUnits.filter(id => !filteredIds.includes(id));
					}
				},
				filterUnits() {
					if (!this.unitsSearch || this.unitsSearch.trim() === '') {
						this.filteredUnits = this.availableUnits;
						return;
					}
					const search = this.unitsSearch.toLowerCase().trim();
					this.filteredUnits = this.availableUnits.filter(unit => 
						unit.bitrix_id.toLowerCase().includes(search) ||
						(unit.name && unit.name.toLowerCase().includes(search))
					);
				},
				getUnitName(unitId) {
					const unit = this.availableUnits.find(u => u.bitrix_id === unitId);
					return unit ? `${unit.bitrix_id} - ${unit.name}` : unitId;
				},
				getShortUnitName(fullName) {
					if (!fullName) return '';
					const match = fullName.match(/^[^/]+/);
					return match ? match[0].trim() : fullName;
				},
				removeUnit(unitId) {
					this.selectedUnits = this.selectedUnits.filter(id => id !== unitId);
					this.loadReport();
				},
				resetFilters() {
					this.selectedYear = <?php echo $currentYear; ?>;
					this.selectedMonth = '';
					this.selectedContractType = '';
					this.selectedContractTypeIds = [];
					this.selectedUnits = [];
					this.loadData();
					this.showFiltersModal = false;
				},
				onContractTypeChange() {
					this.selectedContractTypeIds = [];
					this.loadReport();
				},
				getAvailableContractTypeIds(contractType) {
					return this.contractTypeIds[contractType] || [];
				},
				getContractTypeIdName(typeId) {
					const allTypes = [...this.contractTypeIds['краткосрок'], ...this.contractTypeIds['долгосрок']];
					const found = allTypes.find(t => t.id == typeId);
					return found ? found.name : `ID ${typeId}`;
				},
				removeContractTypeId(typeId) {
					this.selectedContractTypeIds = this.selectedContractTypeIds.filter(id => id != typeId);
					this.loadReport();
				},
				applyFilters() {
					this.loadReport();
					this.showFiltersModal = false;
				},
				async showStats() {
					try {
						const response = await fetch('api.php?action=stats');
						const result = await response.json();
						if (result.success) {
							const stats = result.data;
							alert(`Данные из базы:\nВсего контрактов: ${stats.total}\nВалидных: ${stats.valid}\nПоследняя синхронизация: ${stats.last_sync}`);
						} else {
							alert('Ошибка загрузки статистики');
						}
					} catch (e) {
						console.error('Error loading stats:', e);
						alert('Ошибка загрузки статистики');
					}
				},
				getActiveFiltersCount() {
					let count = 0;
					if (this.selectedYear != <?php echo $currentYear; ?>) count++;
					if (this.selectedMonth) count++;
					if (this.selectedContractType) count++;
					if (this.selectedContractTypeIds.length > 0) count++;
					if (this.selectedUnits.length > 0) count++;
					return count;
				},
				getDays(unitId, contractType, monthKey) {
					const report = this.reports[unitId] && this.reports[unitId][contractType] && this.reports[unitId][contractType][monthKey];
					if (!report) return '-';
					
					if (contractType === 'долгосрок' && report.contract_start_date && report.contract_end_date) {
						const startDate = new Date(report.contract_start_date);
						const endDate = new Date(report.contract_end_date);
						
						let months = 0;
						let days = 0;
						
						const startYear = startDate.getFullYear();
						const startMonth = startDate.getMonth();
						const startDay = startDate.getDate();
						
						const endYear = endDate.getFullYear();
						const endMonth = endDate.getMonth();
						const endDay = endDate.getDate();
						
						months = (endYear - startYear) * 12 + (endMonth - startMonth);
						
						const tempDate = new Date(startYear, startMonth + months, startDay);
						
						if (tempDate > endDate) {
							months--;
							tempDate.setMonth(tempDate.getMonth() - 1);
						}
						
						days = Math.floor((endDate - tempDate) / (1000 * 60 * 60 * 24));
						
						if (days >= 30) {
							months++;
							days = 0;
						}
						
						if (months > 0) {
							return `${months} мес`;
						} else {
							return '-';
						}
					}
					
					return report.occupied_days > 0 ? report.occupied_days : '-';
				},
				getAvgPrice(unitId, contractType, monthKey) {
					const report = this.reports[unitId] && this.reports[unitId][contractType] && this.reports[unitId][contractType][monthKey];
					if (report && report.avg_price_per_day > 0) {
						const rounded = Math.round(report.avg_price_per_day);
						return rounded.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
					}
					return '-';
				},
				getRevenue(unitId, contractType, monthKey) {
					const report = this.reports[unitId] && this.reports[unitId][contractType] && this.reports[unitId][contractType][monthKey];
					if (report && report.total_revenue > 0) {
						const rounded = Math.round(report.total_revenue);
						return rounded.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
					}
					return '-';
				},
				getContractsCount(unitId, contractType, monthKey) {
					const report = this.reports[unitId] && this.reports[unitId][contractType] && this.reports[unitId][contractType][monthKey];
					if (report && report.contracts_count) {
						return report.contracts_count;
					}
					return 0;
				},
				getContracts(unitId, contractType, monthKey) {
					const report = this.reports[unitId] && this.reports[unitId][contractType] && this.reports[unitId][contractType][monthKey];
					if (report && report.contracts) {
						return report.contracts;
					}
					return [];
				},
				handleRevenueClick(unitId, contractType, monthKey) {
					const contracts = this.getContracts(unitId, contractType, monthKey);
					if (contracts.length === 0) {
						return;
					}
					
					if (contracts.length === 1) {
						window.open(`https://colifeae.bitrix24.eu/page/register_of_documents/register_of_tenants_documents/type/183/details/${contracts[0].id}/`, '_blank');
					} else {
						this.contractsModalData = contracts;
						this.showContractsModal = true;
					}
				}
			}
		}).mount('#vueApp');
	</script>
</body>
</html>
