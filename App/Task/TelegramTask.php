<?php

namespace App\Task;

use App\Utility\Telegram;
use EasySwoole\Task\AbstractInterface\TaskInterface;

class TelegramTask implements TaskInterface
{
    protected $data;

    public function __construct($data)
    {
        // $data 格式: ['text' => '...', 'channel' => 'grounp']
        $this->data = $data;
    }

    public function run(int $taskId, int $workerIndex)
    {
        try {
            $channel = $this->data['channel'] ?? 'grounp';
            $tg = new Telegram($channel);
            $tg->sendText($this->data['content']);
        } catch (\Throwable $e) {
            // 异步任务内部消化异常
        }

        return true;
    }

    public function onException(\Throwable $throwable, int $taskId, int $workerIndex)
    {
    }
}
