<?php 
	
// Прокси версия api gateway
$errCase = [
	'wallet' => $_GET['wallet'],
	'err' => 1,
	'title' => 'Empty result, press check again.'
];

if ($_GET['mode'] == 'csv') {
	
	$csvFileName = 'report_'.$_GET['wallet'].'.csv';
	
	header("Content-type: text/csv");
	header("Content-Disposition: attachment; filename=".$csvFileName);
	header("Pragma: no-cache");
	header("Expires: 0");

	// force download
	#header("Content-Type: application/force-download");
	#header("Content-Type: application/octet-stream");
	#header("Content-Type: application/download");

	// Пытаемся получить csv https://i.888defi.biz/api_json_files/csvs/report_0x93baddc9001663ecf87af34d22a28679824683fa.csv
 	
	die(file_get_contents('https://i.888defi.biz/api_json_files/csvs/'.$csvFileName)); 	
	
} 

# Json для всего остального
header('Content-Type: application/json');

$raw = file_get_contents('https://i.888defi.biz/api/doReportWeb.php?wallet='.$_GET['wallet'].'&mode='.$_GET['mode']); 
	
// Если пришел ответ , но это не json > 99% ошибка 
$jsonObj = json_decode($raw, true);	
if (!empty($raw) && $jsonObj === null && json_last_error() !== JSON_ERROR_NONE) { //
	$errCase['err'] = 2;
	$errCase['title'] = 'Some server error.';
	$raw = null;
}

if (empty($raw)) $raw=json_encode($errCase, JSON_UNESCAPED_UNICODE);	

die($raw);