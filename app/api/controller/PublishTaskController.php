<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\service\publish_task\PublishTaskService;
use InvalidArgumentException;
use think\facade\Log;
use Throwable;

final class PublishTaskController extends BaseApiController
{
    public function create()
    {
        if (!$this->request->isPost()) {
            return $this->fail('请使用 POST 请求');
        }
        try {
            return $this->success('', PublishTaskService::create(
                (int)$this->request->tenantId, $this->userId, $this->request->post()
            ));
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        } catch (Throwable $e) {
            Log::channel('mns')->error(sprintf('[publish_task] create failed tenant=%d user=%d type=%s',
                (int)$this->request->tenantId, $this->userId, get_class($e)));
            return $this->fail('任务发布失败，请稍后重试');
        }
    }

    public function lists()
    {
        if (!$this->request->isGet()) {
            return $this->fail('请使用 GET 请求');
        }
        try {
            return $this->success('', PublishTaskService::lists(
                (int)$this->request->tenantId, $this->userId,
                (int)$this->request->get('page_no/d', 1), (int)$this->request->get('page_size/d', 20)
            ));
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
    }
}
