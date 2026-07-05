中文

![](index.svg)

[![Latest Stable Version](https://poser.pugx.org/easy-swoole-php/easyswoole-skeleton/v/stable)](https://packagist.org/packages/easy-swoole-php/easyswoole-skeleton)
[![Total Downloads](https://poser.pugx.org/easy-swoole-php/easyswoole-skeleton/downloads)](https://packagist.org/packages/easy-swoole-php/easyswoole-skeleton)
[![Latest Unstable Version](https://poser.pugx.org/easy-swoole-php/easyswoole-skeleton/v/unstable)](https://packagist.org/packages/easy-swoole-php/easyswoole-skeleton)
[![License](https://poser.pugx.org/easy-swoole-php/easyswoole-skeleton/license)](https://packagist.org/packages/easy-swoole-php/easyswoole-skeleton)
[![Monthly Downloads](https://poser.pugx.org/easy-swoole-php/easyswoole-skeleton/d/monthly)](https://packagist.org/packages/easy-swoole-php/easyswoole-skeleton)

# 更新部分功能

原版代码在这里，在 [XueSiLf/easyswoole-docker](https://github.com/easy-swoole-php/easyswoole-skeleton) 
作者比较忙，框架中有几个Bug，修改了几个文件
- EasySwooleLib\Helper\Functions.php 
	修改了 response 方法
- EasySwooleLib\Response\Response.php
- EasySwooleLib\Request\Request.php

其他文件没有修改(移除了一些无用的配置文件 比如docker的)!

# 最近项目重构与功能更新 (2026-03-13)

## 1. 架构调整与核心 Helper
- **FastCache **: 并迁移至 `App/Helper/FastCache.php`，作为全局共享内存缓存。
- **MongoDB 封装**: `MongoDbHelper` 与 `MongoDbPool` 迁移至 `App/Helper` 目录。
- **全局初始化**: `FastCache` 与 `Mongo` 服务已在 `GlobalEvent.php` 中配置为服务启动时自动初始化。
- **便捷函数**: 在 `App/Helper/Functions.php` 中新增了全局 Helper 函数：
    - `cache()`: 获取 `FastCache` 实例。
    - `mongo()`: 获取 `MongoDbHelper` 实例。

## 2. 新增工具类 (App/Utility)
- **PlatesRender.php**: 集成 `league/plates` 模板引擎，支持灵活的视图渲染。
- **SendMail.php**: 基于 `PHPMailer` 的 SMTP 邮件发送工具，支持 HTML 格式。
- **Telegram.php**: 支持富文本、图片、媒体组及内联键盘的 Telegram Bot 推送工具。

## 3. 环境与配置
- 新增配置项模板：
    - `Config/cache.php`: 缓存配置。
    - `Config/mongo.php`: MongoDB 连接池。
    - `Config/smtp.php`: 邮件服务器设置。
    - `Config/telegram.php`: 机器人 Token 与频道 ID。

## 4. 依赖更新
- 执行了 `composer update`，新增了 `mongodb/mongodb`、`phpmailer/phpmailer`、`guzzlehttp/guzzle`、`league/plates` 等必要依赖。

# 骨架介绍

这是一个使用 `EasySwoole` 框架搭建的骨架应用程序。这个骨架让开发者更容易使用 `EasySwoole` 框架。该应用程序旨在作为那些希望熟悉 `EasySwoole` 框架的人的起点。

# 安装要求

`EasySwoole` 对系统环境有一些要求，只能在 `Linux` 和 `Mac` 环境下运行。你的运行环境需要满足以下要求：

- PHP >= 8.1
- Swoole PHP 扩展（具体版本兼容范围参考 `composer.json` 及 `deploy/` 目录下的部署脚本说明）
- JSON PHP 扩展
- Pcntl PHP 扩展
- OpenSSL PHP 扩展（如果需要使用 `HTTPS`）

# 一键部署脚本（Ubuntu 24.04 + 宝塔）

如果你的服务器是 Ubuntu 24.04，已经装好宝塔面板（aaPanel）和对应的 PHP，可以直接用
[`deploy/bt-swoole-mongodb-setup.sh`](deploy/bt-swoole-mongodb-setup.sh) 一键处理：

- 自动探测宝塔已装的 PHP 版本，按版本自动匹配可用的 Swoole / MongoDB 扩展版本并源码编译安装
- 写入 php.ini 并重启 php-fpm
- 写入 `/etc/security/limits.conf`、`/etc/sysctl.conf` 的 ulimit / 内核参数优化（幂等，可重复执行）
- 跑完自动做一次自检（扩展加载情况、关键内核参数当前值）

```bash
sudo bash deploy/bt-swoole-mongodb-setup.sh
```

**注意**：脚本默认不会给 PHP 8.4 装 Swoole——Swoole 6.0+ 才支持 PHP 8.4，但 6.0+ 彻底移除了
`Swoole\Coroutine\MySQL`/`Redis`，会直接弄坏本项目依赖的 `easyswoole/mysqli`（ORM）和
`easyswoole/redis-pool`，所以本项目暂时只能用 PHP 8.0-8.3。具体版本映射表和可用的环境变量
（`PHP_VERSION`、`SWOOLE_VERSION`、`MONGODB_VERSION` 等）见脚本头部注释。

# 使用 Composer 安装

创建新 `EasySwoole` 项目的最简单方法是使用 [Composer](https://getcomposer.org/)。 如果您尚未安装，请按照[文档](https://getcomposer.org/download/)安装。

创建新的 `EasySwoole` 项目：



## 安装 3.7.x 版本

```bash
composer create-project yooer/easyswoole project_name
```

安装完显示PHPUnit依赖有问题，要解决这个问题，您需要使用以下命令更新 PHPUnit 到修复版本：

```bash
composer update phpunit/phpunit --with-dependencies
```


# 建议

- 建议您将骨架中部分文件中的项目名称重命名为您实际的项目名称，例如 `composer.json`。
- 查看 `App/HttpController/Index.php` 以查看 HTTP 入口点的示例。

**请记住**：您始终可以将此 `README.md` 文件的内容替换为适合您项目的内容描述。

## 联系我们

问题：[https://github.com/easy-swoole/easyswoole/issues](https://github.com/easy-swoole/easyswoole/issues)

加群请加微信：

<img src="https://raw.githubusercontent.com/easy-swoole-php/easyswoole-skeleton/main/contactus.jpg" width="210">
