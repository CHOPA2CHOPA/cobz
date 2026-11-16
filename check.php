<?php
$need = ['curl','pdo_sqlite','mbstring','dom','simplexml','gd','fileinfo','openssl'];
printf("PHP %s (%s)\nini: %s\n", PHP_VERSION, PHP_OS, php_ini_loaded_file());
foreach ($need as $e) printf("%-12s %s\n", $e, extension_loaded($e) ? 'OK' : 'НЕТ');

$cfg = json_decode((string)@file_get_contents(__DIR__ . '/config.json'), true);
echo 'config.json  ' . (is_array($cfg) ? 'OK' : 'ОШИБКА JSON (BOM/кодировка?)') . "\n";

foreach (['tmpDir','imgPath'] as $k) {
    $d = $cfg[$k] ?? '';
    if ($d !== '' && !is_dir($d)) @mkdir($d, 0755, true);
    printf("%-12s %s\n", $k, ($d !== '' && is_writable($d)) ? 'запись OK' : 'НЕТ ЗАПИСИ: ' . $d);
}

$ch = curl_init('https://api.wordpress.org/core/version-check/1.7/');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
$r = curl_exec($ch);
echo 'HTTPS/cURL   ' . ($r === false ? 'ОШИБКА: ' . curl_error($ch) : 'OK') . "\n";
