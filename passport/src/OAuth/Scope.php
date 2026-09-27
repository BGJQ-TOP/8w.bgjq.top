<?php

namespace W8\Passport\OAuth;

/**
 * 授权范围（scope）
 *
 * 第三方应用能拿到多少信息，完全由这里定义。新增 scope 只需在 MAP 里加一项。
 */
final class Scope
{
    /**
     * scope => 说明
     *
     * @var array<string,string>
     */
    const MAP = array(
        'basic'          => '通行证UID、用户名、站内角色',
        'email'          => '验证邮箱与邮箱验证状态（未绑定时不返回该字段）',
        'player'         => '游戏内玩家名、玩家ID、所属邦国ID',
        'country'        => '所属邦国ID（与 player 重复，供只关心邦国的应用使用）',
        'simpass'        => '简幻通ID与等级',
        'fanverify'      => 'FanVerify 账号ID（未绑定时不返回该字段）',
        'offline_access' => '刷新令牌到期后仍可继续换取新的刷新令牌（不申请则只能刷新一次）',
        'directory'      => '查询游戏内玩家与邦国公开信息（机器对机器）',
    );

    /**
     * client_credentials（机器对机器）允许申请的 scope
     *
     * 刻意收得很紧：拿不到任何用户身份数据，只能查权威公开数据。
     *
     * @var array<int,string>
     */
    const MACHINE_SCOPES = array('directory');

    /** 默认 scope */
    const DEFAULT_SCOPE = 'basic';

    /**
     * 全部合法 scope
     *
     * @return array<int,string>
     */
    public static function all()
    {
        return array_keys(self::MAP);
    }

    /**
     * 解析 scope 字符串
     *
     * @param string $scope 空格或逗号分隔
     * @return array<int,string>
     */
    public static function parse($scope)
    {
        $scope = trim((string) $scope);
        if ($scope === '') {
            return array(self::DEFAULT_SCOPE);
        }

        $parts = preg_split('/[\s,]+/', $scope);
        $result = array();
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '' && !in_array($part, $result, true)) {
                $result[] = $part;
            }
        }

        return $result === array() ? array(self::DEFAULT_SCOPE) : $result;
    }

    /**
     * @param array<int,string> $scopes
     * @return string
     */
    public static function toString(array $scopes)
    {
        return implode(' ', $scopes);
    }

    /**
     * @param array<int,string> $scopes
     * @return array<int,string> 非法项
     */
    public static function unknown(array $scopes)
    {
        $known = self::all();
        $unknown = array();
        foreach ($scopes as $scope) {
            if (!in_array($scope, $known, true)) {
                $unknown[] = $scope;
            }
        }
        return $unknown;
    }

    /**
     * 求交集，保证不会越权
     *
     * @param array<int,string> $requested
     * @param array<int,string> $allowed
     * @return array<int,string>
     */
    public static function intersect(array $requested, array $allowed)
    {
        $result = array();
        foreach ($requested as $scope) {
            if (in_array($scope, $allowed, true)) {
                $result[] = $scope;
            }
        }
        return $result;
    }

    /**
     * 是否包含指定 scope
     *
     * @param array<int,string> $granted
     */
    public static function has(array $granted, $scope)
    {
        return in_array($scope, $granted, true);
    }

    /**
     * @return array<string,string>
     */
    public static function describe()
    {
        return self::MAP;
    }
}
