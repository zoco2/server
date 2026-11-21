<?php

namespace app\common\service\app {
    class AppAccessService {
        public static function assertTenantAvailable(int $tenantId, string $app): ?string { return null; }
    }
}

namespace app\common\service\wenan {
    class WenanConfigService {
        public static function resolveTextModelId(int $tenantId): string { return 'configured-model'; }
    }
}

namespace app\common\service\app\aigc_llm {
    class AigcLlmService {
        public static array $request;
        public static array $reply;
        public static function generateText(int $tenantId, int $userId, array $params): array {
            self::$request = $params;
            return self::$reply;
        }
    }
}

namespace think\facade {
    class Log {
        public static array $warnings = [];
        public static function warning(string $message): void { self::$warnings[] = $message; }
    }
}

namespace {
    use app\common\service\app\aigc_llm\AigcLlmService as Model;
    use app\common\service\wenan\WenanEnhanceService as Service;
    use think\facade\Log;

    require dirname(__DIR__, 2) . '/app/common/service/wenan/WenanEnhanceService.php';
    function check(bool $ok, string $message): void {
        if (!$ok) throw new \RuntimeException($message);
    }

    $params = ['scene' => 'geo_article_titles', 'text' => '实体店本地推广从0到1实操指南等 6 篇', 'hint' => '避开上一批'];
    $titles = [];
    $lines = [];
    for ($i = 1; $i <= 6; $i++) {
        $title = '第' . $i . '篇' . str_repeat('实体店本地推广', 4);
        $titles[] = ['title' => $title, 'category' => '实操指南'];
        $lines[] = "$i. $title" . ($i % 2 ? '｜' : '|') . '实操指南';
    }
    Model::$reply = ['content' => "<think>推理</think>\n候选标题：\n" . implode("\r\n", [$lines[0], ...$lines])];
    $result = Service::write(7, 9, $params);
    check($result['titles'] === $titles && $result['text'] === $result['prompt'], 'Six separate candidates and their categories must survive');
    check(!str_contains(Model::$request['system_prompt'], '禁止输出思考过程、分析、自我解释、任务复述、Markdown、标题')
        && str_contains(Model::$request['system_prompt'], '6 个中文宣传稿标题'), 'Conflicting title instructions');
    check(str_contains(Model::$request['content'], $params['text']) && str_contains(Model::$request['content'], $params['hint'])
        && !str_contains(Model::$request['content'], '请写一条'), 'Topic or refresh instruction lost');
    check(Model::$request['model_code'] === 'configured-model', 'Tenant model selection changed');

    Model::$reply = ['content' => "未分类的标题\n" . str_repeat('长', 121) . "\n重复标题\n重复标题"];
    $result = Service::write(7, 9, $params);
    check(array_column($result['titles'], 'title') === ['未分类的标题', '重复标题'], 'Plain, duplicate or overlong title handling');
    check(Log::$warnings === [], 'Valid lists must not be logged');

    foreach (['content', 'reasoning'] as $source) {
        Model::$reply = ['content_source' => $source, 'content' => str_repeat('长', 121), 'consumption_id' => 17];
        try {
            Service::write(7, 9, $params);
            throw new \LogicException('An unusable reply was accepted');
        } catch (\Exception $e) {
            check($e->getMessage() === '模型未返回可用的候选标题，请重新拓展', 'Wrong failure reason');
        }
        $warning = end(Log::$warnings);
        $details = json_decode(substr($warning, strpos($warning, '{')), true, 512, JSON_THROW_ON_ERROR);
        check($details['consumption_id'] === 17 && $details['response_content'] === ($source === 'content' ? Model::$reply['content'] : ''), 'Wrong failure diagnostic');
    }

    Model::$reply = ['content' => '通用文案。'];
    $result = Service::write(7, 9, ['scene' => 'general', 'text' => '通用需求']);
    check($result['text'] === '通用文案。' && !isset($result['titles']) && str_contains(Model::$request['content'], '请写一条'), 'General writing contract changed');
    echo "GEO title expansion checks passed\n";
}
