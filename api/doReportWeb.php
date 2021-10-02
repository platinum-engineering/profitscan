<?php define('FILE', __FILE__);     # Точка входа

require_once('../sap_light/modules/charsOnlyFromList.php');   	# Валидация строк 
require_once('../config_sapiens.php');   		

// Проверяем валидность адреса
$accountAdrBase = doReportWeb_adr2base(charsOnlyFromList($_GET['wallet'], 'eng', '0123456789'));  
$inHtml = (empty($_GET['inHtml'])) ? 0 : 1;
  
if (strlen($accountAdrBase) !== 40) doReportWebEnd('Wallet is wrong.', $inHtml);

$accountAdr='0x'.$accountAdrBase;

if (empty($_GET['mode'])) $_GET['mode']='init';

$mode=$_GET['mode'];

if ($mode == 'csv' || $mode == 'csvfull') {
	
	$slug = ($mode == 'csv') ? 'details' : 'report';
	
	$filename = $slug.'_'.$accountAdr.'.csv';

	//if (!file_exists($filename)) $filename = 'report_'.$accountAdr.'.csv';

	// Пытаемся получить html контент об ошибках 		
	$serverUrl = $store_path.'/csvs/'.$filename; 
	
	header("Content-type: text/csv");
	header("Content-Disposition: attachment; filename={$filename}");
	header("Pragma: no-cache");
	header("Expires: 0");

	// force download
	#header("Content-Type: application/force-download");
	#header("Content-Type: application/octet-stream");
	#header("Content-Type: application/download");

	die(file_get_contents($serverUrl)); 	 
}
	# Json для всего остального
	header('Content-Type: application/json');

# Просмотр операции по номеру
$config_app = [
    'load_modules' => [
        #'charsOnlyFromList'	=> 	'sap_light/modules',              
		'doReport'			=>	'modules',
		'getCoins'			=>	'modules',
		'get_account'		=>	'modules',	
	],
];

# Стартуем мини сапиенс 
require('../sap_light/sap_loader.php');	

$apiClimesLimit = 100 ;

$states = [
	0 => 'New',
	1 => 'To small for report',
	2 => 'To recount',
	3 => 'Report is done',
		31 => 'Node data uploading',
		32 => 'Do Balance',
		33 => 'Do Movings',
		34 => 'Do Fixed profit',
		35 => 'Do Open profit',
		36 => 'Do Csv file',
		37 => 'Do Monthly file',
	4 => 'To urgent recount',
	5 => 'To local urgent recount',
];

/*
state:
0 - только создан в обычном порядке прокачиваем , скорее всего пришел из юнисвопа
1 - уже прокачен по erc20 есть дата первой и последней проводки , но лимит не вышел за 200 проводок
2 - импортнутые из старого , аккаунты на перекачку
3 - отчет сделан 
	- статусы в процессе создания отчета
	31 данные из блокчейна загружены,
	32 баланс подгружен,
	33 движение готово,
	34 фиксированная прибыль,
	35 открытые позиции,
	36 файл отчета,
	37,
	38,
	39 
4 - нужен срочный новый отчет по запросу (с перекачкой естественно)
*/

/*
state:
0 - только создан в обычном порядке прокачиваем , скорее всего пришел из юнисвопа
1 - уже прокачен по erc20 есть дата первой и последней проводки , но лимит не вышел за 200 проводок
2 - импортнутые из старого , аккаунты на перекачку
3 - отчет сделан 
	- статусы в процессе создания отчета
	31 данные из блокчейна загружены,
	32 баланс подгружен,
	33 движение готово,
	34 фиксированная прибыль,
	35 открытые позиции,
	36 файл отчета,
	37,
	38,
	39 
4 - нужен срочный новый отчет по запросу (с перекачкой естественно)
*/

db_request('USE dune');


// Проверить наличие отчета $GLOBALS['store_path'].'/'.$adr.'/report.json' если его нет > надо выкачивать все базовые транзакции с эзерскана и делать отчет 
$path = $store_path . '/' . $accountAdr;

// Считаем рейтинг аккаунта и поднимаем таблицу результатов

$rezult = [
	'wallet' 		=> $accountAdr,
	'title'			=> 'Account in processing.',	
	'nextTurnInSec'	=> 15,	// Интервал за следующим обращением
];

// Получаем номер аккаунта в таблице , если нет то заводим его 
$accountDb = db_row('SELECT * FROM wallets WHERE adr = '.sql_fstr($accountAdrBase).';');

if (empty($accountDb)) {
	// Если такого аккаунта нет в таблице > добавляем его туда со статусом срочного пересчета
	db_request('INSERT IGNORE INTO wallets (adr,state,stateAt,isDegen) VALUES ('.sql_fstr($accountAdrBase).',4,'.time().',1);');		
	doReportWebEnd($rezult);
} else {
	// Если у нас статус вне обработки // обновляем статус на срочную обработку
	if ($accountDb['state'] < 3) $mode = 'refresh';
	// Если аккаунт есть но ранее он небыл дегеном > добавляем его в дегены
	$isDegen = (empty($accountDb['isDegen'])) ? ',isDegen=1' : ''; 
}

# Подключаем extra к summary 
$extra = db_row('SELECT * FROM wallets_extra WHERE id = '.$accountDb['id'].';');

if (!empty($extra)) $accountDb = array_merge($accountDb, $extra);

if ($mode == 'refresh') {
	
	$refreshLimit =  (empty($_GET['nolimit'])) ? 60*120 : 0; // //  120 минут / 2 часа , лимит интервал на обновления отчета 
	$isRef = (file_exists($path.'/coins.json') && (time() - filemtime($path.'/coins.json')) < $refreshLimit ) ? false : true;
	// Если файл есть и лимит его обновления вышел > удаляем все хэши делаем отчет с нуля с запросом данных от эзеркана
	if ($isRef && !empty($accountDb)) $accountDb=doReportWebToUrgent($accountDb);			
}

// Строка статуса
$stateStr = (isset($states[$accountDb['state']])) ? $states[$accountDb['state']] : 'Unknown state';
// + Время статуса
$stateStr = $stateStr.' at '.date ("F d Y H:i:s.", $accountDb['stateAt']);

// Разрез по месяцам если есть файл > прикладываем к ответу
$monthly = [];
if (file_exists($path.'/monthly.json')) {
	$monthly = json_decode(file_get_contents($path.'/monthly.json'), true);
	// Если нужен html
	if (!empty($inHtml)) $monthly=doReportMonthlyVert($monthly);
}

# Запрос на кол-во участников и место текущего в таблице рейтингов
$rankIds=array_column(db_array('SELECT id FROM wallets WHERE isDegen = 1 ORDER BY profEthTotal DESC, txLastAt;'), 'id') ;
$rank = array_search($accountDb['id'], $rankIds)+1;

$rezult = array_merge($rezult, [
	'isRef'		=> $isRef,
	'title'		=> $stateStr,
	'summary' 	=> $accountDb,
	'csv' 		=> true,
	'members'  	=> count($rankIds),
	'rank'  	=> $rank,
]);		

$rankSql = 'SELECT 0 "rank",concat("0x",adr) wallet,txTotal,txSales,tradeEth,profEthTotal,txLastAt,stateAt FROM wallets WHERE isDegen = 1 ORDER BY profEthTotal DESC,txLastAt';

$rankStep =  3;
$rankLimit =  ' LIMIT 5, 2;';

if ($rank < 5) {
	$lim = ($rank > 3) ? 7 : 6;  
	$rating = db_array($rankSql.' limit '.$lim.';');
	$rating = doReportWebAddRank($rating);
} else {
	$rating = db_array($rankSql.' limit 3;');
	$rating = doReportWebAddRank($rating);

	$rankStep =  $rank-2;
	$rankLimit =  ' LIMIT '.$rankStep.', 3;';	
	
	$rankClose = db_array($rankSql.$rankLimit.';');
	$rankClose = doReportWebAddRank($rankClose, $rank-1);
	
	$emptyRow=[];
	foreach (array_keys($rating[0]) as $col) $emptyRow[$col]='-';

	$rating[]=$emptyRow;

	foreach ($rankClose as $row) $rating[]=$row; 

}

if (!empty($monthly)) $rezult['monthly'] = $monthly;
if (!empty($rating)) $rezult['rating'] = $rating;

// Если статус не в процессе , убиваем запрос
if ($accountDb['state'] == 3 || $accountDb['apiClimes'] > $apiClimesLimit) {
	unset($rezult['nextTurnInSec']);
	$apiClimes = 0;	// Сбрасываем счетчик запросов
} else {
	$apiClimes = 'apiClimes+1';
}
// print_r($accountDb); die('dd');	if (!empty($accountDb['id']))
db_request('UPDATE wallets SET apiClimes='.$apiClimes.$isDegen.' WHERE id='.$accountDb['id'].';');
	
doReportWebEnd($rezult);

function doReportWebEnd($rezult, $inHtml = null){
	// Если на входе не массив > это сообщение об ошибке
	if (!is_array($rezult)) {
		if (empty($inHtml)) {
			$rezult = ['stop'=>$rezult];
		} else {
			$rezult = ['stop'=>'<strong class="text-info">'.$rezult.'</strong>'];
		}
	}

	die(json_encode($rezult, JSON_UNESCAPED_UNICODE));
}

function doReportWebToUrgent($accountDb) {
	db_request('UPDATE wallets SET state=4,stateAt='.time().',dogId=0 WHERE id='.$accountDb['id'].';');
	$accountDb['state'] = 4;	
	$accountDb['stateAt'] = time();	
	return $accountDb;
}

function doReportMonthlyVert($monthly){
	$monthlyData=[
		'Jan'=>['Month'=>'<strong>Jan</strong>'],
		'Feb'=>['Month'=>'<strong>Feb</strong>'],
		'Mar'=>['Month'=>'<strong>Mar</strong>'],
		'Apr'=>['Month'=>'<strong>Apr</strong>'],
		'May'=>['Month'=>'<strong>May</strong>'],
		'Jun'=>['Month'=>'<strong>Jun</strong>'],
		'Jul'=>['Month'=>'<strong>Jul</strong>'],
		'Aug'=>['Month'=>'<strong>Aug</strong>'],
		'Sep'=>['Month'=>'<strong>Sep</strong>'],
		'Oct'=>['Month'=>'<strong>Oct</strong>'],
		'Nov'=>['Month'=>'<strong>Nov</strong>'],
		'Dec'=>['Month'=>'<strong>Dec</strong>'],
	];
	
	# 
	foreach ($monthly as $year=>$yearSet) {
		foreach (array_keys($monthlyData) as $month) {
			$v = 'no sales';
			if (isset($yearSet[$month])) {
				$monthSet = $yearSet[$month];
				
				// Прибыль
				$profEth=round(array_sum(array_column($monthSet,'profEth')),4);
				$profEthStyle = ($profEth<0) ? 'danger' : 'success';

				// Прибыль в %
				$profEthPer=round(array_sum(array_column($monthSet,'profEthPer'))/count($monthSet),2);
				$profEthPerStyle = ($profEthPer<0) ? 'danger' : 'success';
 								
				$v=[
					'deals' => count($monthSet).' sales',
					'tradeEthIn'=>round(array_sum(array_column($monthSet,'tradeEthIn')),4).' ETH',
					'profEth'=>'<span class="text-'.$profEthStyle.'">'.$profEth.' ETH</span>',
					'profEthPer'=>'<span class="text-'.$profEthPerStyle.'">'.$profEthPer.' %</span>',
				];
				$v=implode('</br>', array_values($v));
			}
			$monthlyData[$month][$year]=$v;				
		}
	}
	return array_values($monthlyData);	
}

function doReportWebAddRank($arr, $from = 1, $col = 'rank') {
	foreach ($arr as $k=>$v) {
		$arr[$k]['rank'] = $from + $k;
	}
	return $arr;
}


function doReportWeb_adr2base($adr, $lim=40){
	$adr=strtolower($adr);
	if (strlen($adr)>$lim) $adr=substr($adr, (-1*$lim));
	return $adr;
}
?>