<?php

namespace App\Helper;

use Swoole\Table;
use EasySwoole\Component\Singleton;

/**
 * FastCache - 基于 Swoole Table 的高性能跨进程缓存
 * 支持 10M-20M 大数据的自动切块存储
 */
class FastCache
{
    use Singleton;

    private $table;
    private $chunkSize;

    public function __construct()
    {
        $config = config('cache');
        $this->chunkSize = $config['chunk_size'] ?? 2097152; // 默认 2MB
    }

    /**
     * 初始化表格 (必须在主进程启动前调用)
     */
    public function initTable()
    {
        $config = config('cache');
        if (!($config['enable'] ?? false)) {
            return;
        }

        $tableSize = $config['table_size'] ?? 1024;
        
        $this->table = new Table($tableSize);
        $this->table->column('value', Table::TYPE_STRING, $this->chunkSize);
        $this->table->column('is_chunk', Table::TYPE_INT, 1);
        $this->table->column('chunk_count', Table::TYPE_INT, 2);
        $this->table->column('chunk_index', Table::TYPE_INT, 2);
        $this->table->column('is_serialized', Table::TYPE_INT, 1);
        $this->table->column('expire_time', Table::TYPE_INT, 4);
        $this->table->create();
    }

    /**
     * 设置缓存
     */
    public function set(string $key, $value, ?int $ttl = null)
    {
        if (!$this->table) return false;

        $isSerialized = 0;
        if (is_string($value)) {
            $data = $value;
        } else {
            $data = serialize($value);
            $isSerialized = 1;
        }
        
        $len = strlen($data);
        $expireAt = ($ttl > 0) ? (time() + $ttl) : 0;

        if ($len <= $this->chunkSize) {
            $res = $this->table->set($key, [
                'value'         => $data,
                'is_chunk'      => 0,
                'chunk_count'   => 1,
                'chunk_index'   => 0,
                'is_serialized' => $isSerialized,
                'expire_time'   => $expireAt
            ]);
            
            if (!$res) {
                $this->cleanExpired();
                return $this->table->set($key, [
                    'value'         => $data,
                    'is_chunk'      => 0,
                    'chunk_count'   => 1,
                    'chunk_index'   => 0,
                    'is_serialized' => $isSerialized,
                    'expire_time'   => $expireAt
                ]);
            }
            return $res;
        }

        $count = ceil($len / $this->chunkSize);
        $chunkKeys = [];

        for ($i = 0; $i < $count; $i++) {
            $chunkKey = "{$key}_chunk_{$i}";
            $chunkKeys[] = $chunkKey;
            $res = $this->table->set($chunkKey, [
                'value'       => substr($data, $i * $this->chunkSize, $this->chunkSize),
                'is_chunk'    => 1,
                'chunk_count' => $count,
                'chunk_index' => $i,
                'expire_time' => $expireAt
            ]);

            if (!$res) {
                $this->cleanExpired();
                $res = $this->table->set($chunkKey, [
                    'value'       => substr($data, $i * $this->chunkSize, $this->chunkSize),
                    'is_chunk'    => 1,
                    'chunk_count' => $count,
                    'chunk_index' => $i,
                    'expire_time' => $expireAt
                ]);
            }

            if (!$res) {
                foreach ($chunkKeys as $ck) {
                    $this->table->del($ck);
                }
                return false;
            }
        }

        $res = $this->table->set($key, [
            'value'         => 'META_DATA',
            'is_chunk'      => 2,
            'chunk_count'   => (int)$count,
            'chunk_index'   => 0,
            'is_serialized' => $isSerialized,
            'expire_time'   => $expireAt
        ]);

        if (!$res) {
            foreach ($chunkKeys as $ck) {
                $this->table->del($ck);
            }
            return false;
        }

        return true;
    }

    public function get(string $key)
    {
        if (!$this->table) return null;

        $mainData = $this->table->get($key);
        if (!$mainData) return null;

        if ($mainData['expire_time'] > 0 && $mainData['expire_time'] < time()) {
            $this->del($key);
            return null;
        }

        $isSerialized = $mainData['is_serialized'];

        if ($mainData['is_chunk'] == 0) {
            return $isSerialized ? unserialize($mainData['value']) : $mainData['value'];
        }

        if ($mainData['is_chunk'] == 2) {
            $count = $mainData['chunk_count'];
            $chunks = [];
            for ($i = 0; $i < $count; $i++) {
                $chunk = $this->table->get("{$key}_chunk_{$i}");
                if (!$chunk) return null;
                $chunks[] = $chunk['value'];
            }
            $fullData = implode('', $chunks);
            return $isSerialized ? unserialize($fullData) : $fullData;
        }

        return null;
    }

    public function cleanExpired()
    {
        if (!$this->table) return;
        $now = time();
        foreach ($this->table as $key => $row) {
            if ($row['expire_time'] > 0 && $row['expire_time'] < $now) {
                if ($row['is_chunk'] == 2) {
                    $this->del($key);
                } else {
                    $this->table->del($key);
                }
            }
        }
    }

    public function del(string $key)
    {
        if (!$this->table) return false;

        $mainData = $this->table->get($key);
        if ($mainData && $mainData['is_chunk'] == 2) {
            $count = $mainData['chunk_count'];
            for ($i = 0; $i < $count; $i++) {
                $this->table->del("{$key}_chunk_{$i}");
            }
        }

        return $this->table->del($key);
    }

    public function getTable()
    {
        return $this->table;
    }
}
