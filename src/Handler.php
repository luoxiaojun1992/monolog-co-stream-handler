<?php

namespace Lxj\Monolog\Co\Stream;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;

/**
 * Stores to any stream resource
 *
 * Can be used to store into php://stderr, remote and local files, etc.
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Handler extends AbstractProcessingHandler
{
    protected $stream;
    protected $url;
    private $errorMessage;
    protected $filePermission;
    private $dirCreated;

    /**
     * @param resource|string $stream
     * @param int             $level          The minimum logging level at which this handler will be triggered
     * @param Boolean         $bubble         Whether the messages that are handled can bubble up the stack or not
     * @param int|null        $filePermission Optional file permissions (default (0644) are only for owner read/write)
     *
     * @throws \Exception                If a missing directory is not buildable
     * @throws \InvalidArgumentException If stream is not a resource or string
     */
    public function __construct(
        $stream,
        $level = Logger::DEBUG,
        $bubble = true,
        $filePermission = null
    )
    {
        parent::__construct($level, $bubble);
        if (is_string($stream)) {
            $this->url = $stream;
        } else {
            throw new \InvalidArgumentException('A stream must be a string.');
        }

        $this->filePermission = $filePermission;
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        //
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
    protected function write(array $record)
    {
        if (null === $this->url || '' === $this->url) {
            throw new \LogicException('Missing stream url, the stream can not be opened. This may be caused by a premature call to close().');
        }
        $this->createDir();
        $this->errorMessage = null;
        set_error_handler(array($this, 'customErrorHandler'));
        $stream = fopen($this->url, 'a');
        if ($this->filePermission !== null) {
            @chmod($this->url, $this->filePermission);
        }
        restore_error_handler();
        if (!is_resource($stream)) {
            $stream = null;
            throw new \UnexpectedValueException(sprintf('The stream or file "%s" could not be opened: ' . $this->errorMessage, $this->url));
        }

        $this->streamWrite($stream, $record);
    }

    /**
     * Write to stream
     * @param resource $stream
     * @param array $record
     */
    protected function streamWrite($stream, array $record)
    {
        $logContent = (string)$record['formatted'];

        if (extension_loaded('swoole')) {
            if (function_exists('go')) {
                if (class_exists('co')) {
                    go(function () use ($stream, $logContent) {
                        co::fwrite($stream, $logContent);
                        fclose($stream);
                    });
                    return;
                }
            }
        }

        fwrite($stream, $logContent);
        fclose($stream);
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
}
