<?php

declare(strict_types=1);

namespace app\common\service\publish_task;

use InvalidArgumentException;

final class PublishTaskMaterialShortage extends InvalidArgumentException
{
    /** @param list<array{type:string,name:string}> $missingGroups */
    public function __construct(public readonly array $missingGroups)
    {
        parent::__construct('可用素材不足');
    }
}
