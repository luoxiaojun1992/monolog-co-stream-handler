<?php

namespace Lxj\Monolog\Co\Stream;

class StreamPool
{
    const STREAM_AVAILABLE = 0;
    const STREAM_OCCUPIED = 1;

    private $stream_pool = [];
    private $available_streams = [];
    private $occupied_streams = [];
    private $stream_pool_max_size;
    private $url;

    public function __construct($url, $stream_pool_size, $stream_pool_max_size = 1024)
    {
        $this->url = $url;
        $this->stream_pool_max_size = $stream_pool_max_size;
        $this->initStreamPool($stream_pool_size);
    }

    /**
     * Initialize stream pool
     *
     * @param $stream_pool_size
     */
    public function initStreamPool($stream_pool_size)
    {
        if ($stream_pool_size > $this->stream_pool_max_size) {
            $stream_pool_size = $this->stream_pool_max_size;
        }

        for($i = 0; $i < $stream_pool_size; ++$i) {
            $stream_id = $this->createStream();
            $this->releaseStream($stream_id);
        }
    }

    /**
     * Release a stream resource to available stream pool
     *
     * @param $stream_id
     */
    public function releaseStream($stream_id)
    {
        $this->setStreamStatus($stream_id, self::STREAM_AVAILABLE);
        $this->removeOccupiedStream($stream_id);
        $this->addAvailableStream($stream_id);
    }

    /**
     * Create a stream resource to stream pool
     *
     * @return int
     */
    public function createStream()
    {
        $stream = fopen($this->url, 'a');
        $this->stream_pool[] = ['stream' => $stream, 'status' => self::STREAM_AVAILABLE];
        return count($this->stream_pool) - 1;
    }

    /**
     * Put a stream resource to occupied stream pool
     *
     * @param $stream_id
     */
    public function occupyStream($stream_id)
    {
        $this->setStreamStatus($stream_id, self::STREAM_OCCUPIED);
        $this->removeAvailableStream($stream_id);
        $this->addOccupiedStream($stream_id);
    }

    /**
     * Pick an available stream resource
     *
     * @return array
     */
    public function pickStream()
    {
        $stream = null;
        $stream_id = 0;
        if (count($this->available_streams) > 0) {
            $stream_id = array_pop($this->available_streams);
            $this->occupyStream($stream_id);
        }
        if ($stream_id <= 0) {
            if (count($this->stream_pool) < $this->stream_pool_max_size) {
                $stream_id = $this->createStream();
                $this->occupyStream($stream_id);
            }
        }
        if ($stream_id > 0) {
            $stream = $this->stream_pool[$stream_id]['stream'];
        }

        return [$stream_id, $stream];
    }

    /**
     * Close all stream resources
     */
    public function closeStream()
    {
        foreach ($this->stream_pool as $stream_id => $stream) {
            fclose($stream['stream']);
            unset($this->stream_pool[$stream_id]);
            $this->removeAvailableStream($stream_id);
            $this->removeOccupiedStream($stream_id);
        }
    }

    private function addAvailableStream($stream_id)
    {
        $this->available_streams[$stream_id] = $stream_id;
    }

    private function removeAvailableStream($stream_id)
    {
        if (isset($this->available_streams[$stream_id])) {
            unset($this->available_streams[$stream_id]);
        }
    }

    private function addOccupiedStream($stream_id)
    {
        $this->occupied_streams[$stream_id] = $stream_id;
    }

    private function removeOccupiedStream($stream_id)
    {
        if (isset($this->occupied_streams[$stream_id])) {
            unset($this->occupied_streams[$stream_id]);
        }
    }

    private function setStreamStatus($stream_id, $status)
    {
        $this->stream_pool[$stream_id]['status'] = $status;
    }

    public function __destruct()
    {
        $this->closeStream();
    }
}
