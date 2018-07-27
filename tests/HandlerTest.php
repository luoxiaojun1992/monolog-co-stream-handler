<?php

class HandlerTest extends \PHPUnit\Framework\TestCase
{
    public function testLogSuccess()
    {
        $log_path = __DIR__ . '/../logs/test.log';
        file_exists($log_path) && unlink($log_path);
        $handler = new \Lxj\Monolog\Co\Stream\Handler(
            $log_path,
            \Monolog\Logger::DEBUG,
            true,
            null,
            100,
            1024,
            8
        );
        $formatter = new \Monolog\Formatter\LineFormatter("%message%\n");
        $handler->setFormatter($formatter);
        $monolog = new \Monolog\Logger('test');
        $monolog->pushHandler($handler);
        $monolog->info('test info 1');
        $monolog->info('test info 2');
        $monolog->warning('test warning 1');
        $monolog->warning('test warning 2');
        $monolog->debug('test debug 1');
        $monolog->debug('test debug 2');
        $monolog->notice('test notice 1');
        $monolog->notice('test notice 2');
        $monolog->error('test error 1');
        $monolog->error('test error 2');
        $monolog->critical('test critical 1');
        $monolog->critical('test critical 2');
        $monolog->alert('test alert 1');
        $monolog->alert('test alert 2');
        $monolog->emergency('test emergency 1');
        $monolog->emergency('test emergency 2');

        $expected = <<<EOF
test info 1
test info 2
test warning 1
test warning 2
test debug 1
test debug 2
test notice 1
test notice 2
test error 1
test error 2
test critical 1
test critical 2
test alert 1
test alert 2
test emergency 1
test emergency 2

EOF;

        swoole_event::wait();

        $start = time();
        while (!file_get_contents($log_path)) {
            if (time() - $start > 5) {
                break;
            }
        }
        $this->assertStringEqualsFile($log_path, $expected);

        unlink($log_path);
    }
}
