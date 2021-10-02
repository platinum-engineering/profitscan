<?php 

function dog(){ return true;}

function dogGetId() { # loopGetDogId
	# Получает текущий номер собаки (если есть в глобалке)
	$dogId = (empty($GLOBALS['dogId'])) ? 0 : $GLOBALS['dogId'] ;

	if (empty($dogId)) {
		# Заводим новый номер собаке
		$dogId=dogCreateNew();
	} else {
		# Проверка статуса номера собаки
		$dogDB=db_row('SELECT * FROM wallets_dogs WHERE id = '.sql_fnum($dogId).';');
		# Если номер не активен , заводим следующий
		if (empty($dogDB['isAct'])) $dogId=dogCreateNew();
	}
	
	# Кидаем номер собаки в глобалку
	$GLOBALS['dogId'] = $dogId;
	
	return $dogId;
}
	
function dogCreateNew() { 
	$pid = getmypid();
	echo '[mypid:'.$pid.']';
	db_request('update wallets_dogs set isAct=0 where dogPid='.$pid.';');
	return db_insert('INSERT INTO wallets_dogs (startAt,isAct,dogPid) VALUES ('.time().',1,'.$pid.');');
}

function dogGet($dogId){
	return db_row('select * from wallets_dogs where id='.$dogId.';');	
}

function dogSet($set, $dogId){
	# waits='.$sqlSetWaits.
	if (empty($set)) return false;
	$setSql=[]; 
	foreach ($set as $col=>$val) $setSql[] = $col.'='.$val;
	db_request('update wallets_dogs set lastAt='.time().','.implode(', ', $setSql).' where id='.$dogId.';');
	return true;	
}