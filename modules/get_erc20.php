<?php
function get_erc20($row=null) {
	// Скачиваем erc20 транзакции кошелька в очереди с предварительной обработкой 
	// Помечаем выбранный файл как взятый в работу
	if (empty($row)) {
		db_request('UPDATE wallets SET state=1 WHERE state=0 LIMIT 1;');
		echo '^';
		$row=db_row('SELECT id,adr FROM wallets WHERE state=1 LIMIT 1;');		
	}

	// Если нет данных > запрос не выполняется > false
	if (empty($row)) return false; 
	
	$adr='0x'.$row['adr'];
	
	$apiRaw = get_account($adr, 'tokentx');
	
	sap_out($adr. ' get erc20');
	
	// Получаем кол-во транзакций для статистики
    $apiData = json_decode($apiRaw, true);
    
    if (is_array($apiData['result'])) {
		// Промежуточный статус
		db_request('UPDATE wallets SET state=3,stateAt=UNIX_TIMESTAMP(now()) WHERE id='.$row['id'].';');
		$totalTokens=count($apiData['result']);
		echo ' +'.$totalTokens;
		if (!empty($apiData['result'])) {
			$lastActiveAt = intval($apiData['result'][$totalTokens-1]['timeStamp']);
			$firstActiveAt = intval($apiData['result'][0]['timeStamp']);
			// Обновляем статус (выкачено) , время статуса, кол-во транз, время последней транзы
			db_request('UPDATE wallets SET erc20len='.count($apiData['result']).',erc20At=UNIX_TIMESTAMP(now()),firstActiveAt='.$firstActiveAt.',lastActiveAt='.$lastActiveAt.',state=2,stateAt=UNIX_TIMESTAMP(now()) WHERE id='.$row['id'].';');
		}
	}
	
	return true;
}

function get_normal($row=null) {
	$rangeLimit = 86400 * 3; // 3 дня
	$erc20moreThen = 200;
	
	if (empty($row)) {
		// Выкачиваем нормальные транзакции тем: state=2,erc20len>100,erc20At+limit>stateAt ,  выкачены erc20 + у кого последняя активность не позже лимита + число erc20 более 100 86400 - 1 день   and erc20At+'.$rangeLimit.'>stateAt 
		db_request('UPDATE wallets SET state=4 WHERE state=2 and erc20len>'.$erc20moreThen.' LIMIT 1;');
		echo '<';
		$row=db_row('SELECT id,adr FROM wallets WHERE state=4 LIMIT 1;');
	}
	
	// Если нет данных > запрос не выполняется > false
	if (empty($row)) return false; 
	
	$adr='0x'.$row['adr'];
	
	$apiRaw = get_account($adr, 'txlist');
	
	sap_out($adr. ' get txlist');
	
	// Получаем кол-во транзакций для статистики
    $apiData = json_decode($apiRaw, true);
    
    if (is_array($apiData['result'])) {
		// Промежуточный статус
		db_request('UPDATE wallets SET state=5,stateAt=UNIX_TIMESTAMP(now()) WHERE id='.$row['id'].';');
		$totalActions=count($apiData['result']);
		echo ' +'.$totalActions;
	}
	
	return true;
}

function get_internal($row=null) {

	if (empty($row)) {
		// Выкачиваем нормальные транзакции тем: state=2,erc20len>100,erc20At+limit>stateAt ,  выкачены erc20 + у кого последняя активность не позже лимита + число erc20 более 100 86400 - 1 день 
		db_request('UPDATE wallets SET state=6 WHERE state=5 LIMIT 1;');
		echo '>';
		$row=db_row('SELECT id,adr,erc20At FROM wallets WHERE state=6 LIMIT 1;');
	}
	
	// Если нет данных > запрос не выполняется > false
	if (empty($row)) return false; 
	
	$adr='0x'.$row['adr'];
	
	$apiRaw = get_account($adr, 'txlistinternal');
	
	sap_out($adr. ' get txlistinternal');
	
	// Получаем кол-во транзакций для статистики
    $apiData = json_decode($apiRaw, true);
    
    if (is_array($apiData['result'])) {
		// Промежуточный статус
		db_request('UPDATE wallets SET state=7,stateAt=UNIX_TIMESTAMP(now()) WHERE id='.$row['id'].';');
		$totalActions=count($apiData['result']);
		echo ' +'.$totalActions;
		
		// Получаем справочник койнов
		$coins=getCoins();
		
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
		}
		# $balancePageKeys[0] !== '0000000000000000000000000000000000000000'			
		$coins=balance_obj2database($balance, $row['id'], $row['erc20At'], $coins);
		sap_out(' {+'.count($balance).'}');
		
		# Ставим статус что баланс обработан
		db_request('UPDATE wallets SET erc20balance=1 WHERE id='.$row['id'].';');
		
		/*
		
		// Также сразу подкачиваем баланс кошелька с эзерскана
		$balanceUrl=balanceGetUrl($adr);
		$apiRaw = get_account($adr, 'balance', $balanceUrl);
		$apiData = json_decode($apiRaw, true);
		if (isset($apiData['layout'])) {
			$val=1;
			$trm=explode('<tr>', $apiData['layout']);
			if (count($trm)>0) $val=count($trm);
			if ($val>255) $val=255;
			db_request('UPDATE wallets SET erc20balance='.$val.' WHERE id='.$row['id'].';');
		}
		*/
	}
	
	return true;
}

function balanceGetUrl($adr, $page=1){
	return 'https://etherscan.io/tokenholdingsHandler.aspx?&ps=100&f=0&h=0&sort=total_price_usd&order=desc&pUsd24hrs=2500&pBtc24hrs=0.0768&pUsd=2500&fav=&langMsg=A%20total%20of%20XX%20tokenSS%20found&langFilter=Filtered%20by%20XX&a='.$adr.'&p='.$page;
}

function get_emergencyAccount(){
	// Перекачивает все транзакци и баланс в один заход
	// Скачиваем erc20 транзакции кошелька в очереди с предварительной обработкой 
	// Помечаем выбранный файл как взятый в работу
	db_request('UPDATE wallets SET state=11 WHERE state=10 LIMIT 1;');
	echo '(emergency)';
	$row=db_row('SELECT id,adr,erc20At FROM wallets WHERE state=11 LIMIT 1;');
	
	// Если нет данных > запрос не выполняется > false
	if (empty($row)) return false; 
	
	get_erc20($row);
	get_normal($row);
	
	$row=db_row('SELECT id,adr,erc20At FROM wallets WHERE id='.$row['id'].';');
	get_internal($row);
	
	db_request('UPDATE wallets SET state=71 WHERE id='.$row['id'].';');
	
	return true;	
}