<?php

declare(strict_types=1);

namespace app\common\job;

final class HelloWorldJob
{
    public function handle(): string
    {
        return 'Hello World';
    }
}
