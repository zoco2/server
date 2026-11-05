<?php

namespace app\api\logic\merchant;

use app\common\logic\BaseLogic;
use app\common\model\merchant\MerchantStore;
use app\common\model\merchant\MerchantStoreDesign;
use InvalidArgumentException as Invalid;
use Throwable;

/**
 * 门店装修草稿。只保存配置，不发布、不授权平台能力。
 * 图片用上传接口返回的 id + URL，存在 files JSON 里，不查独立文件表。
 */
class StoreDesignLogic extends BaseLogic
{
    private const TYPES = ['notice', 'store', 'title', 'navigation', 'product', 'cube', 'community', 'floating'];

    /** 门店资料图的保留 id，避免和上传文件 id 撞上 */
    private const STORE_LOGO_FILE_ID = 2000000000;

    /**
     * 功能入口目录，给编辑器左侧平台入口用。
     *
     * @return array<int, array{key:string,title:string,items:array}>
     */
    private static function catalog(): array
    {
        $groups = [
            ['video', '视频发布', [['douyin', '抖音', '发抖音'], ['kuaishou', '快手', '发快手'], ['xiaohongshu', '小红书', '小红书'], ['channels', '视频号', '视频号']]],
            ['review', '打卡/点评/收藏', [['dianping', '点评+打卡', '点评+打卡'], ['douyin', '点评+收藏', '点评+收藏'], ['amap', '点评+收藏', '点评+收藏'], ['baidu', '点评+收藏', '点评+收藏'], ['meituan', '点评+收藏', '点评+收藏'], ['ctrip', '点评+收藏', '点评+收藏'], ['scenic', '景区点评', '景区点评']]],
            ['notes', '图文发布', [['moments', '朋友圈(图文)', '发朋友圈'], ['xiaohongshu', '小红书(笔记)', '小红书图文'], ['ctrip', '携程(笔记)', '携程笔记'], ['dianping', '大众点评', '大众点评']]],
            ['follow', '关注账号', [['douyin', '关注抖音', '关注抖音'], ['kuaishou', '关注快手', '关注快手'], ['xiaohongshu', '关注小红书', '关注小红书'], ['channels', '关注视频号', '关注视频号'], ['wechat', '关注公众号', '关注公众号']]],
            ['like', '点赞/分享', [['douyin', '抖音', '抖音'], ['channels', '视频号', '视频号']]],
            ['service', '连WIFI&加微信', [['wifi', '连WIFI', '连WIFI'], ['wechat', '加微信', '加微信'], ['drive', '叫代驾', '叫代驾']]],
            ['deals', '优惠团购', [['meituan', '美团团购', '美团团购'], ['douyin', '抖音团购', '抖音团购'], ['dianping', '大众点评', '大众点评']]],
        ];
        $out = [];
        foreach ($groups as [$key, $title, $items]) {
            $rows = [];
            foreach ($items as [$platform, $label, $text]) {
                $code = $key . '_' . $platform;
                $rows[] = [
                    'code' => $code,
                    'platform' => $platform,
                    'label' => $label,
                    'title' => $text,
                    'enabled' => !in_array($code, ['review_ctrip', 'review_scenic', 'notes_ctrip', 'like_douyin', 'like_channels'], true),
                ];
            }
            $out[] = ['key' => $key, 'title' => $title, 'items' => $rows];
        }
        return $out;
    }

    /**
     * 组件字段定义。
     *
     * @return array<string, array>
     */
    private static function componentSchema(string $type): array
    {
        $common = [
            'background' => ['color', ''],
            'color' => ['color', ''],
            'margin' => ['number', 10, 0, 20],
            'padding' => ['number', 12, 0, 30],
            'radius' => ['number', 12, 0, 30],
        ];
        $types = [
            'notice' => ['text' => ['text', '欢迎光临本店', 500], 'style' => ['enum', 'one', ['one', 'two']]],
            'store' => [
                'title' => ['text', '', 80],
                'description' => ['text', '', 200],
                'background_file_id' => ['image', 0],
                'logo_file_id' => ['image', 0],
                'style' => ['number', 1, 1, 5],
                'overlay' => ['bool', true],
                'show_phone' => ['bool', true],
                'show_address' => ['bool', true],
                'show_hours' => ['bool', true],
            ],
            'title' => [
                'title' => ['text', '标题文字', 100],
                'description' => ['text', '', 100],
                'title_size' => ['number', 16, 12, 32],
                'title_weight' => ['number', 600, 200, 800],
                'description_size' => ['number', 12, 10, 24],
                'description_weight' => ['number', 400, 200, 800],
                'description_color' => ['color', ''],
                'align' => ['enum', 'left', ['left', 'center', 'right']],
                'height' => ['number', 24, 20, 100],
                'divider' => ['bool', false],
                'more' => ['bool', false],
                'url' => ['url', ''],
            ],
            'navigation' => [
                'columns' => ['number', 5, 2, 5],
                'text_size' => ['number', 12, 10, 24],
                'text_height' => ['number', 24, 24, 50],
                'image_radius' => ['number', 0, 0, 50],
                'scroll' => ['bool', false],
            ],
            'product' => [
                'layout' => ['enum', 'two', ['big', 'two', 'three', 'list', 'featured', 'scroll']],
                'grouping' => ['bool', false],
                'source' => ['enum', 'manual', ['manual', 'douyin', 'meituan', 'dianping']],
                'weight' => ['number', 400, 200, 800],
                'image_radius' => ['number', 10, 0, 30],
                'gap' => ['number', 10, 0, 20],
                'show_price' => ['bool', true],
                'show_button' => ['bool', true],
                'button_text' => ['text', '查看详情', 6],
                'badge' => ['bool', false],
                'badge_text' => ['enum', '新品', ['新品', '热卖', 'NEW', 'HOT']],
                'badge_color' => ['color', '#f45d69'],
                'badge_position' => ['enum', 'top-left', ['top-left', 'bottom-left', 'top-right', 'bottom-right']],
            ],
            'cube' => ['layout' => ['enum', 'two', ['one', 'two', 'three', 'four', 'grid', 'leftTwo', 'topTwo', 'leftThree']], 'gap' => ['number', 5, 0, 20]],
            'community' => [
                'image_file_id' => ['image', 0],
                'mode' => ['enum', 'qr', ['qr', 'phone']],
                'title' => ['text', '加入门店社群', 40],
                'description' => ['text', '了解更多门店资讯', 80],
                'button_text' => ['text', '查看', 6],
                'qr_file_id' => ['image', 0],
                'phone' => ['text', '', 32],
            ],
            'floating' => [
                'image_file_id' => ['image', 0],
                'text' => ['text', '联系我们', 12],
                'mode' => ['enum', 'url', ['url', 'builtin', 'phone']],
                'url' => ['url', ''],
                'target' => ['text', '', 64],
                'phone' => ['text', '', 32],
                'position' => ['enum', 'right', ['left', 'right']],
                'bottom' => ['number', 30, 0, 300],
            ],
        ];
        if (!isset($types[$type])) {
            throw new Invalid('组件类型不正确');
        }
        return $common + $types[$type];
    }

    /**
     * 读取门店装修。没有草稿时返回默认配置，revision=0。
     *
     * @param array $params
     * @return array
     */
    public static function detail(array $params): array
    {
        try {
            $id = self::num($params['store_id'] ?? 0, 1, PHP_INT_MAX);
            $store = self::store($id);
            $saved = MerchantScope::query(MerchantStoreDesign::class)->where('store_id', $id)->findOrEmpty();
            $raw = $saved->isEmpty() ? [] : json_decode((string)$saved['config'], true);
            $files = self::incomingFiles($saved->isEmpty() ? [] : json_decode((string)($saved['files'] ?? '[]'), true));
            $config = self::normalize($raw, $files, false);
            self::attachStoreLogo($store, $files);
            return [
                'store' => self::storePayload($store),
                'config' => $config,
                'revision' => $saved->isEmpty() ? 0 : (int)$saved['revision'],
                'update_time' => $saved->isEmpty() ? 0 : (int)$saved['update_time'],
                'files' => array_values($files),
                'catalog' => self::catalog(),
                'schemas' => array_combine(self::TYPES, array_map(fn($type) => self::componentSchema($type), self::TYPES)),
            ];
        } catch (Invalid $e) {
            self::setError($e->getMessage());
            return [];
        } catch (Throwable $e) {
            self::setError('装修操作失败');
            return [];
        }
    }

    /**
     * 保存装修草稿。后写覆盖先写，不校验版本。
     *
     * @param array $params
     * @return array
     */
    public static function save(array $params): array
    {
        try {
            $files = self::incomingFiles($params['files'] ?? []);
            $config = self::normalize($params['config'] ?? null, $files, true);
            $id = self::num($params['store_id'] ?? 0, 1, PHP_INT_MAX);
            self::store($id);
            $old = MerchantScope::query(MerchantStoreDesign::class)->where('store_id', $id)->findOrEmpty();
            $now = time();
            $revision = ($old->isEmpty() ? 0 : (int)$old['revision']) + 1;
            $data = [
                'config' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'files' => json_encode(array_values($files), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'revision' => $revision,
                'update_time' => $now,
            ];
            if (!$old->isEmpty()) {
                $old->save($data);
            } else {
                MerchantStoreDesign::create($data + [
                    'user_id' => (int)request()->userId,
                    'store_id' => $id,
                ]);
            }
            return ['revision' => $revision, 'update_time' => $now];
        } catch (Invalid $e) {
            self::setError($e->getMessage());
            return [];
        } catch (Throwable $e) {
            self::setError('装修操作失败');
            return [];
        }
    }

    /**
     * 品牌下各门店的装修摘要，给装修 Tab 列表用。
     *
     * @param int $brandId
     * @return array
     */
    public static function summaries(int $brandId): array
    {
        try {
            $query = MerchantScope::query(MerchantStore::class)->field('id');
            if ($brandId > 0) {
                $query->where('brand_id', $brandId);
            }
            $storeIds = $query->column('id');
            if ($storeIds === []) {
                return [];
            }
            $rows = MerchantScope::query(MerchantStoreDesign::class)
                ->whereIn('store_id', $storeIds)
                ->field('store_id,revision,update_time')
                ->select()
                ->toArray();
            $map = [];
            foreach ($rows as $row) {
                $map[(int)$row['store_id']] = [
                    'store_id' => (int)$row['store_id'],
                    'revision' => (int)$row['revision'],
                    'update_time' => (int)$row['update_time'],
                ];
            }
            $out = [];
            foreach ($storeIds as $storeId) {
                $id = (int)$storeId;
                $out[] = $map[$id] ?? ['store_id' => $id, 'revision' => 0, 'update_time' => 0];
            }
            return $out;
        } catch (Invalid $e) {
            self::setError($e->getMessage());
            return [];
        } catch (Throwable $e) {
            self::setError('装修操作失败');
            return [];
        }
    }

    /**
     * 规范化装修 JSON。
     *
     * @param mixed $input
     * @param array<int, array> $files
     * @param bool $strict 保存时缺图要报错；读取时缺图清成 0
     * @return array
     */
    private static function normalize(mixed $input, array &$files = [], bool $strict = true): array
    {
        if (!is_array($input) || strlen(json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > 262144) {
            throw new Invalid('装修配置格式不正确或内容过大');
        }
        $out = ['version' => 1, 'style' => self::value($input['style'] ?? 'one', ['enum', 'one', ['one', 'two', 'custom']], $files, $strict)];
        $out['header'] = self::fields($input['header'] ?? [], [
            'title' => ['text', '发布任务 领福利', 20],
            'subtitle' => ['text', '发现门店好内容', 20],
            'background_file_id' => ['image', 0],
        ], $files, $strict);
        $out['page'] = self::fields($input['page'] ?? [], [
            'accent' => ['color', '#8a3ffc'],
            'background' => ['color', '#eaf4ff'],
            'color' => ['color', '#17212f'],
            'background_file_id' => ['image', 0],
            'blur' => ['bool', false],
        ], $files, $strict);
        $out['link'] = self::fields($input['link'] ?? [], [
            'enabled' => ['bool', false],
            'title' => ['text', '自定义标题', 40],
            'description' => ['text', '自定义描述', 80],
            'button_text' => ['text', '点击跳转', 12],
            'url' => ['url', ''],
        ], $files, $strict);
        $features = $input['features'] ?? [];
        if (!is_array($features)) {
            throw new Invalid('功能设置不正确');
        }
        $out['features'] = [];
        foreach (self::catalog() as $group) {
            foreach ($group['items'] as $item) {
                $out['features'][$item['code']] = self::value($features[$item['code']] ?? $item['enabled'], ['bool', true], $files, $strict);
            }
        }
        $front = $input['storefront'] ?? [];
        if (!is_array($front)) {
            throw new Invalid('门店外观设置格式不正确');
        }
        $out['storefront'] = self::fields($front, [
            'enabled' => ['bool', true],
            'hero_template' => ['enum', 'modern', ['modern', 'bright', 'night', 'warm']],
            'tagline' => ['text', '欢迎光临，感谢您的到来', 80],
            'hero_size' => ['enum', 'large', ['large', 'compact']],
            'show_favorite' => ['bool', true],
            'show_deals' => ['bool', true],
            'primary_feature' => ['enum', 'video_douyin', array_merge([''], self::codes())],
            'primary_style' => ['enum', 'platform', ['platform', 'black']],
        ], $files, $strict);
        $order = self::featureCodes($front['order'] ?? []);
        $out['storefront']['order'] = array_merge($order, array_values(array_diff(self::codes(), $order)));
        $out['storefront']['full_width'] = self::featureCodes($front['full_width'] ?? []);
        if ($out['storefront']['primary_feature'] !== '' && !$out['features'][$out['storefront']['primary_feature']]) {
            $out['storefront']['primary_feature'] = '';
        }
        $components = $input['components'] ?? [];
        if (!is_array($components) || !array_is_list($components) || count($components) > 40) {
            throw new Invalid('组件最多40个');
        }
        $out['components'] = [];
        $seen = [];
        foreach ($components as $component) {
            if (!is_array($component)) {
                throw new Invalid('组件格式不正确');
            }
            $cid = self::text($component['id'] ?? '', 64);
            if (!preg_match('/^[a-zA-Z0-9_-]{8,64}$/D', $cid) || isset($seen[$cid])) {
                throw new Invalid('组件标识重复或不正确');
            }
            $seen[$cid] = true;
            $type = $component['type'] ?? '';
            if (!is_string($type) || !in_array($type, self::TYPES, true)) {
                throw new Invalid('组件类型不正确');
            }
            $entry = [
                'id' => $cid,
                'type' => $type,
                'enabled' => self::value($component['enabled'] ?? true, ['bool', true], $files, $strict),
                'settings' => self::fields($component['settings'] ?? [], self::componentSchema($type), $files, $strict),
                'items' => [],
            ];
            $items = $component['items'] ?? [];
            if (!is_array($items) || !array_is_list($items) || count($items) > ($type === 'product' ? 30 : 20)) {
                throw new Invalid('组件条目数量或格式不正确');
            }
            if ($items && !in_array($type, ['navigation', 'product', 'cube'], true)) {
                throw new Invalid('该组件不支持条目');
            }
            foreach ($items as $item) {
                $schema = ['title' => ['text', '', 80], 'image_file_id' => ['image', 0], 'url' => ['url', ''], 'target' => ['text', '', 64]];
                if ($type === 'product') {
                    $schema += ['price' => ['text', '0.00', 12], 'tags' => ['text', '', 40], 'group' => ['text', '', 30]];
                }
                $row = self::fields($item, $schema, $files, $strict);
                if ($row['target'] !== '' && !in_array($row['target'], self::codes(), true)) {
                    throw new Invalid('内置入口不正确');
                }
                if ($type === 'product' && !preg_match('/^(?:0|[1-9]\d{0,6})(?:\.\d{1,2})?$/D', $row['price'])) {
                    throw new Invalid('商品价格格式不正确');
                }
                $entry['items'][] = $row;
            }
            if ($type === 'floating' && $entry['settings']['target'] !== '' && !in_array($entry['settings']['target'], self::codes(), true)) {
                throw new Invalid('内置入口不正确');
            }
            $out['components'][] = $entry;
        }
        return $out;
    }

    /**
     * @param int $id
     * @return array
     */
    private static function store(int $id): array
    {
        $row = MerchantScope::query(MerchantStore::class)->where('id', $id)->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Invalid('门店不存在或无权限');
        }
        return $row->toArray();
    }

    /**
     * @param array $store
     * @return array
     */
    private static function storePayload(array $store): array
    {
        return [
            'id' => (int)$store['id'],
            'brand_id' => (int)$store['brand_id'],
            'name' => (string)$store['name'],
            'status' => (int)$store['status'],
            'phone' => (string)($store['phone'] ?? ''),
            'business_hours' => (string)($store['business_hours'] ?? ''),
            'province' => (string)($store['province'] ?? ''),
            'city' => (string)($store['city'] ?? ''),
            'district' => (string)($store['district'] ?? ''),
            'address' => (string)($store['address'] ?? ''),
            'logo_url' => (string)($store['logo_url'] ?? ''),
        ];
    }

    /**
     * @param mixed $raw
     * @return array<int, array{id:int,url:string,uri:string}>
     */
    private static function incomingFiles(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (int)($item['id'] ?? 0);
            $url = trim((string)($item['url'] ?? $item['uri'] ?? ''));
            if ($id <= 0 || $url === '') {
                continue;
            }
            if (!preg_match('#^(https?:)?/#i', $url)) {
                continue;
            }
            $out[$id] = ['id' => $id, 'url' => $url, 'uri' => $url];
            if (count($out) >= 160) {
                break;
            }
        }
        return $out;
    }

    /**
     * @param array $store
     * @param array<int, array> $files
     */
    private static function attachStoreLogo(array $store, array &$files): void
    {
        $logo = trim((string)($store['logo_url'] ?? ''));
        if ($logo === '') {
            return;
        }
        foreach ($files as $file) {
            if (($file['url'] ?? '') === $logo) {
                return;
            }
        }
        $files[self::STORE_LOGO_FILE_ID] = [
            'id' => self::STORE_LOGO_FILE_ID,
            'url' => $logo,
            'uri' => $logo,
        ];
    }

    /**
     * @return string[]
     */
    private static function codes(): array
    {
        return array_merge(...array_map(fn($group) => array_column($group['items'], 'code'), self::catalog()));
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    private static function featureCodes(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > count(self::codes())) {
            throw new Invalid('功能列表格式不正确');
        }
        $seen = [];
        foreach ($value as $code) {
            if (!is_string($code) || !in_array($code, self::codes(), true) || isset($seen[$code])) {
                throw new Invalid('功能标识重复或不正确');
            }
            $seen[$code] = true;
        }
        return $value;
    }

    /**
     * @param mixed $input
     * @param array<string, array> $schema
     * @param array<int, array> $files
     * @param bool $strict
     * @return array
     */
    private static function fields(mixed $input, array $schema, array &$files, bool $strict): array
    {
        if (!is_array($input)) {
            throw new Invalid('设置格式不正确');
        }
        $out = [];
        foreach ($schema as $key => $spec) {
            $out[$key] = self::value($input[$key] ?? $spec[1], $spec, $files, $strict);
        }
        return $out;
    }

    /**
     * @param mixed $value
     * @param array $spec
     * @param array<int, array> $files
     * @param bool $strict
     * @return mixed
     */
    private static function value(mixed $value, array $spec, array &$files, bool $strict): mixed
    {
        switch ($spec[0]) {
            case 'text':
                return self::text($value, $spec[2]);
            case 'number':
                return self::num($value, $spec[2], $spec[3]);
            case 'bool':
                if (!in_array($value, [true, false, 0, 1, '0', '1'], true)) {
                    throw new Invalid('开关格式不正确');
                }
                return (bool)$value;
            case 'enum':
                if (!is_string($value) || !in_array($value, $spec[2], true)) {
                    throw new Invalid('选项不正确');
                }
                return $value;
            case 'color':
                $value = self::text($value, 16);
                if ($value !== '' && $value !== 'transparent' && !preg_match('/^#[a-f0-9]{6}$/iD', $value)) {
                    throw new Invalid('颜色格式不正确');
                }
                return $value;
            case 'url':
                $value = self::text($value, 2048);
                if ($value !== '' && (
                    !filter_var($value, FILTER_VALIDATE_URL)
                    || !in_array(strtolower((string)parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)
                    || parse_url($value, PHP_URL_USER) !== null
                    || parse_url($value, PHP_URL_PASS) !== null
                )) {
                    throw new Invalid('链接仅支持不含账号密码的HTTP或HTTPS地址');
                }
                return $value;
            case 'image':
                $id = self::num($value, 0, PHP_INT_MAX);
                if ($id === 0) {
                    return 0;
                }
                if (!isset($files[$id])) {
                    if ($strict) {
                        throw new Invalid('装修图片不存在或未上传');
                    }
                    return 0;
                }
                return $id;
        }
        throw new Invalid('字段类型不正确');
    }

    /**
     * @param mixed $value
     * @param int $min
     * @param int $max
     * @return int
     */
    private static function num(mixed $value, int $min, int $max): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/D', $value))) {
            throw new Invalid('数字字段格式不正确');
        }
        $number = (int)$value;
        if ($number < $min || $number > $max) {
            throw new Invalid('数值超出范围');
        }
        return $number;
    }

    /**
     * @param mixed $value
     * @param int $max
     * @return string
     */
    private static function text(mixed $value, int $max): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $max) {
            throw new Invalid('文字长度或格式不正确');
        }
        return trim($value);
    }
}
