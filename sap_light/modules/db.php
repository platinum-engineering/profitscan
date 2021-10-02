<?php 
function db_master_link ($sap) {

	# [1] Выбираем по каким реквизитам соединиться с главной базой данных (явные , локальные или продакт)
	# Если нет никаких реквизитов соединений с базой данных
	if (empty($sap['db'])) die('db settings not found but db functional was included in this project, pls fix it');

	$dbkey = array_keys($sap['db'])[0];		
	
	$sap['db_key']=$dbkey;
	$sap['db_base']=$sap['db'][$dbkey]['base'];
	
	# [2] Соединяемся с базой данных с указанием ресурсного соединения  $sap['db'][$sap['db_key']]['link']
	$sap['db'][$dbkey]['link'] = db_link($sap['db'][$dbkey]);	# Прописываем соединение в реквизитах
		
	# [4] Если у нас есть разрешение читать главную базу данных мы считываем структуру ее таблиц, линк соединения не указываем т.к. берется последний а он и есть основной
	if (!empty($sap['db'][$dbkey]['db_read'])) $sap['db'][$dbkey]['db_read']=db_read_selected(['a'=>$sap['db'][$dbkey]['db_read']]);
	
	return $sap;
}

# Соединение с базой данных
function db_link($db_connm)
{

    if (!is_array($db_connm['username'])) {
        $link = mysqli_connect($db_connm['host'], $db_connm['username'], $db_connm['password']);
        $mysql_err = mysqli_connect_error();
        if (!$link) {
            #print_r($db_connm);
            die("Не удалось подключиться к серверу MySQL<br />" . mb_convert_encoding($mysql_err, "utf-8", "windows-1251"));
        }
    } else {
        # Блок когда пробуем подключаться к базе через список аккаунтов перебирая их пока не получим ответ. 
        # Используется когда число подключений к бд ограниченно хостером по ряду причин или в иных случаях
        /*
		# Этот код не рабочий надо исправить согласно логике выше.
		$x=0; while ($x<count($sas_db['username'])) {
			$link = @mysql_connect($sas_db['host'], $sas_db['username'][$x], $sas_db['password'][$x]);		$mysql_err = mysqli_connect_error();
			if ($link) { header("u1: ".$sas_db['username'][$x]); $x=count($sas_db['username']); }
			$x++; 
		}
		if (!$link) die("Не удалось подключиться к серверу MySQL<br />" . $mysql_err);
		*/
    }

    mysqli_select_db($link, $db_connm['base']);
    # [3] Выполняем обязательные первые запросы сразу первыми после коннекта при соединении с главной базой данных
    if (isset($db_connm['db_query'])) foreach ($db_connm['db_query'] as $q) mysqli_query($link, $q);

    return $link;
}

// Получение строки из таблицы (в виде ассоциативного массива)
function db_row($mp) // db_row(['q' => $mp, 'l'=>'link'])
{
    if (is_string($mp)) $mp = ['q' => $mp];        # Совместимость для классчиеских простых запросов

    $link = $GLOBALS['sap']['db'][$GLOBALS['sap']['db_key']]['link'];
    
	if (!empty($mp['l'])) $link = $mp['l'];

    $ret = mysqli_query($link, $mp['q']);

    if (!empty(mysqli_error($link))) db_error_log(['sql' => $mp['q'], 'err' => mysqli_error($link)]);    # Обработка ощибки

    if (!empty($ret)) return mysqli_fetch_assoc($ret);
    return false;
}
# Возвращает типовой sql массив но с ключем по указанному столбцу
function db_arrayKey($mp, $key = 'id')
{
    if (is_string($mp)) $mp = ['q' => $mp];
    $mp['arrayKey'] = $key;
    return db_array($mp);
}
// Получение массива строк, каждый элемент массива - ассоциативный массив с информацией из одной строки
function db_array($mp)
{
    if (is_string($mp)) $mp = ['q' => $mp];        # Совместимость для классчиеских простых запросов
    $link = $GLOBALS['sap']['db'][$GLOBALS['sap']['db_key']]['link'];
    if (!empty($mp['l'])) $link = $mp['l'];

    $ret = mysqli_query($link, $mp['q']);

    if (!empty(mysqli_error($link))) db_error_log(['sql' => $mp['q'], 'err' => mysqli_error($link)]);    # Обработка ощибки

    $res = [];
    if (!empty($ret)) {
        while ($row = mysqli_fetch_assoc($ret)) {
            if (empty($mp['arrayKey'])) {
                $res[] = $row;
            } else {
                $key = $mp['arrayKey'];
                if (empty($row[$key])) {
                    $key = array_keys($row)[0];
                }
                $res[$row[$key]] = $row;
            }
        }
    }

    return $res;
}

// Получение результата выполнения из MySQL
function db_result($mp)
{
    if (is_string($mp)) $mp = ['q' => $mp];        # Совместимость для классчиеских простых запросов

    $link = $GLOBALS['sap']['db'][$GLOBALS['sap']['db_key']]['link'];
    if (!empty($mp['l'])) $link = $mp['l'];

    $ret = mysqli_query($link, $mp['q']);

    if (!empty(mysqli_error($link))) db_error_log(['sql' => $mp['q'], 'err' => mysqli_error($link)]);    # Обработка ощибки

    if (!empty($ret)) {
        if (!is_array($ret)) {
            $data = $ret;
        } else {
            while ($row = mysqli_fetch_row($ret)) {
                $data[] = $row;
            }
        }
        $k = mysqli_affected_rows($GLOBALS['sap']['db'][$GLOBALS['sap']['db_key']]['link']);
        return ['r' => $data, 'k' => $k];
    } else return false;
}

// Отправка произвольного запроса в MySQL, возвращает true / false
function db_request($mp)
{
    if (is_string($mp)) $mp = ['q' => [$mp]];        # Совместимость для классчиеских простых запросов
    if (is_string($mp['q'])) $mp['q'] = [$mp['q']];

    $link = $GLOBALS['sap']['db'][$GLOBALS['sap']['db_key']]['link'];
    if (!empty($mp['l'])) $link = $mp['l'];

    foreach ($mp['q'] as $key => $sql) {
        $ret = mysqli_query($link, $sql);
        if (!empty(mysqli_error($link))) db_error_log(['sql' => $sql, 'err' => mysqli_error($link)]);    # Обработка ошибки
    }

    if (!empty($ret)) return true;
    else return false;
}
// Вставка строки в таблицу, возвращает id вставленной строки
function db_insert($mp)
{
    if (is_string($mp)) $mp = ['q' => $mp];        # Совместимость для классчиеских простых запросов

    $link = $GLOBALS['sap']['db'][$GLOBALS['sap']['db_key']]['link'];
    if (!empty($mp['l'])) $link = $mp['l'];

    $ret = mysqli_query($link, $mp['q']);

    if (!empty(mysqli_error($link))) db_error_log(['sql' => $mp['q'], 'err' => mysqli_error($link)]);    # Обработка ощибки

    $id = mysqli_insert_id($GLOBALS['sap']['db'][$GLOBALS['sap']['db_key']]['link']);

    if (!empty($ret)) return $id;
    else return false;
}

function db_error_log($mp)
{
    #$query=addslashes(stripslashes(trim($query)));
    #$err=addslashes(stripslashes(trim($err)));
    #db_error_log(['sql'=>$query,'err'=>$err]);

    # Если таблица пользователей не найдена + у нас попытка авторизации > пробуем ребутнуть базу для запуска
    if (strpos($mp['err'], "scrm_users' doesn't exist") !== false) {
        if (isset($_POST['sau_login']) && isset($_POST['sau_pass'])) {
            header('Location: ' . FD . '/?sapage=dbcheck_first&pass=pass');
            die();
        }
    }

    $o = ['sql' => $mp['sql'], 'ef' => ['sap_notice' => ['t' => $mp['err'], 'c' => 3, 'h' => 100]]];

    die(json_encode($o));    # Вывод ошибки.
}

function db_ObjToWhere($obj, $i = false)
{  # db_ObjToWhere($obj,',') для update
    if ($i === false) $i = ' AND ';
    # Соединяет ключи и значения асоц массива в строку аналогию: для $i=' AND ' для where выражения , $i=',' для SET выражения
    $s = [];
    foreach ($obj as $k => $v) $s[] = $k . '=' . $v;
    return implode($i, $s);
}

function sql_formatFiledValue_int($val)
{
    if (is_array($val)) {
        $cvalm = [];
		foreach ($val as $cval) {
			$fval = sql_fnum($cval);
			$isAdd = (!empty($cval) && empty($fval)) ? 0 : 1;
			if ($isAdd) $cvalm[] = $cval;
        }
        return '(' . implode(',', $cvalm) . ')';
    }
	return sql_fnum($num);
}

function sql_formatFiledValue_string($val)
{
    if (is_array($val)) {
        foreach ($val as $cval) $cvalm[] = sql_fstring($cval);
        return "(" . implode(',', $cvalm) . ")";
    } else {
        return sql_fstring($val);
    }
}

function sql_formatFiledValue_date($dateStr)
{
    $dateStrFormat = sql_charsOnlyFromList($dateStr, $chars = 'dig', $add = '-.');
    if (strlen($dateStrFormat) != strlen($dateStr)) return false;
    $isPointSep = sql_charsOnlyFromList($dateStrFormat, '.');
    $sep = '-';
    if (strlen($isPointSep) == 2) $sep = '.';

    $m = explode($sep, $dateStr);
    if (count($m) != 3) return false;
    # У нас строчка по шаблону похожая на дату с разделителем $sep, нам надо ее представить в формате YYYY-MM-DD
    foreach ($m as $k => $v) $m[$k] = intval($v);
    if (checkdate($m[1], $m[2], $m[0])) return $m[0] . '-' . $m[1] . '-' . $m[2];
    if (checkdate($m[1], $m[0], $m[2])) return $m[2] . '-' . $m[1] . '-' . $m[0];
    if (checkdate($m[0], $m[1], $m[2])) return $m[2] . '-' . $m[0] . '-' . $m[1];
    return false;
}

function sql_fstring($str, $isHtmlTags = 0)
{
    return sql_fstr($str, $isHtmlTags);
}
function sql_fnumeric($num)
{
    return sql_fnum($num);
}

function sql_fstr($str, $isHtmlTags = 0)
{
    if (is_array($str)) {
        if (empty($str)) return 'null';
        $str = json_encode($str, JSON_UNESCAPED_UNICODE);
    }
    if ($str === 'null') return 'null';
    if (empty($isHtmlTags)) $str = strip_tags($str); # Срезаем тэги пока не будет полноценного решения для безопасного html вывода
    #$t=$str;
    #$v="'" . mysqli_real_escape_string($GLOBALS['sap']['db'][$GLOBALS['sap']['db_key']]['link'], $str) . "'";
    #if (strpos($str, 'Denis') !== false) eval(dd('[$str,$v]'));
    return "'" . mysqli_real_escape_string($GLOBALS['sap']['db'][$GLOBALS['sap']['db_key']]['link'], $str) . "'";
}

function sql_fnum($num)
{
    $v = 0;
    $float = str_replace(',', '.', $num);
    if (is_numeric($float)) $v = $float;
    return $v;
}

function sql_fnumDiapazon($num, $min, $max)
{
    $num = sql_fnum($num);
    if (is_numeric($min) && $num < $min) $num = $min;
    if (is_numeric($max) && $num > $max) $num = $max;
    return $num;
}
