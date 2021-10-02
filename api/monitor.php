<?php define('FILE', __FILE__);     # Точка входа
/*
Json сводные данные о работе сервера :
*/
header('Content-Type: application/json');

require_once('../config_sapiens.php');   		

# Просмотр операции по номеру
$config_app = [
    'load_modules' => [             
		'os'			=>	'modules',
		#'getActiveDogs' =>	'modules',
	],
];

# Стартуем мини сапиенс 
require('../sap_light/sap_loader.php');	

# Подключаемся к установкам пастуха
require_once('../bat/watchDogs_sets.php'); 

$reportsInWaitingsDb = db_row('SELECT count(*) kol FROM wallets WHERE state!=3;');

# Получаем список процессов в системе processPid => processName
$sytemProcess = os_getSytemProcess();

$dogsOnline = [];
$watcherOnline = [];

# Подсчитываем кол-во собак в работе и наличие кассира
foreach ($sytemProcess as $processPid=>$processName) {
	$processNameM = explode('/', $processName);
	if (isset($dogs[$processName])) $dogsOnline[$processPid]= $processNameM[count($processNameM)-1];
	if (stripos($processName, $dogsWatcherFile) !== false) $watcherOnline[]=$processPid;
}

# Получаем список 
$rezult = [
	'watcherOnline'		=>	$watcherOnline,	#'Pidы запущенных пастухов',
	'dogsOnline'		=>	$dogsOnline,	#'Pidы запущенных собак',
	#'Кол-во не готовых отчетов state!=3',
	'reportsInWaitings'	=>	(isset($reportsInWaitingsDb['kol'])) ? intval($reportsInWaitingsDb['kol']) : -1,	
	# Кол-во свободной памяти в килобайтах
	'availableMemory'	=> os_getAvailableMemory(),
];

die(json_encode($rezult, JSON_UNESCAPED_UNICODE));
?>