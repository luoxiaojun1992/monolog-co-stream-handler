<?php

ini_set('memory_limit', -1);

require_once __DIR__ . '/../vendor/autoload.php';

$log = file_get_contents(__DIR__ . '/Test.log');

$monolog = new Monolog\Logger('test');
$monolog->pushHandler(new \Lxj\Monolog\Co\Stream\Handler(__DIR__ . '/../logs/test.log'));
$start = microtime(true) * 1000;
$monolog->warning($log);
echo 'Coroutine:', (microtime(true) * 1000 - $start), 'ms', PHP_EOL;
swoole_event::wait();

$monolog = new Monolog\Logger('test2');
$monolog->pushHandler(new \Monolog\Handler\StreamHandler(__DIR__ . '/../logs/test2.log'));
$start = microtime(true) * 1000;
$monolog->warning($log);
echo 'Sync:', (microtime(true) * 1000 - $start), 'ms', PHP_EOL;
