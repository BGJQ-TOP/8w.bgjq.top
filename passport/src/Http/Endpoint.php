<?php

namespace W8\Passport\Http;

use Throwable;
use W8\Passport\Application;

/**
 * API 端点运行器
 *
 * 每个 api/*.php 只需要：
 *     Endpoint::run(function (Request $request, Application $app) {
 *         return Response::ok([...]);
 *     });
 *
 * 统一负责：CORS、OPTIONS 预检、异常翻译、访问日志。
 */
final class Endpoint
{
    /**
     * @param callable $handler function (Request, Application): Response|array|null
     * @param array<string,mixed> $options ['cors' => bool, 'log' => bool, 'require_auth' => bool]
     */
    public static function run($handler, array $options = array())
    {
        $options = array_merge(array('cors' => true, 'log' => true), $options);

        $app = Application::instance();
        $request = Request::fromGlobals();

        if ($options['cors']) {
            self::applyCors();
        }

        if ($request->method() === 'OPTIONS') {
            http_response_code(204);
            return;
        }

        $started = microtime(true);
        $response = null;

        try {
            $result = call_user_func($handler, $request, $app);
            $response = $result instanceof Response ? $result : Response::ok($result);
        } catch (ApiException $e) {
            $response = $e->isOAuth()
                ? Response::oauthError($e->oauthError(), $e->getMessage(), $e->httpStatus())
                : Response::error($e->errorCode(), $e->getMessage(), $e->httpStatus(), $e->details());

            if ($e->httpStatus() >= 500) {
                $app->logger()->error('api.business_error', array(
                    'code' => $e->errorCode(), 'message' => $e->getMessage(), 'path' => $request->path(),
                ));
            } else {
                $app->logger()->debug('api.rejected', array(
                    'code' => $e->errorCode(), 'message' => $e->getMessage(), 'path' => $request->path(),
                ));
            }
        } catch (Throwable $e) {
            $app->logger()->error('api.unhandled', array(
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'path' => $request->path(),
            ));

            $message = $app->config()->isDebug() ? $e->getMessage() : '服务器内部错误';
            $response = Response::error('server_error', $message, 500);
        }

        if ($options['log']) {
            self::logCall($app, $request, $response, (int) ((microtime(true) - $started) * 1000));
        }

        $response->send();
    }

    private static function applyCors()
    {
        // 通行证是给第三方消费的公共服务，浏览器端直连是常态。
        // 真正敏感的接口靠 Bearer 令牌保护，不靠 CORS。
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Max-Age: 86400');
    }

    private static function logCall(Application $app, Request $request, Response $response, $elapsedMs)
    {
        try {
            $app->db()->insert('passport_api_logs', array(
                'client_id'        => $app->currentClientId(),
                'account_id'       => $app->currentAccountId(),
                'endpoint'         => substr($request->path(), 0, 160),
                'method'           => $request->method(),
                'response_status'  => $response->status(),
                'response_time_ms' => $elapsedMs,
                'ip_address'       => substr($request->ip(), 0, 45),
                'user_agent'       => $request->userAgent(),
            ));
        } catch (Throwable $e) {
            // 日志失败不能影响业务响应
            $app->logger()->warning('api.log_failed', array('message' => $e->getMessage()));
        }
    }
}
