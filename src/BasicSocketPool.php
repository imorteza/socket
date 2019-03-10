<?php

namespace Amp\Socket;

use Amp\CancellationToken;
use Amp\CancelledException;
use Amp\Failure;
use Amp\Loop;
use Amp\Promise;
use Amp\Struct;
use Amp\Success;
use function Amp\call;

final class BasicSocketPool implements SocketPool
{
    const ALLOWED_SCHEMES = [
        'tcp'  => null,
        'udp'  => null,
        'unix' => null,
        'udg'  => null,
    ];

    private $sockets = [];
    private $socketIdUriMap = [];
    private $pendingCount = [];

    private $idleTimeout;
    private $socketContext;

    public function __construct(int $idleTimeout = 10000, ClientConnectContext $socketContext = null)
    {
        $this->idleTimeout = $idleTimeout;
        $this->socketContext = $socketContext ?? new ClientConnectContext;
    }

    /**
     * @param string $uri
     *
     * @return string
     *
     * @throws SocketException
     */
    private function normalizeUri(string $uri): string
    {
        if (\stripos($uri, 'unix://') === 0) {
            return $uri;
        }

        $parts = \parse_url($uri);

        if (!$parts) {
            throw new SocketException("Could not parse URI");
        }

        $scheme = isset($parts['scheme']) ? \strtolower($parts['scheme']) : null;
        $host = isset($parts['host']) ? \strtolower($parts['host']) : null;
        $port = $parts['port'] ?? 0;

        if ($scheme === null) {
            throw new SocketException("Invalid URI for socket pool; no scheme given");
        }

        if (!\array_key_exists($scheme, self::ALLOWED_SCHEMES)) {
            throw new SocketException(\sprintf(
                "Invalid URI for socket pool; '%s' scheme not allowed - scheme must be one of %s",
                $scheme,
                \implode(', ', \array_keys(self::ALLOWED_SCHEMES))
            ));
        }

        if ($host === null || $port === 0) {
            throw new SocketException("Invalid URI for socket pool; missing host or port");
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new SocketException("Invalid URI for socket pool; query or fragment components not allowed");
        }

        if (isset($parts['path'])) {
            throw new SocketException("Invalid URI for socket pool; path component must be empty");
        }

        if (isset($parts['user'])) {
            throw new SocketException("Invalid URI for socket pool; user component not allowed");
        }

        return $scheme . '://' . $host . ':' . $port;
    }

    /** @inheritdoc */
    public function checkout(string $uri, CancellationToken $token = null): Promise
    {
        // A request might already be cancelled before we reach the checkout, so do not even attempt to checkout in that
        // case. The weird logic is required to throw the token's exception instead of creating a new one.
        if ($token && $token->isRequested()) {
            try {
                $token->throwIfRequested();
            } catch (CancelledException $e) {
                return new Failure($e);
            }
        }

        $uri = $this->normalizeUri($uri);

        if (empty($this->sockets[$uri])) {
            return $this->checkoutNewSocket($uri, $token);
        }

        foreach ($this->sockets[$uri] as $socketId => $socket) {
            if (!$socket->isAvailable) {
                continue;
            }

            if (!\is_resource($socket->resource) || \feof($socket->resource)) {
                $this->clearFromId((int) $socket->resource);
                continue;
            }

            $socket->isAvailable = false;

            if ($socket->idleWatcher !== null) {
                Loop::disable($socket->idleWatcher);
            }

            return new Success(new ClientSocket($socket->resource));
        }

        return $this->checkoutNewSocket($uri, $token);
    }

    private function checkoutNewSocket(string $uri, CancellationToken $token = null): Promise
    {
        return call(function () use ($uri, $token) {
            $this->pendingCount[$uri] = ($this->pendingCount[$uri] ?? 0) + 1;

            try {
                /** @var ClientSocket $rawSocket */
                $rawSocket = yield connect($uri, $this->socketContext, $token);
            } finally {
                if (--$this->pendingCount[$uri] === 0) {
                    unset($this->pendingCount[$uri]);
                }
            }

            $socketId = (int) $rawSocket->getResource();

            $socket = new class {
                use Struct;

                public $id;
                public $uri;
                public $resource;
                public $isAvailable;
                public $idleWatcher;
            };

            $socket->id = $socketId;
            $socket->uri = $uri;
            $socket->resource = $rawSocket->getResource();
            $socket->isAvailable = false;

            $this->sockets[$uri][$socketId] = $socket;
            $this->socketIdUriMap[$socketId] = $uri;

            return $rawSocket;
        });
    }

    /** @inheritdoc */
    public function clear(StreamSocket $socket)
    {
        $this->clearFromId((int) $socket->getResource());
    }

    /**
     * @param int $socketId
     */
    private function clearFromId(int $socketId)
    {
        if (!isset($this->socketIdUriMap[$socketId])) {
            throw new \Error(
                \sprintf('Unknown socket: %d', $socketId)
            );
        }

        $uri = $this->socketIdUriMap[$socketId];
        $socket = $this->sockets[$uri][$socketId];

        if ($socket->idleWatcher) {
            Loop::cancel($socket->idleWatcher);
        }

        unset(
            $this->sockets[$uri][$socketId],
            $this->socketIdUriMap[$socketId]
        );

        if (empty($this->sockets[$uri])) {
            unset($this->sockets[$uri]);
        }
    }

    /** @inheritdoc */
    public function checkin(StreamSocket $socket)
    {
        $socketId = (int) $socket->getResource();

        if (!isset($this->socketIdUriMap[$socketId])) {
            throw new \Error(
                \sprintf('Unknown socket: %d', $socketId)
            );
        }

        $uri = $this->socketIdUriMap[$socketId];

        $resource = $socket->getResource();

        if (!\is_resource($resource) || \feof($resource)) {
            $this->clearFromId((int) $resource);
            return;
        }

        $socket = $this->sockets[$uri][$socketId];
        $socket->isAvailable = true;

        if (isset($socket->idleWatcher)) {
            Loop::enable($socket->idleWatcher);
        } else {
            $socket->idleWatcher = Loop::delay($this->idleTimeout, function () use ($socket) {
                $this->clearFromId((int) $socket->resource);
            });

            Loop::unreference($socket->idleWatcher);
        }
    }
}
