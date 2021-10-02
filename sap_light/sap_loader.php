<?php	if (!defined('FILE')) die('[FILE]');

// Загрузчик подключает настройки + запускает необходимые модули и соединяется с базой данных
// есть два типа настроек $sap['sets']=['app'=>'','sap'=>''] , 
// app это кастомные изменения для запускаемого процесса их может и не быть
// sap это постоянные настройки приложения 


# sap_light всегда лежит с приложением в одной папке , поэтому документ рут всегда __DIR__ минус последняя папка
if (!defined('DR')) define('DR', implode('/', array_slice(explode('/', str_replace('\\', '/', __DIR__)), 0, -1))); 

define('NR', '								
');

#$t = implode('/', array_slice(explode('/', str_replace('\\', '/', __DIR__)), 0, -1)); 
#die('|'.FD.'|');

if (!isset($config_sapiens)) require(DR.'/config_sapiens.php');

if (!empty($config_app)) $config_sapiens=sap_mergeObjDeep($config_sapiens, $config_app);

$sap = $config_sapiens;

# До определяем константы если они не были определены в настройках
if(!defined('DR')) 		define('DR', $_SERVER['DOCUMENT_ROOT']); 	# Корень

# Загрузка модулей
if (!empty($sap['load_modules'])) {
    $sap['loaded_modules'] = [];                                # Уже загруженные модули
	foreach ($sap['load_modules'] as $modulName => $modulPath) {
		if (!isset($sap['loaded_modules'][$modulName])) {        	# Если мы текущий модуль еще не загружали
			$filePath=DR.'/'.$modulPath.'/'.$modulName.'.php';
			if (!file_exists($filePath)) die('{"error":"Loading module not found: '.$filePath.'."}');
			if (function_exists($modulName)) continue;	
			require_once($filePath);                				# Не загружена и файл есть > грузим
			$sap['loaded_modules'][$modulName] = $modulPath;        # Добавляем тек модуль в загруженные	
		}
		unset($sap['load_modules'][$modulName]);               
	}
}

# Выполнение модулей
if (!empty($sap['run_modules'])) {
	foreach ($sap['run_modules'] as $modulName => $modulSet) {
		if (!function_exists($modulName)) die('{"error":"Runing module not found: '.$modulName.'."}');
		$sap=$modulName($sap, $modulSet);
	}
}

# Обязательный набор функций
function sap_mergeObjDeep($target, $source)
{

	// Глубокое слияние асоц массивов c возможностью удалить , заменить или добавить к целевому
	$modes=['merge', 'delete', 'replace', 'append'];
	
	if (empty($target)) $target=[];

	//for (let key of Object.keys(source)) {
	foreach (array_keys($source) as $key) {
		
		$keym = explode('||', $key);
		$targetKey = $keym[0];
		$mode = 'merge';   // merge  , delete , replace 

		if ($targetKey != $key) {
			if (in_array($keym[1],$modes)) $mode = $keym[1];	// Если режим валидные, берем его 
			$source[$targetKey] = $source[$key];
			unset($source[$key]);
		}

		if ($mode === 'delete') {
			unset($target[$targetKey]);
			unset($source[$targetKey]);
		} else {
			if (is_array($source[$targetKey]) && isset($target[$targetKey])) { 
				if ($mode === 'replace') $target[$targetKey]=$source[$targetKey];
				if ($mode === 'merge') $target[$targetKey]=sap_mergeObjDeep($target[$targetKey], $source[$targetKey]);
				if ($mode === 'append') $target[$targetKey]=array_merge($target[$targetKey], $source[$targetKey]);
			} else {
				// Если значение не массив устанавливаем итоговым новое
				$target[$targetKey]=$source[$targetKey];
			}					
		}

	}
	return $target;
}

function sap_out($text, $microtimer = 0 ){
	$time=''; if (!empty($microtimer)) $time='('.round((microtime(true)-$microtimer),2).'sec) ';
	
	$dogId = (empty($GLOBALS['dogId'])) ? 0 : $GLOBALS['dogId'] ;
	
	if (empty($_GET['mode'])) echo(NR.date("Y.m.d H:i:s").'|dog:Id='.$dogId.'pid='.getmypid().'|'.$time.$text); 
}