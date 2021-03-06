<?php

namespace vakata\websocket;

/**
 * A websocket server class.
 */
class Server
{
    use Base;
    
    protected $address = '';
    protected $server = null;
    protected $sockets = [];
    protected $clients = [];
    protected $callbacks = [];
    protected $tick = null;

    /**
     * Create an instance.
     * @method __construct
     * @param  string $address where to create the server, defaults to "ws://127.0.0.1:8080"
     * @param  string $cert    optional PEM encoded public and private keys to secure the server with (if `wss` is used)
     * @param  string $pass    optional password for the PEM certificate
     */
    public function __construct($address = 'ws://127.0.0.1:8080', $cert = null, $pass = null)
    {
        $addr = parse_url($address);
        if ($addr === false || !isset($addr['scheme']) || !isset($addr['host']) || !isset($addr['port'])) {
            throw new WebSocketException('Invalid address');
        }

        $context = stream_context_create();
        if ($cert) {
            stream_context_set_option($context, 'ssl', 'allow_self_signed', true);
            stream_context_set_option($context, 'ssl', 'verify_peer', false);
            stream_context_set_option($context, 'ssl', 'local_cert', $cert);
            if ($pass) {
                stream_context_set_option($context, 'ssl', 'passphrase', $pass);
            }
        }

        $this->address = $address;
        $this->server = @stream_socket_server(
            (in_array($addr['scheme'], ['wss', 'tls']) ? 'tls' : 'tcp').'://'.$addr['host'].':'.$addr['port'],
            $ern = null,
            $ers = null,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );
        if ($this->server === false) {
            throw new WebSocketException('Could not create server');
        }
    }

    /**
     * Start processing requests. This method runs in an infinite loop.
     * @method run
     */
    public function run()
    {
        $this->sockets[] = $this->server;
        while (true) {
            if (isset($this->tick)) {
                if(call_user_func($this->tick, $this) === false) {
                    break;
                }
            }
            $changed = $this->sockets;
            if (@stream_select($changed, $write = null, $except = null, (isset($this->tick) ? 0 : null) ) > 0) {
                $messages = [];
                foreach ($changed as $socket) {
                    if ($socket === $this->server) {
                        $temp = stream_socket_accept($this->server);
                        if ($temp !== false) {
                            if ($this->connect($temp)) {
                                if (isset($this->callbacks['connect'])) {
                                    call_user_func($this->callbacks['connect'], $this->clients[(int) $temp], $this);
                                }
                            }
                        }
                    } else {
                        $message = $this->receive($socket);
                        if ($message === false) {
                            $this->disconnect($socket);
                            if (isset($this->callbacks['disconnect'])) {
                                call_user_func($this->callbacks['disconnect'], $this->clients[(int) $socket], $this);
                            }
                        } else {
                            $messages[] = [
                                'client' => $this->clients[(int) $socket],
                                'message' => $message,
                            ];
                        }
                    }
                }
                foreach ($messages as $message) {
                    if (isset($this->callbacks['message'])) {
                        call_user_func($this->callbacks['message'], $message['client'], $message['message'], $this);
                    }
                }
            }
            usleep(5000);
        }
    }
    /**
     * Get an array of all connected clients.
     * @method getClients
     * @return array     the clients
     */
    public function getClients()
    {
        return $this->clients;
    }
    /**
     * Get the server socket.
     * @method getServer
     * @return resource    the socket
     */
    public function getServer()
    {
        return $this->server;
    }
    /**
     * Set a callback to be executed when a client connects, returning `false` will prevent the client from connecting.
     *
     * The callable will receive:
     *  - an associative array with client data
     *  - the current server instance
     * The callable should return `true` if the client should be allowed to connect or `false` otherwise.
     * @method validateClient
     * @param  callable       $callback the callback to execute when a client connects
     * @return self
     */
    public function validateClient(callable $callback)
    {
        $this->callbacks['validate'] = $callback;

        return $this;
    }
    /**
     * Set a callback to be executed when a client is connected.
     * 
     * The callable will receive:
     *  - an associative array with client data
     *  - the current server instance
     * @method onConnect
     * @param  callable  $callback the callback to execute
     * @return self
     */
    public function onConnect(callable $callback)
    {
        $this->callbacks['connect'] = $callback;

        return $this;
    }
    /**
     * Set a callback to execute when a client disconnects.
     * 
     * The callable will receive:
     *  - an associative array with client data
     *  - the current server instance
     * @method onDisconnect
     * @param  callable     $callback the callback
     * @return self
     */
    public function onDisconnect(callable $callback)
    {
        $this->callbacks['disconnect'] = $callback;

        return $this;
    }
    /**
     * Set a callback to execute when a client sends a message.
     * @method onMessage
     *
     * The callable will receive:
     *  - an associative array with client data
     *  - the message string
     *  - the current server instance
     * @param  callable  $callback the callback
     * @return self
     */
    public function onMessage(callable $callback)
    {
        $this->callbacks['message'] = $callback;

        return $this;
    }
    /**
     * Set a callback to execute every few milliseconds.
     * 
     * The callable will receive the server instance. If it returns boolean `false` the server will stop listening.
     * @method onTick
     * @param  callable  $callback the callback
     * @return self
     */
    public function onTick(callable $callback)
    {
        $this->tick = $callback;

        return $this;
    }
    protected function connect(&$socket)
    {
        $headers = $this->receiveClear($socket);
        if (!$headers) {
            return false;
        }
        $headers = str_replace(["\r\n", "\n"], ["\n", "\r\n"], $headers);
        $headers = array_filter(explode("\r\n", preg_replace("(\r\n\s+)", ' ', $headers)));
        $request = explode(' ', array_shift($headers));
        if (strtoupper($request[0]) !== 'GET') {
            $this->sendClear($socket, "HTTP/1.1 405 Method Not Allowed\r\n\r\n");

            return false;
        }
        $temp = [];
        foreach ($headers as $header) {
            $header = explode(':', $header, 2);
            $temp[trim(strtolower($header[0]))] = trim($header[1]);
        }
        $headers = $temp;
        if (!isset($headers['sec-websocket-key']) ||
            !isset($headers['upgrade']) ||
            !isset($headers['connection']) ||
            strtolower($headers['upgrade']) != 'websocket' ||
            strpos(strtolower($headers['connection']), 'upgrade') === false
        ) {
            $this->sendClear($socket, "HTTP/1.1 400 Bad Request\r\n\r\n");

            return false;
        }
        $cookies = [];
        if (isset($headers['cookie'])) {
            $temp = explode(';', $headers['cookie']);
            foreach ($temp as $v) {
                $v = explode('=', $v, 2);
                $cookies[trim($v[0])] = $v[1];
            }
        }
        $client = [
            'socket' => $socket,
            'headers' => $headers,
            'resource' => $request[1],
            'cookies' => $cookies,
        ];
        if (isset($this->callbacks['validate']) && !call_user_func($this->callbacks['validate'], $client, $this)) {
            $this->sendClear($socket, "HTTP/1.1 400 Bad Request\r\n\r\n");

            return false;
        }

        $response = [];
        $response[] = 'HTTP/1.1 101 WebSocket Protocol Handshake';
        $response[] = 'Upgrade: WebSocket';
        $response[] = 'Connection: Upgrade';
        $response[] = 'Sec-WebSocket-Version: 13';
        $response[] = 'Sec-WebSocket-Location: '.$this->address;
        $response[] = 'Sec-WebSocket-Accept: '.base64_encode(sha1($headers['sec-websocket-key'].self::$magic, true));
        if (isset($headers['origin'])) {
            $response[] = 'Sec-WebSocket-Origin: '.$headers['origin'];
        }

        $this->sockets[(int) $socket] = $socket;
        $this->clients[(int) $socket] = $client;

        return $this->sendClear($socket, implode("\r\n", $response)."\r\n\r\n");
    }
    protected function disconnect(&$socket)
    {
        unset($this->clients[(int) $socket], $this->sockets[(int) $socket], $socket);
    }
}
