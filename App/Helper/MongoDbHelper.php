<?php
namespace App\Helper;

use MongoDB\Client;
use EasySwooleLib\Logger\Log as AppLog;
use EasySwoole\Pool\Config as PoolConfig;
use EasySwoole\Component\Singleton;

class MongoDbHelper
{
    use Singleton;
    
    private $dbname = null;
    private $config;
    private $pool;

    public function __construct() {
        $this->config = config('mongo');
        if (isset($this->config['default'])) {
            $this->config = $this->config['default'];
        }
        $this->dbname = $this->config["dbname"];
        // 不在构造函数中预热连接池，防止在 initialize 阶段因网络问题触发日志死循环
    }

    private function initPool() {
        if (!$this->pool) {
            $this->pool = new MongoDbPool(
                $this->createPoolConfig(),
                [
                    'host' => $this->config["host"],
                    'port' => $this->config["port"],
                    'user' => $this->config["user"],
                    'password' => $this->config["password"],
                    'dbname' => $this->config["dbname"],
                    'connectTimeoutMS' => $this->config['connectTimeoutMS'] ?? 5000,
                    'socketTimeoutMS' => $this->config['socketTimeoutMS'] ?? 10000,
                ]
            );
            $minConnections = $this->config['minPoolSize'] ?? 5;
            $this->warmUpPool($minConnections);
        }
    }
    
    private function warmUpPool($count) {
        try {
            $clients = [];
            for ($i = 0; $i < $count; $i++) {
                $obj = $this->pool->getObj();
                if ($obj) {
                    $clients[] = $obj;
                }
            }
            foreach ($clients as $client) {
                $this->pool->recycleObj($client);
            }
        } catch (\Throwable $e) {
            // 在 initialize 阶段，尽量避免使用 AppLog 以免递归循环
            fwrite(STDERR, "MongoDB 连接池预热失败: " . $e->getMessage() . PHP_EOL);
        }
    }
    
    private function createPoolConfig() {
        $poolConfig = new PoolConfig();
        $poolConfig->setMinObjectNum($this->config['minPoolSize'] ?? 5);
        $poolConfig->setMaxObjectNum($this->config['maxPoolSize'] ?? 50);
        $poolConfig->setMaxIdleTime($this->config['maxIdleTime'] ?? 300);
        $poolConfig->setGetObjectTimeout($this->config['maxWaitTime'] ?? 3.0);
        return $poolConfig;
    }

    private function getClient() {
        if (!$this->pool) {
            $this->initPool();
        }
        return $this->pool ? $this->pool->getObj() : null;
    }

    private function recycleClient($client) {
        if ($client) {
            $this->pool->recycleObj($client);
        }
    }

    private function usePooledClient(callable $callable) {
        $client = $this->getClient();
        if (!$client) {
            AppLog::error("MongoDB 获取连接失败", 'error');
            return null; 
        }
        try {
            return call_user_func($callable, $client);
        } catch (\Throwable $e) {
            AppLog::error("MongoDB Pool操作错误: " . $e->getMessage(), 'error');
            throw $e;
        } finally {
            $this->recycleClient($client);
        }
    }
    
    public static function __callStatic($name, $arguments)
    {
        return call_user_func_array([self::getInstance(), $name], $arguments);
    }

    public function find(string $collection, array $filter, array $options = []){ 
        try {
            return $this->usePooledClient(function ($client) use ($collection, $filter, $options) {
                $option = ['limit' => 1];
                $options = array_merge($option, $options);
                $data = $client->selectDatabase($this->dbname)
                    ->selectCollection($collection)
                    ->find($filter, $options)
                    ->toArray();
                if(!empty($data)){
                    return self::Json($data)[0];
                }
                return null;
            });
        } catch (\Throwable $e) {
            AppLog::error("MongoDB 查询错误: " . $e->getMessage(), 'error');
            return null;
        }
    }

    public function findMany(string $collection, array $filter, array $options = []){ 
        try {
            return $this->usePooledClient(function ($client) use ($collection, $filter, $options) {
                $data = $client->selectDatabase($this->dbname)
                    ->selectCollection($collection)
                    ->find($filter, $options)
                    ->toArray();
                if(!empty($data)){
                    return self::Json($data);
                }
                return null;
            });
        } catch (\Throwable $e) {
            AppLog::error("MongoDB 查询错误: " . $e->getMessage(), 'error');
            return null;
        }
    }

    public function insert(string $collection, array $data) { 
        try {
            return $this->usePooledClient(function ($client) use ($collection, $data) {
                try {
                    $result = $client->selectDatabase($this->dbname)
                        ->selectCollection($collection)
                        ->insertOne($data);
                    return $result->getInsertedCount();
                } catch (\MongoDB\Driver\Exception\BulkWriteException $e) {
                    if ($e->getCode() == 11000) return false;
                    throw $e;
                }
            });
        } catch (\Throwable $e) {
            AppLog::error("MongoDB 插入错误: " . $e->getMessage(), 'error');
            return false;
        }
    }

    public function update(string $collection, array $filter, array $update, array $options = []){ 
        $defaultOptions = ['multi' => false, 'upsert' => true];
        $options = array_merge($defaultOptions, $options);
        try {
            return $this->usePooledClient(function ($client) use ($collection, $filter, $update, $options) {
                $updateMethod = $options['multi'] ? 'updateMany' : 'updateOne';
                $result = $client->selectDatabase($this->dbname)
                    ->selectCollection($collection)
                    ->$updateMethod($filter, ['$set' => $update], ['upsert' => $options['upsert']]);
                return $result->getModifiedCount() + $result->getUpsertedCount();
            });
        } catch (\Throwable $e) {
            AppLog::error("MongoDB 更新错误: " . $e->getMessage(), 'error');
            return false;
        }
    }

    public function delete(string $collection, array $filter, array $options = []){ 
        try {
            return $this->usePooledClient(function ($client) use ($collection, $filter, $options) {
                $result = $client->selectDatabase($this->dbname)
                    ->selectCollection($collection)
                    ->deleteOne($filter, $options);
                return $result->getDeletedCount();
            });
        } catch (\Throwable $e) {
            AppLog::error("MongoDB 删除错误: " . $e->getMessage(), 'error');
            return -1;
        }
    }

    public static function Json($bson){
        if (empty($bson)) return [];
        return json_decode(json_encode($bson), true);
    }
}
