<?php
sap_out('read_erc20null:run');
function read_erc20null() {  // Считываем реквизиты токенов erc20 , symbol и decimal из имеющихся транзакций

	# Запрашиваем все нулевые койны
	$coins=db_arrayKey('SELECT id,adr FROM coins WHERE symbol IS NULL;','adr');

	# Запрашиваем аккаунты в обороте которых есть неизвестные монеты
	$data=db_array('SELECT c.id,c.adr,(SELECT walletId FROM wallets_trades_coins WHERE coin=c.id LIMIT 1) walletId  FROM coins c WHERE c.symbol IS NULL HAVING walletId IS NOT NULL LIMIT 10;');
	
	if (empty($data)) {
		sap_out('.');
		sleep(60);
		return false;
	}
	$wallets=[];
	foreach ($data as $v) {
		if (!in_array($v['walletId'], $wallets)) $wallets[]=$v['walletId'];
	}

	# Перебирая каждый аккаунт > берем из него неопределенные токены и вносим их реквизиты в базу
	foreach ($wallets as $k=>$walletId) {
		$kol=0;
		$walletAdr=db_row('SELECT adr FROM wallets WHERE id='.$walletId.';')['adr'];
		$apiRaw = file_get_contents('d:/uniswap/api_json_files/0x'.$walletAdr.'/erc20.json');
		#$apiRaw = file_get_contents('c:/ftp_data2/OpenServer/domains/mn12/mn/app/bat/uniswap/api_json_files/0x00ad26ac5556ba250b50551a71bbe57b2ba82904/erc20.json');

		$data=json_decode($apiRaw, true);

		foreach ($data['result'] as $tx) {
			$coinAdr=substr($tx['contractAddress'], 2); # из контракта токена первые два символа 0x не берем
			if (isset($coins[$coinAdr])) {
				$kol++;
				#sap_out('coin|'.$coinAdr.'|'.$tx['tokenSymbol']);
				# туду: Вносим реквизиты токена в базу
				$upd=[
					'symbol='.sql_fstr(substr(sql_charsOnlyFromList($tx['tokenSymbol'], 'eng'),0,19)),
					'decimals='.sql_fnum($tx['tokenDecimal']),
					'coinName='.sql_fstr(substr(sql_charsOnlyFromList($tx['tokenName'], 'eng'),0, 99)),
				];
				$sql='UPDATE coins SET '.implode(', ',$upd).' WHERE id='.$coins[$coinAdr]['id'].';';
				#sap_out ($sql);
				db_request($sql);
				# Убираем текущий токен из неизвестных
				unset($coins[$coinAdr]);
			}
		}
		sap_out($k.'|'.$walletId.' +'.$kol);
	}
	return true;
}

function sql_charsOnlyFromList($mp, $chars = 'dig', $add = '')
{    // Перебирает строчку на входе и возвращает из нее только те символы которые указаны ws 0 -ток цифры , 1 ток eng , 2 ток рус
    /*
$v=sql_charsOnlyFromList($float,'dig',',.');  # Только цифры и знаки ,.
$v=sql_charsOnlyFromList($str,'abcde');       # Только abcde и все
*/
    $def = [
        'lib' => [
            'dig' => '0123456789',
            'eng' => 'qwertyuiopasdfghjklzxcvbnmQWERTYUIOPASDFGHJKLZXCVBNM',
            'rus' => 'йцукенгшщзхъфывапролджэячсмитьбюЙЦУКЕНГШЩЗХЪФЫВАПРОЛДЖЭЯЧСМИТЬБЮ',
            'sig' => '~!@#$%^&*()_+=-`.,?:"\';{}[]|/<>\\ ',
        ],
    ];

    # Если вызов идет в упращенном виде
    if (!is_array($mp)) {
        if (isset($def['lib'][$chars])) {
            $mp = ['str' => $mp, 'chars' => $chars, 'add' => $add];
        } else {
            $mp = ['str' => $mp, 'list' => $chars];
        }
    }

    if (!empty($mp['returnList']) && isset($def[$mp['returnList']])) return $def[$mp['returnList']];
    # sql_charsOnlyFromList(['returnAll'=>1]);
    if (!empty($mp['returnAll'])) return $def['lib'];

    $mp = array_merge($def, $mp);

    if (!empty($mp['chars']) && isset($mp['lib'][$mp['chars']])) $list = $mp['lib'][$mp['chars']];
    if (!empty($mp['list'])) $list = $mp['list'];
    if (!empty($mp['add'])) $list .= $mp['add'];

    $list = str_split($list);

    $str = $mp['str'];
    $str .= '';
    $out = '';
    for ($i = 0; $i < strlen($str); $i++) {
        if (in_array($str[$i], $list)) $out = $out . $str[$i];
    }
    return $out;
}