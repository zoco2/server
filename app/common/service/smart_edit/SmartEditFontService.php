<?php

namespace app\common\service\smart_edit;

use app\common\service\FileService;

/**
 * 标题字体库。
 *
 * 字体固定写在 catalog()，文件预置在 OSS fonts/。
 * IMS Font 字段不可靠，统一走 FontURL：用文件路径拼可访问地址。
 * 不读库，增删字体改本清单即可。
 */
class SmartEditFontService
{
    /** 平台字体所在 OSS 域名（公有读） */
    private const FONT_DOMAIN = 'https://zoco2.oss-cn-beijing.aliyuncs.com';

    /** OSS 目录前缀 */
    private const FONT_DIR = 'fonts';

    /**
     * 预置字体清单。key 即提交时的 font_key。
     *
     * @return array<string, array{label:string,file:string,preview:string}>
     */
    public static function catalog(): array
    {
        return [
            'xinqingnian' => ['label' => '新青年', 'file' => '新青年.ttf', 'preview' => ''],
            'aa-jianhao' => ['label' => 'Aa剑豪体', 'file' => 'Aa剑豪体.ttf', 'preview' => ''],
            'baotu-xiaobai' => ['label' => '包图小白体', 'file' => '包图小白体.ttf', 'preview' => ''],
            'jingnan-bobohei' => ['label' => '荆南波波黑', 'file' => '荆南波波黑.otf', 'preview' => ''],
            'canger-shuyuan' => ['label' => '苍耳舒圆体', 'file' => '苍耳舒圆体.ttf', 'preview' => ''],
            'chuangketie-jingang' => ['label' => '创客贴金刚体', 'file' => '创客贴金刚体.otf', 'preview' => ''],
            'yuanjing-heiti' => ['label' => '源影黑体', 'file' => '源影黑体.ttf', 'preview' => ''],
            'honglei-xingshu' => ['label' => '鸿雷行书简体', 'file' => '鸿雷行书简体.otf', 'preview' => ''],
            'maoken-shijin' => ['label' => '猫啃什锦体', 'file' => '猫啃什锦体.ttf', 'preview' => ''],
            'muyao-softbrush' => ['label' => '沐瑶软笔手写体', 'file' => '沐瑶软笔手写体.ttf', 'preview' => ''],
            'qiantu-xiaotu' => ['label' => '千小图体', 'file' => '千小图体.ttf', 'preview' => ''],
            'sanji-pomo' => ['label' => '三极泼墨体', 'file' => '三极泼墨体.ttf', 'preview' => ''],
            'yanshi-foxi' => ['label' => '演示佛系体', 'file' => '演示佛系体.ttf', 'preview' => ''],
            'biaoxiaozhi-wujiehei' => ['label' => '标小智无界黑', 'file' => '标小智无界黑.ttf', 'preview' => ''],
            'youshe-biaotiyuan' => ['label' => '优设标题圆', 'file' => '优设标题圆.otf', 'preview' => ''],
            'aa-houdihei' => ['label' => 'Aa厚底黑', 'file' => 'Aa厚底黑.ttf', 'preview' => ''],
            'alibaba-puhui' => ['label' => '阿里巴巴普惠体', 'file' => '阿里巴巴普惠体.ttf', 'preview' => ''],
            'alimama-fangyuan' => ['label' => '阿里妈妈方圆体', 'file' => '阿里妈妈方圆体.ttf', 'preview' => ''],
            'shiwei-yongtui' => ['label' => '狮尾咏腿黑体', 'file' => '狮尾咏腿黑体.ttf', 'preview' => ''],
            'zizhiqu-ximai' => ['label' => '自制区喜脉体', 'file' => '自制区喜脉体.ttf', 'preview' => ''],
            'zitiquan-weijunhei-w3' => ['label' => '字体圈伟君黑W3', 'file' => '字体圈伟君黑W3.ttf', 'preview' => ''],
            'alimama-dongfangdakai' => ['label' => '阿里妈妈东方大楷', 'file' => '阿里妈妈东方大楷.ttf', 'preview' => ''],
            'zitiquan-xinyiguanhei' => ['label' => '字体圈欣意冠黑体', 'file' => '字体圈欣意冠黑体.ttf', 'preview' => ''],
            'zihun-biantao' => ['label' => '字魂扁桃体', 'file' => '字魂扁桃体.ttf', 'preview' => ''],
            'yanshi-xiehei' => ['label' => '演示斜黑体', 'file' => '演示斜黑体.otf', 'preview' => ''],
            'jingnan-junjun' => ['label' => '荆南俊俊体', 'file' => '荆南俊俊体.otf', 'preview' => ''],
            'huxiaobo-nanshen' => ['label' => '胡晓波男神体', 'file' => '胡晓波男神体.otf', 'preview' => ''],
            'douyin-meihao' => ['label' => '抖音美好体', 'file' => '抖音美好体.otf', 'preview' => ''],
            'alimama-shuheiti' => ['label' => '阿里妈妈数黑体', 'file' => '阿里妈妈数黑体.ttf', 'preview' => ''],
        ];
    }

    /**
     * 前端下拉用的字体列表。
     *
     * url 供预览页 @font-face 使用；提交任务仍走 prepare()。
     *
     * @return array<int, array{key:string,label:string,preview:string,url:string}>
     */
    public static function options(): array
    {
        $rows = [];
        foreach (self::catalog() as $key => $item) {
            $uri = self::FONT_DIR . '/' . $item['file'];
            $rows[] = [
                'key' => $key,
                'label' => $item['label'],
                'preview' => $item['preview'],
                'url' => SmartEditImsConfigService::joinUrl(self::FONT_DOMAIN, $uri),
            ];
        }
        return $rows;
    }

    /**
     * 取得某个预置字体的可访问地址（IMS FontURL）。
     *
     * 文件已在 OSS，不再下载或上传；拼好地址即可。
     * 返回空串表示该字体不可用。
     *
     * @param array  $config  SmartEditImsConfigService::require() 的结果（保留入参以兼容调用方）
     * @param string $key     字体 key
     * @param int    $timeout 兼容旧签名，已无下载逻辑
     */
    public static function prepare(array $config, string $key, int $timeout = 0): string
    {
        unset($timeout);
        $font = self::catalog()[$key] ?? null;
        if ($font === null) {
            return '';
        }

        $file = (string)$font['file'];
        if ($file === '' || str_contains($file, '..') || str_contains($file, '/') || str_contains($file, '\\')) {
            return '';
        }
        $uri = self::FONT_DIR . '/' . $file;

        $url = FileService::getFileUrlByStorage($uri, 'platform', 'aliyun', self::FONT_DOMAIN);
        if ($url !== '') {
            return $url;
        }

        $domain = trim((string)($config['domain'] ?? self::FONT_DOMAIN));
        return $domain === '' ? '' : SmartEditImsConfigService::joinUrl($domain, $uri);
    }

    /**
     * 字体的回退系统字体名；固定清单无别名时返回空。
     */
    public static function aliasOf(string $key): string
    {
        unset($key);
        return '';
    }

    /**
     * 字体是否在预置清单中。
     */
    public static function has(string $key): bool
    {
        return isset(self::catalog()[$key]);
    }

    /**
     * 全部预置字体的 key 列表（命令行校验用）。
     *
     * @return array<int, string>
     */
    public static function codes(): array
    {
        return array_keys(self::catalog());
    }
}
