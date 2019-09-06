<?php

namespace Lxj\Monolog\Co\Stream;

class Swoole
{
    public static function withoutPreemptive($callback)
    {
        if (extension_loaded('swoole')) {
            if (class_exists('\co')) {
                if (method_exists('\co', 'disableScheduler')) {
                    if (method_exists('\co', 'enableScheduler')) {
                        \Co::disableScheduler();
                        $result = call_user_func($callback);
                        \Co::enableScheduler();
                        return $result;
                    }
                }
            }
        }

        return call_user_func_array($callback);
    }
}
