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
    private $pool; // 直接保存 Pool 对象

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->config = config('mongo');

        // 兼容配置
        if (isset($this->config['default'])) {
            $this->config = $this->config['default'];
        }

        $this->dbname = $this->config["dbname"];
    }

    /**
     * 初始化连接池
     */
    private function initPool()
    {
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
            // 只在首次创建时预热连接池
            $minConnections = $this->config['minPoolSize'] ?? 5;
            $this->warmUpPool($minConnections);
        }
    }

    /**
     * 手动预热连接池
     * @param int $count 预创建连接数量
     */
    private function warmUpPool($count)
    {
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
            fwrite(STDERR, "MongoDB连接池预热失败: " . $e->getMessage() . PHP_EOL);
        }
    }

    /**
     * 创建连接池配置
     * @return PoolConfig
     */
    private function createPoolConfig()
    {
        $poolConfig = new PoolConfig();
        $poolConfig->setMinObjectNum($this->config['minPoolSize'] ?? 5);
        $poolConfig->setMaxObjectNum($this->config['maxPoolSize'] ?? 500);
        $poolConfig->setMaxIdleTime($this->config['maxIdleTime'] ?? 300); 
        $poolConfig->setGetObjectTimeout($this->config['maxWaitTime'] ?? 3.0);
        $poolConfig->setIntervalCheckTime($this->config['intervalCheckTime'] ?? 180000);
        return $poolConfig;
    }

    /**
     * 从连接池获取MongoDB客户端
     * @return Client|null
     */
    private function getClient()
    {
        if (!$this->pool) {
            $this->initPool();
        }
        return $this->pool->getObj();
    }

    /**
     * 归还MongoDB客户端到连接池
     * @param Client $client MongoDB客户端
     */
    private function recycleClient($client)
    {
        if ($client) {
            $this->pool->recycleObj($client);
        }
    }

    /**
     * 使用连接池执行操作
     * @param callable $callable 回调函数
     * @return mixed
     */
    private function usePooledClient(callable $callable)
    {
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

    // 支持静态调用直接调用实例方法
    public static function __callStatic($name, $arguments)
    {
        return call_user_func_array([self::getInstance(), $name], $arguments);
    }

    //查询单条数据返回 - 使用连接池
    public function find(string $collection, array $filter, array $options = [])
    {
        try {
            return $this->usePooledClient(function ($client) use ($collection, $filter, $options) {
                $option = ['limit' => 1];
                $options = array_merge($option, $options);

                $data = $client->selectDatabase($this->dbname)
                    ->selectCollection($collection)
                    ->find($filter, $options)
                    ->toArray();
                if (!empty($data)) {
                    $data = self::Json($data);
                    return $data[0];
                }
                return null;
            });
        } catch (\Throwable $e) {
            AppLog::error("MongoDB 查询错误:  MongoDbHelper::find() " . $e->getMessage(), 'error');
            return null;
        }
    }

    //查询多条数据返回 - 使用连接池
    public function findMany(string $collection, array $filter, array $options = [])
    {
        try {
            return $this->usePooledClient(function ($client) use ($collection, $filter, $options) {
                $data = $client->selectDatabase($this->dbname)
                    ->selectCollection($collection)
                    ->find($filter, $options)
                    ->toArray();
                if (!empty($data)) {
                    return self::Json($data);
                }
                return null;
            });
        } catch (\Throwable $e) {
            AppLog::error("MongoDB 查询错误:  MongoDbHelper::findMany() " . $e->getMessage(), 'error');
            return null;
        }
    }

    //只插入一条数据 重复就不再插入 - 使用连接池
    public function insert(string $collection, array $data)
    {
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
            AppLog::error("MongoDB 插入错误: MongoDbHelper::insert() " . $e->getMessage(), 'error');
            return false;
        }
    }

    //使用连接池插入多条数据
    public function insertMany(string $collection, array $data, $options = ['ordered' => false])
    {
        try {
            return $this->usePooledClient(function ($client) use ($collection, $data, $options) {
                try {
                    $result = $client->selectDatabase($this->dbname)
                        ->selectCollection($collection)
                        ->insertMany($data, $options);
                    return $result->getInsertedCount();
                } catch (\MongoDB\Driver\Exception\BulkWriteException $e) {
                    return $e->getWriteResult()->getInsertedCount();
                }
            });
        } catch (\Throwable $e) {
            AppLog::error("MongoDB 插入错误:  MongoDbHelper::insertMany() " . $e->getMessage(), 'error');
            return 0;
        }
    }

    //根据条件更新数据 - 使用连接池
    public function update(string $collection, array $filter, array $update, array $options = [])
    {
        $defaultOptions = ['multi' => false, 'upsert' => true];
        $options = array_merge($defaultOptions, $options);

        try {
            return $this->usePooledClient(function ($client) use ($collection, $filter, $update, $options) {
                $updateMethod = $options['multi'] ? 'updateMany' : 'updateOne';
                $result = $client->selectDatabase($this->dbname)
                    ->selectCollection($collection)
                    ->$updateMethod(
                        $filter,
                        ['$set' => $update],
                        ['upsert' => $options['upsert']]
                    );
                return $result->getModifiedCount() + $result->getUpsertedCount();
            });
        } catch (\Throwable $e) {
            AppLog::error("MongoDB 更新错误:  MongoDbHelper::update() " . $e->getMessage(), 'error');
            return false;
        }
    }

    // 支持变量变化操作 - 使用连接池
    public function _update(string $collection, array $filter, array $update, array $options = [])
    {
        try {
            return $this->usePooledClient(function ($client) use ($collection, $filter, $update, $options) {
                $result = $client->selectDatabase($this->dbname)
                    ->selectCollection($collection)
                    ->updateOne($filter, $update, $options);
                return $result->getModifiedCount();
            });
        } catch (\Throwable $e) {
            AppLog::error("MongoDB 更新错误:  MongoDbHelper::_update() " . $e->getMessage(), 'error');
            return -1;
        }
    }

    // 只删除一条数据 - 使用连接池
    public function delete(string $collection, array $filter, array $options = [])
    {
        try {
            return $this->usePooledClient(function ($client) use ($collection, $filter, $options) {
                $result = $client->selectDatabase($this->dbname)
                    ->selectCollection($collection)
                    ->deleteOne($filter, $options);
                return $result->getDeletedCount();
            });
        } catch (\Throwable $e) {
            AppLog::error("MongoDB 删除错误:  MongoDbHelper::delete() " . $e->getMessage(), 'error');
            return -1;
        }
    }

    // 删除全部数据 - 使用连接池
    public function deleteMany(string $collection, array $filter, array $options = [])
    {
        try {
            return $this->usePooledClient(function ($client) use ($collection, $filter, $options) {
                $result = $client->selectDatabase($this->dbname)
                    ->selectCollection($collection)
                    ->deleteMany($filter, $options);
                return $result->getDeletedCount();
            });
        } catch (\Throwable $e) {
            AppLog::error("MongoDB 删除错误:  MongoDbHelper::deleteMany() " . $e->getMessage(), 'error');
            return false;
        }
    }

    // 更新一个数据并返回更新后的数据
    public function findAndUpdate(string $collection, array $filter, array $update, array $options = [])
    {
        $defaultOptions = [
            'returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
            'upsert' => true
        ];
        $options = array_merge($defaultOptions, $options);

        try {
            return $this->usePooledClient(function ($client) use ($collection, $filter, $update, $options) {
                $result = $client->selectDatabase($this->dbname)
                    ->selectCollection($collection)
                    ->findOneAndUpdate($filter, ['$set' => $update], $options);
                return self::Json($result);
            });
        } catch (\Throwable $e) {
            AppLog::error("MongoDB 更新错误: MongoDbHelper::findOneAndUpdate() " . $e->getMessage(), 'error');
            return false;
        }
    }

    // 计数 - 使用连接池
    public function count(string $collection, array $filter)
    {
        try {
            return $this->usePooledClient(function ($client) use ($collection, $filter) {
                return $client->selectDatabase($this->dbname)
                    ->selectCollection($collection)
                    ->countDocuments($filter);
            });
        } catch (\Throwable $e) {
            AppLog::error("MongoDB 统计数量:  MongoDbHelper::count() " . $e->getMessage(), 'error');
            return false;
        }
    }

    // 去重复查询
    public function distinct(string $collection, string $filed, array $filter)
    {
        try {
            return $this->usePooledClient(function ($client) use ($collection, $filed, $filter) {
                return $client->selectDatabase($this->dbname)
                    ->selectCollection($collection)
                    ->distinct($filed, $filter);
            });
        } catch (\Throwable $e) {
            AppLog::error("MongoDB 去重查询:  MongoDbHelper::distinct() " . $e->getMessage(), 'error');
            return false;
        }
    }

    // 按照条件聚合查询
    public function pipe($collection, $filter, $pipsql)
    {
        try {
            return $this->usePooledClient(function ($client) use ($collection, $filter, $pipsql) {
                $pipeline = [
                    ['$match' => $filter],
                    ['$group' => $pipsql]
                ];
                $result = $client->selectDatabase($this->dbname)
                    ->selectCollection($collection)
                    ->aggregate($pipeline);
                $data = $result->toArray();
                if (!empty($data)) {
                    $data = self::Json($data);
                    return $data[0];
                }
                return false;
            });
        } catch (\Throwable $e) {
            AppLog::error("MongoDB 聚合查询:  MongoDbHelper::pipe() " . $e->getMessage(), 'error');
            return false;
        }
    }

    // 向数组字段中添加元素
    public function push(string $collection, array $filter, array $update, string $field)
    {
        try {
            return $this->usePooledClient(function ($client) use ($collection, $filter, $update, $field) {
                $pushData = ['$push' => [$field => $update]];
                $result = $client->selectDatabase($this->dbname)
                    ->selectCollection($collection)
                    ->updateOne($filter, $pushData);
                return $result->getModifiedCount();
            });
        } catch (\Throwable $e) {
            AppLog::error("MongoDB 更新数组错误:  MongoDbHelper::push() " . $e->getMessage(), 'error');
            return false;
        }
    }

    // 判断集合是否存在
    public function exists(string $collection)
    {
        try {
            return $this->usePooledClient(function ($client) use ($collection) {
                $collections = $client->selectDatabase($this->dbname)->listCollectionNames();
                foreach ($collections as $name) {
                    if ($name === $collection) return true;
                }
                return false;
            });
        } catch (\Throwable $e) {
            AppLog::error("MongoDB 检查集合错误:  MongoDbHelper::exists() " . $e->getMessage(), 'error');
            return false;
        }
    }

    // 获取自增长ID
    public function autoId(string $field)
    {
        try {
            return $this->usePooledClient(function ($client) use ($field) {
                $command = [
                    'findAndModify' => "autoId",
                    'query' => ['field' => $field],
                    'update' => ['$inc' => ['id' => 1]],
                    'upsert' => true,
                    'new' => true
                ];
                $result = $client->selectDatabase($this->dbname)->command($command)->toArray();
                if (isset($result[0]['ok']) && $result[0]['ok']) {
                    return $result[0]['value']['id'];
                }
                return false;
            });
        } catch (\Throwable $e) {
            AppLog::error("MongoDB 自增长ID错误:  MongoDbHelper::autoId() " . $e->getMessage(), 'error');
            return false;
        }
    }

    // 创建单索引 (兼容性接口)
    public function createIndex(string $collection, array $key, array $options = [])
    {
        try {
            return $this->usePooledClient(function ($client) use ($collection, $key, $options) {
                return $client->selectDatabase($this->dbname)
                    ->selectCollection($collection)
                    ->createIndex($key, $options);
            });
        } catch (\Throwable $e) {
            AppLog::error("MongoDB 创建索引错误: MongoDbHelper::createIndex() " . $e->getMessage(), 'error');
            return false;
        }
    }

    // 批量创建索引
    public function createIndexes(string $collection, array $indexes)
    {
        try {
            return $this->usePooledClient(function ($client) use ($collection, $indexes) {
                $coll = $client->selectDatabase($this->dbname)->selectCollection($collection);
                $res = [];
                foreach ($indexes as $index) {
                    if (!isset($index['keys'])) continue;
                    $res[] = $coll->createIndex($index['keys'], $index['options'] ?? []);
                }
                return $res;
            });
        } catch (\Throwable $e) {
            AppLog::error("MongoDB 创建索引错误: MongoDbHelper::createIndexes() " . $e->getMessage(), 'error');
            return false;
        }
    }

    // 获取所有索引
    public function getIndexes(string $collection)
    {
        try {
            return $this->usePooledClient(function ($client) use ($collection) {
                $indexes = $client->selectDatabase($this->dbname)
                    ->selectCollection($collection)
                    ->listIndexes()
                    ->toArray();
                return self::Json($indexes);
            });
        } catch (\Throwable $e) {
            AppLog::error("MongoDB 获取索引错误: MongoDbHelper::getIndexes() " . $e->getMessage(), 'error');
            return false;
        }
    }

    // 删除索引
    public function dropIndex(string $collection, $indexName)
    {
        try {
            return $this->usePooledClient(function ($client) use ($collection, $indexName) {
                return $client->selectDatabase($this->dbname)
                    ->selectCollection($collection)
                    ->dropIndex($indexName);
            });
        } catch (\Throwable $e) {
            AppLog::error("MongoDB 删除索引错误: MongoDbHelper::dropIndex() " . $e->getMessage(), 'error');
            return false;
        }
    }

    // 删除所有索引
    public function dropAllIndexes(string $collection)
    {
        try {
            return $this->usePooledClient(function ($client) use ($collection) {
                return $client->selectDatabase($this->dbname)
                    ->selectCollection($collection)
                    ->dropIndexes();
            });
        } catch (\Throwable $e) {
            AppLog::error("MongoDB 删除所有索引错误: MongoDbHelper::dropAllIndexes() " . $e->getMessage(), 'error');
            return false;
        }
    }

    // 执行自定义命令
    public function command(array $param)
    {
        try {
            return $this->usePooledClient(function ($client) use ($param) {
                return $client->selectDatabase($this->dbname)->command($param)->toArray();
            });
        } catch (\Throwable $e) {
            AppLog::error("MongoDB 执行命令错误:  MongoDbHelper::command() " . $e->getMessage(), 'error');
            return false;
        }
    }

    // 助手方法：JSON 格式化 (保持兼容性)
    public static function Json($bson)
    {
        if (empty($bson)) return [];
        return json_decode(json_encode($bson), true);
    }

    // 助手方法：BSON 格式化
    public static function Bson($array)
    {
        if (empty($array)) return $array;
        $data = json_encode($array);
        $bson = \MongoDB\BSON\fromJSON($data);
        return \MongoDB\BSON\toPHP($bson);
    }
}