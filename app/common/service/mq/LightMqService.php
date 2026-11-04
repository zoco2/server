<?php

namespace app\common\service\mq;

use app\common\job\Job;
use app\common\service\ConfigService;
use AliyunMNS\Client;
use AliyunMNS\Exception\MnsException;
use AliyunMNS\Requests\SendMessageRequest;
use think\facade\Log;

/**
 * 阿里云轻量消息队列（原 MNS）封装。
 *
 * 只负责收发与序列化，不关心具体业务；任务执行由调用方（worker / 命令）负责。
 */
class LightMqService
{
    public const RECEIVE_KEY_MESSAGE_ID = 'message_id';
    public const RECEIVE_KEY_RECEIPT_HANDLE = 'receipt_handle';
    public const RECEIVE_KEY_MESSAGE_BODY = 'message_body';
    public const RECEIVE_KEY_PAYLOAD = 'payload';
    /** 消息被接收的次数，用于判断是否达到重试上限 */
    public const RECEIVE_KEY_DEQUEUE_COUNT = 'dequeue_count';

    private Client $client;

    /**
     * @throws \RuntimeException MNS 未安装或配置不完整
     */
    public function __construct()
    {
        if (!class_exists(Client::class)) {
            throw new \RuntimeException('未安装 MNS SDK，请执行 composer require aliyun/aliyun-mns-php-sdk');
        }

        $endpoint = (string)config('mns.endpoint');
        $accessKeyId = (string)config('mns.access_key_id');
        $accessKeySecret = (string)config('mns.access_key_secret');

        // 未单独配置 AccessKey 时回退到平台阿里云存储配置，避免重复维护密钥。
        if ($accessKeyId === '' || $accessKeySecret === '') {
            $aliyun = ConfigService::get('storage', 'aliyun', []);
            if (is_array($aliyun)) {
                $accessKeyId = $accessKeyId !== '' ? $accessKeyId : (string)($aliyun['access_key'] ?? '');
                $accessKeySecret = $accessKeySecret !== '' ? $accessKeySecret : (string)($aliyun['secret_key'] ?? '');
            }
        }

        if ($endpoint === '' || $accessKeyId === '' || $accessKeySecret === '') {
            throw new \RuntimeException('MNS 配置不完整，请配置 mns.endpoint、mns.access_key_id、mns.access_key_secret');
        }

        $this->client = new Client($endpoint, $accessKeyId, $accessKeySecret);
    }

    /**
     * 发送原始消息。
     *
     * @param string $queueName 队列名
     * @param mixed  $body      消息体，非字符串会被 JSON 序列化
     * @param int    $delaySeconds 延迟可见秒数
     * @return array<string,mixed>
     */
    public function sendMessage(string $queueName, $body, int $delaySeconds = 0): array
    {
        $payload = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE);
        if ($payload === false || $payload === null) {
            throw new \RuntimeException('消息序列化失败');
        }
        try {
            $queue = $this->client->getQueueRef($queueName);
            // 优先级 8 为 MNS 默认值
            $request = new SendMessageRequest($payload, $delaySeconds, 8);
            $response = $queue->sendMessage($request);
            return [
                'queue' => $queueName,
                'message_id' => (string)$response->getMessageId(),
                'delay' => $delaySeconds,
                'sent_at' => time(),
            ];
        } catch (MnsException $e) {
            throw new \RuntimeException('发送消息失败: ' . $e->getMessage());
        }
    }

    /**
     * 投递 Job。
     *
     * @param string $queueName 队列名
     * @param Job    $job       任务实例
     * @param int    $delaySeconds 延迟秒数
     * @return array<string,mixed>
     */
    public function dispatchJob(string $queueName, Job $job, int $delaySeconds = 0): array
    {
        $payload = $job->getPayload();
        $result = $this->sendMessage($queueName, $payload, $delaySeconds);
        return [
            'job_id' => $job->getId(),
            'job_class' => get_class($job),
            'queue' => $queueName,
            'delay' => $delaySeconds,
            'message_id' => (string)($result['message_id'] ?? ''),
        ];
    }

    /**
     * 拉取单条消息。
     *
     * @param string $queueName    队列名
     * @param int    $waitSeconds  长轮询等待秒数
     * @param bool   $autoDelete   是否接收即删除；需要失败重试时必须传 false
     * @return array<string,mixed>|null 队列为空返回 null
     */
    public function receiveMessage(string $queueName, int $waitSeconds = 3, bool $autoDelete = true): ?array
    {
        try {
            $queue = $this->client->getQueueRef($queueName);
            $response = $queue->receiveMessage($waitSeconds);
            $receiptHandle = (string)$response->getReceiptHandle();
            if ($autoDelete && $receiptHandle !== '') {
                $queue->deleteMessage($receiptHandle);
            }
            $rawBody = $response->getMessageBody();
            $messageBody = is_string($rawBody)
                ? $rawBody
                : (string)json_encode($rawBody, JSON_UNESCAPED_UNICODE);
            $payload = json_decode($messageBody, true);
            if (!is_array($payload)) {
                $payload = [];
            }

            return [
                self::RECEIVE_KEY_MESSAGE_ID => method_exists($response, 'getMessageId') ? (string)$response->getMessageId() : '',
                self::RECEIVE_KEY_RECEIPT_HANDLE => $receiptHandle,
                self::RECEIVE_KEY_MESSAGE_BODY => $messageBody,
                self::RECEIVE_KEY_PAYLOAD => $payload,
                self::RECEIVE_KEY_DEQUEUE_COUNT => method_exists($response, 'getDequeueCount') ? (int)$response->getDequeueCount() : 0,
            ];
        } catch (MnsException $e) {
            // 队列为空是正常状态，不作为错误抛出
            if ($this->isQueueEmptyException($e)) {
                return null;
            }
            Log::channel('mns')->error(sprintf('[mns.receive] fail queue=%s error=%s', $queueName, $e->getMessage()));
            throw new \RuntimeException('消费消息失败: ' . $e->getMessage());
        }
    }

    /**
     * 删除消息；任务执行成功后调用。
     */
    public function deleteMessage(string $queueName, string $receiptHandle): void
    {
        try {
            $this->client->getQueueRef($queueName)->deleteMessage($receiptHandle);
        } catch (MnsException $e) {
            Log::channel('mns')->error(sprintf('[mns.delete] fail queue=%s error=%s', $queueName, $e->getMessage()));
            throw new \RuntimeException('删除消息失败: ' . $e->getMessage());
        }
    }

    /**
     * 从消息体还原 Job 实例。
     *
     * @throws \RuntimeException 任务类非法或未继承 Job
     */
    public static function restoreJob(array $payload): Job
    {
        $jobClass = $payload['data']['job'] ?? '';
        if (!is_string($jobClass) || $jobClass === '' || !class_exists($jobClass)) {
            throw new \RuntimeException('非法的 Job 类: ' . (is_string($jobClass) ? $jobClass : gettype($jobClass)));
        }
        if (!is_subclass_of($jobClass, Job::class)) {
            throw new \RuntimeException('Job 类必须继承 ' . Job::class . ': ' . $jobClass);
        }
        return $jobClass::fromPayload($payload);
    }

    /**
     * 兼容不同 SDK 版本的「队列为空」错误码/文案。
     */
    private function isQueueEmptyException(MnsException $e): bool
    {
        $normalized = preg_replace('/[^a-z]/', '', strtolower((string)$e->getMessage())) ?? '';
        if (str_contains($normalized, 'messagenotexist')) {
            return true;
        }
        if (method_exists($e, 'getMnsErrorCode')) {
            $code = strtolower((string)$e->getMnsErrorCode());
            if ($code !== '' && str_contains($code, 'messagenotexist')) {
                return true;
            }
        }
        return false;
    }
}
