<?php

namespace Amp\Socket;

use Amp\Deferred;
use Amp\Loop;
use Amp\Promise;
use Amp\Success;

class Datagram implements UdpStreamSocket
{
    const DEFAULT_CHUNK_SIZE = 8192;

    /** @var resource Stream socket datagram resource. */
    private $socket;

    /** @var string Watcher ID. */
    private $watcher;

    /** @var string|null Stream socket name */
    private $address;

    /** @var Deferred|null */
    private $reader;

    public function __construct($socket, int $chunkSize = self::DEFAULT_CHUNK_SIZE)
    {
        if (!\is_resource($socket) || \get_resource_type($socket) !== 'stream') {
            throw new \Error('Invalid resource given to constructor!');
        }

        $this->socket = $socket;
        $this->address = Internal\cleanupSocketName(@\stream_socket_get_name($this->socket, false));

        \stream_set_blocking($this->socket, false);

        $reader = &$this->reader;
        $this->watcher = Loop::onReadable($this->socket, static function ($watcher, $socket) use (&$reader, $chunkSize) {
            $deferred = $reader;
            $reader = null;

            $data = @\stream_socket_recvfrom($socket, $chunkSize, 0, $address);

            if ($data == false) {
                Loop::cancel($watcher);
                $deferred->resolve();
                return;
            }

            $deferred->resolve(new Packet($data, Internal\cleanupSocketName($address)));

            if (!$reader) {
                Loop::disable($watcher);
            }
        });

        Loop::disable($this->watcher);
    }

    public function receive(): Promise
    {
        if ($this->reader) {
            throw new PendingReceiveError;
        }

        if (!$this->socket) {
            return new Success; // Resolve with null when datagram is closed.
        }

        $this->reader = new Deferred;
        Loop::enable($this->watcher);

        return $this->reader->promise();
    }

    public function send(Packet $packet): int
    {
        if (!$this->socket) {
            throw new SocketException('The datagram is not writable');
        }

        $result = @\stream_socket_sendto($this->socket, $packet->getData(), 0, $packet->getAddress());

        if ($result < 0 || $result === false) {
            $error = \error_get_last();
            throw new SocketException('Could not send packet on datagram: ' . $error['message']);
        }

        return $result;
    }

    final public function getResource()
    {
        return $this->socket;
    }

    final public function reference()
    {
        Loop::reference($this->watcher);
    }

    final public function unreference()
    {
        Loop::unreference($this->watcher);
    }

    /**
     * Closes the datagram and stops receiving data. Any pending read is resolved with null.
     */
    public function close()
    {
        if ($this->socket) {
            \fclose($this->socket);
        }

        $this->free();
    }

    /**
     * @return string|null
     */
    public function getLocalAddress()
    {
        return $this->address;
    }

    private function free()
    {
        Loop::cancel($this->watcher);

        $this->socket = null;

        if ($this->reader) {
            $this->reader->resolve();
            $this->reader = null;
        }
    }
}