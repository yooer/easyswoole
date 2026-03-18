<?php

namespace EasySwooleLib\Redis\Utility;

use EasySwoole\Redis\Config as RedisConfig;

trait SerializeTrait
{
    /**
     * 序列化处理value
     *
     * @param        $value
     * @param int|null $serializeType
     * @param string $connectionName
     *
     * @return string
     */
    public static function serialize($value, ?int $serializeType = null, string $connectionName = 'default')
    {
        if ($serializeType === null) {
            $serializeType = config("redis.{$connectionName}.serialize") ?? RedisConfig::SERIALIZE_JSON;
            if ($serializeType === RedisConfig::SERIALIZE_NONE) {
                // 如果底层的 redis 设置了 NONE，我们在此处强升为 JSON 进行拦截
                $serializeType = RedisConfig::SERIALIZE_JSON;
            }
        }

        switch ($serializeType) {
            case RedisConfig::SERIALIZE_PHP:
                return serialize($value);
            case RedisConfig::SERIALIZE_JSON:
                return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            case RedisConfig::SERIALIZE_NONE:
            default:
                return $value;

        }
    }

    /**
     * 反序列化value
     *
     * @param string $value
     * @param int|null $serializeType
     * @param string $connectionName
     *
     * @return mixed|string
     */
    public static function unSerialize(?string $value, ?int $serializeType = null, string $connectionName = 'default')
    {
        // 应对不存在键或 vendor 层不安全的返回拦截
        if ($value === null) {
            return null;
        }

        if ($serializeType === null) {
            $serializeType = config("redis.{$connectionName}.serialize") ?? RedisConfig::SERIALIZE_JSON;
            if ($serializeType === RedisConfig::SERIALIZE_NONE) {
                 // 如果底层的 redis 设置了 NONE，我们在此处强升为 JSON 进行拦截反序列化
                $serializeType = RedisConfig::SERIALIZE_JSON;
            }
        }

        switch ($serializeType) {
            case RedisConfig::SERIALIZE_PHP: {
                $res = @unserialize($value);
                return $res !== false ? $res : $value;
            }
            case RedisConfig::SERIALIZE_JSON: {
                $res = json_decode($value, true);
                return $res !== null ? $res : $value;
            }
            case RedisConfig::SERIALIZE_NONE:
            default: {
                return $value;
            }
        }
    }
}
