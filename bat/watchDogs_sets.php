<?php 
define('availableMemoryLimit', 300000); 

$dogsWatcherFile = 'watchDogs.php';

$dogs = [
	'php -f '.DR.'/bat/dogSmall.php' => [
		'min' => 2,
		'max' => 12,
	],
	'php -f '.DR.'/bat/dogBig.php' => [
		'min' => 2,
		'max' => 6,
	],	
];

$turn = [
	'sec' => 0,
	'everyMin'  => ['last'=>0, 'sec' => 60*1 ], 
	'every5min' => ['last'=>0, 'sec' => 60*5 ], 
];

$sleepStep = 5;