<?php 

function doReportFromTable($adrSet = null, $getData = null) {
	
	# Получаем текущий номер собаки
	# Смысл в том чтобы через номера потоков/собак исключить одновременное взятие в работу одно и то же
	
	$dogId = (!$GLOBALS['dogId']) ? 1 : $GLOBALS['dogId'] ;
	
	# $isEmptyStart = ($adrSet) ? 0 : 1; 
	
	if (empty($adrSet)) $adrSet=doReportFromTableGetWork($dogId, [
		'state=4',		# Срочные
		'state=2',		# Рутина
		'state=0',		# Новые
	]);

	// Если работы нет 
	if (empty($adrSet)) return false;
	
	// Делаем отчет
	$a = doReport($adrSet, $set=null);
	
	// Если не массив > значит статус = 1 , слишком мал для отчета
	if (!is_array($a)) return true;
		
	// Если у нас degen аккаунт или аккаунт считаемый по статусу 6
	if (!empty($adrSet['isDegen']) || $adrSet['state'] == 6) {
		sap_out('doReportFromTable_DegenExtra');
		doReportFromTable_DegenExtra($a, $adrSet);
	}

	$updCols = [
		'txCount'			=> ['txTotal'],
		'trades'			=> ['txSales'],
		'tradeEthIn'		=> ['tradeEth', 	9999999999999.9999], # dec 28,8
		'tradeEthNoRate'	=> ['tradeNoRate', 	9999999999999.9999],
		'errBalance'		=> ['errBalance', 	255],
		'errReport'			=> ['reportNote'],
		'profEth'			=> ['profEth', 		9999999999999.9999],
		'profEthPer'		=> ['profEthPer', 	999999999999999.99],
		'profEthOut'		=> ['profEthOut', 	9999999999999.9999],
		'fee'				=> ['fee'],
		'expenses'			=> ['expenses'],
		'profEthOpen'		=> ['profEthOpen', 	9999999999999.9999],
		'profTotal'			=> ['profEthTotal', 9999999999999.9999],
		'balanceEth'		=> ['balanceEth', 	9999999999999.9999],
		
		'profEth_top10'			=> ['profEth_top10', 		9999999999999.9999],
		'profEth_top100'		=> ['profEth_top100', 		9999999999999.9999],
		
		'profEthOut_top10'		=> ['profEthOut_top10', 	9999999999999.9999],
		'profEthOut_top100'		=> ['profEthOut_top100', 	9999999999999.9999],
		
		'profEthOpen_top10'		=> ['profEthOpen_top10', 	9999999999999.9999],
		'profEthOpen_top100'	=> ['profEthOpen_top100', 	9999999999999.9999],
	]; 
	
	# Форматируем данные в update set формат
	$updSet = getUpdateSet($a['summary'], $updCols)['set'];

	$txFirstAt=0;
	$txLastAt=0;
	if (!empty($a['report'])) {
		$reportKeys = array_keys($a['report']);
		$txFirstAt=$a['report'][$reportKeys[0]]['timeStamp'];
		$txLastAt=$a['report'][$reportKeys[count($reportKeys)-1]]['timeStamp'];
	}
	
	$updSet[]='txFirstAt='.$txFirstAt.',txLastAt='.$txLastAt.',state=3,dogId=0,stateAt='.time();
	
	// print_r($adrSet); print_r($updSet); die();
	// У нас есть суммари и нам надо их внести в таблицу
	db_request('UPDATE wallets SET '.implode(', ', $updSet).' WHERE id='.$adrSet['id'].';');
	
	if (empty($getData)) return true;

	print_r($a['summary']);
	sap_out($adrSet['adr'].'|done');

	return $a;
	
}

function doReportFromTableBig(){
	
	$dogId = (!$GLOBALS['dogId']) ? 1 : $GLOBALS['dogId'] ;
	# Делаем только большие отчеты (state=5)
	$nextBig=doReportFromTableGetWork($dogId, [
		'state=5',		# Срочные
		'state=4',		# Срочные
		'state=2',		# Рутина
		'state=0',		# Новые
	]);
	
	if (empty($nextBig)) return false;
	
	return doReportFromTable($nextBig);

}
	
function doReportFromTable_DegenExtra($a, $adrSet) {
	# Рабочая папка 
	$path = $a['filePath'].'/'.$a['adr'];		
	// Фиксируем отчет в csv файле
	db_request('UPDATE wallets SET state=37,stateAt='.time().' WHERE id='.$adrSet['id'].';');
	doReport2csv($a);
	// Делаем разрез по месяцам
	db_request('UPDATE wallets SET state=36,stateAt='.time().' WHERE id='.$adrSet['id'].';');
	$a = doReportMonthly($a);
	# Фиксируем разрез по месяцам в отдельном файле json
	doReportPutCash($a['monthly'], $path.'/monthly.json');

	# Дорабатываем дополнительные параметры
	# 2 Last Trade date -  дата последнего входа в монету, её покупка
	# 3 Last Profit date - дата выхода из монеты, продажа монеты, момент фиксации прибыли
	# 5 Open Trades Count - количество открытых сделок по которым не было прибылей 
	# 7 Количество токенов в открытых позициях

	$extra = [
		'lastBuyAt'		=> 0,	# Last Trade date
		'lastSaleAt'	=> 0,	# Last Profit date
		'openBuys'		=> 0,	# Open Trades Count - количество открытых сделок по которым не было прибылей - КОЛ-ВО ОТКРЫТЫХ ВХОДОВ
		'openTokens'	=> 0,	# Total Open Token Amount - Количество токенов в открытых позициях
		'avEfficiency'	=> 0,	# Total Efficiency:
	];

	list ($extra['lastBuyAt'], $extra['lastSaleAt']) = doReportExtraLastDates($a['report']);
	
	$openTokens = [];		# Ассортимент открытых токенов и позиций по ним
	
	foreach ($a['balance'] as $coin=>$balance) {
		foreach ($balance as $inputHash=>$inputSet) {
			if (!empty($inputSet['store']) && isset($inputSet['inEth'])) {
				if (empty($openTokens[$coin])) $openTokens[$coin] = 0;
				$openTokens[$coin] += 1;
			}
		}
	}
	
	$extra['openBuys'] = array_sum(array_values($openTokens));
	$extra['openTokens'] = count($openTokens);
	
	$updCols = [
		'lastBuyAt'			=> ['lastBuyAt'],
		'lastSaleAt'		=> ['lastSaleAt'],
		'openBuys'			=> ['openBuys'], 
		'openTokens'		=> ['openTokens'],
		'avEfficiency'		=> ['avEfficiency', 999999999999999.999999999], # dec 25,9
	]; 

	# Форматируем данные в update set формат
	$update = getUpdateSet($extra, $updCols);
	$update['values']['id'] = $adrSet['id'];
	// print_r($adrSet); print_r($updSet); die();
	// У нас есть суммари и нам надо их внести в таблицу
	db_request('INSERT INTO wallets_extra ('.implode(', ', array_keys($update['values'])).') VALUES ('.implode(', ',$update['values']).') ON DUPLICATE KEY UPDATE '.implode(', ',$update['set']).';');
	
}
	
function getUpdateSet($data, $updCols){
	# Форматируем входнящие данные в update set формат
	$set = [];
	$values = [];
	foreach ($data as $k=>$v) {
		if (isset($updCols[$k])) {
			$u = $updCols[$k];
			$dbCol = $u[0];
			$dbVal = $v;
			# Если есть Допустимый диапазон значения > проверяем его и если он выходит ставим равный лимиту
			if (!empty($u[1])) {
				$dbValSign = ($dbVal>0) ? 1 : -1;
				if (abs($dbVal) > $u[1]) $dbVal = $dbValSign * $u[1];
			}
			$value = sql_fnum($dbVal);
			$values[$dbCol] = $value;
			$set[]=$dbCol.'='.$value;
		}
	}
	return ['values'=>$values, 'set'=>$set];
}

function doReportExtraLastDates($report) {
	$lastBuyAt=0;
	$lastSaleAt=0;
	
	$hashes = array_keys($report);
	$total = count($hashes) -1;
	
	for ($i = $total; $i >= 0; $i--) {
		$d = $report[$hashes[$i]];
		if (!empty($d['type'])) {
			// Покупка
			if ($d['type'] == 'stab2coin' && empty($lastBuyAt)) $lastBuyAt = $d['timeStamp']*1;
			// Покупка
			if ($d['type'] == 'coin2stab' && empty($lastSaleAt)) $lastSaleAt = $d['timeStamp']*1;			
		}
		if (!empty($lastBuyAt) && !empty($lastSaleAt)) return [$lastBuyAt, $lastSaleAt];
	}
	
	return [$lastBuyAt, $lastSaleAt];
}

function doReportMonthly($a){
	
	$a['monthly']=[];
	
	foreach ($a['report'] as $hash=>$d) {
		# -----------------------------------------------------------------------------
		// Если есть продажа
		if (!empty($d['profit']['profEth'])) {

			// В статистику по месяцам вношу только фиксированную прибыль (слив не в счет)
			// Заполняем статистику по месяцам
			$a['monthly'][date('Y', $d['timeStamp'])][date('M', $d['timeStamp'])][]=[
				'profEth' => $d['profit']['profEth'],
				'profEthPer' => $d['profit']['profEthPer'],
				'tradeEthIn' => $d['profit']['tradeEthIn'],
			];

		}			
	}

	return $a;
}

function doReportFromTableGetWork(
	$dogId, 	
	$whereList = [
		'state=4',		# Срочные
		'state=2',		# Рутина
		'state=0',		# Новые
	]){
	# Сбрасываем старый номер если есть
	db_request('update wallets set dogId=0 where dogId='.$dogId.';');

	foreach ($whereList as $where) {
		db_request('update wallets set dogId='.$dogId.' where '.$where.' AND dogId=0 limit 1');
		$adrSet=doReportFromTableGetDogRow($dogId);
		if (!empty($adrSet)) return $adrSet;
	}
	return null;
}

function doReportFromTableGetDogRow($dogId) {
	return db_row('select * from wallets where dogId='.sql_fnum($dogId).' limit 1');
}
# -------------------
function doReport2csv($a){
	// Отчет в csv файл
	foreach (explode(',','adr,balance') as $k) ${$k}=$a[$k];

	$hashes = array_keys($a['report']);	
	
	#$lastHash = (count($hashes) > 0 ) ? $hashes[count($hashes) - 1] : null;
	
	$csvArr=[];
	
	$lastHash = '';
	
	foreach ($hashes as $hash) {
		$d = $a['report'][$hash];
		if ($d['type']==='fail') continue;
		# -----------------------
		// Вывод табличной строки
		foreach ($d['coinAmount'] as $coin=>$coinSet) {
			
			$rowVals=['Date'=>date('Y-m-d H:i:s', $d['timeStamp']), 'Hash'=>$hash, 'DealType'=>$d['type']];  # doReportLimitHash()
			foreach ([
					0 => 'Amount', 			// 0 amount with sign
					1 => 'Symbol',			// 1 coin symbol
					2 => 'CoinType',		// 2 type
					3 => 'outOfBalance',	// 3 outOfBalance
					4 => 'coinId',			// 4 id монеты в базе (внимание - разделяем ETH от WETH для учета монет (ранее смешивали для курса)
					5 => 'inCoin',			// 5 inCoin
					6 => 'inEth',			// 6 inEth
					7 => 'RateEth',			// 7  Курс (цена 1 монеты в eth)
					8 => 'noCostK',			// 8 Коэфициент наличия себестоимости
					9 => 'OnBalance',		// 9 Койнов на балансе после движения 
					10 => 'Comment',		// 10  Комментарий по монете
					/*
					4 => 'AmountUsd',		// 4 amountUsd
					5 => 'inCoin',			// 5 inCoin
					6 => 'inUsd',			// 6 inUsd
					7 => 'inAvRate',		// 7 inAvRate
					8 => 'rateCalc',		// 8 rateCalc;
					9 => 'outOfBalance',	// 9 outOfBalance
					10=> 'coinId',			// 10 id монеты в базе (внимание - разделяем ETH от WETH для учета монет (ранее смешивали для курса)	
					11=> 'inEth',			// 11 inEth
					*/
				] as $num=>$key) {
				$rowVals[$key]=doReportNumberDot2zap($coinSet[$num]); // '('.$i.')'.
			} 

			// 12,13,14,15,16 Параметры профита сделки (если сделка фиксация прибыли)
			if (!empty($d['profit']) && $lastHash != $hash) {  #  
				foreach ($d['profit'] as $key=>$val) $rowVals[$key]=doReportNumberDot2zap($val);
			}
			
			# Если у нас первая строчка (это шапка таблицы с заголовками столбцов)
			if (empty($csvArr)) {
				
				$profitKeys=getProfitCols($a);
				
				# Создаем заголовок таблицы , тут же меняем термины на нужные нам
				$headers = array_keys($rowVals);
				$headersChange = [
					'tradeEthIn'=>'TurnOverEth',
					'profEthPer'=>'profEth%',
				];
				foreach ($headers as $key=>$val) if (isset($headersChange[$val]))  $headers[$key] = $headersChange[$val];
				$csvArr = [$headers];
			}

			$csvArr[]=array_values($rowVals);
			$lastHash = $hash;
		}  
		
	}

	// Вносим таблицу с открытыми позициями чуть ниже основного движения
	if (!empty($a['tradeOpen'])) {
		// Отступаем три строчки
		$csvArr[]=[];	$csvArr[]=[];  $csvArr[]=[];
		$csvArr[]=['Open Positions Results'];
		$csvArr[]=[];
		
		$firstCoin = array_keys($a['tradeOpen'])[0];
		
		$csvArr[]=array_keys($a['tradeOpen'][$firstCoin]);
		
		foreach ($a['tradeOpen'] as $coin=>$coinSet) {
			$vals =[];
			// Меняем точку на заяпятую для csv/excel
			foreach ($coinSet as $col=>$val) $vals[]=doReportNumberDot2zap($val);
			$csvArr[]=$vals; //array_values($coinSet);
		}
		
	}

	# Это админский репорт
	doReportObj2csv($csvArr, $a['filePath'].'/csvs/report_'.$adr.'.csv');
	# Это пользовательский репорт (легкий без лишних терминов)
	# Список и порядок полей которые идут в легком отчете
	$lightReportCols = explode(',', 'Date,Hash,DealType,Amount,Symbol,OnBalance,TurnOverEth,profEth,profEth%,profEthOut,fee');
	
	$lightReportColSets = [];
	foreach ($lightReportCols as $col) {
		$position = -1;
		foreach ($csvArr[0] as $k=>$v) {
			if ($v == $col) $position = $k;
		}
		$lightReportColSets[$col] = $position;
	}
	
	#print_r($lightReportColSets); die();
	
	# Первой строкой идет заголовок
	$csvArrLight=[$lightReportCols];
	$isChange = true;
	for($i = 1; $i < count($csvArr); ++$i) {
		if ($csvArr[$i] === []) $isChange = false;
		if ($isChange) {
			$csvArrLight[$i]=[];
			foreach ($lightReportColSets as $col=>$position) {
				$csvArrLight[$i][] = ($position>-1) ? $csvArr[$i][$position] : ''; 
			}			
		} else {
			$csvArrLight[$i]=$csvArr[$i];
		}
	}
		
	#print_r($csvArrLight[2]); die();
	
	# Это легкий репорт
	doReportObj2csv($csvArrLight, $a['filePath'].'/csvs/details_'.$adr.'.csv');
}

# Возвращает профитные столбцы если они есть в отчете
function getProfitCols($a) {
	foreach ($a['report'] as $d) {
		if (!empty($d['profit'])) return array_keys($d['profit']);
	}
	return [];
}
