<?php
function get_accounts() {
	$hist=db_row('SELECT count(*) kol FROM uniswaplast;')['kol'];
	echo '!';
	// Если нет данных > запрос не выполняется > false
	if (empty($hist)) return false; 
	
	// sap_out($coin['adr'].' get coin');
	$tabName = 'uniswaplast';
	// Создаем пустую таблицу
	db_request('DROP TABLE IF EXISTS tmpl_'.$tabName.';');	
	db_request('CREATE TABLE tmpl_'.$tabName.' LIKE '.$tabName.';');	

	// Меняем таблицы местами (рабочую с данными забираем к себе по префикс tmpl_ а вместо рабочей ставим пустую под наполнение до след цикла
	renameTable($tabName,'tmpl_'.$tabName);

	$before=db_row('SELECT count(*) kol FROM wallets;')['kol'];

	// Мы имеем в таблице tmpl_uniswaplast срез по последним заявкам на юнисвопе
	// 1. Вставляем новые адреса в таблицу wallets	
	db_request('INSERT INTO wallets (adr,txLastAt) SELECT `from`,timeStamp FROM tmpl_'.$tabName.' as t ON DUPLICATE KEY UPDATE txLastAt = t.timeStamp;');		

	$now=db_row('SELECT count(*) kol FROM wallets;')['kol'];

	// 2. После вставки присваиваем accountId , чтобы вести компактную историю обращений аккаунта к юнисвопу 
	db_request('UPDATE wallets w,tmpl_'.$tabName.' t SET t.accountId = w.id WHERE w.adr = t.`from`;');
	
	// 3. Вставляем accountId,timeStamp в таблицу wallets_uniswap_history
	db_request('INSERT IGNORE INTO wallets_uniswap_history (accountId,timeStamp) SELECT accountId,timeStamp FROM tmpl_'.$tabName.';');		

	db_request('DROP TABLE IF EXISTS tmpl_'.$tabName.';');

	sap_out('hist +'.$hist.',new +'.($now-$before));

	return true;
}

function renameTable($tab1,$tab2){ // Меняем таблицы местами (меняя их имена) , $tab1 на $tab2 а $tab2 на $tab1
	db_request('DROP TABLE IF EXISTS '.$tab2.'_backup;'); 
	db_request('RENAME TABLE '.$tab1.' TO '.$tab2.'_backup, '.$tab2.' TO '.$tab1.';'); 
	db_request('RENAME TABLE '.$tab2.'_backup TO '.$tab2.';'); 
	//db_request('DROP TABLE IF EXISTS '.$tab2.'_backup;'); 
}