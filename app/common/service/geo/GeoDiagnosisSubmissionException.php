<?php

declare(strict_types=1);

namespace app\common\service\geo;

use RuntimeException;
use Throwable;

/** 检测提交失败，但失败批次已持久化，可供用户查询。 */
final class GeoDiagnosisSubmissionException extends RuntimeException
{
    public function __construct(private readonly int $batchId, Throwable $cause)
    {
        parent::__construct($cause->getMessage(), 0, $cause);
    }

    public function batchId(): int
    {
        return $this->batchId;
    }
}
