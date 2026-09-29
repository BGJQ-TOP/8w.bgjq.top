<?php
/**
 * 第三方应用管理（API 分发）
 *
 *   GET    /passport/api/oauth/clients            列出全部应用
 *   POST   /passport/api/oauth/clients            创建应用 → 返回 client_id / client_secret（仅此一次）
 *   DELETE /passport/api/oauth/clients?id=<id>    停用应用并吊销其全部令牌
 *
 * 需要具备管理员角色的通行证登录态（默认 secretary_general，可用
 * PASSPORT_ADMIN_ROLES 配置，逗号分隔）。
 *
 * 创建请求体：
 * {
 *   "name": "我的应用",
 *   "description": "……",
 *   "homepage_url": "https://example.com",
 *   "redirect_uris": "https://example.com/callback",   // 多个用换行分隔
 *   "allowed_scopes": "basic player country",
 *   "is_confidential": true,
 *   "rate_limit": 600
 * }
 */

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../_guard.php';

use W8\Passport\Application;
use W8\Passport\Http\ApiException;
use W8\Passport\Http\Endpoint;
use W8\Passport\Http\Request;
use W8\Passport\Http\Response;
use W8\Passport\OAuth\Scope;

Endpoint::run(function (Request $request, Application $app) {
    $account = $app->authenticator()->requireCurrent($request);
    $app->markApiContext(null, $account->id());

    w8_require_admin($app, $account);

    $clients = $app->oauthClients();

    if ($request->method() === 'POST') {
        return createClient($clients, $request, $account);
    }

    if ($request->method() === 'DELETE') {
        $id = $request->int('id', (int) $request->query('id', 0));
        if ($id <= 0) {
            throw ApiException::validation('缺少参数 id');
        }

        $client = $clients->find($id);
        if ($client === null) {
            throw ApiException::notFound('应用不存在');
        }

        $app->db()->execute('UPDATE `passport_oauth_clients` SET `status` = 0 WHERE `id` = ?', array($id));
        $app->db()->execute(
            'UPDATE `passport_oauth_tokens` SET `revoked_at` = NOW() WHERE `client_id` = ? AND `revoked_at` IS NULL',
            array($client['client_id'])
        );
        $app->db()->execute(
            'UPDATE `passport_oauth_codes` SET `consumed_at` = NOW() WHERE `client_id` = ? AND `consumed_at` IS NULL',
            array($client['client_id'])
        );

        return Response::ok(array('disabled' => true, 'client_id' => $client['client_id']));
    }

    $rows = $app->db()->select(
        'SELECT * FROM `passport_oauth_clients` ORDER BY `id` DESC'
    );

    $list = array();
    foreach ($rows as $row) {
        $item = $clients->toPublicArray($row);
        $item['id'] = (int) $row['id'];
        $item['status'] = (int) $row['status'];
        $item['rate_limit'] = (int) $row['rate_limit'];
        $item['last_used_at'] = $row['last_used_at'];
        $item['created_at'] = $row['created_at'];
        $list[] = $item;
    }

    return Response::ok(array(
        'clients'     => $list,
        'all_scopes'  => Scope::describe(),
    ));
});

/**
 * @param \W8\Passport\OAuth\ClientRepository $clients
 */
function createClient($clients, Request $request, $account)
{
    $name = $request->string('name');
    if ($name === '' || mb_strlen($name) > 64) {
        throw ApiException::validation('应用名称必填，且不超过 64 个字符', array('field' => 'name'));
    }

    $redirectUris = normalizeRedirectUris($request->input('redirect_uris', ''));

    $isConfidential = $request->bool('is_confidential', true);
    if ($isConfidential && $redirectUris === array()) {
        throw ApiException::validation(
            '机密客户端必须登记至少一个回调地址',
            array('field' => 'redirect_uris')
        );
    }

    $scopes = Scope::parse($request->string('allowed_scopes', Scope::DEFAULT_SCOPE));
    $unknown = Scope::unknown($scopes);
    if ($unknown !== array()) {
        throw ApiException::validation('不支持的 scope：' . implode(' ', $unknown), array('field' => 'allowed_scopes'));
    }

    $rateLimit = $request->int('rate_limit', 600);
    if ($rateLimit < 0 || $rateLimit > 100000) {
        throw ApiException::validation('rate_limit 取值不合理', array('field' => 'rate_limit'));
    }

    $created = $clients->create(array(
        'name'            => $name,
        'description'     => $request->string('description'),
        'homepage_url'    => $request->string('homepage_url'),
        'logo_url'        => $request->string('logo_url'),
        'redirect_uris'   => implode("\n", $redirectUris),
        'allowed_scopes'  => Scope::toString($scopes),
        'is_confidential' => $isConfidential ? 1 : 0,
        'rate_limit'      => $rateLimit,
        'owner_account_id' => $account->id(),
    ));

    return Response::ok(array(
        'id'            => $created['id'],
        'client_id'     => $created['client_id'],
        // 密钥只在创建时返回这一次，之后库里只有哈希
        'client_secret' => $isConfidential ? $created['client_secret'] : null,
        'warning'       => '请立即保存 client_secret，关闭本页后无法再次查看。',
    ), array('Cache-Control' => 'no-store'));
}

/**
 * @param mixed $raw
 * @return array<int,string>
 */
function normalizeRedirectUris($raw)
{
    if (is_array($raw)) {
        $raw = implode("\n", $raw);
    }

    $uris = array();
    foreach (preg_split('/[\r\n,]+/', (string) $raw) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (filter_var($line, FILTER_VALIDATE_URL) === false) {
            throw ApiException::validation('回调地址不是合法 URL：' . $line, array('field' => 'redirect_uris'));
        }
        if (!in_array($line, $uris, true)) {
            $uris[] = $line;
        }
    }

    return $uris;
}
