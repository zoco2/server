<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\service\DefaultWorkGroup;
use InvalidArgumentException;
use think\facade\Db;

final class WorkGroupController extends BaseApiController
{
    private const TYPES = ['video', 'finished_image', 'copy', 'topic'];

    public function lists()
    {
        if (!$this->request->isGet()) {
            return $this->fail('请使用 GET 请求');
        }

        try {
            $scope = $this->scope($this->request->get('type', null, null));
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }

        $groups = Db::name('work_group')->where($scope)->whereNull('delete_time')
            ->field('id,type,name,max_uses')->order('id', 'desc')->select()->toArray();
        foreach ($groups as $index => $group) {
            $groups[$index]['id'] = (int)$group['id'];
            $groups[$index]['max_uses'] = (int)$group['max_uses'];
        }
        array_unshift($groups, ['id' => 0, 'type' => $scope['type'], 'name' => DefaultWorkGroup::NAME, 'max_uses' => 0]);
        return $this->data(['lists' => $groups, 'count' => count($groups)]);
    }

    public function create()
    {
        if (!$this->request->isPost()) {
            return $this->fail('请使用 POST 请求');
        }

        $input = $this->request->post(false);
        try {
            $scope = $this->scope($input['type'] ?? null);
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }

        $name = $input['name'] ?? null;
        $name = is_string($name) ? preg_replace('/^\s+|\s+$/u', '', $name) : null;
        if ($name === null || $name === '' || mb_strlen($name, 'UTF-8') > 100) {
            return $this->fail('请输入 1 到 100 字的分组名称');
        }
        if ($name === DefaultWorkGroup::NAME) {
            return $this->fail('默认分组是系统分组，请使用其他名称');
        }
        $limit = array_key_exists('max_uses', $input) ? $input['max_uses'] : 0;
        if ((!is_int($limit) && !is_string($limit)) || !ctype_digit((string)$limit)
            || (int)$limit > 4294967295) {
            return $this->fail('每条作品的使用上限必须是 0 到 4294967295 的整数');
        }

        $group = ['type' => $scope['type'], 'name' => $name, 'max_uses' => (int)$limit];
        $now = time();
        $id = Db::name('work_group')->insertGetId($scope + $group + ['create_time' => $now, 'update_time' => $now]);
        return $this->data(['id' => (int)$id] + $group);
    }

    /** 返回本次查询的作品中需要从默认分组排除的引用。历史作品没有 work_item 行。 */
    public function exclusions()
    {
        if (!$this->request->isPost()) {
            return $this->fail('请使用 POST 请求');
        }

        $input = $this->request->post(false);
        try {
            $scope = $this->scope($input['type'] ?? null);
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }

        $refs = $input['refs'] ?? null;
        if (!is_array($refs) || !array_is_list($refs) || count($refs) > 100) {
            return $this->fail('请提供最多 100 个作品标识');
        }
        foreach ($refs as $ref) {
            if (!is_string($ref) || !preg_match('/^[\x00-\x7F]{1,128}$/D', $ref)) {
                return $this->fail('作品标识格式无效');
            }
        }
        if ($refs === []) {
            return $this->data(['refs' => []]);
        }
        $hits = DefaultWorkGroup::excludedItems($scope)
            ->whereIn('source_ref', $refs)->column('source_ref');
        return $this->data(['refs' => array_values(array_intersect(array_unique($refs), $hits))]);
    }

    private function scope(mixed $type): array
    {
        $tenantId = (int)($this->userInfo['tenant_id'] ?? 0);
        if ($tenantId <= 0 || $this->userId <= 0 || (int)$this->request->tenantId !== $tenantId) {
            throw new InvalidArgumentException('账号与租户不匹配，请重新登录');
        }
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('请选择视频、成品图片、文案或发布话题');
        }
        return ['tenant_id' => $tenantId, 'user_id' => $this->userId, 'type' => $type];
    }
}
