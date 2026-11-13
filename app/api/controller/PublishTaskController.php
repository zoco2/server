<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\service\publish_task\PublishTaskService;
use InvalidArgumentException;
use think\db\exception\DbException;
use think\facade\Log;
use Throwable;

/** 提供当前登录用户的任务发布和查询接口，由框架默认路由分发。 */
final class PublishTaskController extends BaseApiController
{
    /** POST /api/publish_task/create：保存任务并投递首次 MNS 消息。 */
    public function create()
    {
        if (!$this->request->isPost()) {
            return $this->fail('请使用 POST 请求');
        }
        try {
            $input = $this->request->post();
            // 全局 trim 会把 JSON 数字和布尔值转成字符串，员工 ID 需要按原始类型校验。
            if (array_key_exists('staff_ids', $input)) {
                $input['staff_ids'] = $this->request->post('staff_ids', null, null);
            }
            return $this->success('', PublishTaskService::create($this->userId, $input));
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        } catch (Throwable $e) {
            // 投递或存储故障记录到服务端，接口只返回通用提示。
            $field = $e instanceof DbException
                && preg_match('/^fields not exists:\[([a-zA-Z0-9_]+)\]$/D', $e->getMessage(), $match)
                ? $match[1] : '-';
            Log::channel('mns')->error(sprintf('[publish_task] create failed tenant=%d user=%d type=%s code=%d missing_field=%s',
                (int)$this->request->tenantId, $this->userId, get_class($e), (int)$e->getCode(), $field));
            return $this->fail('任务发布失败，请稍后重试');
        }
    }

    /** GET /api/publish_task/lists：分页返回当前租户和用户的任务及最近执行结果。 */
    public function lists()
    {
        if (!$this->request->isGet()) {
            return $this->fail('请使用 GET 请求');
        }
        try {
            return $this->success('', PublishTaskService::lists(
                $this->userId,
                (int)$this->request->get('page_no/d', 1), (int)$this->request->get('page_size/d', 20)
            ));
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
    }
}
