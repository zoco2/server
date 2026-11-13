<?php
namespace app\api\controller;

use app\api\logic\file\FileLogic;
use app\common\service\WorkspaceAssetService;
use app\common\service\UploadService;
use app\common\enum\FileEnum;
use InvalidArgumentException;

/** Shared account asset library, independent of any single installed AI app. */
class WorkspaceAssetController extends BaseApiController
{
    public function canvas() { return $this->respond(fn($t, $u) => \app\common\service\WorkspaceCanvasAssetService::lists($t, $u, (int)$this->request->get('page_no', 1), (string)$this->request->get('kind', 'task'))); }
    public function state() { return $this->respond(fn($t, $u) => WorkspaceAssetService::read($t, $u)); }
    public function uploads() { return $this->respond(fn($t, $u) => WorkspaceAssetService::uploads($t, $u, min(10000, max(1, (int)$this->request->get('page_no', 1))), $this->request->get('cid'))); }
    public function update() { return $this->respond(fn($t, $u) => WorkspaceAssetService::update($t, $u, $this->request->post()), true); }
    public function label() { return $this->respond(fn($t, $u) => \app\common\service\WorkspaceAssetLabelService::change($t, $u, $this->request->post()), true); }
    /**
     * 分析素材库里的图片或视频，返回画面描述、标签、OCR 与可用性等结构化结果。
     * 传 ref 或 refs，一次最多 4 个；视频会先抽帧再交给视觉文本模型。
     */
    public function analyze()
    {
        return $this->respond(fn($t, $u) => \app\common\service\WorkspaceAssetAnalyzeService::analyze($t, $u, $this->request->post()), true);
    }
    public function upload()
    {
        return $this->respond(function ($t, $u) {
            $file = $this->request->file('file');
            if (!$file || $file->getSize() > 100 * 1024 * 1024) throw new InvalidArgumentException('请选择100MB以内的图片、视频或音频');
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());
            $ext = strtolower($file->getOriginalExtension());
            if (in_array($ext, ['jpg','jpeg','png','webp','gif'], true) && @getimagesize($file->getPathname())) {
                return UploadService::image(FileLogic::uploadCid(FileEnum::IMAGE_TYPE, $this->request->post('cid', 0)), $u, FileEnum::SOURCE_USER);
            }
            if (in_array($ext, ['mp4','mov','webm'], true) && str_starts_with($mime, 'video/')) {
                $type = $ext === 'webm' ? FileEnum::FILE_TYPE : FileEnum::VIDEO_TYPE;
                $cid = FileLogic::uploadCid($type, $this->request->post('cid', 0));
                return $ext === 'webm' ? UploadService::file($cid, $u, FileEnum::SOURCE_USER) : UploadService::video($cid, $u, FileEnum::SOURCE_USER);
            }
            // 音频是独立类型（type=40），与图片、视频同级，有自己的分组和列表页签。
            if (in_array($ext, self::AUDIO_EXTENSIONS, true) && str_starts_with((string)$mime, 'audio/')) {
                $cid = FileLogic::uploadCid(FileEnum::AUDIO_TYPE, $this->request->post('cid', 0));
                return UploadService::audio($cid, $u, FileEnum::SOURCE_USER);
            }
            throw new InvalidArgumentException('请选择支持的图片、视频或音频格式');
        }, true);
    }

    /** 允许上传的音频扩展名；与 config/project.php 的 file_file 白名单保持一致 */
    private const AUDIO_EXTENSIONS = ['mp3', 'wav', 'm4a', 'aac', 'ogg', 'flac', 'opus'];

    private function respond(callable $action, bool $write = false)
    {
        try {
            $tenant = (int)($this->userInfo['tenant_id'] ?? 0);
            if (!$this->userId || $tenant <= 0 || (int)$this->request->tenantId !== $tenant) throw new InvalidArgumentException('账号与租户不匹配，请重新登录');
            if ($write && !$this->request->isPost()) throw new InvalidArgumentException('请使用POST请求');
            return $this->data($action($tenant, $this->userId));
        } catch (\Throwable $e) {
            return $this->fail($e instanceof InvalidArgumentException ? $e->getMessage() : '资产服务暂不可用，请稍后重试');
        }
    }
}
