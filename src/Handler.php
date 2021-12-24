<?php

namespace Lxj\Monolog\Co\Stream;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;

/**
 * Stores to any stream resource
 *
 * Can be used to store into php://stderr, remote and local files, etc.
 *
 * @author Xiaojun Luo <luoxiaojun1992@sina.cn>
 */
class Handler extends AbstractProcessingHandler
{
    protected $stream;
    protected $url;
    private $errorMessage;
    protected $filePermission;
    private $dirCreated;
    private $stream_pool;
    private $recordBuffer = [];
    private $recordBufferMaxSize = 10;
    private $coroutine = false;
    private $restoreStreamsLock = [true];
    protected $syncLevels = [];

    /**
     * @param resource|string $stream
     * @param int             $level                    The minimum logging level at which this handler will be triggered
     * @param array           $syncLevels               The logging levels at one of which the log will be flushed immediately
     * @param Boolean         $bubble                   Whether the messages that are handled can bubble up the stack or not
     * @param int|null        $filePermission           Optional file permissions (default (0644) are only for owner read/write)
     * @param int             $streamPoolSize         Initial Size of stream pool
     * @param int             $recordBufferMaxSize   Max size of record buffer
     * @param Boolean         $coroutine                Coroutine switch
     *
     * @throws \Exception                If a missing directory is not buildable
     * @throws \InvalidArgumentException If stream is not a resource or string
     */
    public function __construct(
        $stream,
        $level = Logger::DEBUG,
        $syncLevels = [],
        $bubble = true,
        $filePermission = null,
        $streamPoolSize = 100,
        $recordBufferMaxSize = 10,
        $coroutine = false
    )
    {
        parent::__construct($level, $bubble);

        $this->syncLevels = $syncLevels;

        if (is_string($stream)) {
            $this->url = $stream;
        } else {
            throw new \InvalidArgumentException('A stream must be a string.');
        }

        $this->filePermission = $filePermission;

        $this->createDir();
        $this->createStreamPool($streamPoolSize);

        $this->recordBufferMaxSize = $recordBufferMaxSize;
        $this->coroutine = $coroutine;
    }

    private function createStreamPool($stream_pool_size)
    {
        $this->stream_pool = new StreamPool($this->url, $stream_pool_size);
    }

    public function flush()
    {
        if (count($this->recordBuffer) > 0) {
            $this->write([], true);
            if ($this->coroutine) {
                if ($this->coroutineEnabled()) {
                    \swoole_event::wait();
                }
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        $this->flush();
        $this->stream_pool->closeStream();
    }

    /**
     * Return the stream URL if it was configured with a URL and not an active resource
     *
     * @return string|null
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * {@inheritdoc}
     *
     * @param array $record
     * @param bool $flushAll
     * @throws \Exception
     */
    protected function write(array $record, $flushAll = false)
    {
        if (null === $this->url || '' === $this->url) {
            throw new \LogicException('Missing stream url, the stream can not be opened. This may be caused by a premature call to close().');
        }

        $level = $record['level'] ?? null;

        if (is_null($level)) {
            $isSyncLevel = false;
        } else {
            $isSyncLevel = in_array($level, $this->syncLevels);
        }

        if (!$isSyncLevel) {
            if (count($record) > 0) {
                $this->recordBuffer[] = $record;
            }
            $recordBufferCount = $this->countRecordBuffer();
            if (!$flushAll && $recordBufferCount < $this->recordBufferMaxSize) {
                return;
            }
            if ($recordBufferCount <= 0) {
                return;
            }
        }

        list($stream_id, $stream) = $this->prepareWrite();

        if ($isSyncLevel) {
            $records = [$record];
        } else {
            $records = Swoole::withoutPreemptive(function () {
                return array_splice($this->recordBuffer, 0);
            });
        }

        try {
            try {
                $this->streamWrite($stream, $records, $stream_id);
            } catch (\Exception $e) {
                if (!is_null($stream_id)) {
                    $stream = $this->stream_pool->restoreStream($stream_id);
                } else {
                    list($stream_id, $stream) = $this->prepareWrite();
                }

                $this->streamWrite($stream, $records, $stream_id);
            }
        } catch (\Exception $writeEx) {
            foreach ($records as $record) {
                Swoole::withoutPreemptive(function () use ($record) {
                    array_push($this->recordBuffer, $record);
                });
            }
            if (!is_null($stream_id)) {
                $this->stream_pool->removeStream($stream_id);
            } else {
                fclose($stream);
                $stream = null;
            }
            throw $writeEx;
        }
    }

    /**
     * Prepare for write
     *
     * @return array
     */
    private function prepareWrite()
    {
        $this->createDir();

        if (Swoole::withoutPreemptive(function () {
            return array_pop($this->restoreStreamsLock);
        })) {
            if (!file_exists($this->url)) {
                touch($this->url);
                $this->stream_pool->restoreStreams();
            }
            array_push($this->restoreStreamsLock, true);
        }

        $this->errorMessage = null;
        set_error_handler(array($this, 'customErrorHandler'));
        list($stream_id, $stream) = $this->stream_pool->pickStream();
        if ($this->filePermission !== null) {
            @chmod($this->url, $this->filePermission);
        }
        restore_error_handler();

        return [$stream_id, $stream];
    }

    /**
     * Write to stream
     *
     * @param $stream
     * @param array $records
     * @param $stream_id
     * @throws \Exception
     */
    protected function streamWrite($stream, array $records, $stream_id)
    {
        if (count($records) <= 0) {
            $this->stream_pool->releaseStream($stream_id);
            return;
        }

        $logContent = '';
        foreach ($records as $record) {
            $logContent .= (string)$record['formatted'];
        }

        if ($this->coroutine) {
            if ($this->coroutineEnabled()) {
                $thisObj = $this;
                \go(function () use ($stream, $logContent, $stream_id, $thisObj) {
                    if (version_compare(swoole_version(), '4.4.6') < 0) {
                        $writeResult = \co::fwrite($stream, $logContent);
                    } else {
                        if (version_compare(swoole_version(), '4.5.1') < 0) {
                            $writeResult = \co\system::fwrite($stream, $logContent);
                        } else {
                            $writeResult = fwrite($stream, $logContent);
                        }
                    }
                    if (!$writeResult) {
                        throw new \Exception('fwrite error');
                    }
                    $thisObj->stream_pool->releaseStream($stream_id);
                });
                return;
            }
        }

        if (!fwrite($stream, $logContent)) {
            throw new \Exception('fwrite error');
        }
        $this->stream_pool->releaseStream($stream_id);
    }

    public function customErrorHandler($code, $msg)
    {
        $this->errorMessage = preg_replace('{^(fopen|mkdir)\(.*?\): }', '', $msg);
    }

    /**
     * @param string $stream
     *
     * @return null|string
     */
    private function getDirFromStream($stream)
    {
        $pos = strpos($stream, '://');
        if ($pos === false) {
            return dirname($stream);
        }

        if ('file://' === substr($stream, 0, 7)) {
            return dirname(substr($stream, 7));
        }

        return;
    }

    private function createDir()
    {
        // Do not try to create dir if it has already been tried.
        if ($this->dirCreated) {
            return;
        }

        $dir = $this->getDirFromStream($this->url);
        if (null !== $dir && !is_dir($dir)) {
            $this->errorMessage = null;
            set_error_handler(array($this, 'customErrorHandler'));
            $status = mkdir($dir, 0777, true);
            restore_error_handler();
            if (false === $status) {
                throw new \UnexpectedValueException(sprintf('There is no existing directory at "%s" and its not buildable: '.$this->errorMessage, $dir));
            }
        }
        $this->dirCreated = true;
    }

    private function coroutineEnabled()
    {
        if (extension_loaded('swoole')) {
            if (function_exists('\go')) {
                if (class_exists('\co')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return int
     */
    public function countRecordBuffer()
    {
        return count($this->recordBuffer);
    }

    /**
     * @return StreamPool
     */
    public function getStreamPool()
    {
        return $this->stream_pool;
    }
}
