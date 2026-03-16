<?php
/**
 * FastCache 配置文件
 */
return [
    'enable'     => true,
    'table_size' => 20480,     // Table 槽位总数 (需根据业务量预留 20% 空间)
    'chunk_size'      => 8192,      // 单个分块大小 (单位: 字节)，8KB 完美对齐内存页
    'alert_threshold'  => 90,        // 使用率达到多少时触发告警 (%)
    'monitor_mode'     => false,     // 是否实时监控: true (每次检测), false (默认抽样检测, 1/50 概率)
    'server_name'      => '服务器名称',  //服务器名字 推送通知时候区分
    'alert_telegram'   => false,     // 是否开启 Telegram 告警通知，默认 false (记录到 log)
    'alert_cooling'    => 3600,      // 告警冷却时间 (单位: 秒)，默认 1 小时内不重复发送
    'alert_template'   => "<b>[紧急告警] FastCache 内存表容量告急</b>\n\n" .
                          "<b>域名:</b> {host}\n" .
                          "<b>时间:</b> {time}\n" .
                          "<b>分块使用量:</b> {count} / {size} ({usage_pct}%)\n" .
                          "<b>内存使用量:</b> {mem_used}MB / {mem_total}MB ({mem_pct}%)\n\n" .
                          "<b>建议:</b> 请及时在 Config/cache.php 中调大 table_size 并重启服务。",
    /* 
     * 告警模板可用变量说明:
     * {host}       - 当前请求的 HTTP_HOST (域名)
     * {time}       - 告警触发的北京时间 (Y-m-d H:i:s)
     * {count}      - 当前已占用的 Table 槽位/分块数量
     * {size}       - 配置文件中定义的总槽位数量 (table_size)
     * {usage_pct}  - 槽位使用率百分比 (0-100)
     * {mem_used}   - 当前已分配的物理内存 (单位: MB)
     * {mem_total}  - 全局预分配的物理内存总额 (单位: MB)
     * {mem_pct}    - 物理内存实际占用百分比 (0-100)
     */
];
