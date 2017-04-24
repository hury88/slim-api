<?php
namespace App\Middlewares;

use App\Cache\AuthCache;
use Psr\Container\ContainerInterface;

class AuthMiddleware
{
    private $_di;

    protected $code;
    protected $msg;

    function __construct(ContainerInterface $di) {
        $this->_di = $di;
        $this->code = 0;
        $this->msg = 'success';
    }

    /**
     * Example middleware invokable class
     *
     * @param  \Psr\Http\Message\ServerRequestInterface $request  PSR7 request
     * @param  \Psr\Http\Message\ResponseInterface      $response PSR7 response
     * @param  callable                                 $next     Next middleware
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function __invoke($request, $response, $next)
    {
        $accessSign = $request->getHeader('Access-Sign');
        $accessTime = $request->getHeader('Access-Time');
        $uuid = $request->getHeader('Access-UUID');

        if (empty($accessSign) || empty($accessTime) || empty($uuid)) {
            return $response->withJson([
                'code' => -1,
                'msg' => 'Invalid token, access failed!',
            ], 200);
        }

        // 登录验证
        $login = $this->validateLogin($uuid[0]);

        if (!$login) {
            return $response->withJson([
                'code' => $this->code,
                'msg' => $this->msg,
            ], 200);
        }

        // 验签
        $path = $request->getUri()->getPath();
        $query = $request->getQueryParams();

        $success = $this->validateSign($uuid[0], $accessTime[0], $accessSign[0], $path, $query);

        if (!$success) {
            return $response->withJson([
                'code' => $this->code,
                'msg' => $this->msg,
            ], 200);
        }

        $response = $next($request, $response);

        return $response;
    }

    // 验证登录
    protected function validateLogin($uuid)
    {
        $authCache = new AuthCache($this->_di);
        $loginInfo = $authCache->getAuthCache($uuid);

        if (empty($loginInfo)) {
            $this->code = 403;
            $this->msg = '用户未登录';

            return false;
        }

        if ($loginInfo['expire_time'] > 0 && $loginInfo['expire_time'] <= time()) {
            $this->code = 403;
            $this->msg = '登录已过期';

            return false;
        }

        return true;
    }

    // 验签
    protected function validateSign($uuid, $accessTime, $accessSign, $query)
    {
        $accessExpire = env('ACCESS_EXPIRE', 0);

        if (is_numeric($accessExpire) && $accessExpire > 0) {
            if (time() - $accessTime >= $accessExpire) {
                $this->code = -1;
                $this->msg = '请求已失效';

                return false;
            }
        }

        $authCache = new AuthCache($this->_di);
        $token = $authCache->getAuthToken($uuid);

        array_shift($query);
        $query['token'] = $token;
        $query['timestamp'] = $accessTime;

        $signUrl = sprintf("%s?%s", $path, http_build_query($query));

        if (strtolower($accessSign) != md5($signUrl)) {
            $this->code = -1;
            $this->msg = '验签失败';

            return false;
        }

        return true;
    }
}