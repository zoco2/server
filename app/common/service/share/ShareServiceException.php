<?php

declare(strict_types=1);

namespace app\common\service\share;

use RuntimeException;

/** 可在分享页面展示的错误。只使用本地文案和平台数字错误码。 */
final class ShareServiceException extends RuntimeException
{
}
