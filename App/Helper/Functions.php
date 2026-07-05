<?php
if (!function_exists('cache')) {
    /**
     * @return \App\Helper\FastCache
     */
    function cache()
    {
        return \App\Helper\FastCache::getInstance();
    }
}

if (!function_exists('mongo')) {
    /**
     * @return \App\Helper\MongoDbHelper
     */
    function mongo()
    {
        return \App\Helper\MongoDbHelper::getInstance();
    }
}

if (!function_exists('mongoIndex')) {
    /**
     * 高并发安全的 MongoDB 索引创建助手函数
     * @param string $collection 集合名称
     * @param array $indexes 索引配置二维数组，例如 [['keys' => ['id' => 1], 'options' => ['unique' => true]]]
     */
    function mongoIndex(string $collection, array $indexes): void
    {
        if (!cache()->get('IndexChecked:' . $collection)) {
            $lockDir = EASYSWOOLE_ROOT . '/runtime/locks';
            if (!is_dir($lockDir)) {
                @mkdir($lockDir, 0777, true);
            }
            $lockFile = $lockDir . '/' . $collection . '_index.lock';

            if (file_exists($lockFile)) {
                cache()->set('IndexChecked:' . $collection, 1);
                return;
            }

            $fp = @fopen($lockFile . '.tmp', 'w+');
            if ($fp && flock($fp, LOCK_EX | LOCK_NB)) {
                try {
                    $hasError = false;
                    foreach ($indexes as $index) {
                        try {
                            $keys = $index['keys'];
                            $options = $index['options'] ?? [];
                            // 强制追加后台建立参数，保护线上数据库不被锁表
                            $options['background'] = true;
                            mongo()->createIndex($collection, $keys, $options);
                        } catch (\Throwable $e) {
                            $hasError = true;
                            // 必须记录日志，因为可能是表里有重复脏数据导致唯一索引建立失败
                            // \App\Utility\Log::error("Index creation failed for {$collection}: " . $e->getMessage());
                        }
                    }
                    // 无论部分索引是否因为脏数据报错，都写入永久锁和内存标记。
                    // 否则如果有一个索引建失败，会导致每次请求都疯狂穿透到文件锁去建立索引，引发严重的性能危机。
                    file_put_contents($lockFile, time());
                    cache()->set('IndexChecked:' . $collection, 1);
                } finally {
                    flock($fp, LOCK_UN);
                    fclose($fp);
                }
            }
        }
    }
}
