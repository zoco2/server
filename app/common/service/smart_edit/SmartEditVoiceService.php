<?php

namespace app\common\service\smart_edit;

use Exception;
use app\common\model\TenantConfig;

/**
 * 智能剪辑的配音音色目录。
 *
 * 与字体库同理：不读第三方应用表，清单写在代码里，租户可在后台追加。
 * key 是提交时用的 voice_key；provider_asset_id 是火山大模型的 speaker ID。
 *
 * 火山 2.0 音色必须配 seed-tts-2.0 资源 ID，否则会报音色不存在。
 * 内置清单覆盖豆包语音合成 2.0 已授权的中文 uranus 音色；租户仍可在后台追加。
 */
class SmartEditVoiceService
{
    private const TYPE = 'smart_edit';
    private const NAME = 'voices';

    /** 单个组最多同时选几个配音演员（与组合轮换上限一致） */
    public const MAX_VOICES = 50;

    /** 试听文本，与设计稿的短句长度接近 */
    private const PREVIEW_TEXT = '这是一段配音试听，用来确认音色效果。';

    /**
     * 火山门户头像 CDN 前缀。
     * 官方 ListSpeakers 的 Avatar 字段同域；多数音色可用「展示名.png」直接拼出。
     */
    private const AVATAR_CDN = 'https://lf3-static.bytednsdoc.com/obj/eden-cn/lm_hz_ihsph/ljhwZthlaukjlkulzlp/portal/bigtts/avatar/';

    /**
     * 展示名与 CDN 文件名不一致时的别名。
     * scene 字段本身就是分类（如「视频配音」「角色扮演」），不另建 Categories。
     *
     * @var array<string, string>
     */
    private const AVATAR_ALIASES = [
        '知性灿灿' => '灿灿',
    ];

    /**
     * 按展示名拼官方头像 URL；无可靠文件时返回空串，前端用首字兜底。
     */
    public static function avatarUrl(string $label): string
    {
        $name = trim($label);
        if ($name === '') {
            return '';
        }
        $file = self::AVATAR_ALIASES[$name] ?? $name;
        return self::AVATAR_CDN . rawurlencode($file) . '.png';
    }

    /**
     * 内置音色清单。
     *
     * 豆包语音合成 2.0 的音色 ID 统一形如 zh_xxx_xxx_uranus_bigtts，
     * 必须配 X-Api-Resource-Id: seed-tts-2.0，配 1.0 的 *_moon_bigtts 会报资源不匹配。
     * 这里放入官方文档中的中文 uranus 全量清单；已有 key（如 vv / cancan）保持不变，避免旧任务失效。
     * 若个别音色在当前账号未开通，合成时会由火山返回错误，可在租户后台删掉或改掉。
     * scene 即分类（视频配音 / 角色扮演 / 通用场景等）。
     *
     * @return array<string, array{label:string,provider_asset_id:string,gender:string,scene:string}>
     */
    public static function builtin(): array
    {
        return [
            'vv' => [
                'label' => 'Vivi',
                'provider_asset_id' => 'zh_female_vv_uranus_bigtts',
                'gender' => 'female',
                'scene' => '通用场景',
            ],
            'xiaohe' => [
                'label' => '小何',
                'provider_asset_id' => 'zh_female_xiaohe_uranus_bigtts',
                'gender' => 'female',
                'scene' => '通用场景',
            ],
            'm191' => [
                'label' => '云舟',
                'provider_asset_id' => 'zh_male_m191_uranus_bigtts',
                'gender' => 'male',
                'scene' => '通用场景',
            ],
            'taocheng' => [
                'label' => '小天',
                'provider_asset_id' => 'zh_male_taocheng_uranus_bigtts',
                'gender' => 'male',
                'scene' => '通用场景',
            ],
            'liufei' => [
                'label' => '刘飞',
                'provider_asset_id' => 'zh_male_liufei_uranus_bigtts',
                'gender' => 'male',
                'scene' => '通用场景',
            ],
            'sophie' => [
                'label' => '魅力苏菲',
                'provider_asset_id' => 'zh_female_sophie_uranus_bigtts',
                'gender' => 'female',
                'scene' => '通用场景',
            ],
            'qingxinnvsheng' => [
                'label' => '清新女声',
                'provider_asset_id' => 'zh_female_qingxinnvsheng_uranus_bigtts',
                'gender' => 'female',
                'scene' => '通用场景',
            ],
            'cancan' => [
                'label' => '知性灿灿',
                'provider_asset_id' => 'zh_female_cancan_uranus_bigtts',
                'gender' => 'female',
                'scene' => '角色扮演',
            ],
            'sajiaoxuemei' => [
                'label' => '撒娇学妹',
                'provider_asset_id' => 'zh_female_sajiaoxuemei_uranus_bigtts',
                'gender' => 'female',
                'scene' => '角色扮演',
            ],
            'tianmeixiaoyuan' => [
                'label' => '甜美小源',
                'provider_asset_id' => 'zh_female_tianmeixiaoyuan_uranus_bigtts',
                'gender' => 'female',
                'scene' => '通用场景',
            ],
            'tianmeitaozi' => [
                'label' => '甜美桃子',
                'provider_asset_id' => 'zh_female_tianmeitaozi_uranus_bigtts',
                'gender' => 'female',
                'scene' => '通用场景',
            ],
            'shuangkuaisisi' => [
                'label' => '爽快思思',
                'provider_asset_id' => 'zh_female_shuangkuaisisi_uranus_bigtts',
                'gender' => 'female',
                'scene' => '通用场景',
            ],
            'peiqi' => [
                'label' => '佩奇猪',
                'provider_asset_id' => 'zh_female_peiqi_uranus_bigtts',
                'gender' => 'female',
                'scene' => '视频配音',
            ],
            'linjianvhai' => [
                'label' => '邻家女孩',
                'provider_asset_id' => 'zh_female_linjianvhai_uranus_bigtts',
                'gender' => 'female',
                'scene' => '通用场景',
            ],
            'shaonianzixin' => [
                'label' => '少年梓辛',
                'provider_asset_id' => 'zh_male_shaonianzixin_uranus_bigtts',
                'gender' => 'male',
                'scene' => '通用场景',
            ],
            'sunwukong' => [
                'label' => '猴哥',
                'provider_asset_id' => 'zh_male_sunwukong_uranus_bigtts',
                'gender' => 'male',
                'scene' => '视频配音',
            ],
            'yingyujiaoxue' => [
                'label' => 'Tina老师',
                'provider_asset_id' => 'zh_female_yingyujiaoxue_uranus_bigtts',
                'gender' => 'female',
                'scene' => '教育场景',
            ],
            'kefunvsheng' => [
                'label' => '暖阳女声',
                'provider_asset_id' => 'zh_female_kefunvsheng_uranus_bigtts',
                'gender' => 'female',
                'scene' => '客服场景',
            ],
            'xiaoxue' => [
                'label' => '儿童绘本',
                'provider_asset_id' => 'zh_female_xiaoxue_uranus_bigtts',
                'gender' => 'female',
                'scene' => '有声阅读',
            ],
            'dayi' => [
                'label' => '大壹',
                'provider_asset_id' => 'zh_male_dayi_uranus_bigtts',
                'gender' => 'male',
                'scene' => '视频配音',
            ],
            'mizai' => [
                'label' => '黑猫侦探社咪仔',
                'provider_asset_id' => 'zh_female_mizai_uranus_bigtts',
                'gender' => 'female',
                'scene' => '视频配音',
            ],
            'jitangnv' => [
                'label' => '鸡汤女',
                'provider_asset_id' => 'zh_female_jitangnv_uranus_bigtts',
                'gender' => 'female',
                'scene' => '视频配音',
            ],
            'meilinvyou' => [
                'label' => '魅力女友',
                'provider_asset_id' => 'zh_female_meilinvyou_uranus_bigtts',
                'gender' => 'female',
                'scene' => '通用场景',
            ],
            'liuchangnv' => [
                'label' => '流畅女声',
                'provider_asset_id' => 'zh_female_liuchangnv_uranus_bigtts',
                'gender' => 'female',
                'scene' => '视频配音',
            ],
            'ruyayichen' => [
                'label' => '儒雅逸辰',
                'provider_asset_id' => 'zh_male_ruyayichen_uranus_bigtts',
                'gender' => 'male',
                'scene' => '视频配音',
            ],
            'wenroumama' => [
                'label' => '温柔妈妈',
                'provider_asset_id' => 'zh_female_wenroumama_uranus_bigtts',
                'gender' => 'female',
                'scene' => '通用场景',
            ],
            'jieshuoxiaoming' => [
                'label' => '解说小明',
                'provider_asset_id' => 'zh_male_jieshuoxiaoming_uranus_bigtts',
                'gender' => 'male',
                'scene' => '通用场景',
            ],
            'tvbnv' => [
                'label' => 'TVB女声',
                'provider_asset_id' => 'zh_female_tvbnv_uranus_bigtts',
                'gender' => 'female',
                'scene' => '通用场景',
            ],
            'yizhipiannan' => [
                'label' => '译制片男',
                'provider_asset_id' => 'zh_male_yizhipiannan_uranus_bigtts',
                'gender' => 'male',
                'scene' => '通用场景',
            ],
            'qiaopinv' => [
                'label' => '俏皮女声',
                'provider_asset_id' => 'zh_female_qiaopinv_uranus_bigtts',
                'gender' => 'female',
                'scene' => '通用场景',
            ],
            'zhishuaiyingzi' => [
                'label' => '直率英子',
                'provider_asset_id' => 'zh_female_zhishuaiyingzi_uranus_bigtts',
                'gender' => 'female',
                'scene' => '角色扮演',
            ],
            'linjiananhai' => [
                'label' => '邻家男孩',
                'provider_asset_id' => 'zh_male_linjiananhai_uranus_bigtts',
                'gender' => 'male',
                'scene' => '通用场景',
            ],
            'silang' => [
                'label' => '四郎',
                'provider_asset_id' => 'zh_male_silang_uranus_bigtts',
                'gender' => 'male',
                'scene' => '角色扮演',
            ],
            'ruyaqingnian' => [
                'label' => '儒雅青年',
                'provider_asset_id' => 'zh_male_ruyaqingnian_uranus_bigtts',
                'gender' => 'male',
                'scene' => '通用场景',
            ],
            'qingcang' => [
                'label' => '擎苍',
                'provider_asset_id' => 'zh_male_qingcang_uranus_bigtts',
                'gender' => 'male',
                'scene' => '角色扮演',
            ],
            'xionger' => [
                'label' => '熊二',
                'provider_asset_id' => 'zh_male_xionger_uranus_bigtts',
                'gender' => 'male',
                'scene' => '角色扮演',
            ],
            'yingtaowanzi' => [
                'label' => '樱桃丸子',
                'provider_asset_id' => 'zh_female_yingtaowanzi_uranus_bigtts',
                'gender' => 'female',
                'scene' => '角色扮演',
            ],
            'wennuanahu' => [
                'label' => '温暖阿虎',
                'provider_asset_id' => 'zh_male_wennuanahu_uranus_bigtts',
                'gender' => 'male',
                'scene' => '通用场景',
            ],
            'naiqimengwa' => [
                'label' => '奶气萌娃',
                'provider_asset_id' => 'zh_male_naiqimengwa_uranus_bigtts',
                'gender' => 'male',
                'scene' => '通用场景',
            ],
            'popo' => [
                'label' => '婆婆',
                'provider_asset_id' => 'zh_female_popo_uranus_bigtts',
                'gender' => 'female',
                'scene' => '通用场景',
            ],
            'gaolengyujie' => [
                'label' => '高冷御姐',
                'provider_asset_id' => 'zh_female_gaolengyujie_uranus_bigtts',
                'gender' => 'female',
                'scene' => '通用场景',
            ],
            'aojiaobazong' => [
                'label' => '傲娇霸总',
                'provider_asset_id' => 'zh_male_aojiaobazong_uranus_bigtts',
                'gender' => 'male',
                'scene' => '通用场景',
            ],
            'lanyinmianbao' => [
                'label' => '懒音绵宝',
                'provider_asset_id' => 'zh_male_lanyinmianbao_uranus_bigtts',
                'gender' => 'male',
                'scene' => '角色扮演',
            ],
            'fanjuanqingnian' => [
                'label' => '反卷青年',
                'provider_asset_id' => 'zh_male_fanjuanqingnian_uranus_bigtts',
                'gender' => 'male',
                'scene' => '通用场景',
            ],
            'wenroushunv' => [
                'label' => '温柔淑女',
                'provider_asset_id' => 'zh_female_wenroushunv_uranus_bigtts',
                'gender' => 'female',
                'scene' => '通用场景',
            ],
            'gufengshaoyu' => [
                'label' => '古风少御',
                'provider_asset_id' => 'zh_female_gufengshaoyu_uranus_bigtts',
                'gender' => 'female',
                'scene' => '角色扮演',
            ],
            'huolixiaoge' => [
                'label' => '活力小哥',
                'provider_asset_id' => 'zh_male_huolixiaoge_uranus_bigtts',
                'gender' => 'male',
                'scene' => '通用场景',
            ],
            'baqiqingshu' => [
                'label' => '霸气青叔',
                'provider_asset_id' => 'zh_male_baqiqingshu_uranus_bigtts',
                'gender' => 'male',
                'scene' => '有声阅读',
            ],
            'xuanyijieshuo' => [
                'label' => '悬疑解说',
                'provider_asset_id' => 'zh_male_xuanyijieshuo_uranus_bigtts',
                'gender' => 'male',
                'scene' => '有声阅读',
            ],
            'mengyatou' => [
                'label' => '萌丫头',
                'provider_asset_id' => 'zh_female_mengyatou_uranus_bigtts',
                'gender' => 'female',
                'scene' => '通用场景',
            ],
            'tiexinnvsheng' => [
                'label' => '贴心女声',
                'provider_asset_id' => 'zh_female_tiexinnvsheng_uranus_bigtts',
                'gender' => 'female',
                'scene' => '通用场景',
            ],
            'jitangmei' => [
                'label' => '鸡汤妹妹',
                'provider_asset_id' => 'zh_female_jitangmei_uranus_bigtts',
                'gender' => 'female',
                'scene' => '通用场景',
            ],
            'cixingjieshuonan' => [
                'label' => '磁性解说男声',
                'provider_asset_id' => 'zh_male_cixingjieshuonan_uranus_bigtts',
                'gender' => 'male',
                'scene' => '通用场景',
            ],
            'liangsangmengzai' => [
                'label' => '亮嗓萌仔',
                'provider_asset_id' => 'zh_male_liangsangmengzai_uranus_bigtts',
                'gender' => 'male',
                'scene' => '通用场景',
            ],
            'kailangjiejie' => [
                'label' => '开朗姐姐',
                'provider_asset_id' => 'zh_female_kailangjiejie_uranus_bigtts',
                'gender' => 'female',
                'scene' => '通用场景',
            ],
            'gaolengchenwen' => [
                'label' => '高冷沉稳',
                'provider_asset_id' => 'zh_male_gaolengchenwen_uranus_bigtts',
                'gender' => 'male',
                'scene' => '通用场景',
            ],
            'shenyeboke' => [
                'label' => '深夜播客',
                'provider_asset_id' => 'zh_male_shenyeboke_uranus_bigtts',
                'gender' => 'male',
                'scene' => '通用场景',
            ],
            'lubanqihao' => [
                'label' => '鲁班七号',
                'provider_asset_id' => 'zh_male_lubanqihao_uranus_bigtts',
                'gender' => 'male',
                'scene' => '角色扮演',
            ],
            'jiaochuannv' => [
                'label' => '娇喘女声',
                'provider_asset_id' => 'zh_female_jiaochuannv_uranus_bigtts',
                'gender' => 'female',
                'scene' => '通用场景',
            ],
            'linxiao' => [
                'label' => '林潇',
                'provider_asset_id' => 'zh_female_linxiao_uranus_bigtts',
                'gender' => 'female',
                'scene' => '角色扮演',
            ],
            'lingling' => [
                'label' => '玲玲姐姐',
                'provider_asset_id' => 'zh_female_lingling_uranus_bigtts',
                'gender' => 'female',
                'scene' => '角色扮演',
            ],
            'chunribu' => [
                'label' => '春日部姐姐',
                'provider_asset_id' => 'zh_female_chunribu_uranus_bigtts',
                'gender' => 'female',
                'scene' => '角色扮演',
            ],
            'tangseng' => [
                'label' => '唐僧',
                'provider_asset_id' => 'zh_male_tangseng_uranus_bigtts',
                'gender' => 'male',
                'scene' => '角色扮演',
            ],
            'zhuangzhou' => [
                'label' => '庄周',
                'provider_asset_id' => 'zh_male_zhuangzhou_uranus_bigtts',
                'gender' => 'male',
                'scene' => '角色扮演',
            ],
            'kailangdidi' => [
                'label' => '开朗弟弟',
                'provider_asset_id' => 'zh_male_kailangdidi_uranus_bigtts',
                'gender' => 'male',
                'scene' => '通用场景',
            ],
            'zhubajie' => [
                'label' => '猪八戒',
                'provider_asset_id' => 'zh_male_zhubajie_uranus_bigtts',
                'gender' => 'male',
                'scene' => '角色扮演',
            ],
            'ganmaodianyin' => [
                'label' => '感冒电音姐姐',
                'provider_asset_id' => 'zh_female_ganmaodianyin_uranus_bigtts',
                'gender' => 'female',
                'scene' => '角色扮演',
            ],
            'chanmeinv' => [
                'label' => '谄媚女声',
                'provider_asset_id' => 'zh_female_chanmeinv_uranus_bigtts',
                'gender' => 'female',
                'scene' => '通用场景',
            ],
            'nvleishen' => [
                'label' => '女雷神',
                'provider_asset_id' => 'zh_female_nvleishen_uranus_bigtts',
                'gender' => 'female',
                'scene' => '角色扮演',
            ],
            'qinqienv' => [
                'label' => '亲切女声',
                'provider_asset_id' => 'zh_female_qinqienv_uranus_bigtts',
                'gender' => 'female',
                'scene' => '通用场景',
            ],
            'kuailexiaodong' => [
                'label' => '快乐小东',
                'provider_asset_id' => 'zh_male_kuailexiaodong_uranus_bigtts',
                'gender' => 'male',
                'scene' => '通用场景',
            ],
            'kailangxuezhang' => [
                'label' => '开朗学长',
                'provider_asset_id' => 'zh_male_kailangxuezhang_uranus_bigtts',
                'gender' => 'male',
                'scene' => '通用场景',
            ],
            'youyoujunzi' => [
                'label' => '悠悠君子',
                'provider_asset_id' => 'zh_male_youyoujunzi_uranus_bigtts',
                'gender' => 'male',
                'scene' => '通用场景',
            ],
            'wenjingmaomao' => [
                'label' => '文静毛毛',
                'provider_asset_id' => 'zh_female_wenjingmaomao_uranus_bigtts',
                'gender' => 'female',
                'scene' => '通用场景',
            ],
            'zhixingnv' => [
                'label' => '知性女声',
                'provider_asset_id' => 'zh_female_zhixingnv_uranus_bigtts',
                'gender' => 'female',
                'scene' => '通用场景',
            ],
            'qingshuangnanda' => [
                'label' => '清爽男大',
                'provider_asset_id' => 'zh_male_qingshuangnanda_uranus_bigtts',
                'gender' => 'male',
                'scene' => '通用场景',
            ],
            'yuanbo' => [
                'label' => '渊博小叔',
                'provider_asset_id' => 'zh_male_yuanboxiaoshu_uranus_bigtts',
                'gender' => 'male',
                'scene' => '通用场景',
            ],
            'yangguangqingnian' => [
                'label' => '阳光青年',
                'provider_asset_id' => 'zh_male_yangguangqingnian_uranus_bigtts',
                'gender' => 'male',
                'scene' => '通用场景',
            ],
            'qingchezizi' => [
                'label' => '清澈梓梓',
                'provider_asset_id' => 'zh_female_qingchezizi_uranus_bigtts',
                'gender' => 'female',
                'scene' => '通用场景',
            ],
            'tianmeiyueyue' => [
                'label' => '甜美悦悦',
                'provider_asset_id' => 'zh_female_tianmeiyueyue_uranus_bigtts',
                'gender' => 'female',
                'scene' => '通用场景',
            ],
            'xinlingjitang' => [
                'label' => '心灵鸡汤',
                'provider_asset_id' => 'zh_female_xinlingjitang_uranus_bigtts',
                'gender' => 'female',
                'scene' => '通用场景',
            ],
            'wenrouxiaoge' => [
                'label' => '温柔小哥',
                'provider_asset_id' => 'zh_male_wenrouxiaoge_uranus_bigtts',
                'gender' => 'male',
                'scene' => '通用场景',
            ],
            'roumeinvyou' => [
                'label' => '柔美女友',
                'provider_asset_id' => 'zh_female_roumeinvyou_uranus_bigtts',
                'gender' => 'female',
                'scene' => '通用场景',
            ],
            'dongfanghaoran' => [
                'label' => '东方浩然',
                'provider_asset_id' => 'zh_male_dongfanghaoran_uranus_bigtts',
                'gender' => 'male',
                'scene' => '通用场景',
            ],
            'wenrouxiaoya' => [
                'label' => '温柔小雅',
                'provider_asset_id' => 'zh_female_wenrouxiaoya_uranus_bigtts',
                'gender' => 'female',
                'scene' => '通用场景',
            ],
            'tiancaitongsheng' => [
                'label' => '天才童声',
                'provider_asset_id' => 'zh_male_tiancaitongsheng_uranus_bigtts',
                'gender' => 'male',
                'scene' => '通用场景',
            ],
            'wuzetian' => [
                'label' => '武则天',
                'provider_asset_id' => 'zh_female_wuzetian_uranus_bigtts',
                'gender' => 'female',
                'scene' => '角色扮演',
            ],
            'gujie' => [
                'label' => '顾姐',
                'provider_asset_id' => 'zh_female_gujie_uranus_bigtts',
                'gender' => 'female',
                'scene' => '角色扮演',
            ],
            'guanggaojieshuo' => [
                'label' => '广告解说',
                'provider_asset_id' => 'zh_male_guanggaojieshuo_uranus_bigtts',
                'gender' => 'male',
                'scene' => '通用场景',
            ],
            'shaoergushi' => [
                'label' => '少儿故事',
                'provider_asset_id' => 'zh_female_shaoergushi_uranus_bigtts',
                'gender' => 'female',
                'scene' => '有声阅读',
            ],

        ];
    }

    /**
     * 租户自定义音色（后台追加），与内置清单合并。
     *
     * @return array<int, array{key:string,label:string,provider_asset_id:string,gender:string,scene:string,custom:bool}>
     */
    public static function catalog(int $tenantId = 0): array
    {
        $rows = [];
        foreach (self::builtin() as $key => $item) {
            $rows[$key] = $item + ['key' => $key, 'custom' => false];
        }
        if ($tenantId > 0) {
            foreach (self::custom($tenantId) as $item) {
                $key = (string)$item['key'];
                if ($key === '') {
                    continue;
                }
                // 自定义覆盖内置：同一个 key 以租户配置为准
                $rows[$key] = $item + ['custom' => true];
            }
        }
        return array_values($rows);
    }

    /**
     * 前端下拉用的音色列表。
     *
     * scene 即分类，供前端分组/筛选；avatar 为官方门户头像 URL。
     *
     * @param int  $tenantId      租户 ID；0 表示只看内置清单
     * @param bool $withAssetId   是否返回 provider_asset_id。租户后台的音色管理要编辑它，
     *                            用户端下拉只需要显示名，不必暴露底层 ID。
     * @return array<int, array{key:string,label:string,gender:string,scene:string,avatar:string,custom:bool}>
     */
    public static function options(int $tenantId = 0, bool $withAssetId = false): array
    {
        $result = [];
        foreach (self::catalog($tenantId) as $item) {
            $label = (string)$item['label'];
            $row = [
                'key' => (string)$item['key'],
                'label' => $label,
                'gender' => (string)$item['gender'],
                // scene 就是分类（视频配音 / 角色扮演 / 通用场景 …）
                'scene' => (string)$item['scene'],
                'avatar' => self::avatarUrl($label),
                'custom' => (bool)$item['custom'],
            ];
            if ($withAssetId) {
                $row['provider_asset_id'] = (string)$item['provider_asset_id'];
            }
            $result[] = $row;
        }
        return $result;
    }

    /**
     * 取单个音色；key 不存在时抛错，避免提交了拿不到 speaker。
     *
     * @throws Exception
     */
    public static function get(int $tenantId, string $key): array
    {
        foreach (self::catalog($tenantId) as $item) {
            if ((string)$item['key'] === $key) {
                return $item;
            }
        }
        throw new Exception('不支持的配音音色：' . $key);
    }

    /**
     * 音色 key 白名单校验，返回去重后的 key 列表。
     *
     * @param mixed $raw 前端传入的 key 数组
     * @return array<int, string>
     * @throws Exception
     */
    public static function normalizeKeys(int $tenantId, $raw): array
    {
        if (!is_array($raw) || $raw === []) {
            return [];
        }
        $keys = [];
        foreach ($raw as $value) {
            $key = trim((string)$value);
            if ($key === '') {
                continue;
            }
            // 逐项校验：非法 key 直接报错，不要静默丢掉导致口播少了音色
            self::get($tenantId, $key);
            if (!in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }
        if (count($keys) > self::MAX_VOICES) {
            throw new Exception('最多选择 ' . self::MAX_VOICES . ' 个配音演员');
        }
        return $keys;
    }

    /** 试听文本 */
    public static function previewText(): string
    {
        return self::PREVIEW_TEXT;
    }

    /**
     * 租户自定义音色清单。
     *
     * @return array<int, array{key:string,label:string,provider_asset_id:string,gender:string,scene:string}>
     */
    public static function custom(int $tenantId): array
    {
        $raw = TenantConfig::where([
            'tenant_id' => $tenantId,
            'type' => self::TYPE,
            'name' => self::NAME,
        ])->value('value');

        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $rows = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = trim((string)($item['key'] ?? ''));
            $assetId = trim((string)($item['provider_asset_id'] ?? ''));
            if ($key === '' || $assetId === '') {
                continue;
            }
            $rows[] = [
                'key' => $key,
                'label' => trim((string)($item['label'] ?? $key)),
                'provider_asset_id' => $assetId,
                'gender' => trim((string)($item['gender'] ?? '')),
                'scene' => trim((string)($item['scene'] ?? '自定义')),
            ];
        }
        return $rows;
    }

    /**
     * 保存租户自定义音色清单。
     *
     * @param array $rows [{key,label,provider_asset_id,gender,scene}, ...]
     */
    public static function saveCustom(int $tenantId, array $rows): void
    {
        $clean = [];
        foreach ($rows as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = trim((string)($item['key'] ?? ''));
            $assetId = trim((string)($item['provider_asset_id'] ?? ''));
            if ($key === '' || $assetId === '') {
                continue;
            }
            $clean[] = [
                'key' => $key,
                'label' => trim((string)($item['label'] ?? $key)),
                'provider_asset_id' => $assetId,
                'gender' => trim((string)($item['gender'] ?? '')),
                'scene' => trim((string)($item['scene'] ?? '自定义')),
            ];
        }

        $payload = json_encode($clean, JSON_UNESCAPED_UNICODE);
        $row = TenantConfig::where([
            'tenant_id' => $tenantId,
            'type' => self::TYPE,
            'name' => self::NAME,
        ])->findOrEmpty();

        if ($row->isEmpty()) {
            TenantConfig::create([
                'tenant_id' => $tenantId,
                'type' => self::TYPE,
                'name' => self::NAME,
                'value' => $payload,
            ]);
            return;
        }
        $row->save(['value' => $payload]);
    }
}
