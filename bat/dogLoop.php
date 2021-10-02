<?php	if (!defined('FILE')) die('[FILE]');

# На входе требуется $loopFuncList

if (empty($loopFuncList)) die('[There are not function for looping.]');

# Просмотр операции по номеру
$config_app = [
    'load_modules' => [
		'eth'				=>	'modules',	
		'get_account'		=>	'modules',	
		'getCoins'			=>	'modules',
		
		'doReport'			=>	'modules',
		'doReportFromTable'	=>	'modules',
		'dog'				=>	'modules',
	],
];

# Стартуем мини сапиенс 
#require('../sap_light/sap_loader.php');	
# если один вверх ../ то -1 если два ../../ то -2
require(implode('/', array_slice(explode('/', str_replace('\\', '/', __DIR__)), 0, -1)).'/sap_light/sap_loader.php');
		
// Бесконечный цикл обращения к api ресурса с учетом лимита времени между запросами и очереди функций

db_request('USE dune');
$mode = 'loop';

$deepSleep = 5;	# Если 30 раз набрался сон без списка задач > спим больше
$deepSleepCount = 0;
$sleepInSec = 1;

# Создаем собаке новый номер
$dogId=dogGetId();

while ($mode === 'loop') {

	// Перебираем все модули и создаем очередь которые надо запустить уже сейчас и запускаем их
	$runList = [];
	foreach ($loopFuncList as $k=>$v) {
		if (microtime(true) > ($v[0]+$v[1])) $runList[]=$k;	// Ставим в очередь на запуск
	} 
	
	$lastEmpty=0;
	
	if (!empty($runList)) {
		foreach ($runList as $func) {
			$oldWaits = dogGet($dogId)['waits'];		# Запоминаем старое состояние
			dogSet(['waits' => 0], $dogId);				# Сбрасываем счетчик отдыха (надеямся что работа будет, если нет то быстро вернется)
			$startAt = time();
			$rez = $func();								// Запускаем функцию
			$loopFuncList[$func][0] = microtime(true);	// Фиксируем время запуска
			if (!$rez) {
				$lastEmpty++;
				if (!empty($oldWaits)) dogSet(['waits' => $oldWaits], $dogId);	# Сбрасываем счетчик отдыха (надеямся что работа будет, если нет то быстро вернется)
			}

			# Если собака отработала более двух минут (условно большой аккаунт) то завершаем ее для уверенного сброса лимита памяти
			if ($rez && (time() - $startAt) > 60*2) die();	
		}
	}
	
	// Если список задач пуст > отдых 1 сек
	if (empty($runList) || $lastEmpty===count($runList)) {
		# Если собаку определили на покой > уходим тут красиво
		if (!empty(dogGet($dogId)['toRest'])) die();
		echo '('.$sleepInSec.')';
		sleep($sleepInSec);
		$deepSleepCount++;
	} else {
		$sleepInSec = 1;
		$deepSleepCount = 0;
	}
	
	if ($deepSleepCount>$deepSleep) {
		$sleepInSec = $deepSleep;
		# У нас целиком простой по всем задачам 
		dogSet(['waits' => 'if(waits<200,waits+1,waits)'], $dogId);		
	} 
}


?>