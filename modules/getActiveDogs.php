<?php
// Функции напрямую связанные с OS (Linux/Windows)
// Тут пока для Linux
function getActiveDogs() {return true;}
function getActiveDogsInDB() {
	# Получаем id и pid всех активных собак согласно таблице в базе
	$dogsDb = db_arrayKey('SELECT id,dogPid,waits,toRest FROM wallets_dogs WHERE isAct=1 and dogPid>0;');

	$activeDogsInDB = [];
	foreach ($dogsDb as $k=>$v) {
		$dogId = $k;
		if (!empty($activeDogsInDB[$v['dogPid']])) {
			# У нас уже есть такой pid > оставляем тот id который больше новый или старый
			$dogId = ($k > $activeDogsInDB[$v['dogPid']]) ? $k : $activeDogsInDB[$v['dogPid']];
		}
		$activeDogsInDB[$v['dogPid']] = $dogId;
	}	
	
	foreach ($activeDogsInDB as $dogPid=>$dogId) {
		$activeDogsInDB[$dogPid] = $dogsDb[$dogId];
	}
	
	return $activeDogsInDB;
}

function getActiveDogsInSystem($dogs) {
	
	$unknownDogs = [];
	$waitDogs = [];
	foreach (array_keys($dogs) as $processName) $waitDogs[$processName] = [];
	
	$activeDogsInDB=getActiveDogsInDB();

	# Возвращает список запущеных в системе собак
	$activeDogsInSystemByProcess = [];
	
	# Получаем список процессов в системе processPid => processName
	$sytemProcess = os_getSytemProcess();
	
	foreach ($sytemProcess as $processPid=>$processName) {
		if (isset($dogs[$processName])) {
			
			# Если имя процесса совпадает с командой пастуха > добавляем его Id в список
			# Если у обнаруженного процесса есть номер в базе > указываем его 
			$dogId = (isset($activeDogsInDB[$processPid])) ? $activeDogsInDB[$processPid]['id'] : 0;
			
			if (empty($dogId)) {
				# Если собака без лицензии (ее pid нет в базе) вносим ее в список на kill из списка процессов
				$unknownDogs[] = $processPid;
			} else {
				# Если собака с лицензией (есть в базе)
				$activeDogsInSystemByProcess[$processName][$processPid]=$dogId;
				if ($activeDogsInDB[$processPid]['waits']>1) $waitDogs[$processName][] = $dogId;
			}
			
		}		
	}		
	
	return [$activeDogsInSystemByProcess, $waitDogs, $unknownDogs];
}