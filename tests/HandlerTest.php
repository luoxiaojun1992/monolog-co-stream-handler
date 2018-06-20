<?php

class HandlerTest extends \PHPUnit\Framework\TestCase
{
    public function testLogSuccess()
    {
        $log_path = __DIR__ . '/../logs/test.log';
        file_exists($log_path) && unlink($log_path);
        $handler = new \Lxj\Monolog\Co\Stream\Handler($log_path);
        $monolog = new \Monolog\Logger('test');
        $monolog->pushHandler($handler);
        $monolog->info('test log');

        $now = date('Y-m-d H:i:s');

        $expected = <<<EOF
[{$now}] test.INFO: test log [] []

EOF;
        $this->assertStringEqualsFile($log_path, $expected);
    }
}
