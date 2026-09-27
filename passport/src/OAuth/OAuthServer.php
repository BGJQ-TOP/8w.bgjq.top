<?php

namespace W8\Passport\OAuth;

use W8\Passport\Http\ApiException;
use W8\Passport\Http\Request;
use W8\Passport\Identity\Account;
use W8\Passport\Identity\AccountRepository;
use W8\Passport\Support\Config;
use W8\Passport\Support\Logger;

/**
 * OAuth 2.0 授权服务器
 *
 * 支持：
 *   · authorization_code + PKCE（公开客户端必须带 PKCE）
 *   · refresh_token（轮换式，旧令牌立即作废）
 *   · client_credentials（机器对机器，只能访问 directory 类公开数据）
 *
 * 对应 RFC 6749 / 7636 / 7009 / 7662。
 */
final class OAuthServer
{
    /** @var ClientRepository */
    private $clients;

    /** @var AuthorizationCodeRepository */
    private $codes;

    /** @var TokenRepository */
    private $tokens;

    /** @var AccountRepository */
    private $accounts;

    /** @var Config */
    private $config;

    /** @var Logger */
    private $logger;

    public function __construct(
        ClientRepository $clients,
        AuthorizationCodeRepository $codes,
        TokenRepository $tokens,
        AccountRepository $accounts,
        Config $config,
        Logger $logger
    ) {
        $this->clients = $clients;
        $this->codes = $codes;
        $this->tokens = $tokens;
        $this->accounts = $accounts;
        $this->config = $config;
        $this->logger = $logger;
    }

    public function clients()
    {
        return $this->clients;
    }

    public function tokens()
    {
        return $this->tokens;
    }

    // ========================================================================
    //  授权端点
    // ========================================================================

    /**
     * 解析授权请求的上下文（client 与 redirect_uri）
     *
     * 这两项校验失败时**不能重定向**（可能是开放重定向攻击），只能展示错误页。
     *
     * @return array<string,mixed>
     * @throws ApiException
     */
    public function resolveAuthorizeContext(Request $request)
    {
        $clientId = $request->string('client_id');
        if ($clientId === '') {
            throw ApiException::oauth('invalid_request', '缺少 client_id', 400);
        }

        $client = $this->clients->findByClientId($clientId);
        if ($client === null) {
            throw ApiException::oauth('invalid_client', '未知的 client_id', 401);
        }
        if (!$this->clients->isEnabled($client)) {
            throw ApiException::oauth('unauthorized_client', '该应用已被停用', 403);
        }

        $redirectUri = $request->string('redirect_uri');
        if ($redirectUri === '') {
            $redirectUri = $this->clients->defaultRedirectUri($client);
        }
        if (!$this->clients->isRedirectAllowed($client, $redirectUri)) {
            throw ApiException::oauth('invalid_request', 'redirect_uri 不在该应用登记的回调地址白名单内', 400);
        }

        return array('client' => $client, 'redirect_uri' => $redirectUri);
    }

    /**
     * 校验授权参数
     *
     * 失败时返回 error 而不是抛异常 —— 这些错误允许带 error 参数重定向回应用。
     *
     * @param Request $request
     * @param array<string,mixed> $client
     * @return array<string,mixed>
     */
    public function validateAuthorizeParams(Request $request, array $client)
    {
        $responseType = $request->string('response_type');
        if ($responseType !== 'code') {
            return $this->authorizeError('unsupported_response_type', '仅支持 response_type=code');
        }

        $requested = Scope::parse($request->string('scope', Scope::DEFAULT_SCOPE));

        $unknown = Scope::unknown($requested);
        if ($unknown !== array()) {
            return $this->authorizeError('invalid_scope', '不支持的 scope：' . implode(' ', $unknown));
        }

        $grantable = Scope::intersect($requested, $this->clients->allowedScopes($client));
        if ($grantable === array()) {
            return $this->authorizeError('invalid_scope', '该应用未被授权申请任何请求的 scope');
        }

        $isPublic = (int) $client['is_confidential'] !== 1;
        $codeChallenge = $request->string('code_challenge');
        $codeChallengeMethod = strtoupper($request->string('code_challenge_method', 'S256'));

        if ($isPublic && $codeChallenge === '') {
            return $this->authorizeError('invalid_request', '公开客户端必须使用 PKCE（缺少 code_challenge）');
        }

        if ($codeChallenge !== '' && !in_array($codeChallengeMethod, array('S256', 'plain'), true)) {
            return $this->authorizeError('invalid_request', '不支持的 code_challenge_method');
        }

        return array(
            'ok'                    => true,
            'client'                => $client,
            'scopes'                => $grantable,
            'state'                 => $request->string('state'),
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => $codeChallenge === '' ? null : $codeChallengeMethod,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function authorizeError($error, $description)
    {
        return array(
            'ok'                => false,
            'error'             => $error,
            'error_description' => $description,
        );
    }

    /**
     * 用户同意授权 → 签发授权码 → 生成回调地址
     *
     * @param array<string,mixed> $params validateAuthorizeParams 的结果
     * @param Account $account
     * @param string $redirectUri
     * @return string 完整回调 URL
     */
    public function grantAuthorization(array $params, Account $account, $redirectUri)
    {
        $client = $params['client'];

        $code = $this->codes->issue(array(
            'client_id'             => $client['client_id'],
            'account_id'            => $account->id(),
            'redirect_uri'          => $redirectUri,
            'scopes'                => Scope::toString($params['scopes']),
            'code_challenge'        => $params['code_challenge'],
            'code_challenge_method' => $params['code_challenge_method'],
        ));

        $this->logger->info('oauth.authorized', array(
            'client_id' => $client['client_id'],
            'account_id' => $account->id(),
            'scopes' => Scope::toString($params['scopes']),
        ));

        return $this->buildRedirect($redirectUri, array(
            'code'  => $code,
            'state' => $params['state'],
        ));
    }

    /**
     * 用户拒绝授权 → 回调带 error=access_denied
     *
     * @return string
     */
    public function denyAuthorization($redirectUri, $state, $error = 'access_denied', $description = '用户拒绝了本次授权')
    {
        return $this->buildRedirect($redirectUri, array(
            'error'             => $error,
            'error_description' => $description,
            'state'             => $state,
        ));
    }

    /**
     * 参数校验失败时带回调地址
     *
     * @return string
     */
    public function errorRedirect($redirectUri, $error, $description, $state)
    {
        return $this->buildRedirect($redirectUri, array(
            'error'             => $error,
            'error_description' => $description,
            'state'             => $state,
        ));
    }

    /**
     * @param array<string,string> $params
     * @return string
     */
    private function buildRedirect($redirectUri, array $params)
    {
        $filtered = array();
        foreach ($params as $key => $value) {
            if ($value !== null && $value !== '') {
                $filtered[$key] = $value;
            }
        }

        $separator = strpos($redirectUri, '?') === false ? '?' : '&';
        return $redirectUri . $separator . http_build_query($filtered);
    }

    // ========================================================================
    //  令牌端点
    // ========================================================================

    /**
     * 处理 /oauth/token
     *
     * @return array<string,mixed> token 响应体
     * @throws ApiException
     */
    public function issueToken(Request $request)
    {
        $grantType = $request->string('grant_type');
        $allowPublic = $grantType === 'authorization_code' || $grantType === 'refresh_token';

        // 令牌端点是 RFC 6749 协议端点，错误必须走 OAuth 错误格式
        $client = $this->authenticateClient($request, $allowPublic, true);

        switch ($grantType) {
            case 'authorization_code':
                return $this->handleAuthorizationCode($request, $client);
            case 'refresh_token':
                return $this->handleRefreshToken($request, $client);
            case 'client_credentials':
                return $this->handleClientCredentials($request, $client);
            default:
                throw ApiException::oauth('unsupported_grant_type', '不支持的 grant_type：' . $grantType);
        }
    }

    /**
     * 校验客户端身份
     *
     * @param Request $request
     * @param bool $allowPublic 公开客户端（PKCE）是否允许不带密钥
     * @param bool $oauthStyle 错误是否按 RFC 6749 的 {"error":...} 格式返回
     * @return array<string,mixed>
     * @throws ApiException
     */
    public function authenticateClient(Request $request, $allowPublic = false, $oauthStyle = false)
    {
        $clientId = $request->string('client_id');
        $clientSecret = $request->string('client_secret');

        $basic = $request->basicAuth();
        if ($basic !== null) {
            $clientId = $basic[0];
            $clientSecret = $basic[1];
        }

        if ($clientId === '') {
            throw ApiException::oauth('invalid_client', '缺少 client_id', 401);
        }

        $client = $this->clients->findByClientId($clientId);
        if ($client === null || !$this->clients->isEnabled($client)) {
            throw ApiException::oauth('invalid_client', '客户端认证失败', 401);
        }

        $isPublic = (int) $client['is_confidential'] !== 1;

        if (!$isPublic && !$this->clients->verifySecret($client, $clientSecret)) {
            $this->logger->warning('oauth.invalid_secret', array('client_id' => $clientId));
            throw ApiException::oauth('invalid_client', '客户端认证失败', 401);
        }

        if ($isPublic && !$allowPublic) {
            throw ApiException::oauth('unauthorized_client', '公开客户端不允许使用该 grant_type', 401);
        }

        $this->assertRateLimit($client, $oauthStyle);

        return $client;
    }

    /**
     * @return array<string,mixed>
     */
    private function handleAuthorizationCode(Request $request, array $client)
    {
        $code = $request->string('code');
        if ($code === '') {
            throw ApiException::oauth('invalid_request', '缺少 code');
        }

        $row = $this->codes->consume($code);
        if ($row === null) {
            throw ApiException::oauth('invalid_grant', '授权码无效、已过期或已被使用');
        }

        if (!hash_equals((string) $row['client_id'], (string) $client['client_id'])) {
            throw ApiException::oauth('invalid_grant', '授权码不属于该客户端');
        }

        // redirect_uri 必须与授权时一致
        $redirectUri = $request->string('redirect_uri');
        if ($redirectUri !== '' && !hash_equals((string) $row['redirect_uri'], $redirectUri)) {
            throw ApiException::oauth('invalid_grant', 'redirect_uri 与授权请求不一致');
        }

        $this->assertPkce($request, $row);

        $scopes = Scope::parse($row['scopes']);
        $response = $this->tokens->issue($client['client_id'], (int) $row['account_id'], $scopes, 'authorization_code');

        $this->clients->touchLastUsed($client['client_id']);
        $this->logger->info('oauth.token_issued', array(
            'client_id' => $client['client_id'],
            'account_id' => (int) $row['account_id'],
            'grant_type' => 'authorization_code',
        ));

        return $response;
    }

    /**
     * @return array<string,mixed>
     */
    private function handleRefreshToken(Request $request, array $client)
    {
        $refreshToken = $request->string('refresh_token');
        if ($refreshToken === '') {
            throw ApiException::oauth('invalid_request', '缺少 refresh_token');
        }

        $row = $this->tokens->findActiveByRefreshToken($refreshToken);
        if ($row === null) {
            throw ApiException::oauth('invalid_grant', 'refresh_token 无效或已过期');
        }

        if (!hash_equals((string) $row['client_id'], (string) $client['client_id'])) {
            throw ApiException::oauth('invalid_grant', 'refresh_token 不属于该客户端');
        }

        // 账号可能已被停用
        $account = $this->accounts->find((int) $row['account_id']);
        if ($account === null || !$account->isActive()) {
            throw ApiException::oauth('invalid_grant', '该通行证已不可用');
        }

        // 允许缩小 scope，不允许扩大
        $granted = Scope::parse($row['scopes']);
        $requested = $request->string('scope');
        if ($requested !== '') {
            $narrowed = Scope::intersect(Scope::parse($requested), $granted);
            if ($narrowed === array()) {
                throw ApiException::oauth('invalid_scope', '请求的 scope 超出了原授权范围');
            }
            $granted = $narrowed;
        }

        $response = $this->tokens->rotate($row, $granted);
        $this->clients->touchLastUsed($client['client_id']);

        $this->logger->info('oauth.token_refreshed', array(
            'client_id' => $client['client_id'],
            'account_id' => (int) $row['account_id'],
        ));

        return $response;
    }

    /**
     * 机器对机器：只签发 directory 类 scope，拿不到任何用户数据
     *
     * @return array<string,mixed>
     */
    private function handleClientCredentials(Request $request, array $client)
    {
        $requested = Scope::parse($request->string('scope', 'directory'));
        $allowed = $this->clients->allowedScopes($client);

        $grantable = array();
        foreach ($requested as $scope) {
            if (in_array($scope, $allowed, true) && in_array($scope, Scope::MACHINE_SCOPES, true)) {
                $grantable[] = $scope;
            }
        }

        if ($grantable === array()) {
            throw ApiException::oauth(
                'invalid_scope',
                'client_credentials 只能申请以下 scope：' . implode(' ', Scope::MACHINE_SCOPES)
            );
        }

        $response = $this->tokens->issue($client['client_id'], 0, $grantable, 'client_credentials');
        $this->clients->touchLastUsed($client['client_id']);

        $this->logger->info('oauth.token_issued', array(
            'client_id' => $client['client_id'],
            'grant_type' => 'client_credentials',
            'scopes' => Scope::toString($grantable),
        ));

        return $response;
    }

    /**
     * PKCE 校验（RFC 7636）
     *
     * @param array<string,mixed> $codeRow
     * @throws ApiException
     */
    private function assertPkce(Request $request, array $codeRow)
    {
        $challenge = isset($codeRow['code_challenge']) ? (string) $codeRow['code_challenge'] : '';
        if ($challenge === '') {
            return;
        }

        $verifier = $request->string('code_verifier');
        if ($verifier === '') {
            throw ApiException::oauth('invalid_grant', '缺少 code_verifier');
        }
        if (strlen($verifier) < 43 || strlen($verifier) > 128) {
            throw ApiException::oauth('invalid_grant', 'code_verifier 长度不合法');
        }

        $method = isset($codeRow['code_challenge_method']) && $codeRow['code_challenge_method'] !== null
            ? strtoupper((string) $codeRow['code_challenge_method'])
            : 'S256';

        if ($method === 'S256') {
            $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            if (!hash_equals($challenge, $computed)) {
                throw ApiException::oauth('invalid_grant', 'code_verifier 校验失败');
            }
            return;
        }

        if (!hash_equals($challenge, $verifier)) {
            throw ApiException::oauth('invalid_grant', 'code_verifier 校验失败');
        }
    }

    // ========================================================================
    //  资源端点辅助
    // ========================================================================

    /**
     * 用 Bearer 令牌认证
     *
     * @param Request $request
     * @param string|null $requiredScope
     * @return array{client:array<string,mixed>,account:Account|null,scopes:array<int,string>,token:array<string,mixed>}
     * @throws ApiException
     */
    public function authenticateBearer(Request $request, $requiredScope = null)
    {
        $token = $request->bearerToken();
        if ($token === '') {
            throw ApiException::unauthorized('缺少访问令牌');
        }

        $row = $this->tokens->findActiveByAccessToken($token);
        if ($row === null) {
            throw ApiException::unauthorized('访问令牌无效或已过期');
        }

        $client = $this->clients->findByClientId($row['client_id']);
        if ($client === null || !$this->clients->isEnabled($client)) {
            throw ApiException::oauth('invalid_client', '该应用已被停用', 401);
        }

        $this->assertRateLimit($client);

        $scopes = Scope::parse($row['scopes']);
        if ($requiredScope !== null && !Scope::has($scopes, $requiredScope)) {
            throw ApiException::forbidden('该访问令牌没有 ' . $requiredScope . ' 权限');
        }

        $account = null;
        $accountId = (int) $row['account_id'];
        if ($accountId > 0) {
            $account = $this->accounts->find($accountId);
            if ($account === null || !$account->isActive()) {
                throw ApiException::unauthorized('该通行证已不可用');
            }
        }

        $this->tokens->touchUsed($token);

        return array(
            'client'  => $client,
            'account' => $account,
            'scopes'  => $scopes,
            'token'   => $row,
        );
    }

    /**
     * RFC 7662 令牌内省
     *
     * @return array<string,mixed>
     */
    public function introspect(Request $request)
    {
        $this->authenticateClient($request, false);

        $token = $request->string('token');
        $hint = $request->string('token_type_hint');

        $row = null;
        if ($hint !== 'refresh_token') {
            $row = $this->tokens->findActiveByAccessToken($token);
        }
        if ($row === null && $hint !== 'access_token') {
            $row = $this->tokens->findActiveByRefreshToken($token);
        }

        if ($row === null) {
            return array('active' => false);
        }

        return array(
            'active'    => true,
            'client_id' => $row['client_id'],
            'sub'       => (string) $row['account_id'],
            'scope'     => $row['scopes'],
            'token_type' => 'Bearer',
            'exp'       => strtotime((string) $row['access_expires_at']),
            'iat'       => strtotime((string) $row['created_at']),
            'grant_type' => $row['grant_type'],
        );
    }

    /**
     * RFC 7009 令牌吊销
     */
    public function revoke(Request $request)
    {
        $this->authenticateClient($request, false);
        $this->tokens->revoke($request->string('token'));
    }

    /**
     * 限流
     *
     * @param array<string,mixed> $client
     * @param bool $oauthStyle 是否按 RFC 6749 错误格式抛出
     * @throws ApiException
     */
    private function assertRateLimit(array $client, $oauthStyle = false)
    {
        $limit = (int) $client['rate_limit'];
        if ($limit <= 0) {
            return;
        }

        $used = $this->clients->callsInLastMinute($client['client_id']);
        if ($used >= $limit) {
            $message = '该应用调用频率超限（每分钟 ' . $limit . ' 次）';

            if ($oauthStyle) {
                // 令牌端点必须返回 {"error":...}，否则第三方 SDK 无法按协议解析
                throw ApiException::oauth('temporarily_unavailable', $message, 429);
            }

            throw ApiException::rateLimited($message);
        }
    }
}
