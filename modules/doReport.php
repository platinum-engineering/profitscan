<?php
function doReport($adrSet, $set=null) {  // tokentx
	// api_json_files/0x001410235f1f3b2810d9a82b9064a09ae7edcd87/
	// Выполняем полный бухгалтерский отчет, баланс, курсы и реквизиты койнов в объект
	
	$adr = $adrSet;
	if (is_array($adrSet)) $adr = '0x'.$adrSet['adr'];
	
	$a = [
		'adr' => $adr,
		'usdList' => ['USDC', 'USDT', 'BUSD'],
		'ethList' => ['ETH', 'WETH'],
		'account2type' => [
			'0x7be8076f4ea4a4ad08075c2508e481d6c946d12b' => 'NFT',	// Open Sea - Nft токены (не идет в работу никак)
		],
		'method2type' => [
			// Контракты ERC20 монет
			// Разрешение указанному в заявке адресу(роутеру/смартконтракту) забирать токены с баланса разрешающего кошелька до указанного лимита(обычно все) 
			'0x095ea7b3' => 'Approved',
			// Простое перемещение erc20 между кошельками в сети (не контрактами)
			// Уходит как и трансфер на контракт erc20 койна с указанием в tx.input того кому дается разрешение на манипуляцию с токеном
			// т.к. койны по факту находятся на на балансах кошельков а на балансах их контрактов и там указано у кого сколько.
			// в блокчейне реально на балансе находится только ETH (первичная монета) она лежит на адресе 0x00000000000
			'0xa9059cbb' => 'Transfer',
			// Покупка токена у контракта за чистый ETH (на контракте ICO не DEX) 0x8a90b417eebef953229c5d2a021ab88a68a2d67b144290882ccdadd712b3bb61
			'0x8de93222' => 'Purchase',
			// Какой-то внутренний обмен , записан в поток txToken транзакций под одним хэшем (по факту операция мены одного койна на другие)				
			//'0x95a2251f':'Redeem',
			// Какой-то внутренний обмен с большим и сложным input по итогу доказано что токены поступают по запросу (на депозит). Но сама природа не ясна
			// hash: 0xabb65521915caff2856f79a0dde617b27265b97804f3a56a2bb31d1b7284eb58
			'0x2e7ba6ef' => 'Clime',		
		],
		'list' => [
			'txlist' => [],				// Базовые транзакции
			'erc20' => [],				// Erc20 транзакции
			'txlistinternal' => []		// Внутрение транзакции (если они есть то это перемещение эфира)
		],

		# WETH 0xc02aaa39b223fe8d0a0e5c4f27ead9083c756cc2
		'weth' => '0xc02aaa39b223fe8d0a0e5c4f27ead9083c756cc2',
		'coins' => [
			'ETH' => [1825, 'ETH', 18,'Etherium with WETH coinId for rates request'],
			'0xc02aaa39b223fe8d0a0e5c4f27ead9083c756cc2'=>[1825, 'WETH', 18, 'Wrapped ETH'],
		],	// Стурктура реквизитов койнов на примере эфира (данный пример в базу не пойдет)		
		'rates' => [],
		
		'report' => [],		// Отчет по движениям до торгового анализа
		'balance' => [],	// Баланс аккаунта с движениями
		// Сводные итоги торгового анализа
		'trade' => [
			'txCount'		=> 0,
			'trades'		=> 0,
			'tradeEthIn'	=> [],
			'tradeNoRate'	=> [],
			'errBalance'	=> 0,
			'errReport'		=> 0,
			'profEth'		=> [],
			'profEthPer'	=> [],
			'profEthOut'	=> [],
		],
		// Ячейка аккаунта на диске  
		'filePath' => (!empty($GLOBALS['store_path'])) ? $GLOBALS['store_path'] : '',
		'isCash' => true,
	];

	if (!empty($set)) $a=array_merge($a, $set);

	$a['adrSet'] = $adrSet;

	$a['stable'] = array_merge($a['usdList'], $a['ethList']);


	# Если не были пред определены топовые монеты
	if (empty($a['tops'])) {		
		
		$a['tops'] =[
			'top10'=>[],
			'top100'=>[],
		];
		
		$lastUnix = db_row('SELECT MAX(unix) lastUnix FROM coins_top')['lastUnix'];
		$topCoins = db_array('SELECT t.coin,CONCAT("0x",c.adr) coinAdr FROM coins_top t LEFT JOIN coins c ON (c.id=t.coin) WHERE t.unix = '.$lastUnix.' ORDER BY t.liquidity DESC;');
		
		# Набираем топ10 и топ100 монеты в отдельные массивы
		$topKey='top10';
		for ($i = 0; $i < count($topCoins); $i++) {
			if (!empty($topCoins[$i]['coinAdr'])) {
				if ($i > 9) $topKey='top100';
				$a['tops'][$topKey][] = strtolower($topCoins[$i]['coinAdr']);
			}
		}
		
	}

	# Важно если нужен перерасчет - удалить report.json и coins.json вместе , они идут в связке второй из первого при переасчете формируется но ни при кэше

	# Рабочая папка 
	$path = $a['filePath'].'/'.$adr;
	
	# Если у нас срочный пересчет state=4 или 6 (на state=5 это не относится т.к. это дохват недоделанного отчета) 6 - это локальный пересчет
	if (in_array($adrSet['state'],[4,6])) {  
		foreach (explode(',', 'erc20,txlist,txlistinternal,report,coins,monthly') as $file) {  # ,balances
			if (file_exists($path.'/'.$file.'.json')) unlink($path.'/'.$file.'.json');
		}		
	}

	# Подгрузка данных если их нет 
	if (!file_exists($path.'/report.json')) {
		
		db_request('UPDATE wallets SET state=31,stateAt='.time().' WHERE id='.$adrSet['id'].';');
		
		// Отчета нет > перекачиваем данные
		if (!file_exists($path.'/erc20.json')) {
			$apiRaw = get_account($adr, 'tokentx', false, $path);
			//doReportPutCash(['title'=>'Stage-1: erc20 upload'], $path.'/state.json');
			// isset($adrSet['txTotal']) && $adrSet['txTotal'] === 0 && 
			
			// У нас выкачка нового аккаунта , сначала проверяем кол-во транзакций
			$apiData = json_decode($apiRaw, true);
			$txFirstAt = 0;
			$txLastAt = 0;
			if (is_array($apiData['result'])) {
				// Промежуточный статус
				$totalTokens=count($apiData['result']);
				echo ' +'.$totalTokens;
				if ($totalTokens > 0) {
					$txFirstAt = intval($apiData['result'][$totalTokens-1]['timeStamp']);
					$txLastAt = intval($apiData['result'][0]['timeStamp']);
					// Обновляем статус (выкачено) , время статуса, кол-во транз, время последней транзы	
				}
			}
				
			# Если аккаунт считаем впервый раз
			if (empty($adrSet['txTotal'])) {		
				# Если это новичек или рутина то мы не считаем мелкие <200 и если он мелкий то ставим пометку и берем следующий
				$stateUpd = (in_array($adrSet['state'],[0,2]) && $totalTokens < 200) ? ',state=1,stateAt='.time() : '';
				db_request('UPDATE wallets SET txTotal='.count($apiData['result']).',txFirstAt='.$txFirstAt.',txLastAt='.$txLastAt.$stateUpd.' WHERE id='.$adrSet['id'].';');					
				// Если статус не пустой (т.е. = 1 см выше ) заканчиваем этот аккаунт прямо тут
				if (!empty($stateUpd)) {
					sap_out('Account too small for report. Skeeping...');
					return true;
				}
			}
			
		}
		
		if (!file_exists($path.'/txlist.json')) {
			get_account($adr, 'txlist', false, $path);
		}
		
		if (!file_exists($path.'/txlistinternal.json')) {
			get_account($adr, 'txlistinternal', false, $path);
		}
			
		# Определяемся с тем надо ли нам удалять старый баланс (чтобы выкачать и обсчитать новый)
		if (in_array($adrSet['state'],[4,6]) && file_exists($path.'/balances.json')) { 
			$currTxLastAt = (isset($txLastAt)) ? $txLastAt : $adrSet['txLastAt'];
			# Если файл баланса был сделан до последней транзакции > удаляем его чтобы скачать актуальный
			if (filemtime($path.'/balances.json') < $currTxLastAt) {
				unlink($path.'/balances.json');
			} else {
				sap_out('The current balance is still valid. Not need to do download.');
			}
		}
			
		// Подкачиваем текущий баланс аккаунта с эзерскана для подсчета открытых позиций
		if (!file_exists($path.'/balances.json')) {
			db_request('UPDATE wallets SET state=32,stateAt='.time().' WHERE id='.$adrSet['id'].';');
			$balance=[];
			$page=0;
			$notLastPage=true;
			while ($notLastPage) {
				$page++;
				$balanceUrl=balanceGetUrl($adr, $page);
				$balanceSlug='';
				if ($page>1) $balanceSlug='_'.$page;
				$apiRaw = get_account($adr, 'balance'.$balanceSlug, $balanceUrl);
				$balancePage=balance_html2obj($apiRaw);
				if (count($balancePage)<100) $notLastPage=false;
				$balance=array_merge($balance, $balancePage);
				// После выкачки и конвертации html страницы в json > удаляем ее экономя место
				unlink($path.'/balance'.$balanceSlug.'.json');
			}
			doReportPutCash($balance, $path.'/balances.json');
		}
		
	}
	
	foreach (['report', 'coins', 'rates'] as $mode) {
		$cashFile = $path.'/'.$mode.'.json';	
		if (!empty($a['isCash']) && file_exists($cashFile)) {
			// Извлекаем отчет из кэша
			$raw = file_get_contents($cashFile);
			$a[$mode] = json_decode($raw, true);		
		} else {
			// Обновляем отчет
			$funcName = 'doReportGet_'.$mode;
			if (function_exists($funcName)) $a = $funcName($a);	
			// Фиксируем отчет в кэш файле
			if (!empty($a[$mode])) doReportPutCash($a[$mode], $cashFile);
		}
	}

	// Если нет движений 
	if (empty($a['report'])) {
		db_request('UPDATE wallets SET state=33,stateAt='.time().' WHERE id='.$adrSet['id'].';');
		$a=doReportPrepareTrade($a);
		return $a;
	}
	
	// Расчет фиксированных позиций и слитых/выведенных + баланс + курсы + доходность ( В ЭФИРАХ )
	db_request('UPDATE wallets SET state=34,stateAt='.time().' WHERE id='.$adrSet['id'].';');
	$a = doReportTxTradeEth($a);

	// Возвращаем true если расчет трейда не удалось закончить
	if (!$a) return true;		

	// Расчет открытых позиций по сверке с эзерсканом
	db_request('UPDATE wallets SET state=35,stateAt='.time().' WHERE id='.$adrSet['id'].';');
	$a = doReportOpenProfit($a);
			
	// Подготовка суммари результатов
	$a = doReportPrepareTrade($a);
	
	return $a;

}
		
function doReportPrepareTrade($a){
	// Фиксируем параметры скоринга ---------------------------------------------------------------------------
	
	#print_r($a['trade']); 
		
	$a['summary'] = $a['trade'];
	
	$a['summary']['txCount'] = count($a['report']);	// Кол-во транзакций
	
	$arr = [
		'trades'		=> 0,						// Кол-во продаж
		'tradeEthIn'	=> 0,						// Оборот с курсом 
		'tradeNoRate'	=> 0,						// Оборот без курса 
		'profEthPer'	=> 0,						// Суммарный процент (будет средний)
	];
							
	foreach (['profEth','profEthOut'] as $pn) {
		$t = 0;
		foreach ($a['summary'][$pn] as $k=>$v) {
			if (!empty($v)) {
				$t += $v;
				if ($pn!=='profEthOut') $arr['trades']++;
				if (!empty($a['summary']['tradeEthIn'][$k])) $arr['tradeEthIn'] += $a['summary']['tradeEthIn'][$k];
				if (!empty($a['summary']['tradeNoRate'][$k])) $arr['tradeNoRate'] += $a['summary']['tradeNoRate'][$k];
				if (!empty($a['summary']['profEthPer'][$k])) $arr['profEthPer'] += $a['summary']['profEthPer'][$k];
			}
		}
		$a['summary'][$pn] = round($t, 4);		
	}
	
	// Суммарный ср процент
	if (!empty($arr['trades'])) $arr['profEthPer'] = round($arr['profEthPer'] / $arr['trades'], 2);
	// Вливаем суммированные показатели фиксированного трейда и слива
	$a['summary'] = array_merge($a['summary'], $arr);
		
	// Оставшиеся в trade параметры массивы - просто суммируем
	foreach ($a['summary'] as $k=>$v) if (is_array($v))  $a['summary'][$k]=round(array_sum($v), 4);
	
	// Считаем профит по открытым позициям
	$t = 0;
	if (!empty($a['tradeOpen'])) {
		foreach ($a['tradeOpen'] as $openCoin=>$openCoinSet) {
			$a['summary']['tradeEthIn'] += $openCoinSet['saleInEth'];
			$t += $openCoinSet['profEthOpen'];
		}
	}
	
	// Округления оборотам и фиксация открытой прибыли
	$a['summary']['profEthOpen'] = round($t, 4);		
	$a['summary']['tradeEthIn'] = round($a['summary']['tradeEthIn'], 4);	
	$a['summary']['tradeNoRate'] = round($a['summary']['tradeNoRate'], 4);			
	
	// Считаем итоговый профит который состоит из profEth + profEthOut + profEthOpen
	$a['summary']['profTotal'] = round(($a['summary']['profEth'] + $a['summary']['profEthOut'] + $a['summary']['profEthOpen'] + $a['summary']['expenses'] ), 4);
	
	// Итоговый профит в процентах от оборота (НУЖНА СЕБЕСТОИМОСТЬ) ИЛи НЕТ ???
	
	#print_r($a['summary']); die();
	
	// Отладка для серверного варианта
	//sap_out($adr.'|done '.http_build_query($a['summary'], '', ','));
	
	# Добавляем в суммари текущий баланс аккаунта в eth 
	
	$a['summary']['balanceEth'] = ($a['balanceEth']) ? round($a['balanceEth'], 4) : 0 ;
	
	# Считаем разрез профита фиксация + слив по топовости монеты 
	foreach (['profEth','profEthOut'] as $profType) {
		$a['summary'][$profType.'_top10'] = 0;
		$a['summary'][$profType.'_top100'] = 0;
		foreach (array_keys($a['trade']['profEth']) as $txStep) {
			$profit = $a['trade'][$profType][$txStep];
			$profTopId = $a['trade']['topType'][$txStep];	
			if ($profTopId == 1 || $profTopId == 2) {
				$profTopKey = ($profTopId == 1) ? 'top10' : 'top100';
				$a['summary'][$profType.'_'.$profTopKey] += $profit;
			}			
		}
		$a['summary'][$profType.'_top10'] = round($a['summary'][$profType.'_top10'], 4);
		$a['summary'][$profType.'_top100'] = round($a['summary'][$profType.'_top100'], 4);
		
	}
	# Убираем из сумари topType тк они потеряли актуальность
	unset($a['summary']['topType']);
	
	# Считаем разрез профита в открытом портфеле по топовости монеты  $a['tradeOpen']
	$a['summary']['profEthOpen_top10'] = 0;
	$a['summary']['profEthOpen_top100'] = 0;
	if (!empty($a['tradeOpen'])) {
		foreach ($a['tradeOpen'] as $openCoinSet) {
			# $openCoinSet['profEthOpen']
			$profTopId = $openCoinSet['topType'];	
			if ($profTopId == 1 || $profTopId == 2) {
				$profTopKey = ($profTopId == 1) ? 'top10' : 'top100';
				$a['summary']['profEthOpen_'.$profTopKey] += $openCoinSet['profEthOpen'];
			}				
		}	
		$a['summary']['profEthOpen_top10'] = round($a['summary']['profEthOpen_top10'], 4);
		$a['summary']['profEthOpen_top100'] = round($a['summary']['profEthOpen_top100'], 4);	
	}
	
	return $a;	
}
	
function doReportOpenProfit($a) {
	// Расчет открытых позиций по сверке с эзерсканом
	
	$path = $a['filePath'].'/'.$a['adr'];
	
	$balanceFile = $path.'/balances.json';	
				
	if (!file_exists($balanceFile)) return $a;

	$rates = $a['rates'];
	$balance = $a['balance'];
	$coins = $a['coins'];
	
	// Беру остатки на 1 час назад отмотанные , иначе ЖОПА курсов не будет так в реалтайме ниработает граф
	$balancesTimeStamp = filemtime($path.'/report.json')-60*15;

	$raw = file_get_contents($balanceFile);
	$a['balances'] = json_decode($raw, true);	
	
	$tradeOpen = [];
	
	$balanceEth = 0;
	
	foreach ($a['balances'] as $coinKey=>$set) {

		// Форматируем адрес монеты в полный (кроме ETH)
		$coin = $coinKey;
		if ($coin != 'ETH') {
			$coin = strtolower($coinKey);	
			if (substr($coin, 0, 2) != '0x') $coin = '0x'.$coin;			
		}

		// Параметры монеты
		$onBalance = ($set['coinAmount']*1);
		$coinName = (isset($coins[$coin])) ? $coins[$coin][1] : doReportLimitHash($coin);
		$coinType = 'coin';
		
		if (in_array($coinName, $a['usdList'])) $coinType = 'usd'; 
		if (in_array($coinName, $a['ethList'])) $coinType = 'eth';
		
		// sap_out(''.$coin.' | '.$coinName.' | '.$coinType);
		
		$costOut[$coin] = doReportBalancsap_out($balance, $coin, $onBalance)['cost'];

		$coinAmountCostKoef = round((empty($onBalance)) ? 0 : $costOut[$coin][0][3] / $onBalance, 4);	# Часть монет на которую есть себестоимость делим на остатки (получаем коэфициент суммы трейда для монеты)
		$coinAmountCost = $costOut[$coin][0][4] + $costOut[$coin][0][2];								# 6 => 'inEth',			Cебестоимость исходящей суммы в Eth + fee
			
		$tradeOpen[$coin] = [
			'Date'				=> '',
			'Coin'				=> $coinName,
			'onBalance'			=> $onBalance,
			'inEth'				=> 0,
			'costAmountKoef'	=> 0,
			'costInEth'			=> 0,
			'coinRate'			=> 0,
			'saleInEth'			=> 0,
			'profEthOpen' 		=> 0,
			'profEthOpenPer' 	=> 0,
			'topType'			=> 0,
		];
		
		// Если на балансе нет монеты или у монеты тип стейбл > пропускаем такую монету по ней нечего считать
		if (!empty($coinAmountCostKoef) && $coinType == 'coin' &&  !empty($coinAmountCost)) {

			$tradeOpen[$coin]['costAmountKoef'] = $coinAmountCostKoef;
			$tradeOpen[$coin]['costInEth'] = $coinAmountCost;

			// Если у монеты нет себеса - то и считать нам нечего
			if (empty($coinAmountCost)) continue;
		
			// Тестим наличие номера блока
			if (!isset($blockNumber)) {
				if (!empty($set['blockNumber'])) {	
					$blockNumber = $set['blockNumber'];
					$balancesTimeStamp = $set['timeStamp'];
				} else {
					$blockNumber = doReportGetBlockNumberByTimeStamp($balancesTimeStamp);
					//print_r([$balancesTimeStamp, $blockNumber]);
					if (!is_numeric($blockNumber)) return $a;
					$a['balances'][$coinKey] = array_merge ($a['balances'][$coinKey], [
						'blockNumber' 	=> intval($blockNumber),
						'timeStamp'		=> $balancesTimeStamp
					]);	
					doReportPutCash($a['balances'], $balanceFile);	
				}			
			}
			
			// Тестим наличие курса , если его нет запрашиваем на графе
			if (isset($set['dealEthAmount'])) {
				// Есть предварительно заготовленная сумма сделки в eth
				$dealEthAmount = $set['dealEthAmount'] * 1;
			} else {
				// Нет предварительно заготовленной суммы сделки в eth
				$dealEthAmount = 0;
				if (!empty($set['coinRateEth'])) {
					$dealEthAmount = $set['coinRateEth'] * 1 * $onBalance;
				} else {
					
					// Запрашиваем курс сами или сохраняем его
					$move = doReportCoinAmount2move ([
						$coin => [
							$onBalance,
							$coinName,
							$coinType,
						],
					]);
					#print_r([$onBalance, $coinName, $coinType]); die();
					list ($dealEthAmount, $rates) = doReportDoTradeGetDealEthAmount($rates, $balancesTimeStamp, $blockNumber, null, $move);	
					#print_r(['238-я', $coin, $balancesTimeStamp, $move, $rates[$coin]]); die();					
				}
				#$a['balances'][$coinKey]['dealEthAmount']=$dealEthAmount;
			}

			# eee
			$a['balances'][$coinKey]['dealEthAmount'] = $dealEthAmount ;
			$a['balances'][$coinKey]['coinRateEth'] = $dealEthAmount / $onBalance;

			$saleInEth = $dealEthAmount * $coinAmountCostKoef;	// Продажа части у которой есть себес по тек рынку
			$profEthOpen = $saleInEth - $coinAmountCost;		// Прибыль считаем только на ту часть монет которые заходили по трейду и имеют себес
				
			$tradeOpen[$coin] = array_merge($tradeOpen[$coin], [
				'coinRate'			=> $set['coinRateEth'],
				'saleInEth'			=> $saleInEth,
				'profEthOpen' 		=> $profEthOpen,											// Прибыль в eth		
				'profEthOpenPer' 	=> round($profEthOpen / ($coinAmountCost / 100), 2),		// Прибыль в процентах			
				'topType'			=> doReportGetTopId($coin, $a['tops']),
			]);
			
		}

		# Если есть курс для данной монеты > выводим его
		if (!empty($a['balances'][$coinKey]['coinRateEth'])) {
			if (empty($a['balances'][$coinKey]['dealEthAmount'])) $a['balances'][$coinKey]['dealEthAmount'] = $a['balances'][$coinKey]['coinRateEth'] * $onBalance ;
			$balanceEth += $a['balances'][$coinKey]['dealEthAmount'];
			# Выносим сумму текущей монеты в ETH в свой столбец
			if (!empty($a['balances'][$coinKey]['dealEthAmount'])) $tradeOpen[$coin]['inEth'] = $a['balances'][$coinKey]['dealEthAmount'];
		} 
		
		# Добавляем дату в таблицу
		foreach (array_keys($tradeOpen) as $coin) $tradeOpen[$coin]['Date']=date('Y-m-d H:i:s', $balancesTimeStamp);
		
	}

	// фиксим баланс
	doReportPutCash($a['balances'], $balanceFile);
		
	$a['tradeOpen'] = $tradeOpen;
	$a['balanceEth'] = $balanceEth;
	
	return $a;
}
	
function doReportGetBlockNumberByTimeStamp($timeStamp) {
	// Функция обращается к api эзерскана и возвращает номер блока для указанного timeStamp
	$apiParams = [
	  'module=block',
	  'action=getblocknobytime',
	  'timestamp='.$timeStamp, 	
	  'closest=before',
	  'apikey='.ETHERSCAN_KEY,  // 13113V5BAQZ9ZHTEIDR5Q9FJPFQXWSBV6R    YourApiKeyToken
	];
	
	$apiRaw = file_get_contents('https://api.etherscan.io/api?'.implode('&',$apiParams));
	return json_decode($apiRaw, true)['result'];	
}

function doReportPutCash($data, $cashFile, $mark = null){
	#$cfm = explode('/', $cashFile);
	#if ($cfm[count($cfm)-1] == 'rates.json') die('['.$cashFile.']'); // state.json
	// Переписываем отчет в файл если он есть
	// if (!empty($isCash) && file_exists($cashFile)) continue;		# ЕСЛИ НАДО ПЕРЕПИСАТЬ - КОММЕНТИРУЕМ
	$path = dirname($cashFile);
	$raw=json_encode($data, JSON_UNESCAPED_UNICODE);
	if (file_exists($cashFile)) unlink($cashFile);
	if (!file_exists($path)) {
		if (!mkdir($path, 0777, true)) sap_out('Can\'t create dir path mark ('.$mark.') for file:'.$cashFile) ; //die('Can\'t create dir path mark ('.$mark.') for file:'.$cashFile);
	}
	file_put_contents($cashFile, $raw);					
}

function doReportGet_report($a) {
	// 1) Читает файлы на диске и подготавливает списки транзакций к работе
	$a=doReportPrepareTxLists($a);
	// 2) Перебираем уже полный список рабочих транзакций и проводим бухгалтерские вычисления
	$a=doReportBase($a);

	$adrDir = $a['filePath'].'/'.$a['adr'].'/';
	// После подготовки отчета по движению монет > убиваем исходники эзерскана
	foreach (['erc20','txlist','txlistinternal'] as $file) {
		if (file_exists($adrDir.$file.'.json')) unlink($adrDir.$file.'.json');	
	}
	
	return $a;
}

function doReportGet_coins($a) {
	// 3) Фиксируем реквизиты койнов которые есть в обороте текушего аккаунта (они нужны для корректных курсовых запросов где требует id койна из базы)
	// Запрашиваем те койны которые уже есть в базе данных  0xTMP/
	
	$coins = getCoins(array_keys($a['coins']));
	// Перебираем рабочие койны аккаунта с целью зарегистрировать новые если такие есть и получить их ID
	foreach ($a['coins'] as $coin=>$v) {
		#sap_out(' --- '. $coin.implode(' | ', array_values($v)));
		// Запрашиваем id текущего койна в базе данных (если такого койна в базе нет он будет добавлен со всеми прилагающимися реквизитами с возвращением его нового номера в базе)
		if (empty($a['coins'][$coin][0])) {
			list($coinId, $coins) = getCoinId($coin, $coins, [
				'symbol' => $v[1],
				'coinName' => $v[3],
				'decimals' => $v[2],
			]);
			// Фиксируем id койна в базе данных во внутреннем объекте coins
			$a['coins'][$coin][0] = $coinId;			
		}
	}
	return $a;	
}

function doReportTheGraphRates($block, $tokens = null) {
		
	$wethKey = '0xc02aaa39b223fe8d0a0e5c4f27ead9083c756cc2';
	# Если токенов нет > запрашиваем просто курс WETH (курс эфира) 
	$mode = (empty($tokens) || $tokens === $wethKey) ? 'eth' : 'tokens';	
	if (!is_array($tokens)) $tokensArr=[$tokens];
	
	#die(json_encode($tokens, JSON_UNESCAPED_UNICODE));
	
	$ch = curl_init('https://api.thegraph.com/subgraphs/name/uniswap/uniswap-v2');
	curl_setopt($ch, CURLOPT_POST, 1);

	$body = '{
	 "query":" query pairs($tokens: [Bytes!], $tokenUSDC: Bytes!, $tokenWETH: Bytes!, $blockNumber: Int!) {   usdc0: pairs( where: { token0_in: $tokens, token1: $tokenUSDC, reserveUSD_gt: 10000 } block: { number: $blockNumber }   ) { id token1Price token0 {   id   symbol }   }   usdc1: pairs( where: { token1_in: $tokens, token0: $tokenUSDC, reserveUSD_gt: 10000 } block: { number: $blockNumber }   ) { id token0Price token1 {   id   symbol }   }   weth0: pairs( where: { token0_in: $tokens, token1: $tokenWETH, reserveUSD_gt: 10000 } block: { number: $blockNumber }   ) { id token1Price token0 {   id   symbol }   }   weth1: pairs( where: { token1_in: $tokens, token0: $tokenWETH, reserveUSD_gt: 10000 } block: { number: $blockNumber }   ) { id token0Price token1 {   id   symbol }   }   ethPrice: bundles(block: { number: $blockNumber }) { ethPrice   } }   ",
	 "variables":{
	  "tokens":'.json_encode($tokensArr, JSON_UNESCAPED_UNICODE).',
	  "tokenUSDC":"0xa0b86991c6218b36c1d19d4a2e9eb0ce3606eb48",
	  "tokenWETH":"0xc02aaa39b223fe8d0a0e5c4f27ead9083c756cc2",
	  "blockNumber":'.$block.'
	 }
	}';

	curl_setopt($ch, CURLOPT_POSTFIELDS, $body );
	// Или предать массив строкой:
	// curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($array, '', '&'));

	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_HEADER, false);
	$json = curl_exec($ch);
	curl_close($ch);
	
	$data = json_decode($json, true);
	
	#if ($tokens === '0xd6014ea05bde904448b743833ddf07c3c7837481')  { print_r([$data]); die(); }
	
	# print_r($data); die();
	
	if (empty($data) || is_null($data) || empty($data['data']['ethPrice'])) return 0;
	
	$ethRate = $data['data']['ethPrice'][0]['ethPrice'];
	
	if ($mode === 'eth') return $ethRate;
	
	#if ($block == 10735675 && $tokens != '0xc5be99a02c6857f9eac67bbce58df5572498f40c') { print_r([$tokens, $data]); die(); }
	
	unset($data['data']['ethPrice']);
	
	if (count($data['data']) > 0) {

		foreach ($data['data'] as $pair=>$pairSet) {
			$num1 = substr($pair, -1);
			$num2 = ($num1 === '0') ? 1 : 0;
			$type = (substr($pair, 0, 4) === 'weth') ? 'eth' : 'usd';
			if (!empty($pairSet)) {
				if ($type === 'eth') {
					// eth
					return $pairSet[0]['token'.$num2.'Price'];  
				} else {
					// usd
					return $pairSet[0]['token'.$num2.'Price'] / $ethRate;  
				}
			}
		}
	
	}
	/*
	
	apiData = {
		"data": {
			"ethPrice": [{"ethPrice": "2916.728094883110902289989517256416"}],
			"usdc0": [
			  {
				"id": "0x40c6bc1db179a5c3d464cac557ab890825c638f3",
				"token0": {
				  "id": "0x956f47f50a910163d8bf957cf5846d573e7f87ca",
				  "symbol": "FEI"
				},
				"token1Price": "0.9564624317398571362748600525488482"
			  }
			],
			"usdc1": [],
			"weth0": [
			  {
				"id": "0x67beaf934c2501d1a041d9b15f2b277dc99a9ab0",
				"token0": {
				  "id": "0x4a6ab9792e9f046c3ab22d8602450de5186be9a7",
				  "symbol": "POLVEN"
				},
				"token1Price": "0.0001172183579849900943513213275619735"
			  },
			  {
				"id": "0x94b0a3d511b6ecdb17ebf877278ab030acb0a878",
				"token0": {
				  "id": "0x956f47f50a910163d8bf957cf5846d573e7f87ca",
				  "symbol": "FEI"
				},
				"token1Price": "0.0003292477262660102889300828508168987"
			  }
			],
			"weth1": [
			  {
				"id": "0x60031819a16266d896268cfea5d5be0b6c2b5d75",
				"token0Price": "0.00001284379507826656243695116660691877",
				"token1": {
				  "id": "0xf411903cbc70a74d22900a5de66a2dda66507255",
				  "symbol": "VRA"
				}
			  },
			  {
				"id": "0x7ce01885a13c652241ae02ea7369ee8d466802eb",
				"token0Price": "0.0004974241287120415094340446237116174",
				"token1": {
				  "id": "0xc7283b66eb1eb5fb86327f08e1b5816b0720212b",
				  "symbol": "TRIBE"
				}
			  }
			]
			}
		};
	
	let rez={};
	
	for (let stable in apiData.data) {
		if (apiData.data[stable].length > 0) {
			apiData.data[stable].forEach((pair) => {
				let rate = 1,
					step1 = stable.substr(stable.length - 1)*1,
					step2 = (step1===0) ? 1 : 0;
				if (stable.indexOf('eth') !== -1)  {
					
					rate = ethRate * pair['token'+step2+'Price'];
					console.log('case1:'+rate, ethRate, pair['token'+step2+'Price']);
				}
				// Если койн не обернутый эфир > добавляем его
				if (pair['token'+step1].symbol !== 'WETH') {
					rez[pair['token'+step1].id] = [1 / rate , pair['token'+step1].symbol];
					console.log('case2:', pair['token'+step1].id, rez[pair['token'+step1].id]);
				}
			});	
		}
	}

	return rez;
	*/
	
	
	return 0;
}

function doReportTxTradeEth($a) {
	// Полный отчет + баланс + курсы + доходность
	
	// Извлекаем необходимые для работы функции ресурсы	
	foreach (explode(',','adr,list,stable,usdList,ethList,coins,rates') as $k) ${$k}=$a[$k];

	$path = $a['filePath'].'/'.$adr;
	
	$balance = [];  // Живой баланс

	$txStage = 100;
	$txStageStep = 0;
	$txStageCount = 0;
	$txTotal = count($a['report']);	

	// $state = json_decode(file_get_contents($path.'/state.json'), true);

	$startAt = time();
	
	foreach ($a['report'] as $hash=>$d) {	 
				
		$txStageCount++;
		$txStageStep++;	
		
		if ($d['type'] === 'fail') continue;
		
		// Статистика
		if ($txStageCount > $txStage) {
			$txStageCount=0;
			//doReportPutCash(['title'=>'Stage-5: tx '.$txStageStep.' from '.$txTotal], $path.'/state.json');	
		}
		
		//// Курс эфира на момент транзакции
		//$dealEthRate = $rates['0xc02aaa39b223fe8d0a0e5c4f27ead9083c756cc2'][$d['timeStamp']];
				
		// Если есть Fee списываем ее с баланса в первую очередь
		if ($d['fee']>0) {
			$balance = doReportBalance(
				$balance, 
				$hash,
				'ETH', 								// Эфир
				$d['fee']*-1						// Сумма Fee	//toDecimal(tx.gasPrice, 13)*tx.gasUsed,
				//doReportCloseRate($rates['0xc02aaa39b223fe8d0a0e5c4f27ead9083c756cc2'], $d['timeStamp'], 15) 				// Курс эфира на день сделки (для учета fee в usd)
			)['balance'];		
		}	

		$costOut = [];
		$mowe=[];

		/*
			0 => 'Amount', 			// 0 amount with sign
			1 => 'Symbol',			// 1 coin symbol
			2 => 'CoinType',		// 2 type
			3 => 'outOfBalance',	// 3 outOfBalance
			4 => 'coinId',			// 4 id монеты в базе (внимание - разделяем ETH от WETH для учета монет (ранее смешивали для курса)
			5 => 'inCoin',			// 5 inCoin
			6 => 'inEth',			// 6 inEth
			7 => 'RateEth',			// 7 Курс (цена 1 монеты в eth)
			8 => 'CostK',			// 8 Коэфициент наличия себестоимости
			9 => 'OnBalance'		// 9 Койнов на балансе после движения 
			10 => 'Comment',		// 10  Комментарий по монете
		*/

		foreach ($d['coinAmount'] as $coin=>$coinSet) {
			
			#print_r($coinSet); die();
			
			$mode = ($coinSet[0]>0) ? 'in' : 'out';

			// Койны в потоке
			if (!empty($coinSet[0])) {
				if (empty($mowe[$mode])) $mowe[$mode]=[];
				$mowe[$mode][$coin]=($coinSet[2] === 'coin') ? 1 : 0;					
			}

			// 'outOfBalance', 	Сумма которую не удалось списать, в запросе есть но на баласе нет !!! 
			// Сбрасываем т.к. ранее сюда попадают курс usd = 1 от старых модулей
			$coinSet[3]=0;		//3 outOfBalance
			//$coinSet[4]=0;		//4 => 'coinId',		// 4 id монеты в базе (внимание - разделяем ETH от WETH для учета монет (ранее смешивали для курса)
			$coinSet[5]='';			//5 => 'inCoin',		// 5 inCoin
			$coinSet[6]='';			//6 => 'inEth',			// 6 inEth
			$coinSet[7]='';			//7 => 'RateEth',		// 7 Курс (цена 1 монеты в eth)
			$coinSet[8]='';			//8 => 'CostK',			// 8 Коэфициент наличия себестоимости
			$coinSet[9]='';			//9 => 'OnBalance'		// 9 Койнов на балансе после движения 
			$coinSet[10]='';		//10 => 'Comment',		// 10  Комментарий по монете

			if ($coinSet[0] < 0) {
			
				$costOut[$coin] = doReportBalancsap_out($balance, $coin, abs($coinSet[0]))['cost'];

				// Если сумма в койнах не равна запрашиваемой сумме > +1 в ошибки баланса			
				$coinSet[3]=$costOut[$coin][0][1];								# 7 => 'outOfBalance', 	Сумма которую не удалось списать, в запросе есть но на баласе нет !!! 
				$coinSet[5]=$costOut[$coin][0][0] - $costOut[$coin][0][3];		# 5 => 'inEthOut', 		Сумма монеты на которую нет цены в Eth 
				$coinSet[6]=$costOut[$coin][0][4] + $costOut[$coin][0][2];		# 6 => 'inEth',			Cебестоимость исходящей суммы в Eth + fee	
			
			}
			
			if (!empty($coinSet[3])) $a['trade']['errBalance']++;	

			$d['coinAmount'][$coin]=$coinSet;
			# Фиксируем обновленный coinAmount в основном отчете
			$a['report'][$hash]=$d;
			
		}

		#if ($hash == '0xfa1090d7adf24cb07d372bb7711c2a6d2b939c815995585e3f242a9785a17761') {print_r([$d, $costOut]); die();}

		// Дефолтная трейд статистика сделки  
		$d['profit']=[
			/*
			'profUsd' => 0,
			'profPer' => 0,*/
			'tradeEthIn' => 0,
			//'tradeWithRate' => 0,
			'tradeNoRate' => 0,
			
			'profEth' => 0,	 
			'profEthPer' => 0,
			'profEthOut' => 0,	 
			//'openEth' => 0,
			'fee' => (!empty($d['fee'])) ? $d['fee'] : 0,
			'expenses' => 0,
			'topType' => 0,		# 0 не определен , 1 топ 10, 2 топ100, 3 - прочие
		];

		// Считаем ТРЕЙД
		// Если сделка - это ПАРА
		// Если в движении только пара монет (уход/приход) такие операции считаем за трейд это либо обмен либо продажа либо покупка
		// && count($d['coinAmount']) === 2

		if (doReportIsTrade($mowe) === 1) list ($d, $rates, $balance) = doReportDoTrade($hash, $d, $rates, $balance, $path, $costOut, $txStageStep, $a['tops']);

/*
		if ($hash == '0xe033c136a8348786b985e2e4dc2c09800138c46549ac015f8c73d1c0204c66ac') {
			# 1inch adr = '0x111111111117dc0aa78b770fa6a738034120c302';
			# $balance['0x111111111117dc0aa78b770fa6a738034120c302']
			print_r([$mowe, $d,$costOut, ]); die();
		}
*/

		# Считаем СЛИВ
		if ($d['type'] === 'coinOut') {
			
			// Форматируем движение монет в стандарт move
			$move = doReportCoinAmount2move ($d['coinAmount']);	
	
			// Коэфицент в уходящих монетах на ту часть суммы у которой НЕТ себеса (бывает что не на всю сумму есть себестоимость) в идеале 0			
			list ($coinOutEthCost, $coinOutEthNoCostKoef) = doReportGetCoinOutEthParams ($move);
	
			// $coinOutEthNoCostKoef == 0 - все монеты оценены  		(считаем слив)
			// $coinOutEthNoCostKoef == 1 - все без оценки				(expenses)
			// $coinOutEthNoCostKoef > 0 < 1 - часть монет не оценена	(считаем слив и незакрытую сделку)

			if ($coinOutEthCost > 0) {
				// Если у нас уходят монеты у которых есть себестоимость !!! (т.е. они зашли с тейдом)
				// мы должны зафиксировать продажу в качестве слива по текущему курсу рынка 	
				// Пробуем получить сумму сделки в eth по текущему рынку
				list ($dealEthAmount, $rates) = doReportDoTradeGetDealEthAmount($rates, $d['timeStamp'], $d['blockNumber'], $path, $move);	
				
				$d['profit']['topType'] = doReportGetTopId($move['out']['coin'], $a['tops']); 

				if ($coinOutEthNoCostKoef == 1) {
					// Все уходящие монеты все без оценки , но у них есть себестоимость
					// (так бывает когда монета зашла через обращение к смартконтракту)
					// Слив не оцениваем а себестоимость переносим в прочие expenses					
					$d['profit']['expenses'] = $coinOutEthCost;
				} else {
					// У нас есть оцененная часть монет (или все или часть)
					if (empty($dealEthAmount)) {
						// Если у нас не получилось поднять курс текущей сделки в eth заявляем то что есть в оборот без курса (а есть у нас только себес)
						$d['profit']['tradeEthIn'] = $coinOutEthCost;		
						$d['profit']['tradeNoRate'] = $coinOutEthCost;						
					} else {
						// У нас есть стоимость текущей сделки в eth 
						if (empty($coinOutEthNoCostKoef)) {
							// Все монеты оценены (коэф без оценки - ноль)
						} else {
							// Часть монет оценена (мы считаем оборот только касательно их)
							// !!! часть монет которые сливаются без себеса по ним профит не считается - это просто перемещение !!!)
						
							// !!! Оборот по сливаемым монетам у которых есть себес !!!
							// (1 - $coinOutEthNoCostKoef) - это часть сделки на которую у монет есть себес !!! 
						}
						
						// Неважно все монеты оценены или нет , эти формулы едины для обоих случаев
						$d['profit']['tradeEthIn'] = $dealEthAmount * (1 - $coinOutEthNoCostKoef);				// - это часть сделки на которую у монет есть себес !!! 
						$d['profit']['profEthOut']= $d['profit']['tradeEthIn'] - $coinOutEthCost - $d['fee'];		// Профит слива именно по части монет которая оценена !!!
						
					}	
				}

				// Коэфициент и курсы монет в статистику
				if (!empty($coinOutEthNoCostKoef)) $move['out']['set'][8]=$coinOutEthNoCostKoef;	//8 => 'noCostK',		// 8 Коэфициент наличия себестоимости
				foreach ($move as $mode=>$v) {														//7 => 'RateEth',		// 7 Курс (цена 1 монеты в eth)
					if (isset($rates[$v['coinRateName']][$d['timeStamp']])) $move[$mode]['set'][7]=$rates[$v['coinRateName']][$d['timeStamp']];									
				}
			
				$d = doReportDoTradeMove2d($d, $move);

			}

			#$d['profit']['openEth']=1;	
			
		}
		
		// ============================================================



		// Проводим Движение монет по балансу
		foreach ($d['coinAmount'] as $coin => $coinSet) {

			$coinAmount = $coinSet[0];

			// (НОВАЯ) тестовые работы с новым income ====================
			$income = [];
			
			// В новом варианте расчет нам важна себестоимость для входящих монет
			if ($coinAmount > 0) {
				# Комиссия если есть
				if (!empty($d['fee'])) $income['fee'] = $d['fee'];		
				
				# В coinSet входящего на баланс АЛЬТА всегда должна быть указана себестоимость в eth 
				if ($coinSet[2] === 'coin' && !empty($coinSet[6])) {
					if (!empty($coinSet[5])) $income['inCoin'] = $coinSet[5];		# 3 Сумма монет на выход у которых есть себестоимость в Eth
					$income['inEth'] = $coinSet[6];									# 4 себестоимость монет с себестоимостью в Eth
				}							
			}
			
			// ============================================================
			$balance = doReportBalance($balance, $hash,
				$coin, 										// Коин
				$coinSet[0],								// Сумма с знаком как есть
				#$coinSet[3] 								// Текущий курс
				$income
			)['balance'];
			
			// 7) Баланс монеты на конец операции
			$coinSet[9]=doReportNumberDot2zap(doReportGetCoinBalanceAmount($balance, $coin));	
			
			
			
			// Фиксируем изменения для отчета
			$d['coinAmount'][$coin]=$coinSet;
			
		}
	
		# -----------------------------------------------------------------------------
		# Подсчет/заполнение профитной статистики по сделке (в любом случае даже если не продажа то там могут быть fee и прочие суммари)
		foreach (array_keys($d['profit']) as $k) $a['trade'][$k][] = $d['profit'][$k];
		
		$lastTimeStamp = $d['timeStamp'];
	
		$a['report'][$hash]=$d;
	
		# Если расчет не уложился в 5 минут > завершаем его с возвратом true , и передаем большой собаке
		if ($a['adrSet']['state'] !=5 && (time() - $startAt) > 5*60) {
			db_request('UPDATE wallets SET state=5,stateAt='.time().',dogId=0 WHERE id='.$a['adrSet']['id'].';');
			sap_out('Account too long for small Dog. Skeeping...');			
			return false;
		}
	
	}

	# Форматируем разрез по месяцам в массив для вертикальной таблицы

	# Остатки по движению (пока без надобности т.к. используем от эзерскана
	// $a['stoks'] = doReportGetCoinBalanceAmount($balance);
	
	$a['balance'] = $balance;
	$a['rates'] = $rates;

	#die(json_encode(doReportStoksCoinAdr2coinName($coins, $a['stoks'])));
	
	// ==============================================================================================
	
	return $a;
}

function doReportGetTopId($topCoin, $tops) {
	$topId = 3; 	# 1 топ10, 2 топ100, 3 прочие щитки
	if (in_array($topCoin, $tops['top100'])) $topId = 2;	
	if (in_array($topCoin, $tops['top10'])) $topId = 1;				
	return $topId;
}

# На базе $mowe определяет является ли данная операция трейдом или нет.
function doReportIsTrade($mowe){
	$ok = 0;
	foreach (['in','out'] as $mode) {
		if (!empty($mowe[$mode]) && count($mowe[$mode]) === 1) $ok++;
	}
	if ($ok === 2) return 1;
	return 0;
}

function doReportStoksCoinAdr2coinName($coins, $stoks){
	// Переводит адреса токенов в их символы
	$stoksNames = [];
	foreach ($stoks as $k=>$v) {
		$tokenName = (isset($coins[$k])) ? $coins[$k][1] : 'NaN';
		$stoksNames[$tokenName.'-'.$k]=$v;
	}
	return $stoksNames;
}


function doReportGetCoinOutEthParams ($move) {
	return [
		// [$coinOutEthCost] Себестоимость в eth уходящей монеты 
		$move['out']['set'][6],
		// [$coinOutEthNoCostKoef ] Коэфицент в уходящих монетах на ту часть суммы у которой НЕТ себеса (бывает что не на всю сумму есть себестоимость) в идеале 0			
		round($move['out']['set'][5] / abs($move['out']['set'][0]), 4)
	];
}

function doReportDoTradeMove2d($d, $move) {
	foreach ($move as $v) $d['coinAmount'][$v['coin']] = $v['set'];
	return $d;
}

function doReportDoTradeGetDealEthAmountByGraph ($move, $type, $rates, $block, $unix, $path=null) {
	
	foreach ($move as $mode=>$v) {

		if ($v['set'][2] === $type) {
			$coinRate = doReportTheGraphRates($block, $v['coinRateName']);	
			#print_r([$move, $v]); die();
			if (!empty($coinRate)) {
				
				// Если мы работали в режиме usd (!!!т.е. запрашивали курс эфира!!!)
				$dealEthAmount = abs($v['set'][0]) * $coinRate;										// Сумма всей сделки в eth для не usd (по нашему справочнику) койнов 				
				if ($type === 'usd') {
					$ethRate = $coinRate;
					$dealEthAmount = abs($v['set'][0]) / $ethRate;									// Сумма всей сделки в eth для usd койнов по нашему справочнику типов usd
					//$coinRate = $dealEthAmount / abs($v['set'][0]);									// !!! Курс usd койна в usd !!!
				} 

				$rates[$v['coinRateName']][$unix] = $coinRate;										// Курс тек монеты (получен с graph)

				if (count($move) === 2) {
					
					$nextMode = ($mode === 'in') ? 'out' : 'in';										// След режим в паре
					$nextV = $move[$nextMode];															// След монета в паре
					
					$coinRateNext = $dealEthAmount / abs($nextV['set'][0]);								// Курс след монеты (высчитан)
					if ($nextV['set'][2] === 'usd') {
						$coinRateNext = abs($v['set'][0]) / $dealEthAmount;								// Восстанавливаем курс эфира !!!
					}
					#if ($unix == 1619267997) { print_r([$type, $coinRate, $dealEthAmount, $coinRateNext, $coinRate, $v, $move]); die(); }
					$rates[$nextV['coinRateName']][$unix] = $coinRateNext;						
				
				}
	
				if (!empty($path)) {
					doReportPutCash($rates, $path.'/rates.json', 1);
					return [$dealEthAmount, $rates];					
				}

			}
		}
	}
	
	return null;
}

function doReportDoTradeGetDealEthAmount($rates, $unix, $block, $path, $move){
		
	$dealEthAmount = 0;											// Текущая рыночная сумма сделки в eth (или Сумма в eth входящей монеты )			
	
	// 1) Если эфир на концах сделки
	if (isset($move['in']) && $move['in']['set'][2] === 'eth') return [$move['in']['set'][0], $rates];
	if (isset($move['out']) && $move['out']['set'][2] === 'eth') return [abs($move['out']['set'][0]), $rates];
	
	//if ($block == 12302981) { print_r([$move, $unix, $rates[$move['in']['coinRateName']], $rates[$move['out']['coinRateName']]]); die(); }  // [$unix]
	
	// 2) Перебираем движение и проверяем есть ли у нас уже выкаченные курсы этих монет 
	foreach ($move as $v) {
		if (isset($rates[$v['coinRateName']][$unix])) {			// Если есть курс койна входа или выхода (неважно)
			$coinRate = $rates[$v['coinRateName']][$unix];
			if (empty($coinRate)) return [0, $rates];			// Вернем ноль по тому что попытка уже была ранее и она оказалась без успешной !!! это фиксируем в курсах нулем
			
			$dealEthAmount = (abs($v['set'][0]) * $coinRate);
			
			#if ($unix == 1619267997) {print_r([$dealEthAmount, $coinRate, $block, $v]); die();}
			
			if ($v['set'][2] === 'usd') {
				$dealEthAmount = abs($v['set'][0]) / $coinRate;									// Сумма всей сделки в eth для usd койнов по нашему справочнику типов usd
			}

			return [$dealEthAmount, $rates];	// Возвращаем сумму сделки в eth !!! 
		}				
	}

	// todo : даже после того как курсы были выкачены и отчет сделан полностью скрипт доходит сюда а не должен
	// он должен словить нули выше и выше уйти обратно

	// 3) Пробуем получить курс монеты в приоритете usd (ПРИОРИТЕТ т.к. эфир/usd ликвидность максимальная и всегда есть курс)
	$try = doReportDoTradeGetDealEthAmountByGraph ($move, 'usd', $rates, $block, $unix, $path);
	if (!empty($try)) return $try;

	// 4) Пробуем получить курс монеты (среди которых нет usd)
	$try = doReportDoTradeGetDealEthAmountByGraph ($move, 'coin', $rates, $block, $unix, $path);
	if (!empty($try)) return $try;
	
	// 0,05 eth 10 монет  
	// Если мы тут - у нас полное фиаско при попытке оценить сделку в эфирах на день сделки 
	// Эту попытку мы должны зафиксировать в курсах как НУЛЕВОЙ курс первой монеты , что позволит нам не повторять ДОЛГУЮ попытку поднять стоимость в следующий раз
	
	$firstMove = array_keys($move)[0];
	
	$rates[$move[$firstMove]['coinRateName']][$unix] = 0;
	if (!empty($path)) {
		doReportPutCash($rates, $path.'/rates.json', 2);
	}

	return [$dealEthAmount, $rates];
		
}

function doReportCoinAmount2move ($coinAmount) {
	// Форматирую в движение монет в move формат
	foreach ($coinAmount as $coin=>$coinSet) {
		$mode = ($coinSet[0]<0) ? 'out' : 'in';
		# Если usd то курсовое имя это имя weth т.к. это курс эфира к доллару !!!
		$coinRateName = ($coinSet[2] === 'usd') ? '0xc02aaa39b223fe8d0a0e5c4f27ead9083c756cc2' : $coin ;
		$move[$mode] = ['coin' => $coin, 'set' => $coinSet, 'coinRateName' => $coinRateName];
	}	
	return $move;		
}

function doReportDoTrade($hash, $d, $rates, $balance, $path, $costOut, $txStageStep, $tops) {
	
	#Когда образуется себестоимость!  
	#себестоимость возникает только при первой мене (или покупке) !!! и только !!!
	#при покупке мы всегда знаем себестоимость
	#если при мене не получилось определить себестоимость входящей монеты 
	#просто зашел - себестоимости нет , 
	#просто ушел - если была себестоимость то фиксируем прибыль , если нет то ничего 


	#альт1 зашел
	#альт1 меняем на альт2  (пробуем получить себестоимость на альт2 или на альт1 на день мены) 
	#	если получилось то ок , если нет то пробуем в след раз.
		
	#если альт2 с себестоимостью меняется на альт3 
	#	если себестоимость есть у уходящей монеты , она переходит к приходящей.
	#	если у уходящей она частичная то мы ее переносим только на часть монет !!! новой монете. 
		
	#например альт2 был на складе 10 шт с себестоимостью 3ETH за все

	#добавили альт2 еще 10 шт - себестоимости нет 

	#меняем альт3 15 шт на альт4 - 5шт 

	#	Какая себестоимость у альт4 - 5 шт ?

	#		себестоимость будет 10 (3 eth) + 5 (восстанавливаем из сделки или с рынка) 
	#			если получилось востановить то ок
	#			если нет то ?
	
	// Наша задача получить себестоимости и доли уходящих чтобы передать их входящей монете если она не стейбл , если стейбл зафиксировать прибыль

	#[							# Сводная сибестоимость в eth
	#	$amountOut, 			# 0 Запрашиваемая исходяшая сумма
	#	$amount, 				# 1 Сумма которую не удалось списать, в запросе есть но на баласе нет !!! 
	#	$costFee,				# 2 Сумма расходов на входящие транзакции 
	#	$costAmount, 			# 3 Сумма монет на выход у которых есть себестоимость в Eth
	#	$costEthAmount			# 4 себестоимость исходящей суммы в Eth
	#	$costUsdAmount			# себестоимость исходящей суммы в Usd (для совместимости старого учета)
	#],	

	#0 => 'Amount', 			// 0 amount with sign
	#1 => 'Symbol',			// 1 coin symbol
	#2 => 'CoinType',		// 2 type
	#3 => 'outOfBalance',	// 7 outOfBalance 
	#4 => 'coinId',			// 4 id монеты в базе (внимание - разделяем ETH от WETH для учета монет (ранее смешивали для курса)
	#5 => 'inEthOut',		// # 5 => 'inEthOut', 		Сумма монеты на которую нет цены в Eth 
	#6 => 'inEth',			// # 6 => 'inEth',			Cебестоимость исходящей монеты на которую она есть в Eth + fee транзакции входа монеты	
	#7 => 

	// На балансе было 8 монет альт1 с себестоимостью на все 3 ETH
	// После зашло 4 монеты альт1 (без себестоимости т.к. просто зашло)
	// Меняем 10 альт1 на 4 альт2 
	//		(при этом у двух альт1 нет себестоимости , но мы видем что в балансе на входе еще не было попытки поднять себестоимость)
	//		пытаемся поднять себестоимость только один раз при списании , (если мена то через запрос курса , если продажа то из курса сделки)
	// 		

	// 1) Определяемся с коэфицентом полноты себестоимости . Если себестоимость есть не на все уходящие монеты, то он указывает на какую часть он есть
	// 2) Пытаемся востановить себестоимость всегда когда ее нет на каждой мене .
	// 3) Передаем себестоимость входящей монете на ее баланс если она не стейбл inCoin (монет у кого есть себес) inEth (себес в eth на эти монеты)
	// 4) Если продажа то фиксим прибыль , если нет то передаем себес

    $move = doReportCoinAmount2move($d['coinAmount']);
		
	// 1) Поднимаем себес уходяшей монеты $coinOutCost (если его нет на балансе , через запрос курса если это не эфир)
	// 2) Если продажа считаем прибыль , если нет > передаем себес уходящей монеты входящей. $coinInSet $coinOutSet
	
	// В идеале надо обращаться за курсом монеты которая идет в работу (мена) на каждой мене на ту част которая меняется , но это оч затратно по времени и к графу
	// Будем оценивать монету целиком на первой мене , если оценка ранее не проводилась и если на этой оценке курс получить не удалось то далее так и работаем с ней 
	// как не оцененной , и указываем это в отчете (оборот без курса)


	// Мы работаем всегда только с парными сделки, пара это тейд (мена , продажа , покупка)
	// Если пара продажа или покупка (в ней есть стейбл) - нам всегда надо знать сумму сделки в eth (Для понимания прибыли(продажа) и себестоимости(покупка))
	// Уходящая монета всегда должна иметь себес , если у монеты были "чистые входы" без себеса мы оцениваем ее один раз при первом выходе на паре.

	
	// Пара - это всегда один уходит , другой приходит
	
	// Если уходящая монета по мене не вся покрыта себестоимостью - прямо на мене пробуем востановить ее себестоимость из суммы сделки
	
	
	/*
	if (empty($move['out']['set'][0])) {
		//die(json_encode(['stop'=>implode(', ',['Сумма нулевая', $hash, $move['out']['coin']])], JSON_UNESCAPED_UNICODE));
		eval(dd('["Сумма нулевая", $txStageStep, $hash, $d, $move]')); //print_r(['Сумма нулевая', $txStageStep, $hash, $d, $move]); die();
	} */

	// Себестоимость в eth уходящей монеты
	// Коэфицент в уходящих монетах на ту часть суммы у которой НЕТ себеса (бывает что не на всю сумму есть себестоимость) в идеале 0			
	list ($coinOutEthCost, $coinOutEthNoCostKoef) = doReportGetCoinOutEthParams($move);

	// Коэфициент сделки это  = Вся сумма / сумму монет у которых нет себеса  или 1 
	
	/*
	// !!! TODO !!!!  Возможно уходящая монета у нас условно оценена !!! пробуем это выяснить
	if (!empty($coinOutEthNoCostKoef) && $txStageStep>2) {
		// Монета не оценена , провека на условную оценку , если она есть , то дооцениваем т.к. !!! ЕСЛИ НЕ ПРОДАЖА !!! 
		// (продажа чистых входов (даже условно оцененных) - ЭТО ОБНАЛ - не трейд)
		#print_r(['Монета не оценена , провека на условную оценку', $move]); die();
	}
	*/
	
	// Передаем себес входяшей монете пока как есть !!!
	$move['in']['set'][6] = $coinOutEthCost;								// Себестоимость в eth
	$move['in']['set'][5] = $move['in']['set'][0] * $coinOutEthNoCostKoef;		// Монет без оценки НОЛЬ (т.е. все оценены)
				
	// Если у нас простая мена (простая т.к. себес есть на все и коэф = 1) > то больше считать нечего > возвращается в основной скрипт
	if ($d['type'] === 'coin2coin' && empty($coinOutEthNoCostKoef)) return [doReportDoTradeMove2d($d, $move), $rates, $balance];	

	// Нам надо знать сумму сделки в eth , т.е. у нас не простая пара ( простая это когда мена и есть вся себес) 
	// 1) поднимаем сумму сделки в eth (курсы монет это последнее средство для достижения цели а цель узнать сумму сделки в eth)
	// 2) если коэфициент себеса не полный , восстанавливаем его до полного и УСЛОВНО оцениваем последний "чистый вход" монеты 
	// (т.к. он может быть еще открыт и УСЛОВНАЯ оценка позволит избежать повторной оценки на последующих проводках)
	// УСЛОВНАЯ ОЦЕНКА это фиксация курса монеты на дату входа в самом входе как inEthUslovno = Сумма входа в eth
	// ПОЛУЧАЕМ ЦЕНУ СДЕЛКИ В ETH ПО ТЕК РЫНКУ 
	
	// Текущая рыночная сумма сделки в eth (или Сумма в eth входящей монеты )			
	list ($dealEthAmount, $rates) = doReportDoTradeGetDealEthAmount($rates, $d['timeStamp'], $d['blockNumber'], $path, $move);	

	// Коэфициент и курсы монет в статистику
	if (!empty($coinOutEthNoCostKoef)) $move['out']['set'][8]=$coinOutEthNoCostKoef;	//8 => 'noCostK',		// 8 Коэфициент наличия себестоимости
	foreach ($move as $mode=>$v) {														//7 => 'RateEth',		// 7 Курс (цена 1 монеты в eth)
		if (isset($rates[$v['coinRateName']][$d['timeStamp']])) $move[$mode]['set'][7]=$rates[$v['coinRateName']][$d['timeStamp']];									
	}
	
	if ($dealEthAmount>0) {
		// У нас непростая пара и у нас есть сумма сделки в eth на текущий день
		// 1) мы можем востаноить себестоимость если мена
		// 2) мы устанавливаем себестоимость входящей монете если покупка
		
		if ($d['type'] === 'coin2coin') {
			// Мена (восстанавливаем себес т.к. до сюда доходят только за этим)
			$move['in']['set'][6] += $dealEthAmount * $coinOutEthNoCostKoef;	// Добаваем себестоимость входящих монет текущим
			$move['in']['set'][5] = 0;											// Монет у которых нет себеса равно НОЛЬ (полностью востановили)
			// !!! TODO !!!! Условно оценяем "чистый вход" монеты , чтобы не оценять каждый раз когда мы из него в трейд двигаем
		}

		if ($d['type'] === 'stab2coin') {
			// Покупка , при покупке мы просто назначаем себестоимость входа текущей монете , она равна сумме сделки
			// Передаем себес входяшей монете пока как есть !!!
			$move['in']['set'][6] = $dealEthAmount;								// Себестоимость в eth
			$move['in']['set'][5] = 0;											// Монет без оценки НОЛЬ (т.е. все оценены)			
		}
		
	}

	// Если у нас продажа > фиксируем прибыль как есть  
	if ($d['type'] === 'coin2stab') {
		// Продажа 
		
		/*
		При фиксации прибыли важно понимать как считаем.
		Все (трейд + инфляция) или чистый трейд
		Чистый трейд означает что прибыль считаем только по тем валютам у которых есть себестоимость , которая может возникнуть только при парной сделке ( мене или покупке )
		если монета зашла простым перемещением - у нее чистый вход без себеса (!!! и без fee !!!)
		если монета зашла по мене у нее есть fee , в таких случаях ее оцениваем на мене как можем. если себес был не полный , пробуем дооценить один раз на мене 
		*/
		# Помечаем тип монеты по топу для продажи
		$d['profit']['topType'] = doReportGetTopId($move['out']['coin'], $tops);  
		
		//if ($topId !=3 ) print_r($d['profit']); die();
		
		//if ($d['timeStamp'] == 1619267997) { print_r([$dealEthAmount, $move, $d]); die(); }
			
		$profEth = ($dealEthAmount * (1-$coinOutEthNoCostKoef)) - $coinOutEthCost - $d['fee']; 
		
		if ($coinOutEthNoCostKoef != 1) {
			// Если у нас хотябы часть монет оценена !!! тут есть профит по трейду и нам надо считать проценты
			$d['profit']['profEth'] = $profEth;	
			$d['profit']['profEthPer'] = (empty($coinOutEthCost)) ? 0 : $d['profit']['profEth'] / ( $coinOutEthCost / 100 );	

			//$d['profit']['tradeEthIn'] = $move['in']['set'][6];		
			//$d['profit']['tradeNoRate'] = $move['in']['set'][6] * $coinOutEthNoCostKoef;	
			
			$d['profit']['tradeEthIn'] = $dealEthAmount;		
			$d['profit']['tradeNoRate'] = $dealEthAmount * $coinOutEthNoCostKoef;

		} else {
			// Если у нас коэф =1 (все монеты без оценки) у нас тут только расходы и мы их не можем фиксировать в трейд результативность > выносим их в прочие расходы 
			$d['profit']['expenses'] = $profEth;
		}
		// $balance['0x89d24a6b4ccb1b6faa2625fe562bdd9a23260359']
		// if ($hash === '0x7dc936d16b231d82cf0699bae56f45f3f8736c25f88c6b429f23878be5a72f65') { print_r([$coinOutEthNoCostKoef, $move['out']['set'][6], $costOut,$d]); die(); }
		
	}	

	#print_r([$txStageStep, $dealOutRate, $costOutKoef, $coinInSet, $coinOutSet, $balance, $costOut[$coinOut]]); die();			
	
	return [doReportDoTradeMove2d($d, $move), $rates, $balance];
}



function doReportObj2csv($csvArr, $csvFile) {
	// Если файл открыт локально экселем то он его блокирует как ресурс, и будет ошибка от кода!!!
	if (file_exists($csvFile)) {
		unlink($csvFile);
	} else {
		# Надо убедиться что существует папка файла , если его нет > создать
		$path = dirname($csvFile);
		if (!file_exists($path)) {
			if (!mkdir($path, 0777, true)) die('Can\'t create dir path:'.$path);			
		}
	}

	$df = fopen($csvFile, 'w');
	foreach ($csvArr as $row) {
		fputcsv($df, $row, '	');  
	}
	fclose($df);

	// Костыль для перекодировки , пока так пересчитываем файл целиком (сразу на первом пролете кодировать не получается)
	$csvRaw = file_get_contents($csvFile);
	if (file_exists($csvFile)) unlink($csvFile);
	$csvRaw = chr(255) . chr(254) . mb_convert_encoding($csvRaw, 'UTF-16LE', 'UTF-8') ;
	file_put_contents($csvFile, $csvRaw);

	return true;
}


function test($hash, $v){if ($hash === '0xe1ead1bf163e9a15ae2b97b30effd8d63c649ca4eadfc58bff73e433095f9b2d') {print_r($v); die();}}

function doReportNumberDot2zap($val){ // Если на вхде число > меняем ему точку на запятые
	if (is_numeric($val)) 
	$val=str_replace('.' , ',' ,$val);
	return $val;
}


function doReportLimitHash($hash, $lim=4){ // Укорачиватель хэшей в формат 0x0...eWd;
	if (strlen($hash)>19) $hash=substr($hash,0,$lim).'...'.substr($hash,-$lim);
	return $hash;
}

function doReportGetRateCoinName($coin) {
	return ($coin === 'ETH') ? '0xc02aaa39b223fe8d0a0e5c4f27ead9083c756cc2' : $coin;
}

// Движение по балансу
function doReportBalance($balance, $hash, $coin, $amount, $income = []){		
	if ($amount>0) {
		$balance=doReportBalanceIn($balance, $hash, $coin, $amount, $income);
	} else {
		$balance=doReportBalancsap_out($balance, $coin, $amount*-1, $hash);
	}
	return $balance;
}

// Поступление на баланс
function doReportBalanceIn($balance, $hash, $coin, $amount, $income) {
	
	/*
	if (in_array($coin,['ETH','0xc02aaa39b223fe8d0a0e5c4f27ead9083c756cc2'])) {
		# TODO: !!!!!! если монета приходит и это ETH то автоматом ставим inEth !!!!!!
	}

	if (!empty($income) && !is_array($income)) {
		# TODO: !!!!!! если есть курс (usd по старому) конвертируем income в inEth прямо тут согласно указанному курсу 	
		
		$income= ['rate' => $income, 'inUsd'=> $amount / $income];
	}
	*/
	
	$income['in'] =  $amount;
	$income['store'] =  $amount;
	#$income['story'] =  [[$hash, $amount]];	// Первое значение в story это вход , все последующие это выходы от 1 до N , если выхода нет то депозит еще есть
	
	$income['story'] =  [];
	
	// Приходит coin (Просто создаем депозит по курсу входа)
	if (empty($balance[$coin])) $balance[$coin]=[];
	$balance[$coin][$hash]=$income;	
	return ['balance'=>$balance];
}

// Списание с баланса
function doReportBalancsap_out($balance, $coin, $amountOut, $hash='cost'){
	$accuracy = 9;
	$isDeb = 0;
	//if ($hash === '0x7ebd82d4b7ac0fddb2dc341d5404717f5483ccb9f9959e9eaae19f11b411cb8e' && $coin != 'ETH') $isDeb = true; 
	//if ($isDeb) {print_r([$coin, $amountOut, $balance[$coin]]); die();}	
	// Уходит coin (Просто создаем депозит по курсу входа)

/*
Формат сибестоимости списываемой монеты в эфирах:
если монета поступила простым перемещением:
	А) Это не стейбл считаем ее себестоимость именно в этой монете. ( не запрашиваем курс монеты из графа - уходим от этого)
	Б) стейбл - переводим ее стоимость в ETH на тек момент и считаем в eth
если монета поступила в результате обмена то ее себестоимость унаследуюется от уходящей монеты.
если монета поступает в результате покупки , ее себестоимость унаследуется в eth (если покупалась за usd то конвертируем usd в eth на день сделки)

в итоге видим что income - это себестоимость монеты у нее есть след параметр
сумма монеты (первая проводка в балансе)
fee если мы его платили 
costInEth - сумма сибестоимости монеты в eth
или
costInLegacy - [$coin, $amount, $hash] тут себестоимость монеты в наследии. Койн наследованной монеты и хэш когда наследственная монета зашла

*/

	$step=0;				// Шаг FiFo в балансе
	$amount = $amountOut;	// Остататок списания с баланса на старте она равна сумме списания
	$cost = [[$amountOut,$amount,0,0,0,0,0,0],[],[]];
	/*
	Когда мы вынимаем сумму монеты из баланса то считаем сибестоимость в таком формате
	cost => [						# себестоимость уходящей монеты
		[							# Сводная сибестоимость в eth
			$amountOut, 			# Запрашиваемая исходяшая сумма
			$amount, 				# Сумма которую не удалось списать, в запросе есть но на баласе ее нет
			$costFee,				# Сумма расходов на входящие транзакции 
			$costAmount, 			# Сумма монет на выход у которых есть себестоимость в Eth
			$costEthAmount			# себестоимость исходящей суммы в Eth
			$costUsdAmount			# себестоимость исходящей суммы в Usd (для совместимости старого учета)
		],							# Расшифровка сибестоимости в Eth по уходящей монете
		
		[							# Расшифровка сибестоимости по уходящей монете в Eth
			$hash1 => $amountOut1,
			$hash2 => $amountOut2,
			...
			$hashN => $amountOutN,
		],
		
		[							# Расшифровка сибестоимости по уходящей монете в legacy
			$hash1 => $amountOut1,
			$hash2 => $amountOut2,
			...
			$hashN => $amountOutN,	
		]
	];
	*/
	// Если монеты вообще не было в балансе > на выход с уведомлением
	if (empty($balance[$coin])) {
		doReportBalanceOutErr($amountOut, $amount, $coin, $hash);
		return ['balance'=>$balance, 'cost'=>$cost];
	}
	
	#if ($coin === '0xa2b4c0af19cc16a6cfacce81f192b024d625817d') test($hash, [$amount, $amountOut, $cost]);
	
	$balanceKeys = array_keys($balance[$coin]);
	
	while ($step < count($balanceKeys) && $amount > 0) { 

		$dhash = $balanceKeys[$step];													# хэш поступления вычитаемой на текущем шаге суммы

		$b = $balance[$coin][$dhash];

		if ($b['store']>0) {

			// Вычитаемая часть для текущего депозита, равна сумме депозита или сумме вывода (что меньше)
			$minus = ($amount > $b['store']) ? $b['store'] : $amount;
			// Фиксируем вычет текущей ячейки депозита в общей сумме вычета операции
			$amount -= $minus;

			

			// Если у нас ничтожно малое число > Ставим его равным нулю !!!! 
			//if ($amount < (1 / 100000000)) $amount=0;
			if (round($amount, $accuracy) == 0) $amount=0;

			#$inUsd += $minus / $balance[$coin][$step]['rate'];
			#$inCoin += $minus;
			$inEth = 0;
			$inEthUslovno = 0;

			$minusPer = ($minus / ($b['in'] / 100));									# Часть вычитаемой на текущем шаге из баланса суммы от всего ее поступления
			$fee = (!empty($b['fee'])) ? ( ( $b['fee'] / 100 ) * $minusPer ) : 0;		# Часть потраченной комиссии от всей для суммы вычитаемой на текущем шаге
			$inEth = (!empty($b['inEth'])) ? ( ( $b['inEth'] / 100 ) * $minusPer ) : 0;	# Часть себестоимости в Eth от всей для суммы вычитаемой на текущем шаге 	
			
			// Условная цена , оценка входа условно по первой трейд транзакции , в идеале оценивать надо на каждой транзе , но я экономлю на графе и оцениваю только на первой
			$inEthUslovno = (!empty($b['inEthUslovno'])) ? ( ( $b['inEthUslovno'] / 100 ) * $minusPer ) : 0;
			
			# Проводим текущий шаг в отчете по себестоимости
			# Суммарные параметры
			$cost[0][1] = $amount; 
			$cost[0][2] += $fee; 
			if ($inEth>0) {
				$cost[0][3] += $minus; 
				$cost[0][4] += $inEth;
			}
			 
			if ($inEthUslovno>0) {
				$cost[0][5] += $minus; 
				$cost[0][6] += $inEthUslovno;				
			}
			 
			//$cost[0][5] += $inUsd; 
			# История 
			$listKey = ($inEth>0) ? 1 : 2;
			$cost[$listKey][$dhash] = $minus; 

			// Условный сбор фиксируем и его сумму
			if ($inEthUslovno>0) $cost[3][$dhash] = $minus; 

			// Запрос курса наследования
			// Списание с баланса
			
			if ($isDeb) echo NR.'['.$balance[$coin][$dhash]['store'].'-'.$minus.']';
			
			$balance[$coin][$dhash]['store'] = round($balance[$coin][$dhash]['store'] - $minus, $accuracy);
			
			if ($isDeb) echo NR.'['.$step.'|'.$amount.'|'.$minus.'|'.$balance[$coin][$dhash]['store'].']';
			
			$balance[$coin][$dhash]['story'][]=[$hash, -1 * $minus];					

		}
		$step++;
	}

	if ($isDeb) {print_r([$coin, $amountOut, $cost, $balance[$coin]]); die();}

	if ($amount > 0) doReportBalanceOutErr($amountOut, $amount, $coin, $hash);
	
	// На выход с итогами	
	return ['balance'=>$balance, 'cost'=>$cost];
}

function doReportBalanceOutErr($amountOut, $amount, $coin, $hash){
	if ($hash == 'cost') return true;
	# Ошибка при списании баланса (суммы нет или она есть но не вся)
	$amountStr = ($amountOut === $amount) ? $amountOut : $amountOut.' / '.$amount;
	sap_out('There are out of balance: '.$amountStr.' '.$coin.' '.$hash);
}

function doReportGetCoinBalanceAmount($balance, $coin = null) {

	// Список аккаунтов
	$coins = (!empty($coin)) ? [$coin] : array_keys($balance);
	
	$coinsTotal=0;
	
	foreach ($coins as $coin) {
		
		$amount = 0;
		$step = 0;
		$coinsTotal++;
		
		if (!empty($balance[$coin])) {
			$balanceKeys = array_keys($balance[$coin]);
			while ($step<count($balanceKeys)) { 
				if ($balance[$coin][$balanceKeys[$step]]['store']>0) {
					// Вычитаемая часть для текущего депозита, равна сумме депозита или сумме вывода (что меньше)
					$minus = (!empty($balance[$coin][$balanceKeys[$step]]['store'])) ? $balance[$coin][$balanceKeys[$step]]['store'] : 0;
					// Фиксируем вычет текущей ячейки депозита в общей сумме вычета операции
					$amount += $minus;
				}
				$step++;
			}		
		}
		$stoks[$coin]=$amount;
	}
	
	#print_r([$coin, $coins, $amount, $balance]); die();
	
	if ($coinsTotal === 1) return $amount; // $stoks[$coin]; // 
	return $stoks;
	
}

function doReportBase($a) {
	// Базовый отчет + реквизиты койнов (без баланса , и курсов)
	
	// Извлекаем необходимые для работы функции ресурсы
	//['adr' => $adr, 'list'=> $list, 'account2type' => $account2type, 'method2type' => $method2type, 'usdList' => $usdList, 'ethList' => $ethList, 'coins' => $coins] = $a;
	foreach (explode(',','adr,account2type,method2type,usdList,ethList') as $k) ${$k}=$a[$k];
	
	$a['report']=[];	// Базовый отчет
	
	$txTotal = count($a['list']['txlist']);	

	// Обрезка до 3к если вэб запрос
	//if (!empty($_GET['mode']) && $txTotal>2500) $txTotal=2500; 

	for ($txStep = 0; $txStep < $txTotal; $txStep++) {

		$tx = $a['list']['txlist'][$txStep];
		
		# Чистим буфер
		$a['list']['txlist'][$txStep]=null;
	
	//foreach ($list['txlist'] as $tx) {
		
		$d=[
			'timeStamp' 	=> $tx['timeStamp'],
			'blockNumber' 	=> $tx['blockNumber'],
			#'hash' 			=> $tx['hash'],
			'type' 			=> '',
			'fee' 			=> 0,
			'coinAmount' 	=> [],
		];
		
		if (isset($tx['from'])) {
			// Высчитываем комиссию fee
			if ($tx['from'] === $adr) {
				$d['fee']=toDecimal($tx['gasPrice'], 18)*$tx['gasUsed'];
				# fee не участвует в движении средств НО участвует в балансе монеты (не забываем)
				#$d['coinAmount']=coinAmountAdd($d['coinAmount'], 'ETH', -1*$d['fee']);
			}
		}

		// Пропускаем Fail транзакции
		if ($tx['isError'] !== '0') {
			$a['report'][$tx['hash']]=array_merge($d, ['type' => 'fail']);
			continue;
		}	
		
		$d['stab'] = ['in'=>[],'out'=>[]];
		$d['token'] = ['in'=>[],'out'=>[]];
		
		// БАЛАНС ETH Базовое Движение 
		if (!empty($tx['value'])) {
			//d.To = 'ETH';	//// Базовое перемещение эфира или токена erc20 (добавляем указание что это ETH в столбец Coin)
			// После расход эфира если он есть 0x4a465e2755dadf413a09f846152aa5743aa919b8d0a1b5a238f07e9f4e97fd1a
			$sign=1;
			if ($tx['from'] === $adr) $sign=-1;
			$d['coinAmount']=coinAmountAdd($d['coinAmount'], 'ETH', $sign*toDecimal($tx['value'], 18));	
		}		
		
		// Внутренний эфир
		if (!empty($a['list']['txlistinternal'][$tx['hash']])) {
			foreach ($a['list']['txlistinternal'][$tx['hash']] as $v) {
				if (!empty($v['value'])) {
					$sign=1;
					if ($v['from']===$adr) $sign=-1;
					#sap_out($tx['hash'].'|ETH '.$sign*toDecimal($v['value'], 18));
					$d['coinAmount']=coinAmountAdd($d['coinAmount'], 'ETH', $sign*toDecimal($v['value'], 18));
				}
			}
			# Чистим буфер
			$a['list']['txlistinternal'][$tx['hash']]=null;
		}

		// Erc20 транзы
		if (!empty($a['list']['erc20'][$tx['hash']])) {			
			foreach ($a['list']['erc20'][$tx['hash']] as $v) {
				
				// Пополняем справочник erc20 транзакций
				$coin = strtolower($v['contractAddress']);
				if (empty($a['coins'][$coin])) $a['coins'][$coin] = [0, $v['tokenSymbol'], $v['tokenDecimal'], $v['tokenName']];
				
				if ($v['from'] === $adr || $v['to'] === $adr) {
					$sign=1;
					if ($v['from'] === $adr) $sign=-1;
					#sap_out($tx['hash'].'|'.$v['contractAddress'].' '.$sign*toDecimal($v['value'], $v['tokenDecimal']));
					$d['coinAmount']=coinAmountAdd($d['coinAmount'], $coin, $sign*toDecimal($v['value'], $v['tokenDecimal']));
				}
			}
			# Чистим буфер
			$a['list']['erc20'][$tx['hash']]=null;
		}



		// Предварительный тип операции
		if (!empty($tx['input'])) { 
			// По отправителю или получателю (если такой есть среди выделенных)
			foreach (['from', 'to'] as $k) {
				if (isset($account2type[$tx[$k]])) $d['type']=$account2type[$tx[$k]];
			}
			// По известному методу
			$method = substr($tx['input'], 0, 10);
			
			if (isset($method2type[$method])) $d['type']=$method2type[$method];
			
			// Перевод самому себе по базовой транзе (бессмысленная транза)
			if ($tx['from'] === $tx['to']) $d['type']='self';
			
			// Если тип не определен а метод есть - это взаимодействие с контрактом
			if (empty($d['type']) && strlen($method) === 10) $d['type']='Contract'; 
			
			if (empty($d['type']) && empty($d['coinAmount'])) $d['type']='Empty'; 
		
			// Возврат обернутого эфира. Если у нас эфир уходит на смартконтракт weth - и метод - 0xd0e30db0 (deposit) > то на вход ставим weth в том же кол-ве что уходит ETH
			if ($method === '0xd0e30db0' && $tx['to'] === $a['weth'] && !empty($tx['value']) && !empty($d['fee'])) {
				$d['coinAmount']=coinAmountAdd($d['coinAmount'], $a['weth'], toDecimal($tx['value'], 18));	
			}
			
		}

		//if ($tx['hash'] === '0x1d3a9b81337a800cd246888cde3f5933d6b293d718793be319f7abb739576595') { print_R([($method === '0xd0e30db0') ? 1 : 0, $tx['to'], $a['weth'] ,toDecimal($tx['value'], 18), $d]); die(); }

		// if (d.hash === '0x8a90b417eebef953229c5d2a021ab88a68a2d67b144290882ccdadd712b3bb61') console.log(JSON.stringify(d.coinAmount));		
		// Прогоняем d.coinAmount добавляя каждому койну тип coin , eth , usd и его символ 
		
		foreach ($d['coinAmount'] as $coin=>$amount) {
			$coinSymb = ($a['coins'][$coin]) ? $a['coins'][$coin][1] : $coin; 
			$coinType = (in_array($coinSymb, $usdList)) ? 'usd' : ((in_array($coinSymb, $ethList)) ? 'eth' : 'coin');
			$rate = ($coinType === 'usd') ? 1 : false;

			$d['coinAmount'][$coin]=[
				$amount, 				
				$coinSymb, 				
				$coinType, 					
				$rate,
				($coin === 'ETH' ) ? 1 : $a['coins'][$coin][0],
			];
				
			if ($coinType === 'coin') {
				if ($amount>0) $d['token']['in'][]=$coin;
				if ($amount<0) $d['token']['out'][]=$coin;
			} else {
				if ($amount>0) $d['stab']['in'][]=$coin;
				if ($amount<0) $d['stab']['out'][]=$coin;			
			}
		}
		
		// Возвращает расширенный тип торговой сделки (при наличии движения монет кроме fee)
		$d=doReportGetTradeType($d);
		// Убиваем вспомогательные $d['token'] и $d['stab'] они не нужны в новом расчете трейда
		unset($d['token'],$d['stab']);
		// Добавляем стату по тек транзе в отчет 
		$a['report'][$tx['hash']]=$d;
	}
		
	# Чистим буфер
	$a['list']=null;
	
	return $a;	
}

function doReportPrepareTxLists($a) {
	// Читает файлы на диске и подготавливает списки транзакций к работе
	
	// Извлекаем необходимые для работы функции ресурсы
	// ['filePath'=> $filePath, 'adr' => $adr, 'list'=> $list] = $a;
	foreach (explode(',','filePath,adr,list') as $k) ${$k}=$a[$k];
	$path = $filePath.'/'.$adr;
	
	// 1) Извлекам из файлов на диске транзакции по типу и декодируем их все в рабочий объект
    
	foreach (array_keys($list) as $k) {
		$d = doReportGetObj($path.'/'.$k.'.json'); 	
		//sap_out('['.$k.']'.count($d));
				
		if (!empty($d)) {
			
			# Тут пробуем развернуть массив в обратную сторону
			$d = array_reverse($d);
		
			if ($k === 'txlist') {
				// Рабочий массив транзакций
				$list[$k] = $d;
			} else {
				if (empty($list['txlist'])) return $a;
				// Прочие (не рабочие не txlist) транзакции могут содержать более одной проводки под одним хэшем > собираем их в одну
				$txHashLists=[];
				foreach ($d as $v) {
					if (empty($txHashLists[$v['hash']])) $txHashLists[$v['hash']]=[];
					$txHashLists[$v['hash']][]=$v;
				}
				$list[$k]=$txHashLists;
				// Расширяем рабочий массив транзакций (если в текущем списке транзакции есть те которые отсутствуют в базовом txlist списке)
				$list['txlist']=doReportUnionTx($list['txlist'], $txHashLists);
			}
			
		}
	}
		
	$a['list'] = $list;
	return $a;
}

function doReportGetTradeType($d) {

	$stab = $d['stab'];
	$token = $d['token'];
	$type = false;
	/*
	Типы сделок которые тут определяем
	stab2stab
	coin2coin
	
	coin2stab
	stab2coin

	stabIn
	coinIn
		
	stabOut
	coinOut
	*/
	
	// Понимаем тип сделки , распределяем курсы
	
	if (count($token['in']) + count($stab['in']) + count($token['out']) + count($stab['out']) > 0) {
		// Есть движ
		if (count($token['in']) + count($stab['in']) > 0) {
			// In 
			if (count($token['out']) + count($stab['out']) === 0) {
				// Чистое поступление чего либо eth,usd,coin
				if (count($token['in']) > 0)  {
					$type = 'coinIn';		
				} else {
					$type = 'stabIn';		
				}
			} else {
				// Мена
				if (count($stab['in']) + count($stab['out']) === 0) {
					// Нет стейблов в операции движения - чистый обмен койнов   
					$type = 'coin2coin';
					
				} else {
					// Есть стейблы в операции движения
					if (count($stab['in']) > 0 && count($stab['out']) > 0) {
						// На входе и выходе стейблы
						$type = 'stab2stab';		
					} else {
						// На входе есть стейблы
						if (count($token['in']) > 0)  {
							$type = 'stab2coin';		
						} else {
							// Нет на поступлении койнов
							$type = 'coin2stab';		
						}						
					}
				}
			} 
		} else {
			// Чистый выход чего либо eth,usd,coin
			if (count($token['out']) > 0)  {
				$type = 'coinOut';		
			} else {
				$type = 'stabOut';		
			}
		}		
	}
	
	if (!empty($type)) $d['type'] = $type;
	
	return $d;
}


function toDecimal($amount, $decimal){
	if (!is_numeric($decimal)) $decimal=intval($decimal);
	if (!is_numeric($amount)) $amount=intval($amount);
	return $amount / pow(10, $decimal); 
}	

function coinAmountAdd($coinAmount, $coin, $amount) {
	if ($amount === 0) return $coinAmount;
	if (empty($coinAmount[$coin])) $coinAmount[$coin]=0;
	$coinAmount[$coin] += $amount;
	return $coinAmount;
}

function doReportGetObj($file){
	// Считывает и возвращает рабочий объект из apiRaw json файла на диске.
	if (!file_exists($file)) return []; 
	$raw = file_get_contents($file);
	if (empty($raw)) return []; 
	$obj = json_decode($raw, true);
	if (empty($obj['result']) || !is_array($obj['result'])) return []; 
	return $obj['result']; 
}

function doReportUnionTx($txlist, $txHashLists){
	// Расширяет Рабочий массив транзакций ($txlist) траназакциями по типу из ($tx) если их хэша нет в рабочем массиве
	$hashes=array_column($txlist, 'hash');
	foreach ($txHashLists as $txHash=>$txHashList) {
		
		if (!in_array($txHash, $hashes)) {
			// Текушего хэша новой транзакции нет в рабочем массиве > надо его добавить
			$hashes[]=$txHash;
			// За реквизиты новой транзакции берем реквизиты первой из возможного списка
			$tx=$txHashList[0];
			// Время новой транзакции
			$timeCurr = intval($tx['timeStamp']);

			unset($index);
			$step=0;
			while (!isset($index)) {
				if (intval($txlist[$step]['timeStamp']) > $timeCurr) $index=$step;
				$step++;
				if ($step === count($txlist)) $index=$step;
			}

			// Если индекс не определен > вставляем в конец массива 
			if (!isset($index)) $index=count($txlist);
			
			// Формируем преводо транзакцию
			$psevdoTx=[
				'hash' 			=> $tx['hash'],
				'blockNumber' 	=> $tx['blockNumber'],
				'timeStamp' 	=> $tx['timeStamp'],
				'isError'		=> (!empty($tx['isError'])) ? $tx['isError'] : '0',
			];

			#sap_out('add to index '.$index.':'.$txHash);

			// Вставляем в массив преводо транзакцию
			$left=array_slice($txlist, 0, $index);
			$right=array_slice($txlist, $index);
			array_push($left, $psevdoTx);
			$txlist = array_merge($left, $right);

		} 

	}
	return $txlist;
}
