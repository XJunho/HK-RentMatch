## HK RentMatch – 香港研究生租房匹配平台

一个面向香港授课型研究生的租房信息与室友匹配平台，支持注册登录、发布租房帖、首页筛选浏览、帖子详情查看等核心功能，帮助学生在有限时间内快速找到合适房源或合租对象。

---

## 技术栈与运行环境

- **后端**: PHP 7.4+（兼容 PHP 8，未使用框架）
- **数据库**: MySQL 5.7+/8.0
- **数据访问**: 原生 PDO（`includes/config.php` 中创建全局 `$pdo` 连接）
- **前端**: 原生 HTML / CSS / JavaScript（`assets/css/style.css`, `assets/js/main.js`）
- **推荐本地环境**:
  - Windows: XAMPP / WAMP（内置 Apache + PHP + MySQL）
  - macOS: MAMP / 自行安装 Apache + PHP + MySQL
  - 也可使用 Docker 或远程 LAMP 环境，只要能运行 PHP + MySQL 即可

---

## 目录结构概览

项目核心代码位于 `hk-rentmatch/` 目录，主要文件与职责如下：

```text
hk-rentmatch/
├─ index.php              # 首页：房源列表 + 筛选 + 分页 + 快捷发布入口
├─ login.php              # 登录页：表单校验 + 会话管理
├─ register.php           # 注册页：学生/房东注册表单与逻辑
├─ logout.php             # 退出登录，清理会话
├─ admin.php              # 后台管理（数据看板、帖子/用户管理，仅 role=admin）
├─ database.sql           # 数据库初始化脚本（建库/建表 + 示例数据）
│
├─ includes/
│  ├─ config.php          # 数据库配置与 PDO 连接（需按本地环境修改）
│  ├─ auth.php            # 会话启动、登录状态工具函数
│  ├─ header.php          # 公共页头 HTML（导航栏、基础布局）
│  └─ footer.php          # 公共页脚 HTML（脚本引入等）
│
├─ post/
│  ├─ create.php          # 发布租房帖子表单与写库逻辑
│  └─ detail.php          # 帖子详情页，展示单条房源信息
│
└─ assets/
   ├─ css/
   │  └─ style.css        # 项目主样式（首页卡片、筛选栏、表单等）
   └─ js/
      └─ main.js          # 首页交互（筛选按钮、前端排序、弹窗等）
```

---

## 快速开始（本地运行）

以下步骤适用于第一次接触本项目的新同学，预计 10–15 分钟可以完成环境搭建并跑通核心流程。

### 1. 准备 PHP + MySQL 运行环境

- 安装并启动任意一套 LAMP/WAMP 环境，例如：
  - XAMPP: 安装后启动 **Apache** 和 **MySQL**
  - WAMP/MAMP: 确保 Apache、MySQL 都处于运行状态
- 确认浏览器可访问 `http://localhost/`，并能进入 phpMyAdmin（通常为 `http://localhost/phpmyadmin`）。

### 2. 放置项目代码

以 XAMPP 为例（其他环境类似）：

1. 将整个 `hk-rentmatch/` 文件夹复制到：
   - Windows: `C:\xampp\htdocs\` 目录下
2. 最终路径类似：
   - `C:\xampp\htdocs\hk-rentmatch\index.php`

如果你的 Web 根目录不是 `htdocs`，请按实际情况调整路径。

### 3. 导入数据库

1. 通过浏览器打开 phpMyAdmin（如 `http://localhost/phpmyadmin`）。
2. 在左侧点击「新建」，**无需手动输入库名**，直接进入「导入」功能页。
3. 选择本项目中的 `database.sql` 文件：
   - 路径示例：`hk-rentmatch/database.sql`
4. 点击「执行」。

脚本会自动完成：

- 如果不存在就创建数据库：`hk_rentmatch`
- 创建表：`users`、`posts`、`favorites`、`applications`（与当前代码一致）
- 写入 2 个示例用户和若干示例房源数据，便于开发调试

全新安装**只需**执行 `database.sql`，无需再跑 `migrate.sql`。若你本地是从很旧的库升级、不能删表，再使用根目录下的 `migrate.sql` 做增量变更。

导入成功后，左侧可以看到 `hk_rentmatch` 数据库及上述表。

### 4. 配置数据库连接（`includes/config.php`）

打开 `includes/config.php`，根据你本地的 MySQL 设置修改以下变量：

```php
$dbHost = 'localhost';   // 一般保持默认即可
$dbName = 'hk_rentmatch'; // 与 database.sql 中创建的库名保持一致
$dbUser = 'root';        // 本地 MySQL 用户名
$dbPass = '';            // 本地 MySQL 密码（XAMPP 默认空，WAMP/MAMP 可能为 'root'）
```

- 若你在导入时修改了数据库名，例如改为 `rentmatch_demo`，请同时更新 `$dbName`。
- 保存文件后，无需重启 Apache，刷新页面即可生效。

### 5. 访问项目

在浏览器输入（以 XAMPP 为例）：

- `http://localhost/hk-rentmatch/index.php`（首页）
- `http://localhost/hk-rentmatch/login.php`（登录）
- `http://localhost/hk-rentmatch/register.php`（注册）

如果一切配置正常，首页会展示示例房源卡片和筛选区域。

### 6. 管理员账号与后台访问

默认 `database.sql` 中的示例用户为 **学生** 与 **房东**，**不包含**管理员账号。需要自行把某个已有账号提升为管理员后再使用后台：

1. **推荐做法**：先在 `register.php` 注册一个测试账号并记住登录邮箱。
2. 在 phpMyAdmin 选中库 `hk_rentmatch`，打开「SQL」标签，执行（将邮箱换成你的账号）：

   ```sql
   UPDATE users SET role = 'admin' WHERE email = '你的邮箱@example.com';
   ```

3. **重新登录**（若当前已登录，可先退出再登录），使会话中的角色生效。
4. **访问后台**：
   - 浏览器打开：`http://localhost/hk-rentmatch/admin.php`（路径随你的站点根目录调整）。
   - 登录后，顶部导航在「转租专区」右侧会显示 **「后台管理」**；用户头像下拉菜单中亦有 **「后台管理」**（仅 `role = 'admin'` 时可见）。
5. **权限说明**：未登录访问 `admin.php` 会跳转首页并提示登录；已登录但非管理员会跳转首页并带 `?admin=forbidden`，无法进入后台。

---

## 核心功能说明

### 1. 用户注册 / 登录

- **注册（`register.php`）**
  - 支持两种身份：`港硕学生（student）`、`房源供给方（landlord）`
  - 学生需填写所属学校（如 HKU、CityU 等），房东则可不填
  - 校验内容包括：邮箱格式、昵称长度、性别选择、香港本地 8 位电话号码（首位 2–9）、密码复杂度（8–20 位且包含大小写字母与数字）、两次密码一致性
  - 校验通过后，将用户写入 `users` 表，并通过 `login_user(...)` 自动登录

- **登录（`login.php`）**
  - 使用注册邮箱 + 密码登录
  - 后端使用 `password_verify` 核验密码哈希
  - 若账号状态为 `banned` 会给出相应提示
  - 登录成功后跳转首页，后续页面可通过会话判断登录状态

- **退出（`logout.php`）**
  - 清除会话信息，跳转到首页或登录页

### 2. 帖子发布与详情

- **发布帖子（`post/create.php`）**
  - 仅已登录用户可访问
  - 支持填写标题、价格、区域、租期（短租/中期/长租）、适合学校范围、地铁站、楼层等信息
  - 后端使用 PDO 预处理语句将数据写入 `posts` 表，默认状态为 `active`

- **帖子详情（`post/detail.php`）**
  - 根据 `id` 查询单条帖子
  - 展示完整的文案、价格、区域、适合学校、地铁站信息，以及发布者昵称等

### 3. 首页列表与筛选（`index.php`）

- 首页默认展示所有处于 `active` 状态、类型为 `rent` 的租房帖子：
  - 区域筛选：`region`
  - 学校范围筛选：`school_scope`
  - 租金区间筛选：`min_price` / `max_price`
  - 租期筛选：`rent_period`（short/medium/long）
  - 关键字搜索：在标题与内容中模糊匹配
- 使用 PDO 构造 WHERE 条件并进行分页：
  - 每页默认显示 9 条
  - 底部分页按钮支持页码跳转
- 前端使用卡片样式展示每条房源，包含：
  - 标题、价格、区域标签、地铁站信息、适合学校范围
  - 发布者昵称首字作为头像缩略标识

---

## 数据库设计简要

### `users` 表（用户）

- **主键**: `id`（INT, AUTO_INCREMENT）
- **核心字段**:
  - `username`：昵称 / 显示名称
  - `email`：登录邮箱（唯一索引 `uniq_email`）
  - `password`：密码哈希，使用 `password_hash` 生成
  - `phone`：联系方式（香港本地 8 位号码，首位 2–9）
  - `gender`：`male` / `female` / `other`
  - `role`：`student` / `landlord` / `admin`（当前主要用到 student 与 landlord）
  - `school`：所属学校（如 CityU, HKU）
  - `status`：`active` / `banned`

### `posts` 表（租房帖子）

- **主键**: `id`
- **外键**:
  - `user_id` → `users.id`（级联删除和更新）
- **核心字段**:
  - `type`：目前固定为 `'rent'`
  - `title` / `content`：房源标题和详细描述
  - `price`：月租金（DECIMAL）
  - `floor`：楼层信息，如 `3/F`、`高层`
  - `rent_period`：`short` / `medium` / `long`
  - `region`：区域，如 `九龙`、`港岛` 等
  - `school_scope`：适合就读学校范围，例如 `CityU`、`HKU, PolyU`
  - `metro_stations`：附近地铁站，逗号分隔
  - `images`：预留图片字段（文本），当前版本可为空
  - `status`：`active` / `hidden` / `deleted`

---

## 快速验证流程（手工测试建议）

完成环境搭建后，可按以下顺序快速验证项目是否正常工作：

1. **访问首页**
   - 打开 `http://localhost/hk-rentmatch/index.php`
   - 应能看到示例房源列表与筛选栏
2. **尝试筛选**
   - 选择区域（如「九龙」）、学校（如「CityU」），点击搜索
   - 列表应缩小到匹配条件的示例数据
3. **查看详情**
   - 点击某个房源卡片，应跳转到 `post/detail.php?id=...`，展示单条房源详细信息
4. **注册新账号**
   - 打开 `register.php`，以学生身份填写所有必填项并注册
   - 如填写不合法（邮箱格式、密码复杂度等），界面应展示对应错误提示
5. **登录与退出**
   - 使用刚注册的邮箱和密码在 `login.php` 登录
   - 登录成功后可以访问需要登录的页面（如发帖页）
   - 访问 `logout.php` 可正常退出
6. **发布新房源**
   - 在登录状态下访问 `post/create.php`
   - 填写标题、价格、区域、租期等信息后提交
   - 返回首页应能看到刚刚发布的新房源，并可通过筛选和详情进行验证

若以上步骤全部通过，则说明本地环境与数据库配置基本正确。

---

## 项目状态与后续规划

- **当前状态**:
  - 已实现基础的用户体系（注册/登录/退出）与租房帖子发布、浏览、筛选和详情查看
  - 数据结构预留了图片字段与多角色扩展空间
- **潜在扩展方向**（课程后续可选）:
  - 支持「有房找室友」「无房找室友」等更多帖子类型
  - 引入收藏、申请、聊天等交互功能
  - 完善前端响应式布局与移动端体验
  - 后台管理已提供基础能力（`admin.php`）；可按需扩展审核流、操作日志等

---

## 使用说明 / License

- 本项目为 **课程作业** 性质，仅用于教学与学习目的。
- 如需在课程之外使用或对外开源，请根据学校与团队约定选择合适的 License，并在本文件中补充说明。

