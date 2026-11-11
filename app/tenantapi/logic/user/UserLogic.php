<?php
// +----------------------------------------------------------------------
// | likeadmin快速开发前后端分离管理后台（PHP版）
// +----------------------------------------------------------------------
// | 欢迎阅读学习系统程序代码，建议反馈是我们前进的动力
// | 开源版本可自由商用，可去除界面版权logo
// | gitee下载：https://gitee.com/likeshop_gitee/likeadmin
// | github下载：https://github.com/likeshop-github/likeadmin
// | 访问官网：https://www.likeadmin.cn
// | likeadmin团队 版权所有 拥有最终解释权
// +----------------------------------------------------------------------
// | author: likeadminTeam
// +----------------------------------------------------------------------
namespace app\tenantapi\logic\user;

use app\common\enum\user\AccountLogEnum;
use app\common\enum\user\UserTerminalEnum;
use app\common\enum\YesNoEnum;
use app\common\logic\AccountLogLogic;
use app\common\logic\BaseLogic;
use app\common\model\merchant\MerchantBrand;
use app\common\model\user\User;
use app\common\model\user\UserAccountLog;
use app\common\service\ConfigService;
use app\common\service\JsonService;
use app\common\service\membership\MembershipService;
use think\db\exception\PDOException;
use think\facade\Config;
use think\facade\Db;

/**
 * 用户逻辑层
 * Class TenantLogic
 * @package app\tenantapi\logic\user
 */
class UserLogic extends BaseLogic
{
    public static function add(array $params, int $tenantId): int
    {
        $account = $params['account'];
        if (User::withTrashed()->where('tenant_id', $tenantId)->where('account', $account)->value('id')) {
            JsonService::throw('账号已被使用');
        }

        return Db::transaction(function () use ($params, $tenantId, $account) {
            $sn = User::createUserSn();
            $nickname = trim($params['nickname'] ?? '');
            try {
                $user = User::create([
                    'tenant_id' => $tenantId,
                    'sn' => $sn,
                    'account' => $account,
                    'nickname' => $nickname === '' ? '用户' . $sn : $nickname,
                    'avatar' => ConfigService::get('default_image', 'user_avatar'),
                    'password' => create_password($params['password'], Config::get('project.unique_identification')),
                    'channel' => UserTerminalEnum::TENANT_ADMIN,
                    'is_new_user' => YesNoEnum::YES,
                ]);
            } catch (PDOException $e) {
                $errorInfo = $e->getData()['PDO Error Info'] ?? [];
                if ((int)($errorInfo['Driver Error Code'] ?? 0) === 1062) {
                    JsonService::throw('账号或用户编号已被使用，请核对账号后重试');
                }
                throw $e;
            }

            $userId = (int)$user->id;
            MembershipService::grantDefaultFreeMembership($tenantId, $userId);
            return $userId;
        });
    }

    /**
     * @notes 用户详情
     * @param int $userId
     * @param int|null $tenantId
     * @return array
     * @author 段誉
     * @date 2022/9/22 16:32
     */
    public static function detail(int $userId, ?int $tenantId = null): array
    {
        $field = [
            'id', 'sn', 'account', 'nickname', 'avatar', 'real_name',
            'sex', 'mobile', 'create_time', 'login_time', 'channel',
            'user_money', 'total_recharge_amount', 'is_disable'
        ];

        $query = User::where(['id' => $userId]);
        if (!empty($tenantId)) {
            $query->where('tenant_id', $tenantId);
        }

        $user = $query->field($field)
            ->findOrEmpty();
        if ($user->isEmpty()) {
            JsonService::throw('用户不存在！');
        }

        $user['channel'] = UserTerminalEnum::getTermInalDesc($user['channel']);
        $user->sexCode = $user->getData('sex');
        $detail = $user->toArray();
        $detail['user_money'] = self::formatPoints($detail['user_money'] ?? 0);
        $detail['total_recharge_amount'] = self::formatPoints($detail['total_recharge_amount'] ?? 0);
        $detail['total_used_amount'] = self::formatPoints(self::getUsedAmount($userId, $tenantId));
        $detail['brands'] = $tenantId ? MerchantBrand::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
        ])->field(['id', 'name', 'industry'])->order('id', 'asc')->select()->toArray() : [];
        return $detail;
    }


    /**
     * @notes 更新用户信息
     * @param array $params
     * @return User
     * @author 段誉
     * @date 2022/9/22 16:38
     */
    public static function setUserInfo(array $params, int $tenantId): User
    {
        $user = User::where([
            'id' => (int)$params['id'],
            'tenant_id' => $tenantId,
        ])->findOrEmpty();
        if ($user->isEmpty()) {
            JsonService::throw('用户不存在！');
        }
        $user->save([$params['field'] => $params['value']]);
        return $user;
    }


    /**
     * @notes 修改租户用户密码
     * @param array $params
     * @param int $tenantId
     * @return bool
     */
    public static function resetPassword(array $params, int $tenantId): bool
    {
        $user = User::where([
            'id' => (int)$params['id'],
            'tenant_id' => $tenantId,
        ])->findOrEmpty();
        if ($user->isEmpty()) {
            JsonService::throw('用户不存在！');
        }

        $user->password = create_password(
            $params['password'],
            Config::get('project.unique_identification')
        );
        $user->save();

        return true;
    }


    /**
     * @notes 调整用户点数
     * @param array $params
     * @return bool|string
     * @author 段誉
     * @date 2023/2/23 14:25
     */
    public static function adjustUserMoney(array $params, int $tenantId)
    {
        Db::startTrans();
        try {
            $user = User::where([
                'id' => (int)$params['user_id'],
                'tenant_id' => $tenantId,
            ])->lock(true)->findOrEmpty();
            if ($user->isEmpty()) {
                JsonService::throw('用户不存在！');
            }
            if (AccountLogEnum::INC == $params['action']) {
                //调整可用点数
                $user->user_money += $params['num'];
                $user->save();
                //记录日志
                AccountLogLogic::add(
                    $user->id,
                    AccountLogEnum::UM_INC_ADMIN,
                    AccountLogEnum::INC,
                    $params['num'],
                    '',
                    $params['remark'] ?? ''
                );
            } else {
                if ((float)$user->user_money < (float)$params['num']) {
                    JsonService::throw('用户可用点数不足');
                }
                $user->user_money -= $params['num'];
                $user->save();
                //记录日志
                AccountLogLogic::add(
                    $user->id,
                    AccountLogEnum::UM_DEC_ADMIN,
                    AccountLogEnum::DEC,
                    $params['num'],
                    '',
                    $params['remark'] ?? ''
                );
            }

            Db::commit();
            return true;

        } catch (\Exception $e) {
            Db::rollback();
            return $e->getMessage();
        }
    }


    /**
     * @notes 获取用户累计消费点数
     * @param int $userId
     * @param int|null $tenantId
     * @return float
     */
    private static function getUsedAmount(int $userId, ?int $tenantId = null): float
    {
        $query = UserAccountLog::where('user_id', $userId);
        if (!empty($tenantId)) {
            $query->where('tenant_id', $tenantId);
        }

        return (float)$query
            ->where('change_type', AccountLogEnum::UM_DEC_APP_CONSUME)
            ->where('action', AccountLogEnum::DEC)
            ->sum('change_amount');
    }


    /**
     * @notes 格式化点数
     * @param mixed $value
     * @return string
     */
    private static function formatPoints(mixed $value): string
    {
        $number = round((float)$value, 2);
        return number_format($number, floor($number) == $number ? 0 : 2, '.', '');
    }

}
