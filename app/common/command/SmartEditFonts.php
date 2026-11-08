<?php

namespace app\common\command;

use app\common\model\TenantConfig;
use app\common\service\smart_edit\SmartEditFontService;
use app\common\service\smart_edit\SmartEditImsConfigService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 智能剪辑字体地址校验。
 *
 * 字体文件已预置在 OSS fonts/，本命令只检查 code 能否拼出可访问 URL，
 * 不再下载或上传。
 *
 * 用法：
 *   php think smart-edit:fonts
 *   php think smart-edit:fonts --tenant=1
 *   php think smart-edit:fonts --font=xinqingnian,douyin-meihao
 */
class SmartEditFonts extends Command
{
    protected function configure()
    {
        $this->setName('smart-edit:fonts')
            ->addOption('tenant', 't', Option::VALUE_REQUIRED, '租户ID，不传则遍历已配置智能剪辑阿里云的租户')
            ->addOption('font', null, Option::VALUE_REQUIRED, '字体 code，多个用逗号分隔，不传则全部')
            ->setDescription('校验智能剪辑标题字体 URL 是否可解析');
    }

    protected function execute(Input $input, Output $output)
    {
        $tenants = $this->resolveTenants((int)$input->getOption('tenant'));
        if ($tenants === []) {
            $output->writeln('<error>没有找到已配置智能剪辑阿里云的租户</error>');
            return 1;
        }
        $keys = $this->resolveKeys((string)$input->getOption('font'));
        if ($keys === []) {
            $output->writeln('<error>没有匹配的字体 code</error>');
            return 1;
        }

        $failed = 0;
        foreach ($tenants as $tenantId) {
            $output->writeln(sprintf('[tenant %d] 校验 %d 款字体', $tenantId, count($keys)));
            try {
                $config = SmartEditImsConfigService::require($tenantId);
            } catch (\Throwable $e) {
                $output->writeln('  <error>配置不完整：' . $e->getMessage() . '</error>');
                $failed++;
                continue;
            }
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
        }

        return $failed > 0 ? 1 : 0;
    }

    /**
     * @return array<int, int>
     */
    private function resolveTenants(int $tenantId): array
    {
        if ($tenantId > 0) {
            return [$tenantId];
        }
        $rows = TenantConfig::where(['type' => 'smart_edit', 'name' => 'aliyun'])
            ->column('tenant_id');
        return array_values(array_unique(array_map('intval', $rows)));
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
