<?php
// Функции напрямую связанные с OS (Linux/Windows)
// Тут пока для Linux

function os() {return true;}

function os_getAvailableMemory() {
	$output=null;
	exec('free', $output);
	$str = preg_replace('/\s+/', ' ', $output[1]);
	$availableMemory = intval(explode(' ',$str)[6]);		# available
	return $availableMemory;
}

function os_killProcess($dogPid) {		
	exec('kill -9 '.$dogPid);	
}

function os_runProcess($dogCmd, $memLimit = availableMemoryLimit) {
		
	if ((os_getAvailableMemory() - 200000) < $memLimit) {
		echo '[NotFreeMem]';
		return false;
	}
	
	$command = 'nohup '.$dogCmd.' > /dev/null 2>&1 & echo $!';
	exec($command ,$op);
    return (int)$op[0];	
}

function os_getSytemProcess() {
	$sytemProcess = [];
	$output=null;
	exec('ps ahxwwo pid:1,command:1', $output);
	foreach ($output as $process) {
		$arr =  explode(' ', $process);
		$processPid = $arr[0];
		unset($arr[0]);
		$processName = implode(' ', $arr);	
		$sytemProcess[$processPid]=$processName;
	}
	return $sytemProcess;
}