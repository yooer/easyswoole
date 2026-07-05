# Web 后台开发约束

本文件是通用的 Web 后台开发规范，跟具体项目、语言、框架无关，人类和 AI 协作开发时都必须遵守。新增/修改代码前先读这份文件。

## 1. 分层架构：Controller 不允许碰数据库

标准 MVC 分层，边界必须硬性隔离：

- **Controller** 只负责：解析请求 → 调用验证器 → 调用 Model/服务类/封装好的数据访问类的方法 →
  把返回值序列化成响应。
- **禁止**在任何 Controller 方法里出现数据库/缓存的直接操作（直接写 SQL、拼查询语句、调用 ORM/驱动的
  查询和写入方法，或者直接 `new` 一个 Redis/Mongo 客户端等）。
- 所有数据库查询、缓存操作、业务规则判断，必须下沉到对应的 **Model / 服务类 / 数据访问封装类** 里，
  Controller 只允许调用这些类暴露出来的方法，不允许自己组装查询逻辑。
- 判断标准：一个 Controller 文件里如果能搜到查询语句、ORM 的 `Find`/`Insert`/`Update` 之类调用，
  就是违规，要挪到 Model 或服务类里去。
- 落地到本项目（EasySwoole）时，数据访问统一走这几类现成封装，不允许绕开它们直接调用底层驱动：
  - **MySQL / ORM**：Model 放在 `App/Model/` 下，继承 `EasySwoole\ORM\AbstractModel`，命名为
    `<业务名>Model`（参考 `App/Model/FooModel.php`）。Model 只承载表结构映射和基础查询方法；
    如果一个操作要跨多个 Model、调用外部服务或组合多步业务规则，就新建一个服务类放在 `App/Service/`
    下（命名 `<业务名>Service`），不要把这些逻辑堆在 Controller 或 Model 里。
  - **MongoDB**：统一用 `App\Helper\MongoDbHelper`（单例，全局函数 `mongo()` 可直接取实例），
    不允许在业务代码里自己 new Mongo 客户端。
  - **进程内缓存（Swoole Table）**：统一用 `App\Helper\FastCache`（单例，全局函数 `cache()`），
    不允许自己创建 `Swoole\Table`。
  - **Redis**：统一用 `EasySwooleLib\Redis\Utility\RedisUtil` 门面方法，不允许自己拿连接池连接
    裸调用驱动。
  - 如果以上封装都没有覆盖到需要的能力，先在对应的 Helper/Service 里补方法，再由 Controller 调用，
    不能为了图快在 Controller 里临时绕过去。

## 2. Controller 层必须有验证器

- Controller 方法不能直接在函数体里手写零散的 `if` 判断参数合法性，也不允许在方法体里临时
  `new Validate()` 拼一份规则数组（反面例子：`App/HttpController/Validate.php` 中直接在方法里
  组装规则的那种写法，不要照抄）。
- 每个接口的输入参数要过一层独立的验证器类：放在 `App/Validate/` 下，继承 `EasyApi\Validate\Validate`，
  命名为 `<业务名>Validate`，规则写在 `protected $rule` 里（参考 `App/Validate/TestValidate.php`）。
  同一个验证器如果要给多个接口复用又有细微差异，用场景（scene）区分，不要复制一份新类。
- Controller 统一通过基类方法调用验证器：`$this->validate($data, XxxValidate::class)` 或
  `$this->validate($data, 'XxxValidate.场景名')`（`EasySwooleLib\Controller\BaseController::validate()`），
  拿到 `true` 或错误信息后再决定走业务逻辑还是直接返回错误，不允许自己重新实现一套校验分发逻辑。
- 验证器和业务逻辑分开：格式/范围/必填这类校验放验证器，"这条记录是否存在"这类业务校验放 Model/服务类。

## 3. Ajax 接口规范

- 所有 Ajax 请求统一用 **POST** 方法，body 固定 **JSON** 格式。
- 请求体里必须带一个 `action` 字段标明这次调用具体要做什么操作——即使这个接口本身不需要任何其他业务参数，
  也不能发一个空 body，至少要带 `action`。
- 这个约定跟 EasySwoole 框架本身按 URL 路径分发（`/控制器/方法`）的路由机制并不冲突，两者并存：
  URL 决定请求落到哪个 Controller 的哪个方法，`action` 字段是给前端统一的 Ajax 调用封装和后端审计
  日志用的显式标记，不能因为 URL 已经能定位方法就省略它。
- Controller 方法只要输出 JSON 格式的响应，**强制**通过全局函数 `json($data, $code, $header, $options)`
  发送（`EasySwooleLib/Helper/Functions.php`），不允许自己 `json_encode()` 后拼 `Content-Type` 手写响应，
  也不允许直接 `new Response(...)` 绕开这个全局函数。

## 4. 静态资源本地化

- **禁止**引用任何外部 CDN（字体、图标库、JS 框架等），所有静态资源必须下载到本地、由后端自己提供。
- 静态资源统一放在项目根目录下的 `public/` 目录中，由 Web 服务器/Swoole 静态文件处理直接对外提供，
  不要散落在 `App/` 或其他业务代码目录里。目录规范（按资源类型分类）：
  - `public/assets/js/` — 业务自己写的 JS
  - `public/assets/css/` — 业务自己写的 CSS
  - `public/assets/image/` — 图片
  - `public/assets/libs/` — 第三方框架/库（jQuery、Vue、Bootstrap 等），和业务自己写的资源分开存放
  - `public/static/img/<模块名>/` — 某个具体模块专属的图片资源

## 5. 模板与样式规范

- HTML 模板页面**禁止**写 `<style>` 内嵌样式块。
- HTML 标签上**禁止**写 `style="..."` 这种原生内联样式属性。
- 页面对应的样式必须写在独立的 CSS 文件里，放在 `public/assets/css/` 下，文件名和页面/模块对应
  （例如后台首页面板对应 `public/assets/css/dash.css`，某个模块的专属设置页对应
  `public/assets/css/<模块名>.css`），页面里用 `<link>` 引用。
- JS 代码允许留在 HTML 页面内，不强制外链成单独文件。
