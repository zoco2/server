# 抖音 H5 分享服务

后端入口：`app\common\service\douyin\DouyinH5ShareService::createShare()`（实例方法）。本次只接入 service；公开门店的 HTTP 接口、素材选择、前端按钮及二维码展示后续接入。

## 配置

在抖音开放平台创建网站应用，开通 `h5.share`、`open.get.ticket` 和投稿权限 `aweme.share`；转发到日常还需要 `aweme.forward`。在 server 的 `.env` 添加：

```ini
[DOUYIN]
CLIENT_KEY = 你的应用key
CLIENT_SECRET = 你的应用secret
CACHE_STORE = redis
```

配置入口为 `config/douyin.php`，也可按 ThinkPHP 约定使用进程环境变量 `PHP_DOUYIN_CLIENT_KEY`、`PHP_DOUYIN_CLIENT_SECRET`、`PHP_DOUYIN_CACHE_STORE`。`CACHE_STORE` 留空使用系统默认缓存；支持 file 和 redis。单机 file 使用文件锁，多实例必须共用同一 Redis 缓存及前缀，避免重复刷新使旧 token 失效。测试与生产请使用不同的抖音应用凭据。

凭据按应用及密钥指纹隔离，按接口 `expires_in` 提前刷新；token 失效（2190002 / 2190008）时最多刷新重试一次。HTTP 超时为 10 秒，TLS 校验开启，禁止跟随重定向。返回值不包含 secret、token、ticket；上游异常只保留错误码，不透传原始响应或请求。

## 调用示例

```php
use app\common\service\douyin\DouyinH5ShareService;

$share = (new DouyinH5ShareService())->createShare([
    'video_path' => 'https://cdn.example.com/store/video.mp4',
    'title' => '周末来我们店里坐坐',
    'hashtag_list' => ['同城美食', '周末探店'],
    'poi_id' => '抖音门店POI的真实ID', // 不是本系统 store_id
]);

// $share['schema']：snssdk1128://openplatform/share?...，直接唤起或生成二维码。
// $share['params']：与 Schema 一致的公开参数（复杂字段已 JSON 编码）。
// $share['ticket_expires_at']：ticket 的缓存截止秒级时间，已预留刷新余量。
```

`ticket_expires_at` 不代表官方承诺的 Schema 有效期。请在用户点击发布时生成链接；重新打开页面或缓存到期后重新获取，不将带签名的链接永久保存为门店入口。

图集使用 `image_list_path => ['https://cdn.example.com/a.jpg', 'https://cdn.example.com/b.jpg']`，单图使用 `image_path`。视频优先于图集和单图，图集优先于单图。视频默认 `share_to_publish=1` 进入发布页，图片进入编辑流程；图片传 `share_to_publish=1` 会被拒绝。

可选字段：`title`、`short_title`、`state`、`poi_id`、`hashtag_list`、`title_hashtag_list`（如 `[['name' => '探店', 'start' => 0]]`）、`micro_app_info`（`appId/appTitle/description/appUrl`）、`share_to_publish`、`share_to_type`、`download_type`、`private_status`、`feature`。列表/对象传 PHP 数组，枚举传整数，字符串字段传字符串；不允许调用者覆盖签名或应用身份字段。

`state` 可传已通过官方分享结果能力获得的 `share_id`；本服务不会伪造 share_id，也未接入发布结果查询或回调。生成 Schema 仅代表准备好分享，不能标记为发布成功。

## 后续门店接口接入

- 从公开门店及其允许分享的素材中组装内容，验证门店发布状态、素材归属，并限制公开接口调用频率，再调用 service。勿直接对公网暴露任意签名入口。
- 素材必须是抖音可公开下载的 HTTP(S) 地址。视频支持 mp4/mov、最大 128 MB；单图最大 20 MB，支持 png/jpg/gif，图集支持 png/jpg。下载响应的 Content-Type 必须正确。本 service 不下载素材，不校验远程 MIME、体积或可达性；由素材入库和业务层保证。
- iOS 文件 URL 及小程序 appUrl 中的中文需要先编码；已有 URL 的 `%`、`+`、查询参数会在 Schema 编码/解码后原样保留。包含 `+` 的素材地址仍需 iOS 真机验证。
- PC 网页展示 Schema 二维码供抖音扫码；移动 H5 可由用户点击后导航至 Schema。服务端无法直接打开用户手机上的抖音，用户仍需在抖音确认发布。
- 新字段有客户端最低版本要求；iOS 的 `hashtag_list` 需同时传非空 title。小程序挂载需另行开通能力，同传 POI 时抖音优先展示小程序。

## 离线验证

```sh
php tests/douyin/h5_share.php
```

使用 Guzzle MockHandler 和临时文件缓存验证签名、HTTP 协议、序列化、凭据缓存/刷新、参数校验及异常脱敏，不访问真实抖音账号。上线前仍需配置真实凭据并完成抖音扫码及 Android/iOS 真机联调。

## 官方参考

- [H5 发布能力与签名规则](https://partner.open-douyin.com/docs/resource/zh-CN/dop/develop/sdk/web-app/h5/share-to-h5)
- [client_token](https://developer.open-douyin.com/docs/resource/zh-CN/dop/develop/openapi/account-permission/client-token)
- [open_ticket](https://developer.open-douyin.com/docs/resource/zh-CN/dop/develop/openapi/tools-ability/jsb-management/open-ticket)
- [Schema 编码示例](https://developer.open-douyin.com/docs/resource/zh-CN/dop/develop/sdk/web-app/h5/schema-demo)
