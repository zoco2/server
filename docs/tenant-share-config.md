# 平台分享配置

平台管理员在 **系统设置 → 基础配置 → 分享配置** 保存抖音和小红书的应用标识和密钥。分享业务读取同一份平台配置。调用分享服务前，业务接口仍按当前租户校验任务和素材归属。

- `GET /platformapi/setting.business_config/detail` 返回应用标识和密钥配置状态。
- `POST /platformapi/setting.business_config/save` 每次保存一个平台的凭据。提交 `section=share`。抖音提交 `platform=douyin`、`client_key` 和 `client_secret`。小红书提交 `platform=xiaohongshu`、`app_key` 和 `app_secret`。

应用标识保持不变且密钥留空时，沿用平台已保存的密钥。更换应用标识时同时提交对应密钥。密钥通过现有的 `WechatCredentialService` 加密后写入平台配置。

接入权限与媒体限制见 [抖音 H5 分享服务](douyin-h5-share.md) 和 [小红书 H5 分享服务](xiaohongshu-h5-share.md)。
