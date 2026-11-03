<?php

namespace app\api\controller\geo;

use app\api\controller\BaseApiController;
use app\common\service\geo\GeoArticleService;
use Exception;

/**
 * GEO 稿件（PC 前台用户）。
 *
 * preview 免登录：海讯社建单时按 content 传的地址去抓正文，必须是公开可访问的 HTML。
 */
class ArticleController extends BaseApiController
{
    /** 免登录接口 */
    public array $notNeedLogin = ['preview'];

    /** 我的稿件列表 */
    public function lists()
    {
        try {
            return $this->success('获取成功', GeoArticleService::lists($this->request->get(), $this->userId));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /** 稿件详情 */
    public function detail()
    {
        try {
            return $this->success('获取成功', GeoArticleService::detail((int)$this->request->get('id', 0), $this->userId));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /** 新建 / 编辑 */
    public function save()
    {
        try {
            return $this->success('保存成功', GeoArticleService::save($this->userId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /** 删除 */
    public function delete()
    {
        try {
            GeoArticleService::delete((int)$this->request->post('id', 0), $this->userId);
            return $this->success('删除成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 预览页（免登录，服务端直出 HTML）。
     * 海讯社抓取的就是这个地址，不能依赖前端渲染。
     */
    public function preview()
    {
        try {
            $data = GeoArticleService::preview((string)$this->request->get('token', ''));
        } catch (Exception $e) {
            return $this->htmlError($e->getMessage());
        }
        return response($this->renderPreview($data), 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * 预览页 HTML。内联样式，不依赖外网资源，保证海讯社抓取时能完整还原。
     *
     * @param array<string, mixed> $data 稿件数据
     */
    private function renderPreview(array $data): string
    {
        $title = htmlspecialchars((string)$data['title'], ENT_QUOTES, 'UTF-8');
        $digest = htmlspecialchars((string)($data['digest'] ?? ''), ENT_QUOTES, 'UTF-8');
        $content = (string)$data['content'];
        $updatedAt = date('Y-m-d H:i', (int)($data['update_time'] ?? time()));
        $digestHtml = $digest !== '' ? '<p class="digest">' . $digest . '</p>' : '';
        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title}</title>
<style>
  body{margin:0;background:#f6f7f9;color:#1f2329;font:16px/1.8 -apple-system,"PingFang SC","Microsoft YaHei",sans-serif}
  .wrap{max-width:780px;margin:0 auto;padding:32px 20px 64px;background:#fff;min-height:100vh}
  h1{font-size:26px;line-height:1.5;margin:0 0 12px}
  .meta{color:#8a919f;font-size:13px;margin-bottom:20px}
  .digest{color:#5c6370;background:#f2f4f7;border-radius:8px;padding:12px 16px;margin:0 0 24px}
  .article img{max-width:100%;height:auto;display:block;margin:16px auto}
  .article video{max-width:100%}
  .article p{margin:0 0 16px}
  .article h2,.article h3{margin:28px 0 12px}
</style>
</head>
<body>
<div class="wrap">
  <h1>{$title}</h1>
  <div class="meta">更新于 {$updatedAt}</div>
  {$digestHtml}
  <div class="article">{$content}</div>
</div>
</body>
</html>
HTML;
    }

    /**
     * 预览失效时的提示页。
     *
     * @param string $message 错误信息
     */
    private function htmlError(string $message): \think\Response
    {
        $message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $html = '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><title>预览不可用</title>'
            . '<style>body{font:16px/1.8 -apple-system,"PingFang SC",sans-serif;display:flex;align-items:center;'
            . 'justify-content:center;height:100vh;margin:0;color:#5c6370}</style></head>'
            . '<body><div>' . $message . '</div></body></html>';
        return response($html, 404, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}