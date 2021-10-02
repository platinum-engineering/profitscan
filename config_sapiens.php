<?php if (!defined('FILE')) die('[FILE]');

// Эзерскан ключ
define('ETHERSCAN_KEY', 'ETHERSCAN_KEY');
define('FD', '/');  // if defolt then just  "/"

// Локальное расположение папки с файлами
$store_path = __DIR__.'/api_json_files';

# Типовые настройки для любых приложений  =============================== 
$config_sapiens=[
	'db'=>[
		'local'=>[	
			'username'  	=> 'username',
			'password'  	=> 'password',
			'host'      	=> 'host',  		      
			'base'		=> 'base',  				
			'db_query'	=>['SET NAMES utf8'], 				
			/*
			'username'  	=> 'root',
			'password'  	=> 'root',
			'host'      	=> 'localhost',     		# ,	 # 192.168.0.12
			'base'		=> 'base',				
			'db_query'	=>['SET NAMES utf8'], 		# SET NAMES "utf-8"  SET CHARSET 'utf8';
			*/
		]
	],
	# Загружаемые модули
	'load_modules'=>[	
		'db'     		 => 	'sap_light/modules',    # Работа с базой данных
	],
	# Минимальный сразу запускаемый модуль - это подключение к базе (его можно выкинуть через $config_app['load_modules']['db_master_link||delete']
	'run_modules'=>[
		'db_master_link' => '',							
	],
];