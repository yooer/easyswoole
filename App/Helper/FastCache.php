<?php

namespace App\Helper;

use Swoole\Table;
use EasySwoole\Component\Singleton;
use EasySwoole\EasySwoole\Task\TaskManager;
use EasySwoole\EasySwoole\ServerManager;
use App\Task\TelegramTask;
use EasySwooleLib\Logger\Log;

/**
 * FastCache - 基于 Swoole Table 的高性能跨进程缓存
 */
class FastCache
{
    use Singleton;

    private $table;
    private $chunkSize;

    public function __construct()
    {
        $config = config('cache');
        $this->chunkSize = $config['chunk_size'] ?? 8192; // 默认 8KB
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
        $this->table->column('is_compressed', Table::TYPE_INT, 1); // 新增标志位：是否启用 gzcompress 压缩
        $this->table->column('expire_time', Table::TYPE_INT, 4);
        $this->table->column('int_value', Table::TYPE_INT, 8); // 新增整型值列，支持 64 位整数
        $this->table->create();
    }

    /**
     * 设置缓存
     */
    public function set(string $key, $value, ?int $ttl = null)
    {
        if (!$this->table)
            return false;

        $isSerialized = 0;
        if (is_string($value)) {
            $data = $value;
        } else {
            $data = serialize($value);
            $isSerialized = 1;
        }

        // --- 透明压缩逻辑开始 ---
        $isCompressed = 0;
        if (strlen($data) > 4096) {
            $compressed = gzcompress($data, 6);
            if ($compressed !== false) {
                $data = $compressed;
                $isCompressed = 1;
            }
        }
        // --- 透明压缩逻辑结束 ---

        $len = strlen($data);
        $expireAt = ($ttl > 0) ? (time() + $ttl) : 0;

        if ($len <= $this->chunkSize) {
            $res = $this->table->set($key, [
                'value' => $data,
                'is_chunk' => 0,
                'chunk_count' => 1,
                'chunk_index' => 0,
                'is_serialized' => $isSerialized,
                'is_compressed' => $isCompressed,
                'expire_time' => $expireAt
            ]);

            if (!$res) {
                $this->cleanExpired();
                return $this->table->set($key, [
                    'value' => $data,
                    'is_chunk' => 0,
                    'chunk_count' => 1,
                    'chunk_index' => 0,
                    'is_serialized' => $isSerialized,
                    'is_compressed' => $isCompressed,
                    'expire_time' => $expireAt
                ]);
            }
            if ($res) {
                $this->checkAlert();
            }
            return $res;
        }

        $count = ceil($len / $this->chunkSize);
        $chunkKeys = [];

        for ($i = 0; $i < $count; $i++) {
            $chunkKey = "{$key}_chunk_{$i}";
            $chunkKeys[] = $chunkKey;
            $res = $this->table->set($chunkKey, [
                'value' => substr($data, $i * $this->chunkSize, $this->chunkSize),
                'is_chunk' => 1,
                'chunk_count' => $count,
                'chunk_index' => $i,
                'expire_time' => $expireAt
            ]);

            if (!$res) {
                $this->cleanExpired();
                $res = $this->table->set($chunkKey, [
                    'value' => substr($data, $i * $this->chunkSize, $this->chunkSize),
                    'is_chunk' => 1,
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
            'value' => 'META_DATA',
            'is_chunk' => 2,
            'chunk_count' => (int) $count,
            'chunk_index' => 0,
            'is_serialized' => $isSerialized,
            'is_compressed' => $isCompressed,
            'expire_time' => $expireAt
        ]);

        if (!$res) {
            foreach ($chunkKeys as $ck) {
                $this->table->del($ck);
            }
            return false;
        }

        $this->checkAlert();
        return true;
    }

    /**
     * 设置带过期时间的缓存 (Redis 兼容)
     * @param string $key
     * @param int $ttl 过期时间 (秒)
     * @param mixed $value
     * @return bool
     */
    public function setex(string $key, int $ttl, $value)
    {
        if (is_int($value)) {
            if (!$this->table)
                return false;
            $expireAt = ($ttl > 0) ? (time() + $ttl) : 0;
            $res = $this->table->set($key, [
                'int_value' => $value,
                'is_chunk' => 3,
                'is_serialized' => 0,
                'is_compressed' => 0,
                'expire_time' => $expireAt
            ]);
            if ($res) {
                $this->checkAlert();
            }
            return $res;
        }
        return $this->set($key, $value, $ttl);
    }

    /**
     * 原子自增 (支持 is_chunk=3)
     * @param string $key
     * @param int $column 自增步长
     * @param int|null $ttl 过期时间
     * @return int|false 返回自增后的值，失败返回 false
     */
    public function incr(string $key, int $column = 1, ?int $ttl = null)
    {
        if (!$this->table)
            return false;

        $expireAt = ($ttl > 0) ? (time() + $ttl) : 0;

        // 如果键不存在或已过期，执行初始化 (非原子，但在 Table 锁保护下相对安全)
        $data = $this->table->get($key);
        if (!$data || ($data['expire_time'] > 0 && $data['expire_time'] < time())) {
            $this->table->set($key, [
                'int_value' => $column,
                'is_chunk' => 3, // 标识为原子计数器/纯整型类型
                'is_serialized' => 0,
                'is_compressed' => 0,
                'expire_time' => $expireAt
            ]);
            return $column;
        }

        // 原子自增
        $res = $this->table->incr($key, 'int_value', $column);
        
        // 如果提供了过期时间且发生了变化，或原本没有过期时间，则更新
        if ($ttl > 0) {
            $this->table->set($key, ['expire_time' => $expireAt]);
        }

        return $res;
    }

    /**
     * 检查并触发容量告警 (含抽样与多模式支持)
     */
    private function checkAlert()
    {
        static $cfg = null;
        if ($cfg === null) {
            $cfg = config('cache');
        }

        // 1. 监控模式判断: 只有显式设置为 true 才是实时，否则(false或null)都是抽样
        if (($cfg['monitor_mode'] ?? false) !== true) {
            if (mt_rand(1, 50) !== 1)
                return;
        }

        $threshold = (int) ($cfg['alert_threshold'] ?? 90);
        if ($threshold <= 0)
            return;

        // 获取状态
        $status = $this->status();
        if (!$status['enable'])
            return;

        // 2. 只有超过阈值才继续进入重逻辑
        if ($status['usage_pct'] >= $threshold) {
            $lockKey = '__ALERT_LOCK__';
            $lastAlert = $this->table->get($lockKey);
            $cooling = (int) ($cfg['alert_cooling'] ?? 3600);

            // 冷却期内不触发
            if (!$lastAlert || (time() - $lastAlert['last_time']) >= $cooling) {
                $this->table->set($lockKey, [
                    'value' => 'LOCKED',
                    'last_time' => time()
                ]);

                // 构建模板变量
                $replace = [
                    '{host}' => config('cache.server_name') ?? 'LocalServer',
                    '{time}' => date('Y-m-d H:i:s'),
                    '{count}' => $status['count'],
                    '{size}' => $status['size'],
                    '{usage_pct}' => $status['usage_pct'],
                    '{mem_used}' => $status['memory_used_mb'],
                    '{mem_total}' => $status['memory_reserved_mb'],
                    '{mem_pct}' => round(($status['memory_used_mb'] / $status['memory_reserved_mb']) * 100, 2)
                ];

                // 从配置中获取模板
                $template = $cfg['alert_template'] ?? "<b>[FastCache 报警] 容量告急</b>\n\n分块使用量 : {count} / {size} ({usage_pct}%)\n内存使用量 : {mem_used}MB / {mem_total}MB ({mem_pct}%)\n建议: 调大 table_size 并重启服务。";
                $msgContent = str_replace(array_keys($replace), array_values($replace), $template);

                if ($cfg['alert_telegram'] ?? true) {
                    TaskManager::getInstance()->async(new TelegramTask([
                        'content' => $msgContent,
                        'channel' => 'grounp'
                    ]));
                } else {
                    Log::error(strip_tags($msgContent));
                }
            }
        }
    }

    public function get(string $key)
    {
        if (!$this->table)
            return null;

        $mainData = $this->table->get($key);
        if (!$mainData)
            return null;

        if ($mainData['expire_time'] > 0 && $mainData['expire_time'] < time()) {
            $this->del($key);
            return null;
        }

        $isSerialized = $mainData['is_serialized'];
        $isCompressed = $mainData['is_compressed'] ?? 0;

        if ($mainData['is_chunk'] == 0) {
            $val = $mainData['value'];
            if ($isCompressed) {
                $val = gzuncompress($val);
            }
            return $isSerialized ? unserialize($val) : $val;
        }

        if ($mainData['is_chunk'] == 2) {
            $count = $mainData['chunk_count'];
            $chunks = [];
            for ($i = 0; $i < $count; $i++) {
                $chunk = $this->table->get("{$key}_chunk_{$i}");
                if (!$chunk)
                    return null;
                $chunks[] = $chunk['value'];
            }
            $fullData = implode('', $chunks);
            if ($isCompressed) {
                $fullData = gzuncompress($fullData);
            }
            return $isSerialized ? unserialize($fullData) : $fullData;
        }

        if ($mainData['is_chunk'] == 3) {
            return (int) $mainData['int_value'];
        }

        return null;
    }

    public function cleanExpired()
    {
        if (!$this->table)
            return;
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
        if (!$this->table)
            return false;

        $mainData = $this->table->get($key);
        if ($mainData && $mainData['is_chunk'] == 2) {
            $count = $mainData['chunk_count'];
            for ($i = 0; $i < $count; $i++) {
                $this->table->del("{$key}_chunk_{$i}");
            }
        }

        return $this->table->del($key);
    }

    /**
     * 获取详细的 Table 状态与性能指标
     */
    public function status()
    {
        if (!$this->table) {
            return ['enable' => false];
        }

        $config = config('cache');
        $stats = $this->table->stats();
        $count = $this->table->count();
        $size = (int) ($config['table_size'] ?? 1024); // 从配置读取预分配总容量
        $chunkSize = $this->chunkSize;

        // 计算物理预分配内存占用 (总容量 * (单块大小 + 结构开销))
        $memoryReserved = $size * ($chunkSize + 64);
        // 计算当前数据占据的物理空间 (近似值)
        $memoryUsed = $count * ($chunkSize + 64);

        $server = ServerManager::getInstance()->getSwooleServer();

        return [
            'enable' => true,
            'count' => $count,               // 当前已用条数 (含分块)
            'size' => $size,                 // 总容量 (预分配行数)
            'usage_pct' => $size > 0 ? round(($count / $size) * 100, 2) : 0,
            'free' => max(0, $size - $count),
            'memory_reserved_mb' => round($memoryReserved / 1024 / 1024, 2),
            'memory_used_mb' => round($memoryUsed / 1024 / 1024, 2),
            'chunk_mb' => round($chunkSize / 1024 / 1024, 2),
            'num_rehash' => $stats['num_rehash'] ?? 0,
            'start_time' => $server ? ($server->startTime ?? 0) : 0
        ];
    }

    public function getTable()
    {
        return $this->table;
    }
}
