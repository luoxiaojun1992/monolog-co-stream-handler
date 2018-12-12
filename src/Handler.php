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

    /**
     * @param resource|string $stream
     * @param int             $level                    The minimum logging level at which this handler will be triggered
     * @param Boolean         $bubble                   Whether the messages that are handled can bubble up the stack or not
     * @param int|null        $filePermission           Optional file permissions (default (0644) are only for owner read/write)
     * @param int             $stream_pool_size         Initial Size of stream pool
     * @param int             $record_buffer_max_size   Max size of record buffer
     * @param Boolean         $coroutine                Coroutine switch
     *
     * @throws \Exception                If a missing directory is not buildable
     * @throws \InvalidArgumentException If stream is not a resource or string
     */
    public function __construct(
        $stream,
        $level = Logger::DEBUG,
        $bubble = true,
        $filePermission = null,
        $stream_pool_size = 100,
        $record_buffer_max_size = 10,
        $coroutine = false
    )
    {
        parent::__construct($level, $bubble);
        if (is_string($stream)) {
            $this->url = $stream;
        } else {
            throw new \InvalidArgumentException('A stream must be a string.');
        }

        $this->filePermission = $filePermission;

        $this->createDir();
        $this->stream_pool = new StreamPool($this->url, $stream_pool_size);

        $this->recordBufferMaxSize = $record_buffer_max_size;
        $this->coroutine = $coroutine;
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        if (count($this->recordBuffer) > 0) {
            $this->write([], true);
            if ($this->coroutine) {
                if (extension_loaded('swoole')) {
                    if (function_exists('\go')) {
                        if (class_exists('\co')) {
                            \swoole_event::wait();
                        }
                    }
                }
            }
        }

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
     */
    protected function write(array $record, $flushAll = false)
    {
        if (null === $this->url || '' === $this->url) {
            throw new \LogicException('Missing stream url, the stream can not be opened. This may be caused by a premature call to close().');
        }

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

        list($stream_id, $stream) = $this->prepareWrite();

        $records = array_splice($this->recordBuffer, 0);
        try {
            $this->streamWrite($stream, $records, $stream_id);
        } catch (\Exception $e) {
            if (!is_null($stream_id)) {
                $this->stream_pool->restoreStream($stream_id);
            } else {
                $stream = null;
            }

            list($stream_id, $stream) = $this->prepareWrite();
            $this->streamWrite($stream, $records, $stream_id);
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

        $this->errorMessage = null;
        set_error_handler(array($this, 'customErrorHandler'));
        list($stream_id, $stream) = $this->stream_pool->pickStream();
        if ($this->filePermission !== null) {
            @chmod($this->url, $this->filePermission);
        }
        restore_error_handler();

        if (!is_resource($stream)) {
            if (!is_null($stream_id)) {
                $stream = $this->stream_pool->restoreStream($stream_id);
            } else {
                list($stream_id, $stream) = $this->stream_pool->pickStream();
            }

            if (!is_resource($stream)) {
                if (!is_null($stream_id)) {
                    $this->stream_pool->removeStream($stream_id);
                } else {
                    $stream = null;
                }
                throw new \UnexpectedValueException(sprintf('The stream or file "%s" could not be opened: ' . $this->errorMessage, $this->url));
            }
        }

        return [$stream_id, $stream];
    }

    /**
     * Write to stream
     * @param resource $stream
     * @param array $records
     * @param int $stream_id
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
            if (extension_loaded('swoole')) {
                if (function_exists('\go')) {
                    if (class_exists('\co')) {
                        $thisObj = $this;
                        \go(function () use ($stream, $logContent, $stream_id, $thisObj) {
                            \co::fwrite($stream, $logContent);
                            $thisObj->stream_pool->releaseStream($stream_id);
                        });
                        return;
                    }
                }
            }
        }

        fwrite($stream, $logContent);
        $this->stream_pool->releaseStream($stream_id);
    }

    private function customErrorHandler($code, $msg)
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

    public function countRecordBuffer()
    {
        return count($this->recordBuffer);
    }
}
