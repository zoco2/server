<?php

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use app\common\service\geo\GeoArticleGenerationService;
use app\common\service\power\MarketTextModelRuntimeService;

$check = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};
$decode = new ReflectionMethod(GeoArticleGenerationService::class, 'decodeArticles');
$history = new ReflectionMethod(GeoArticleGenerationService::class, 'assistantToolMessage');
$parse = new ReflectionMethod(MarketTextModelRuntimeService::class, 'parseSseBlock');
$apply = new ReflectionMethod(MarketTextModelRuntimeService::class, 'applyStreamEvent');

$calls = [
    ['index' => 0, 'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'get_brand_article_detail', 'arguments' => '{"article_id":1}']],
    ['index' => 1, 'id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'get_brand_article_detail', 'arguments' => '{"article_id":3}']],
];
$result = ['content_source' => 'reasoning', 'content' => 'Let me read both articles.'];
$message = $history->invoke(null, $result, $calls);
$check($message['content'] === '', 'Tool history promoted reasoning to visible content');
$check(array_column($message['tool_calls'], 'id') === ['call_1', 'call_2'], 'Parallel tool call IDs changed');
$check(!isset($message['tool_calls'][0]['index']) && !isset($message['tool_calls'][1]['index']), 'Streaming indexes leaked into tool history');
$result = ['content_source' => 'content', 'content' => 'Let me read both articles.', '_responses_output' => [['type' => 'function_call']]];
$message = $history->invoke(null, $result, $calls);
$check($message['content'] === '' && $message['_responses_output'] === $result['_responses_output'], 'Tool preamble leaked or native continuation history changed');

$articles = [];
for ($i = 1; $i <= 6; $i++) {
    $articles[] = ['title' => '本地推广 ' . $i, 'digest' => '摘要', 'content' => "第一段有引号 \" 和括号 {}。\n\n第二段。"];
}
$json = json_encode(['articles' => $articles], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
foreach ([$json, "```json\n$json\n```", "以下是稿件。\n```json\n$json\n```\n共六篇。", "\xEF\xBB\xBF<think>{草稿}</think>\n$json"] as $content) {
    $check($decode->invoke(null, $content) === $articles, 'Complete article list or paragraph formatting was lost');
}
$quotedArticles = $articles;
$quotedArticles[0]['content'] = '回答"我的店为谁服务"。例如"手作咖啡""本地烘培"。' . "\n" . $articles[0]['content'];
$quotedJson = json_encode(['articles' => $quotedArticles], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$malformed = str_replace(
    ['\"我的店为谁服务\"', '\"手作咖啡\"', '\"本地烘培\"'],
    ['"我的店为谁服务"', '"手作咖啡"', '"本地烘培"'],
    $quotedJson
);
foreach ([$malformed, "```json\n$malformed\n```"] as $reply) {
    $check($decode->invoke(null, $reply) === $quotedArticles, 'Body quote repair changed the six articles');
}

$punctuated = $articles;
$punctuated[0] = [
    'title' => '活动主题"📍 先看，再选！"',
    'digest' => '门店回答"卖给谁、卖什么、凭什么选我"三个问题。',
    'content' => "第一段保留路径 C:\\menu 与已有的引号 \"原文\"。\n"
        . '先跑通"曝光—到店—体验—复购"。再比较"A/B (v2.0): 50%, OK?", 然后复盘。',
];
$quoteJson = json_encode(['articles' => $punctuated], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$badQuotes = str_replace(
    ['\"📍 先看，再选！\"', '\"卖给谁、卖什么、凭什么选我\"', '\"曝光—到店—体验—复购\"', '\"A\/B (v2.0): 50%, OK?\"'],
    ['"📍 先看，再选！"', '"卖给谁、卖什么、凭什么选我"', '"曝光—到店—体验—复购"', '"A\/B (v2.0): 50%, OK?"'],
    $quoteJson
);
$check($decode->invoke(null, "```json\n$badQuotes") === $punctuated, 'Punctuation, existing escapes or field boundaries changed');
foreach ([
    '<think>' . $json => '模型未返回正式稿件',
    substr($json, 0, -2) => '模型返回的稿件格式有误，无法解析为 JSON',
    $json . "\n" . $json => '模型返回的稿件格式有误，无法解析为 JSON',
    substr($malformed, 0, -2) => '模型返回的稿件格式有误，无法解析为 JSON',
    $malformed . $malformed => '模型返回的稿件格式有误，无法解析为 JSON',
    '{"articles":[{"content":"正文" "title":"标题"}]}' => '模型返回的稿件格式有误，无法解析为 JSON',
    '{"articles":[{"title":"标题","content":"正文中"没闭合。"}]}' => '模型返回的稿件格式有误，无法解析为 JSON',
    '{"articles":[{"title":"第一篇","content":"缺少结尾},{"title":"第二篇","content":"完整"}]}' => '模型返回的稿件格式有误，无法解析为 JSON',
    '{"articles":[{"title":"标题" "digest":"摘要","content":"引语"先看，再选"。"}]}' => '模型返回的稿件格式有误，无法解析为 JSON',
    '{"articles":[{"title":"标题","extra":"带"引语"的未知字段","content":"正文"}]}' => '模型返回的稿件格式有误，无法解析为 JSON',
    '{"articles":{"0":{"title":"标题","content":"正文"}}}' => '模型未返回有效的稿件列表',
    '{"articles":[]}' => '模型未返回有效的稿件列表',
    '{"articles":[{"title":"标题","content":""}]}' => '模型返回的稿件缺少标题或正文',
] as $content => $expectedError) {
    try {
        $decode->invoke(null, $content);
        throw new RuntimeException('Invalid article output was accepted');
    } catch (InvalidArgumentException $e) {
        $check($e->getMessage() === $expectedError, 'The validation error obscured the reason the response failed');
    }
}

foreach ([['reasoning_content' => 'Still thinking.'], ['tool_calls' => $calls]] as $delta) {
    $state = ['content' => '', 'reasoning' => '', 'usage' => [], 'request_id' => '', 'emitted_request_id' => '', 'tool_calls' => [], 'delta_count' => 0];
    $event = $parse->invoke(null, 'data: ' . json_encode(['choices' => [['delta' => $delta, 'finish_reason' => 'length']]]));
    $apply->invokeArgs(null, [$event, &$state, static function (): void {}]);
    $check($state['finish_reason'] === 'length' && $state['content'] === '', 'Truncation was lost on a reasoning or tool-call frame');
}

echo "GEO article reply checks passed\n";
