<?php
/**
 * MongoDB 配置文件
 */
return [
    'host'           => '127.0.0.1',
    'port'           => '27017',
    'user'           => 'test1',
    'password'       => 'bTGinYDen5sF7sjn',
    'dbname'         => 'test1',
    // 连接池配置
    'minPoolSize' => 5,      // 最小连接数
    'maxPoolSize' => 50,     // 最大连接数
    'maxIdleTime' => 600,     // 最大空闲时间(秒)
    'maxWaitTime' => 3.0,    // 获取连接最大等待时间(秒)
    'connectTimeoutMS' => 5000,  // 连接超时(毫秒)
    'socketTimeoutMS' => 10000,   // 操作超时(毫秒)
    'enable'         => true,  // 显式配置是否在启动时预热
];
