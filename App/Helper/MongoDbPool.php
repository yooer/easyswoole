<?php

namespace App\Helper;

use EasySwoole\Pool\AbstractPool;
use EasySwoole\Pool\Config;
use MongoDB\Client;
use EasySwooleLib\Logger\Log as AppLog;

/**
 * MongoDB连接池实现
 */
class MongoDbPool extends AbstractPool
{
    protected $mongoConfig;

    public function __construct(Config $conf, array $mongoConfig)
    {
        parent::__construct($conf);
        $this->mongoConfig = $mongoConfig;
    }

    protected function createObject()
    {
        try {
            $username = rawurlencode($this->mongoConfig['user']);
            $password = rawurlencode($this->mongoConfig['password']);
            $uri = "mongodb://{$username}:{$password}@{$this->mongoConfig['host']}:{$this->mongoConfig['port']}";
            
            $options = [
                'authSource' => $this->mongoConfig['dbname'],
                'connectTimeoutMS' => $this->mongoConfig['connectTimeoutMS'] ?? 5000,
                'socketTimeoutMS' => $this->mongoConfig['socketTimeoutMS'] ?? 10000,
                'typeMap' => [
                    'root' => 'array',
                    'document' => 'array',
                    'array' => 'array',
                ],
            ];
            
            $client = new Client($uri, $options);
            $client->selectDatabase($this->mongoConfig['dbname'])->command(['ping' => 1]);
            return $client;
        } catch (\Throwable $e) {
            AppLog::error("MongoDB连接创建失败: " . $e->getMessage(), 'error');
            throw $e;
        }
    }
    
    protected function itemIntervalCheck($item): bool
    {
        try {
            $item->selectDatabase($this->mongoConfig['dbname'])->command(['ping' => 1]);
            return true;
        } catch (\Throwable $e) {
            AppLog::error("MongoDB连接检查失败: " . $e->getMessage(), 'error');
            return false;
        }
    }
    
    public function recycleObj($obj): bool
    {
        try {
            return parent::recycleObj($obj);
        } catch (\Throwable $e) {
            AppLog::error("MongoDB连接回收失败: " . $e->getMessage(), 'error');
            return false;
        }
    }
    
    public function getObj(?float $timeout = null, int $tryTimes = 3)
    {
        try {
            return parent::getObj($timeout);
        } catch (\Throwable $e) {
            AppLog::error("MongoDB连接获取失败: " . $e->getMessage(), 'error');
            throw $e;
        }
    }
}
