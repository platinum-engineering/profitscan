<?php define('FILE', __FILE__);     # Точка входа

/*
Пастух собак.
Бесконечный цикл с тактом $sleepStep, смотрит сколько процессов собак в системе и их состояние (в работе/свободна).
Если есть работа и собак меньше лимитов и память системы еще позволят, то запускает +1. (одну за одной).
Чистит старые записи, отдает другим собакам те работы где назначенные ранее собаки пропали.
*/ 

# Стартуем мини сапиенс 
# если один вверх ../ то -1 если два ../../ то -2
define('DR', implode('/', array_slice(explode('/', str_replace('\\', '/', __DIR__)), 0, -1))); 

# Просмотр операции по номеру
$config_app = [
    'load_modules' => [             
		'os'			=>	'modules',
		'getActiveDogs' =>	'modules',
	],
];

require(DR.'/sap_light/sap_loader.php');

db_request('USE dune');

# Подгружаем установки пастуха
require(__DIR__.'/watchDogs_sets.php');

while(1) {
	
	# Работы по собакам
	$activeDogsInSystem = doDogsGrazing($dogs); 

	# Актуализируем кол-во статусы и последнее время для активных собак
	db_request('UPDATE wallets_dogs SET isAct = 0;');
	$activeDogIdsInStr = (!empty($activeDogsInSystem)) ? sql_formatFiledValue_int(array_values($activeDogsInSystem)) : null;
	if (!empty($activeDogIdsInStr)) {
		db_request('UPDATE wallets_dogs SET isAct = 1,trayAt='.time().' WHERE id IN '.$activeDogIdsInStr.';');
	}
	
	echo '[dogs:'.count($activeDogsInSystem).']';

	# Прочие работы каждую минуту 
	$isNextTurnKey = 'everyMin'; 
	$isNextTurn = isNextTurn($isNextTurnKey, $turn);
	if ($isNextTurn) {
		$turn[$isNextTurnKey]['last'] = $isNextTurn;
		# Переставляем в срочную работу , там где закреплена потерявшаяся собака
		if (!empty($activeDogIdsInStr)) {
			db_request('UPDATE wallets SET state=if(isDegen=1,4,2),dogId=0 WHERE dogId>0 AND dogId NOT IN '.sql_formatFiledValue_int(array_values($activeDogsInSystem)).';');
		}
	}
	
	# Прочие работы каждую 5 минуту 
	$isNextTurnKey = 'every5min'; 	
	$isNextTurn = isNextTurn($isNextTurnKey, $turn);
	if ($isNextTurn) {
		$turn[$isNextTurnKey]['last'] = $isNextTurn;
		# Удаляем отработавшие записи по собакам
		db_request('DELETE FROM wallets_dogs WHERE id>10 AND isAct=0;');
	}
	
	$turn['sec'] += $sleepStep;	
	sleep($sleepStep); 
	
}

function isNextTurn($turnKey, $turn){
	if (!isset($turn[$turnKey])) return false;
	$set = $turn[$turnKey];
	$nextLast = floor($turn['sec'] / $set['sec']);
	# Если текущее округление в меньшую сторону до целых больше чем последнее > знатич true c сохранением
	if ($nextLast <= $set['last']) return false;
	echo '[turnKey:'.$turnKey.'/'.$nextLast.']';
	return $nextLast;
}

function doDogsGrazing($dogs) {
	# Функця выпаски собак (мониторинг процессов в системе со сверкой в БД, добавить или убирать процессы в зависимости от нагрузки)
	# Получаем 
	list ($activeDogsInSystemByProcess, $waitDogs, $unknownDogs) = getActiveDogsInSystem($dogs);
	
	# Если у нас есть процессы , собаки которых мы не обнаружиди
	foreach ($dogs as $processName=>$dogLimits) {
		if (empty($activeDogsInSystemByProcess[$processName])) {
			# У нас должны быть собаки но их вообще нет > Запускаем минимально разрешенное кол-во
			for ($i = 0; $i < $dogLimits['min']; $i++) {
				$dogPid = os_runProcess($processName);
				if ($dogPid) echo '[+dogPid:'.$dogPid.']';
			}
		}
	}

	# Убиваем тех собак что нам не известны (без лицензии), их PID нет в базе !!!
	foreach ($unknownDogs as $dogPid) os_killProcess($dogPid); 

	# Решаем увеличить или уменьшить собак для каждого типа процесса  
	foreach ($activeDogsInSystemByProcess as $processName=>$dogsList) {
	
		# Добавляем / убираем собак с поля
		$toRest = count($waitDogs[$processName]);
		
		/*
		ДОБАВЛЯЕМ не более максималки
		если свободных меньше 1

		УБИРАЕМ (до мин+1)
		если свободных больше 1
		*/
		
		if ($toRest > 1) {
			# УБИРАЕМ (до мин+1)
			# Если более 1 собаки бездействуют > пора закрывать некоторые
			$minQuota = count($dogsList) - $dogs[$processName]['min'];	# Квота на сокращение
			if ($minQuota > 0) {
				# Если у нас есть квота на сокращение
				# Есть простаивающих больше чем квота > осталяем стоять в системе тех, кто в квоту не попал
				if ($toRest > $minQuota) $toRest = $minQuota;
				$toRestIds = [];
				for ($i = 0; $i < $toRest; $i++) $toRestIds[]=$waitDogs[$processName][$i];
				# Ставим на завершение  
				db_request('UPDATE wallets_dogs SET toRest = 1 WHERE id IN '.sql_formatFiledValue_int(array_values($toRestIds)).';');
			}
		} else {
			# У нас нет отдыхающих собак (должна быть хотябы одна) и если лимиты позволяют мы добавляем
			# Или у нас собак менее минималки

			if (empty($toRest) || count($dogsList) < $dogs[$processName]['min']) {
				
				if (os_getAvailableMemory() < 200000) {
					# Надо жестко кикнуть любую первую собаку (они тут все в работе)
					# Надо выбрать именно ту у которой самый большой отжор памяти !!! это будет эффективно
					# Всем уйти на покой и надеятся что они успеют это сделать до перегрузки сервера? 
					# Жестко кикнуть первую TODO: ту которая больше всего занимает памяти.
					$outDogPid = array_keys($activeDogsInSystemByProcess[$processName])[0];
					os_killProcess($outDogPid); 
				} else {
					if (count($dogsList) < $dogs[$processName]['max']) {
						$dogPid = os_runProcess($processName);
						if ($dogPid) echo '[+dogPid:'.$dogPid.']';
					}				
				}
				
				
			
			}				

		}
	
	}

	# Собираем всех собак в один набор
	$activeDogsInSystemTotal = [];
	foreach ($activeDogsInSystemByProcess as $dogsList) {
		$activeDogsInSystemTotal = array_merge($activeDogsInSystemTotal, $dogsList);
	} 
	
	return $activeDogsInSystemTotal;
	
}

# -----------
