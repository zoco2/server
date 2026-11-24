# 小红书 H5 分享服务

后端入口为 `app\common\service\xiaohongshu\XiaohongshuH5ShareService` 的实例方法 `createShare()`。接入小红书分享开放平台的 JS SDK 鉴权流程，提供图片和视频的分享参数；本次不新增公开门店 HTTP 接口或前端按钮，也不依赖抖音 service。

## 当前能力边界

截至 2026-11-13，[分享开放平台官网](https://agora.xiaohongshu.com/)显示“暂停接入”。代码可以先接入，真实调用仍需平台审核通过后取得有效的 appKey/appSecret，未取得凭据不代表可立即上线。

[JS SDK 官方文档](https://agora.xiaohongshu.com/doc/js)声明不再支持自动填充标题、正文、话题，图片和视频不受影响。本服务只接受媒体字段，明确拒绝文案字段，避免调用方误以为文案已填入。用户需在小红书内填写文案并确认发布；生成分享参数不能标记为发布成功。

## 配置

应用的 App Key 和 App Secret 由平台管理员在 **系统设置 → 基础配置 → 分享配置** 中保存。详见 [平台分享配置](tenant-share-config.md)。

缓存通过 `PHP_XIAOHONGSHU_CACHE_STORE` 配置，留空时使用系统默认缓存。多实例使用同一个 Redis 缓存及前缀。

## 调用

```php
use app\common\service\xiaohongshu\XiaohongshuH5ShareService;

$service = new XiaohongshuH5ShareService();
$share = $service->createShare([
    'type' => 'normal',
    'images' => ['https://cdn.example.com/store/1.jpg', 'https://cdn.example.com/store/2.jpg'],
]);

// 视频笔记；cover 可省略。
$videoShare = $service->createShare([
    'type' => 'video',
    'video' => 'https://cdn.example.com/store/video.mp4',
    'cover' => 'https://cdn.example.com/store/cover.jpg',
]);
```

返回 `shareInfo`、`verifyConfig`、`sdk_url` 及 `token_expires_at_ms`。其中 `verifyConfig` 包含 `appKey`、`nonce`、字符串类型的毫秒 `timestamp` 和 `signature`；不返回 appSecret 或 access_token。后续前端加载官方 SDK 后使用：

```javascript
xhs.share({
  shareInfo: result.shareInfo,
  verifyConfig: result.verifyConfig,
  fail: (error) => { /* 按 SDK 错误展示失败信息 */ },
});
```

这只是后续接入示例，当前未修改前端。服务不生成抖音格式的 Scheme 或声称 PC 可以直接发布；PC 可设计扫码进入移动端分享页，再由移动端用户操作 JS SDK，实际浏览器兼容性需真机验证。

## 鉴权与缓存

- token 请求固定为 `POST https://edith.xiaohongshu.com/api/sns/v1/ext/access/token`，JSON 包含 `app_key`、`nonce`、整数毫秒 `timestamp`、`signature` 和绝对截止时间 `expires_in`（请求 24 小时有效期）。
- 按官方 Java 示例，签名原文为 `appKey={appKey}&nonce={nonce}&timeStamp={timestamp}{secret}`，对 UTF-8 原值做 SHA-256，小写十六进制输出。不 URL 编码，`timeStamp` 中的 S 必须大写，secret 前没有额外分隔符。第一次使用 appSecret；返回 JS SDK 的第二次签名使用 access_token。
- 响应中的 `expires_in` 是毫秒绝对截止时间，不是秒数或 TTL。实现提前最多 120 秒刷新，短有效期按剩余时间的 10% 提前。token 按应用和密钥指纹隔离缓存，刷新锁按应用隔离，锁内二次检查缓存。
- 官方说明重新获取 token 会使历史 token 失效。因此不对失败请求做自动重试；不得多个系统各自维护同一个应用的 token。`token_expires_at_ms` 不是分享签名的有效期，分享参数必须在用户点击时生成，不永久保存。
- 文档只列出 token 响应字段；解析器同时支持直接字段与 `data` 包装，并检查存在的 `code`/`success`。这两种形状已做离线测试，真实账号响应仍需联调确认。
- HTTP 超时 10 秒、连接超时 4 秒，开启 TLS 校验，禁止重定向；错误不透传上游正文、请求签名、密钥或 token。

## 业务层接入约定

公开门店接口调用前必须检查门店发布状态、素材归属和公开下载权限，并限制调用频率。本服务不对公网暴露任意签名入口，不下载素材或检查远程 MIME、大小、可达性。媒体 URL 必须是公开可访问的 HTTP(S) 地址；已有查询串和百分号编码保持原样，中文路径和空格须事先编码。图文与视频字段不混传。

## 验证

运行 `php tests/xiaohongshu/h5_share.php`。使用 Guzzle MockHandler 和临时文件缓存验证两次签名、毫秒协议、媒体参数、缓存复用/过期/隔离、错误脱敏及四进程并发刷新，不调用真实小红书。真实平台凭据、Redis 服务及 Android/iOS 浏览器联调需在上线前完成。
