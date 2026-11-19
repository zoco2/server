# 租户分享配置

租户后台入口：**系统设置 → 基础配置 → 分享配置**（`tenant/src/views/channel/share_setting.vue`）。分别保存抖音和小红书的应用凭据及启用状态，默认关闭。页面只管理分享应用配置，公开门店的发布按钮、手机唤起和二维码入口仍需单独接入。

## 接口

接口使用 tenantapi 的登录和角色权限中间件校验查看、保存权限。租户来自管理员登录信息，不接受请求体中的 `tenant_id`。部署时执行本功能的菜单 SQL，注册查看和保存权限。

### GET /tenantapi/channel.share_setting/getConfig

正常响应的 `data`：

```json
{
  "douyin": {"enabled": 0, "client_key": "", "client_secret_set": false, "configured": false},
  "xiaohongshu": {"enabled": 0, "app_key": "", "app_secret_set": false, "configured": false}
}
```

`configured` 仅表示已保存应用标识和密钥，不代表平台已审核或真实调用成功。不返回密钥明文、密文或部分密钥字符。

### POST /tenantapi/channel.share_setting/setConfig

一次保存一个平台，JSON 请求体示例：

```json
{"platform":"douyin","enabled":1,"client_key":"your-client-key","client_secret":"your-client-secret"}
```

```json
{"platform":"xiaohongshu","enabled":1,"app_key":"your-app-key","app_secret":"your-app-secret"}
```

响应 `data` 为该平台最新的脱敏配置，结构与 GET 对应平台一致。密钥留空或省略表示保留；更换已有应用标识必须同时提供新密钥。启用必须具备完整凭据。关闭不会删除凭据，重新开启可继续使用。应用标识最多 128 字节、密钥最多 256 字节，不接受数组、空白字符或星号掩码。

## 存储、权限和部署

- 使用现有 `tenant_config` 表的 `type=social_share`、`name=douyin/xiaohongshu`，兼容 `tenant_config_{sn}` 分表。无需新增数据表。事务内锁租户行，避免首次保存产生重复记录。
- 密钥沿用项目的 `WechatCredentialService`（AES-256-GCM）加密，使用现有 `[WECHAT] CREDENTIAL_KEY`，未设置时沿用 `project.unique_identification`。生产部署应设置高强度的专用 `CREDENTIAL_KEY` 并妥善备份；更改此密钥会影响已有微信及分享凭据的解密。
- 通过系统升级执行 `upgrade/20261118_tenant_base_config_menu.sql`。升级器会处理共享菜单表与分表租户，给原有配置角色补上“系统设置 → 基础配置”入口。已有分享配置菜单的租户可通过原地址进入分享区块。
- 新建角色时，在角色管理中分配“系统设置 → 基础配置”和分享配置的查看权限。需要修改分享凭据时，再授予保存分享配置的权限。
- `cache_store` 仍由部署配置 `douyin.cache_store`、`xiaohongshu.cache_store` 管理，多实例共用 Redis。缓存继续按应用和密钥指纹隔离，密钥更换后自动使用新的缓存。

## 服务调用

公开门店和后台任务优先显式传入业务验证后的租户 ID：

```php
$douyin = \app\common\service\douyin\DouyinH5ShareService::forTenant($tenantId);
$xiaohongshu = \app\common\service\xiaohongshu\XiaohongshuH5ShareService::forTenant($tenantId);
```

已有无参构造在 `request()->tenantId > 0` 时自动读取当前租户配置。租户配置未启用、缺失或密钥解密失败时拒绝调用，**不回退到平台环境凭据**。无租户上下文的旧代码仍可使用环境配置；显式传入构造参数继续用于测试或受信任的服务端集成。业务层须先校验门店归属、公开状态及素材，不能直接信任客户端提交的租户 ID。

平台申请、权限与媒体限制见 [抖音分享服务](douyin-h5-share.md) 和 [小红书分享服务](xiaohongshu-h5-share.md)。小红书 [JS SDK 文档](https://agora.xiaohongshu.com/doc/js)目前明确不支持自动填充标题、文案、话题；图片和视频不受影响。

## 验证

```sh
php tests/share/tenant_config.php
php tests/douyin/h5_share.php
php tests/xiaohongshu/h5_share.php
```

租户配置测试使用 SQLite 内存数据库和模拟平台 HTTP，不读取业务 `.env`，需要 `pdo_sqlite`、`openssl`、`mbstring` 扩展。覆盖租户及分表隔离、凭据加密、更新/保留密钥、非法参数、伪造租户 ID、HTTP 方法、已有菜单权限机制、禁用后不回退及两家平台的签名服务调用。MySQL 行锁并发和真实平台唤起需在部署环境联调。
