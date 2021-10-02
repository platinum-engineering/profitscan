<?php

function getCoins($adrList=null) {	// 
	$coins=[];
	$where='';
	if (!empty($adrList)) {
		if (!is_array($adrList)) $adrList=[$adrList];
		$adrListWhere=[];
		foreach ($adrList as $adr)  {
			$adr=strtolower($adr);
			if (strlen($adr) === 42) $adr=substr($adr, 2);
			$adrListWhere[]=sql_fstr($adr);
		}
		$where=' WHERE adr IN ('.implode(',', $adrListWhere).')';
	} 
	$d=db_array('SELECT id,adr,symbol,decimals FROM coins'.$where);
	foreach ($d as $v) $coins['0x'.strtolower($v['adr'])]=[$v['id'],$v['symbol'],$v['decimals']]; 
	return 	$coins;	
}

function getCoinId($coin,$coins,$extra=[]) {	// 
	
	$extraCols=[	# Дополнительные данные при регистрации нового койна в базе
		'symbol' => 19,
		'coinName' => 99,
		'decimals' => 0,
	];
	
	$coin = strtolower($coin);
	if (empty($coins[$coin]) || (!empty($extra['symbol']) && (empty($coins[$coin][1])))) {
		// Если койна нет или у него не полные данные а у нас есть данные
		$adr=$coin;
		if (strlen($adr) === 42) $adr=substr($adr, 2);
		
		$ins=[
			'adr' => sql_fstr($adr),
			'act' => 7,
		];
		
		if (!empty($extra)) {
			foreach ($extraCols as $col=>$lim) {
				if (!isset($extra[$col])) {
					$val=$extra[$col];
					if (!empty($lim)) $val=substr($extra[$col],0 , $lim);
					$ins[$col]=sql_fstr($val);
				}
				if (isset($ins[$col])) $upd[]=$col.'='.$ins[$col];
			}
		} 

		$updateOnDup='';
		if (!empty($upd)) $updateOnDup=' ON DUPLICATE KEY UPDATE '.implode(',', $upd);

		db_request('INSERT IGNORE INTO coins ('.implode(',', array_keys($ins)).') VALUES ('.implode(',', array_values($ins)).') '.$updateOnDup);
		$v = getCoins($adr);
		# Извлекаем по номеру 
		$coins[$coin]=$v[$coin];
		// КОСЯК $coinId=$v['id'];
		$coinId=$coins[$coin][0];	
	} else {
		$coinId=$coins[$coin][0];	
	}
	return [$coinId, $coins];			
}

function getWalletId($wallet) {
	$adr=strtolower(substr($wallet,2));
	return db_row('SELECT id FROM wallets where adr='.sql_fstr($adr).';')['id'];
}


# Обрезаем значения по лимиту если они за него выходят по модулю !
function cutLimits($val, $limits){
	foreach ($limits as $col=>$lim) {
		#if (empty($val[$col])) $val[$col]=0;
		#if (!is_numeric($val[$col])) sap_out('['.$val[$col].']');
		#$value=$val[$col]*1;
		$sign=1;
		if ($val[$col] < 0) $sign=-1;
		#if (is_array($val[$col])) {print_R($val); die(); }
		if (($val[$col] * $sign) > $lim) $val[$col]=$lim * $sign;
	}	
	return $val;			
}

function balance_obj2database($balance, $walletId, $dateAt, $coins) {
	# Фиксируем баланс в базе данных на выходе справочник койнов т.к. он модернизируется при регистрации баланса в базе данных
	if (empty($balance)) return $coins;
		
	foreach ($balance as $coinAdr=>$set) {
		
		#$t=['('.$coinAdr.'|'.$coins['0x'.$coinAdr].')'];
		
		list($coinId, $coins) = getCoinId('0x'.$coinAdr, $coins);
		$set=array_merge($set,[
				'coinId' 		=> $coinId,
				'walletId' 		=> $walletId,
				'dateAt' 		=> $dateAt,
			]
		);	
		$set=cutLimits($set, [
			'coinAmount'	=> 99999999999999999999.99999999,	# dec 28,8
			'coinRateEth'	=> 99999999999999999999.99999999,	# dec 28,8
		]);
		$values[] = '('.implode(', ', array_values($set)).')';	

		# Вывод на экран для отладки
		
		#foreach ($set as $k=>$v) $t[]= $k .'|'. $v;
		#sap_out(implode(' ! ', $t));
		
	}

	db_request('DELETE FROM wallets_ethrscan_balance WHERE walletId='.$walletId.' AND dateAt='.$dateAt.';');
	db_request('INSERT INTO wallets_ethrscan_balance ('.implode(', ',array_keys($set)).') VALUES '.implode(', ',array_values($values)).';');		
#die();
	return $coins;
}

function balanceGetUrl($adr, $page=1){
	return 'https://etherscan.io/tokenholdingsHandler.aspx?&ps=100&f=0&h=0&sort=total_price_usd&order=desc&pUsd24hrs=2500&pBtc24hrs=0.0768&pUsd=2500&fav=&langMsg=A%20total%20of%20XX%20tokenSS%20found&langFilter=Filtered%20by%20XX&a='.$adr.'&p='.$page;
}

// 0x812d3178ABb554ea25f04807520C0D36d689cbCF
function balance_html2obj($etherscanBalanceApiJson) {	
	
	# На входе json страницы баланса от эзерскана, после ее парсинга , на выходе объект баланса 
	$values=[];
	$balance=[];
	
	$arr=explode('"layout":"', $etherscanBalanceApiJson); //  

	if (empty($arr[1])) return $balance;

	$html=explode('"fixedlayout":"', $arr[1])[0];

	$startRow = 0;
	$balance = [];
	
	# tbody id="tb1"
	
	# Вырезаем только 
/*
$in = strrpos($html, 'tbody'); //  id="tb1"
die($html);
if ($in === false) return $balance;  // обратите внимание: три знака равенства
$html = substr($html, $in);
$out = strrpos($html, '</tbody>');
if ($out === false) return $balance;  // обратите внимание: три знака равенства
$html = substr($html, -1*$out);
die($html);
*/	

	$trm=explode('<tr>',$html);
	
	if (empty($trm)) return $balance;
	
	$tdm0=explode('</td>',$trm[0]);

	if (isset($tdm0[1])) {
		if (trim(strip_tags($tdm0[1])) === 'Ethereum') {
			# Если первой строкой идет эфир > это первая страница
			$ethAmount=floatval(str_replace(',', '',trim(strip_tags($tdm0[3]))));		
			$balance['ETH'] = [
				'coinAmount' 	=> sql_fnum($ethAmount),
				'coinRateEth' 	=> 1,
			];
			$startRow = 1;			
		} 		
	}

	for ($r=$startRow; $r<count($trm); $r++) {
		$tdm=explode('</td>',$trm[$r]);
		#echo '<br>'.$r.'------------------------------------';
		
		$m=['/token/0x','?a'];
		$row=$trm[$r];
		$mIn = strpos($row, $m[0]);
		if ($mIn !== false) {
			$coinAdr=strtolower(substr($row, $mIn+strlen($m[0]), 40));
			$coinAmount = floatval(str_replace(',', '',trim(strip_tags($tdm[2])))); // floatval(str_replace(',', '',trim($tdm[$d])))
			
			$t = explode(' (',strip_tags($tdm[3]));
			
			$coinRateEth = (isset($t[1])) ? floatval(explode(' ',$t[1])[0]) : 0;

			#echo('['.$coinId.''.$coinAdr.'] ('.$coinAmount.') {'.$coinRateEth.'}');

			$balance[$coinAdr] = [
				'coinAmount' => sql_fnum($coinAmount),
				'coinRateEth' => sql_fnum($coinRateEth),
			];

		}
		
	}			
	#print_r($balance); die();

	return $balance;
}
