<?php
function get_сoin() {
	$coin=db_row('SELECT id,adr,symbol,decimals FROM coins WHERE symbol is null AND act=0 limit 1;');
	echo '*';
	// Если нет данных > запрос не выполняется > false
	if (empty($coin)) return false; 
	
	// Помечаем как взятый в работу
	db_request('UPDATE coins SET act=5 WHERE id='.$coin['id'].';');
// https://etherscan.io/token/0xdacd69347de42babfaecd09dc88958378780fb62
	$tokenUrl = 'https://etherscan.io/token/0x'.$coin['adr'];
	
	$src = file_get_contents_curl($tokenUrl);
		
	if (strpos($src, 'ContentPlaceHolder1_hdnSymbol') !== false) {
		
		$upd=[
			'symbol' => [
				'in'	=> 'var litAssetSymbol = ',
				'out'	=> ';',
				'lim'	=> 12,
				'validate' => ['isSymb'],
			],
			'decimals' => [
				'in'	=> "var litAssetDecimal = '",
				'out'	=> "';",
				'lim'	=> 3,
				'validate' => ['strip_tags','isDec'],
			],			
		];

		// sap_out('$posName:'.$posName.' |$posSymb:'.$posSymb.' |$posDec:'.$posDec);
		$err = 1;
		sap_out($coin['adr'].' get coin');
		foreach ($upd as $par=>$set) {
			$inNum = strpos($src, $set['in']);
			if ($inNum !== false) {
				$srcIn = substr($src, $inNum+strlen($set['in']));
				$outNum = strpos($srcIn, $set['out']);
				if ($outNum !== false && $outNum < $set['lim']+1) {
					// У нас есть символ выхода и он менее лимита 
					$str = trim(substr($srcIn, 0, $outNum));
					// Валидаторы/модификаторы
					if (!empty($set['validate'])) {
						foreach ($set['validate'] as $func) {
							$str=$func($str);
							if (!$str) {
								$err++;
								break;
							}
						}
					}
					echo(' '.$par.':'.$str);
					$upd[$par]=$str;
				} else {
					$err++;
				}
			}
		} 
		
		$update=['act=1'];
		// Если есть ошибки > обрабатываем только 
		if ($err===1) {
			foreach ($upd as $col=>$val) $update[]=$col.'='.sql_fstr($val);			
		} else {
			$update=['act='.$err];
		}
		// Обновляем реквизиты и статус в таблице
		db_request('UPDATE coins SET '.implode(', ', $update ).' WHERE id='.$coin['id'].';');

	}
	return true;
}

function isDec($str){
	// Валидируем decimal
	if (!is_numeric($str)) return false;
	$dec=intval($str);
	//if (empty($dec))  return false;
	return intval($str);
}

function isSymb($str){
	// Убираем ковычки если есть
	//sap_out($str[0].'-'.$str[strlen($str)-1]);
	if ($str[0] === '"' || $str[0] === "'") $str=substr($str, 1);
	$len=strlen($str)-1;
	if ($str[$len]==='"' || $str[$len]==="'") $str=substr($str, 0, -1);
	return trim($str);
}
