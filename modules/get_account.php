<?php
function get_account($adr, $action, $apiUrl = false, $path = false) {  // tokentx

	if (!$path) $path = $GLOBALS['store_path'].'/'.$adr;

	$actionFiles = [
		'tokentx'=> 'erc20',
	];

	$fileName = (isset($actionFiles[$action])) ? $actionFiles[$action] : $action;

	$apiFile = $path.'/'.$fileName.'.json';
		
	if (file_exists($apiFile)) unlink($apiFile);
	if (!file_exists($path)) if (!mkdir($path, 0777, true)) die('Can\'t create dir path:'.$path);
	
	// Функция обращается к api и сохраняет его содержимое в текстовом файле
    $apiParams = [
      'module=account',
      'action='.$action,
      'address='.$adr, 	
	  'sort=desc',
	  'offset=10000',
	  'page=1',
      'apikey='.ETHERSCAN_KEY,  // 13113V5BAQZ9ZHTEIDR5Q9FJPFQXWSBV6R    YourApiKeyToken
    ];

	sap_out($action.'|'.$adr);

    if (!$apiUrl) $apiUrl = 'https://api.etherscan.io/api?'.implode('&',$apiParams);

	$apiRaw = file_get_contents_curl($apiUrl);    // file_get_contents
	file_put_contents($apiFile, $apiRaw);
	
	return $apiRaw;
}


function file_get_contents_curl( $url ) {

  $ch = curl_init();

  curl_setopt( $ch, CURLOPT_AUTOREFERER, TRUE );
  curl_setopt( $ch, CURLOPT_HEADER, 0 );
  curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
  curl_setopt( $ch, CURLOPT_URL, $url );
  curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, TRUE );

  $data = curl_exec( $ch );
  curl_close( $ch );

  return $data;

}