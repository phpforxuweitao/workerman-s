<?php
/**
 * Created by PhpStorm.
 * User: windrunner414
 * Date: 11/9/18
 * Time: 7:43 PM
 */

namespace Workerman;

use Workerman\Connection\TcpConnection;
use Workerman\Lib\Session;

require_once __DIR__ . '/Lib/Constants.php';

class WorkerException extends \Exception
{

}

class Worker
{
    private static $workers = [];
    private static $workers_no_listen = [];

    public $listen;
    public $context;

    private $host;
    private $port;
    private $type;
    private $socketType;
    private $protocolClass;

    public static $_startFile;

    /**
     * @var array fd => coroutineId
     */
    private $cidMapping = [];
    private $coroutine;

    public $setting = [];
    public $id;
    public $connections = [];

    public $swooleObj = null;

    // event callback
    public $onWorkerStart = null;
    public $onWorkerStop = null;
    public $onWorkerReload = null;
    public $onConnect = null;
    public $onMessage = null;
    public $onClose = null;
    public $onBufferFull = null;
    public $onBufferDrain = null;
    public $onError = null;
    public $onWebSocketConnect = null;
    public $onWebSocketClose = null;

    // setting
    public $count = 1;
    public $name = '';
    public $protocol = '';
    public $transport = 'tcp';
    public $reusePort = false;
    public $user = '';
    public $reloadable = true;

    public static $stdoutFile = '/dev/null';
    public static $pidFile = '';
    public static $logFile = __DIR__ . '/../workerman.log';
    public static $daemonize = false;
    public static $globalEvent = null;

    /**
     * Worker constructor.
     * @param string $listen
     * @param array $context
     */
    function __construct($listen = '', $context = [])
    {
        $this->coroutine = class_exists('\\co');
        $this->listen = $listen;
        $this->context = $context;

        WorkerManager::$workers_wait_add[] = $this;
    }

    /**
     * @throws WorkerException
     */
    function add()
    {
        $listen = $this->listen;
        $context = $this->context;

        if ($listen !== '') {
            $set = [];

            // ignore ipv6_v6only, bindto, so_broadcast
            if (isset($context['backlog'])) $set['backlog'] = $context['backlog'];
            if (isset($context['so_reuseport'])) $this->reusePort = $context['so_reuseport'];
            if (isset($context['tcp_nodelay'])) $set['open_tcp_nodelay'] = $context['tcp_nodelay'];

            $bind = explode('://', $listen, 2);
            if (count($bind) !== 2) throw new WorkerException('listen param parse error');
            $type = $this->protocol === '' ? $bind[0] : $this->protocol;

            if (strpos($type, 'unix') === 0) {
                $host = $bind[1];
                $port = 0;
            } else {
                if ($bind[1][0] === '[') {
                    $bind = explode(']:', $bind[1], 2);
                    if (count($bind) !== 2) throw new WorkerException('listen param parse error');
                    $host = substr($bind[0], 1);
                    $port = $bind[1];
                } else {
                    $bind = explode(':', $bind[1], 2);
                    if (count($bind) !== 2) throw new WorkerException('listen param parse error');
                    $host = $bind[0];
                    $port = $bind[1];
                }
            }

            $this->host = $host;
            $this->port = $port;
            $this->type = $type;

            $this->setting = array_merge([
                'open_eof_check' => false,
                'open_length_check' => false,
                'open_http_protocol' => $type === 'http' || $type === 'websocket',
                'open_http2_protocol' => false,
                'open_websocket_protocol' => $type === 'websocket',
                'open_mqtt_protocol' => false,
                'open_websocket_close_frame' => false,
                'buffer_high_watermark' => TcpConnection::$defaultMaxSendBufferSize,
                'buffer_low_watermark' => 0,
                'buffer_output_size' => TcpConnection::$defaultMaxSendBufferSize,
                'socket_buffer_size' => TcpConnection::$defaultMaxSendBufferSize,
                'package_max_length' => TcpConnection::$maxPackageSize
            ], $set, $this->setting);

            $param = WorkerManager::add($host, $port, $type, $this);
            $this->socketType = $param['socketType'];
            $this->protocolClass = $param['protocol'];

            self::$workers[] = $this;
        } else {
            WorkerManager::addNoListen($this);
            self::$workers_no_listen[] = $this;
        }
    }

    static function trigger($worker, $event, ...$param)
    {
        if (isset($worker->$event) && is_callable($worker->$event)) {
            call_user_func($worker->$event, ...$param);
        }
    }

    static function workerStart($server, $workerId)
    {
        $_GET = new GlobalArray();
        $_POST = new GlobalArray();
        $_COOKIE = new GlobalArray();
        $_SESSION = new GlobalArray();
        $_SERVER = new GlobalArray();
        $_FILES = new GlobalArray();
        $_REQUEST = new GlobalArray();

        foreach (self::$workers as $worker) {
            $worker->id = $workerId;
            if ($worker->name !== '') \swoole_set_process_name($worker->name);
            Worker::trigger($worker, 'onWorkerStart', $worker);
        }
    }

    static function workerStop($server, $workerId)
    {
        foreach (self::$workers as $worker) {
            Worker::trigger($worker, 'onWorkerStop', $worker);
        }
    }

    function _onProcessStart()
    {
        if ($this->name !== '') \swoole_set_process_name($this->name);
        Worker::trigger($this, 'onWorkerStart', $this);
    }

    function _onProcessStop()
    {
        Worker::trigger($this, 'onWorkerStop', $this);
    }

    function _onConnect($server, $fd, $reactorId)
    {
        if ($this->type === 'http') $type = TcpConnection::TYPE_HTTP;
        elseif ($this->type === 'websocket') $type = TcpConnection::TYPE_HTTP; // on handshake, websocket conn type is http
        else $type = TcpConnection::TYPE_OTHER;

        $connection = new TcpConnection($type, $fd, $this->protocolClass, $this, $type === TcpConnection::TYPE_OTHER);
        foreach (TcpConnection::EVENTS as $event) {
            $connection->$event = $this->$event;
        }

        $this->connections[$fd] = $connection;
        Worker::trigger($this, 'onConnect', $connection);
    }

    function _onReceive($server, $fd, $reactorId, $data)
    {
        //TODO: need unpack
        $connection = $this->connections[$fd];
        ++TcpConnection::$statistics['total_request'];
        Worker::trigger($connection, 'onMessage', $connection, $data);
        Worker::trigger($this, 'onMessage', $connection, $data);
    }

    /**
     * http server on receive
     *
     * @param $request
     * @param $response
     */
    function _onRequest($request, $response)
    {
        $this->initGlobalArray($request->fd, $request, $response);

        $connection = $this->connections[$request->fd];
        $connection->conn = $response;
        $connection->rawPostData = $request->rawContent();
        $data = $request->getData();
        ++TcpConnection::$statistics['total_request'];
        Worker::trigger($connection, 'onMessage', $connection, $data);
        Worker::trigger($this, 'onMessage', $connection, $data);

        if (!$connection->ended) {
            $connection->close();
        }
        $connection->ended = false;

        $this->cleanGlobalArray($request->fd);
    }

    /**
     * websocket on handshake
     *
     * @param $request
     * @param $response
     * @return bool
     */
    function _onHandShake($request, $response)
    {
        $this->initGlobalArray($request->fd, $request, $response);

        $connection = $this->connections[$request->fd];
        $connection->conn = $response;
        $connection->rawPostData = $request->rawContent();
        Worker::trigger($this, 'onWebSocketConnect', $connection, $request->getData());

        $connection->ended = false;
        if ($connection->closed) return false;

        $secWebSocketKey = $request->header['sec-websocket-key'];
        $patten = '#^[+/0-9A-Za-z]{21}[AQgw]==$#';
        if (0 === preg_match($patten, $secWebSocketKey) || 16 !== strlen(base64_decode($secWebSocketKey))) {
            $response->end();
            return false;
        }

        $key = base64_encode(sha1(
            $request->header['sec-websocket-key'] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11',
            true
        ));

        $headers = [
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Accept' => $key,
            'Sec-WebSocket-Version' => '13',
        ];

        if (isset($request->header['sec-websocket-protocol'])) {
            $headers['Sec-WebSocket-Protocol'] = $request->header['sec-websocket-protocol'];
        }

        foreach ($headers as $key => $val) {
            $response->header($key, $val);
        }

        $response->status(101);
        $response->end();

        return true;
    }

    /**
     * websocket server on open (after handshake)
     *
     * @param $server
     * @param $request
     */
    function _onOpen($server, $request)
    {
        $this->setGlobalArrayId($request->fd);
    }

    /**
     * websocket server on receive
     *
     * @param $server
     * @param $frame
     */
    function _onMessage($server, $frame)
    {
        $this->setGlobalArrayId($frame->fd);

        $connection = $this->connections[$frame->fd];
        $connection->conn = $frame;
        $connection->type = TcpConnection::TYPE_WEBSOCKET;
        ++TcpConnection::$statistics['total_request'];
        Worker::trigger($connection, 'onMessage', $connection, $frame->data);
        Worker::trigger($this, 'onMessage', $connection, $frame->data);
    }

    function _onPacket($server, $data, $clientInfo)
    {
        //TODO: need unpack
        $connection = null;
        Worker::trigger($this, 'onMessage', $connection, $data);
    }

    function _onClose($server, $fd, $reactorId)
    {
        $connection = $this->connections[$fd];
        $connection->closed = true;
        Worker::trigger($connection, 'onClose', $connection);
        Worker::trigger($this, 'onClose', $connection);

        if ($this->type === 'websocket') {
            Worker::trigger($this, 'onWebSocketClose', $connection);
            $this->cleanGlobalArray($fd);
        }

        $connection->_destroy();
        unset($this->connections[$fd]);
    }

    function _onBufferFull($server, $fd)
    {
        $connection = $this->connections[$fd];
        Worker::trigger($connection, 'onBufferFull', $connection);
        Worker::trigger($this, 'onBufferFull', $connection);
    }

    function _onBufferEmpty($server, $fd)
    {
        $connection = $this->connections[$fd];
        Worker::trigger($connection, 'onBufferDrain', $connection);
        Worker::trigger($this, 'onBufferDrain', $connection);
    }

    function setGlobalArrayId($id)
    {
        if ($this->coroutine) {
            $cid = \co::getuid();

            if (isset($this->cidMapping[$id]) && $this->cidMapping[$id] !== $cid) {
                $_SERVER->move($this->cidMapping[$id]);
                $_GET->move($this->cidMapping[$id]);
                $_POST->move($this->cidMapping[$id]);
                $_REQUEST->move($this->cidMapping[$id]);
                $_COOKIE->move($this->cidMapping[$id]);
                $_FILES->move($this->cidMapping[$id]);
                $_SESSION->move($this->cidMapping[$id]);
            }

            $this->cidMapping[$id] = $cid;
            return;
        }

        $_SERVER->setId($id);
        $_GET->setId($id);
        $_POST->setId($id);
        $_REQUEST->setId($id);
        $_COOKIE->setId($id);
        $_FILES->setId($id);
        $_SESSION->setId($id);
    }

    function initGlobalArray($id, $request, $response)
    {
        $this->setGlobalArrayId($id);

        if (!$request->get) $request->get = [];
        if (!$request->post) $request->post = [];
        if (!$request->cookie) $request->cookie = [];
        if (!$request->files) $request->files = [];
        if (!$request->server) $request->server = [];

        $_SERVER->assign(array_change_key_case($request->server, CASE_UPPER));
        $_GET->assign($request->get);
        $_POST->assign($request->post);
        $_REQUEST->assign(array_merge($request->get, $request->post, $request->cookie));
        $_COOKIE->assign($request->cookie);
        $_FILES->assign($request->files);

        Session::gc();
        Session::start($response);
        $_SESSION->assign(Session::get());
    }

    function cleanGlobalArray($id)
    {
        $this->setGlobalArrayId($id);

        $_SERVER->remove();
        $_GET->remove();
        $_POST->remove();
        $_REQUEST->remove();
        $_FILES->remove();

        Session::set($_SESSION->get());
        $_SESSION->remove();
        $_COOKIE->remove();

        if ($this->coroutine) {
            unset($this->cidMapping[$id]);
        }
    }

    /**
     * @throws WorkerException
     */
    static function runAll()
    {
        WorkerManager::runAll();
    }

    static function stopAll()
    {
        $mainWorker = WorkerManager::getMainWorker();

        if ($mainWorker) {
            $mainWorker->stop();
        } else {
            exit(0);
        }
    }
}