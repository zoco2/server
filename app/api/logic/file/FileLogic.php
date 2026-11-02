<?php

namespace app\api\logic\file;

use app\common\enum\FileEnum;
use app\common\logic\BaseLogic;
use app\common\model\file\TenantFile;
use app\common\model\file\UserFileCate;
use InvalidArgumentException;
use think\facade\Db;
use Throwable;

/**
 * 用户文件分组。tenant_id 由 BaseModel 全局作用域处理。
 */
class FileLogic extends BaseLogic
{
    /**
     * @param array $params
     * @return bool
     */
    public static function addCate(array $params): bool
    {
        $userId = self::userId();
        if ($userId <= 0) {
            self::setError('请先登录');
            return false;
        }
        $pid = (int)$params['pid'];
        $type = (int)$params['type'];
        if ($pid > 0 && !self::parentAllowed($userId, $pid, $type)) {
            return false;
        }
        if ($pid === 0) {
            // 顶级分组没有父记录。先查询一次，BaseModel 才会在插入时写入 tenant_id。
            UserFileCate::where('user_id', $userId)->where('id', 0)->value('id');
        }
        try {
            UserFileCate::create([
                'user_id' => $userId,
                'pid' => $pid,
                'type' => $type,
                'name' => trim((string)$params['name']),
            ]);
            return true;
        } catch (Throwable $e) {
            self::setError('分组保存失败');
            return false;
        }
    }

    /**
     * @param array $params
     * @return bool
     */
    public static function editCate(array $params): bool
    {
        $userId = self::userId();
        if ($userId <= 0) {
            self::setError('请先登录');
            return false;
        }
        $row = self::owned($userId, (int)$params['id']);
        if ($row->isEmpty()) {
            self::setError('分组不存在或无权操作');
            return false;
        }
        try {
            $row->save(['name' => trim((string)$params['name'])]);
            return true;
        } catch (Throwable $e) {
            self::setError('分组保存失败');
            return false;
        }
    }

    /**
     * @param int $id
     * @return bool
     */
    public static function delCate(int $id): bool
    {
        $userId = self::userId();
        if ($userId <= 0) {
            self::setError('请先登录');
            return false;
        }
        if (self::owned($userId, $id)->isEmpty()) {
            self::setError('分组不存在或无权操作');
            return false;
        }
        $ids = UserFileCate::branchIds($userId, $id);
        try {
            Db::transaction(function () use ($userId, $ids) {
                self::clearFiles($userId, $ids);
                $cates = UserFileCate::where('user_id', $userId)->whereIn('id', $ids)->select();
                foreach ($cates as $cate) {
                    $cate->delete();
                }
            });
            return true;
        } catch (Throwable $e) {
            self::setError('分组删除失败');
            return false;
        }
    }

    /**
     * @param array $params
     * @return bool
     */
    public static function move(array $params): bool
    {
        $userId = self::userId();
        if ($userId <= 0) {
            self::setError('请先登录');
            return false;
        }
        $ids = array_values(array_unique(array_map('intval', $params['ids'])));
        $cid = (int)$params['cid'];
        $files = self::ownedFiles($userId, $ids);
        if (count($files) !== count($ids)) {
            self::setError('文件不存在或无权操作');
            return false;
        }
        if ($cid > 0) {
            $cate = self::owned($userId, $cid);
            if ($cate->isEmpty()) {
                self::setError('分组不存在或无权操作');
                return false;
            }
            foreach ($files as $file) {
                if ((int)$file['type'] !== (int)$cate['type']) {
                    self::setError('文件类型与分组不一致');
                    return false;
                }
            }
        }
        try {
            Db::transaction(function () use ($files, $cid) {
                foreach ($files as $file) {
                    $file->save(['cid' => $cid]);
                }
            });
            return true;
        } catch (Throwable $e) {
            self::setError('移动失败');
            return false;
        }
    }

    /**
     * 上传时解析分组。不传或 0 表示未分组。
     * @param int $type 文件类型，10 图片、20 视频、30 文件
     * @param mixed $raw 表单字段 cid
     * @return int
     */
    public static function uploadCid(int $type, $raw = 0): int
    {
        $userId = self::userId();
        if ($userId <= 0) {
            throw new InvalidArgumentException('请先登录');
        }
        if ($raw === '' || $raw === null) {
            return 0;
        }
        if (!is_numeric($raw) || (int)$raw < 0) {
            throw new InvalidArgumentException('分组参数无效');
        }
        $cid = (int)$raw;
        if ($cid === 0) {
            return 0;
        }
        $cate = self::owned($userId, $cid);
        if ($cate->isEmpty()) {
            throw new InvalidArgumentException('分组不存在或无权操作');
        }
        if ((int)$cate['type'] !== $type) {
            throw new InvalidArgumentException('文件类型与分组不一致');
        }
        return $cid;
    }

    /**
     * @return int
     */
    private static function userId(): int
    {
        return (int)request()->userId;
    }

    /**
     * @param int $userId
     * @param int $id
     * @return UserFileCate
     */
    private static function owned(int $userId, int $id)
    {
        return UserFileCate::where('user_id', $userId)->where('id', $id)->findOrEmpty();
    }

    /**
     * 先查父分组，让模型全局作用域记下 tenant_id，随后的插入才会写入租户。
     * @param int $userId
     * @param int $pid
     * @param int $type
     * @return bool
     */
    private static function parentAllowed(int $userId, int $pid, int $type): bool
    {
        $parent = self::owned($userId, $pid);
        if ($parent->isEmpty()) {
            self::setError('父分组不存在或无权操作');
            return false;
        }
        if ((int)$parent['type'] !== $type) {
            self::setError('父分组类型不一致');
            return false;
        }
        return true;
    }

    /**
     * @param int $userId
     * @param int[] $ids
     * @return \think\Collection
     */
    private static function ownedFiles(int $userId, array $ids)
    {
        return TenantFile::where('source', FileEnum::SOURCE_USER)
            ->where('source_id', $userId)
            ->where('upload_origin', 'local')
            ->whereIn('id', $ids)
            ->select();
    }

    /**
     * @param int $userId
     * @param int[] $cateIds
     * @return void
     */
    private static function clearFiles(int $userId, array $cateIds): void
    {
        $files = TenantFile::withTrashed()
            ->where('source', FileEnum::SOURCE_USER)
            ->where('source_id', $userId)
            ->where('upload_origin', 'local')
            ->whereIn('cid', $cateIds)
            ->select();
        foreach ($files as $file) {
            $file->save(['cid' => 0]);
        }
    }
}
