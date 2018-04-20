<?php

namespace Comoyi\Hall\Objects;

use Comoyi\Hall\Model\Message;
use Comoyi\Hall\Task\SystemInfoTask;
use Swoole\Websocket\Server as SwooleWebsocketServer;

/**
 * 消息网关
 */
class Gate
{

    /**
     * start
     */
    public function start()
    {
        $server = new SwooleWebsocketServer(config('server.websocket.host'), config('server.websocket.port'));

        // 添加到容器
        container('server', $server);

        // 设置task worker 数量
        $server->set([
            'worker_num' => 3,
            'task_worker_num' => 5,
        ]);

        // start
        $server->on('start', [$this, 'onStart']);

        // worker start
        $server->on('workerStart', [$this, 'onWorkerStart']);

        // client连接
        $server->on('open', [$this, 'onOpen']);

        // 收到消息
        $server->on('message', [$this, 'onMessage']);

        // client断开连接
        $server->on('close', [$this, 'onClose']);

        //处理task
        $server->on('task', [$this, 'onTask']);

        //完成task
        $server->on('finish', [$this, 'onFinish']);

        // 开启服务
        $server->start();
    }

    /**
     * @param $server
     */
    public function onStart(SwooleWebsocketServer $server)
    {
        echo '[' . date('Y-m-d H:i:s') . ']' . ' server started!' . PHP_EOL .
            "[master pid: {$server->master_pid}] [manager pid: {$server->manager_pid}]" . PHP_EOL;
    }

    /**
     * @param $server
     * @param $workerId
     */
    public function onWorkerStart(SwooleWebsocketServer $server, $workerId)
    {
        echo '[' . date('Y-m-d H:i:s') . ']' . " worker started! [id: {$workerId}]" . PHP_EOL;

        if (0 == $workerId) {
            $server->task('system-info');
        }
    }

    /**
     * @param $server
     * @param $request
     */
    public function onOpen(SwooleWebsocketServer $server, $request)
    {
        echo "client-{$request->fd} connected success." . PHP_EOL;

        foreach ($server->connections as $connection) {
            container('packet')->send($connection, [
                'cmd' => 'GlobalMessage',
                'msg' => [
                    'type' => Message::TYPE_TEXT,
                    'content' => "client-{$request->fd} connected success.",
                ],
            ]);
        }
    }

    /**
     * @param $server
     * @param $frame
     */
    public function onMessage(SwooleWebsocketServer $server, $frame)
    {
        // 例 $frame->data {"packageId":"","clientId":"","packageType":"","token":"","data":[{"cmd":"ping"},{"cmd":"login","username":"user-1","password":"pwd-1"}]}
        container('packet')->receive($frame);
    }

    /**
     * @param $server
     * @param $fd
     */
    public function onClose(SwooleWebsocketServer $server, $fd)
    {
        echo "client-{$fd} is closed" . PHP_EOL;

        foreach ($server->connections as $connection) {
            container('packet')->send($connection, [
                'cmd' => 'GlobalMessage',
                'msg' => [
                    'type' => Message::TYPE_TEXT,
                    'content' => "client-{$fd} closed.",
                ],
            ]);
        }
    }

    /**
     * @param $server
     * @param $taskId
     * @param $fromId
     * @param $data
     */
    public function onTask(SwooleWebsocketServer $server, $taskId, $fromId, $data)
    {
        echo "task start [task id: {$taskId}]" . PHP_EOL;

        if ($data == 'system-info') {
            $task = new SystemInfoTask();
            $task->run();
        }

        echo "task finished [task id: {$taskId}]" . PHP_EOL;

        $server->finish($data);
    }

    /**
     * @param $server
     * @param $taskId
     * @param $data
     */
    public function onFinish(SwooleWebsocketServer $server, $taskId, $data)
    {
        echo "async task finished [task id: {$taskId}]." . PHP_EOL . 'task data: ' . var_export($data, true) . PHP_EOL;
    }
}
