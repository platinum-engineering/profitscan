<?php define('FILE', __FILE__);     # Точка входа
	
// Спсок функций выполняемых одна за другой
//$loopFuncList=explode(',', 'get_erc20,get_normal,get_internal'); //   ,get_сoin read_erc20null

$loopFuncList=[
	#'get_accounts'			=> [0, 60*90],  	// 1 раз в 60сек * 90 = 90 мин
	#'get_erc20' 			=> [0, 1],			// Каждую секунду
	'doReportFromTableBig'	=> [0, 1],			// Каждую секунду
];

require(__DIR__.'/dogLoop.php');
?>