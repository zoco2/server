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
 * 智能剪辑字体预热。
 *
 * 中文字体动辄 20MB 以上，从 GitHub 拉取很慢。若等到用户提交时再下载，
 * 请求会卡在同步下载上直到 PHP 超时。这里在部署阶段先把字体取下来传到
 * 每个租户自己的 OSS，之后提交只做一次 exists 判断。
 *
 * 用法：
 *   php think smart-edit:fonts                    # 所有已配置 IMS 的租户，全部字体
 *   php think smart-edit:fonts --tenant=1
 *   php think smart-edit:fonts --font=wenkai,smiley
 */
class SmartEditFonts extends Command
{
    protected function configure()
    {
        $this->setName('smart-edit:fonts')
            ->addOption('tenant', 't', Option::VALUE_REQUIRED, '租户ID，不传则遍历已配置智能剪辑阿里云的租户')
            ->addOption('font', null, Option::VALUE_REQUIRED, '字体 key，多个用逗号分隔，不传则全部')
            ->setDescription('预热智能剪辑标题字体到租户 OSS');
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
            $output->writeln('<error>没有匹配的字体 key</error>');
            return 1;
        }

        $failed = 0;
        foreach ($tenants as $tenantId) {
            $output->writeln(sprintf('[tenant %d] 开始预热 %d 款字体', $tenantId, count($keys)));
            try {
                $config = SmartEditImsConfigService::require($tenantId);
            } catch (\Throwable $e) {
                $output->writeln('  <error>配置不完整：' . $e->getMessage() . '</error>');
                $failed++;
                continue;
            }
            foreach ($keys as $key) {
                $output->write(sprintf('  %-16s ', $key));
                // 命令行不受请求超时限制，给足下载时间
                $url = SmartEditFontService::prepare($config, $key);
                if ($url === '') {
                    $output->writeln('<error>失败</error>');
                    $failed++;
                    continue;
                }
                $output->writeln('<info>完成</info>');
            }
        }

        return $failed > 0 ? 1 : 0;
    }

    /**
     * 需要处理的租户。
     *
     * @return array<int, int>
     */
    private function resolveTenants(int $tenantId): array
    {
        if ($tenantId > 0) {
            return [$tenantId];
        }
        // 只处理真正配置过 IMS 的租户，避免对空租户报错
        $rows = TenantConfig::where(['type' => 'smart_edit', 'name' => 'aliyun'])
            ->column('tenant_id');
        return array_values(array_unique(array_map('intval', $rows)));
    }

    /**
     * 需要预热的字体 key。
     *
     * @return array<int, string>
     */
    private function resolveKeys(string $raw): array
    {
        $all = array_keys(SmartEditFontService::catalog());
        $raw = trim($raw);
        if ($raw === '') {
            return $all;
        }
        $wanted = array_filter(array_map('trim', explode(',', $raw)));
        return array_values(array_intersect($all, $wanted));
    }
}
