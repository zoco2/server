<?php

namespace app\common\command;

use app\common\service\smart_edit\SmartEditFontService;
use app\common\service\smart_edit\SmartEditImsConfigService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 智能剪辑字体地址校验。
 *
 * 字体清单写死在 SmartEditFontService，文件预置在 OSS fonts/。
 * 本命令只检查 key 能否拼出可访问 URL，不再下载或上传。
 *
 * 用法：
 *   php think smart-edit:fonts
 *   php think smart-edit:fonts --font=xinqingnian,douyin-meihao
 */
class SmartEditFonts extends Command
{
    protected function configure()
    {
        $this->setName('smart-edit:fonts')
            ->addOption('font', null, Option::VALUE_REQUIRED, '字体 key，多个用逗号分隔，不传则全部')
            ->setDescription('校验智能剪辑标题字体 URL 是否可解析');
    }

    protected function execute(Input $input, Output $output)
    {
        $keys = $this->resolveKeys((string)$input->getOption('font'));
        if ($keys === []) {
            $output->writeln('<error>没有匹配的字体 key</error>');
            return 1;
        }

        try {
            $config = SmartEditImsConfigService::requirePlatform();
        } catch (\Throwable $e) {
            $output->writeln('<error>配置不完整：' . $e->getMessage() . '</error>');
            return 1;
        }

        $failed = 0;
        foreach ($keys as $key) {
            $output->write(sprintf('  %-24s ', $key));
            $url = SmartEditFontService::prepare($config, $key);
            if ($url === '') {
                $output->writeln('<error>失败</error>');
                $failed++;
                continue;
            }
            $output->writeln('<info>' . $url . '</info>');
        }

        return $failed > 0 ? 1 : 0;
    }

    /**
     * @return array<int, string>
     */
    private function resolveKeys(string $raw): array
    {
        $all = SmartEditFontService::codes();
        $raw = trim($raw);
        if ($raw === '') {
            return $all;
        }
        $wanted = array_filter(array_map('trim', explode(',', $raw)));
        return array_values(array_intersect($all, $wanted));
    }
}
