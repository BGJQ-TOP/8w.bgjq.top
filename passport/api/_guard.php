<?php
/**
 * 通行证 API 共用访问守卫
 *
 * 数据类接口（玩家/邦国查询）有两种合法调用方：
 *   ① 第三方应用：Authorization: Bearer <access_token>，需具备对应 scope
 *   ② 站内页面：浏览器携带通行证会话 Cookie
 *
 * 两者都走这里，保证鉴权语义只有一处定义。
 */

use W8\Passport\Application;
use W8\Passport\Http\ApiException;
use W8\Passport\Http\Request;

if (!function_exists('w8_guard')) {
    /**
     * @param Request $request
     * @param Application $app
     * @param string $scope Bearer 调用所需 scope
     * @return array<string,mixed> ['client'=>array|null,'account'=>Account|null,'scopes'=>array]
     * @throws ApiException
     */
    function w8_guard(Request $request, Application $app, $scope = 'directory')
    {
        // ① Bearer 令牌（第三方应用）
        if ($request->bearerToken() !== '') {
            $context = $app->oauth()->authenticateBearer($request, $scope);
            $app->markApiContext(
                $context['client']['client_id'],
                $context['account'] !== null ? $context['account']->id() : null
            );
            return $context;
        }

        // ② 通行证会话（站内页面）
        $account = $app->authenticator()->current($request);
        if ($account !== null) {
            $app->markApiContext(null, $account->id());
            return array(
                'client'  => null,
                'account' => $account,
                'scopes'  => array('basic', 'email', 'player', 'country', 'simpass'),
            );
        }

        throw ApiException::unauthorized('请先登录通行证，或携带有效的 Bearer 访问令牌');
    }
}
