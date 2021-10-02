<?php 
function charsOnlyFromList($mp, $chars = 'dig', $add = '') {    // Перебирает строчку на входе и возвращает из нее только те символы которые указаны ws 0 -ток цифры , 1 ток eng , 2 ток рус

    /*
$v=charsOnlyFromList($str,'dig',',.');  			# Только цифры и знаки ,.
$v=charsOnlyFromList($str,'abcde');       			# Только abcde и все
$v=charsOnlyFromList($str,'eng','0123456789');  	# Только англбуквы и цифры
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