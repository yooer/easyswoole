<?php
/**
 * FastCache 配置文件
 */
return [
    'enable'     => true,
    'table_size' => 2048,      // Table 槽位总数 (需根据业务量预留 20% 空间)
    'chunk_size' => 2097152,   // 单个分块大小 (单位: 字节)，默认为 2MB
];
