<?php
require 'CdEncoder/CdEncoder.php';

$r = new ReflectionClass('CdEncoder\CdEncoder');
$enc = $r->getMethod('rsEncodeChunk');
$enc->setAccessible(true);
$rec = $r->getMethod('rsRecoverSingleError');
$rec->setAccessible(true);
$sy = $r->getMethod('rsSyndromes');
$sy->setAccessible(true);

$data = str_repeat("\x01", 223);
$code = $enc->invoke(null, $data, 32);
$vals = array_values(unpack('C*', $code));
$corrupted = $vals;
$corrupted[44] ^= 1;

$trial = $rec->invoke(null, $corrupted, 32);
var_dump($trial !== null ? 'fixed' : 'null');
if ($trial !== null) {
    echo 'same=' . (($trial === $vals) ? '1' : '0') . PHP_EOL;
    for ($i = 0; $i < count($trial); $i++) {
        if ($trial[$i] !== $corrupted[$i]) {
            echo 'first_diff=' . $i . ':' . $corrupted[$i] . '->' . $trial[$i] . PHP_EOL;
            break;
        }
    }
    $s = $sy->invoke(null, $trial, 32);
    echo 'syndrome_zero=' . (count(array_unique($s)) === 1 && $s[0] === 0 ? '1' : '0') . PHP_EOL;
    echo 'synd_first8=' . implode(',', array_slice($s, 0, 8)) . PHP_EOL;
}
