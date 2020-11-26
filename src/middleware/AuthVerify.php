<?php
declare (strict_types=1);

namespace think\support\middleware;

use Closure;
use Exception;
use Lcobucci\JWT\Token\Plain;
use stdClass;
use think\Request;
use think\Response;
use think\facade\Cookie;
use think\redis\library\RefreshToken;
use think\support\facade\Context;
use think\support\facade\Token;

/**
 * 授权认证验证中间件
 * Class AuthVerify
 * @package think\support\middleware
 */
abstract class AuthVerify
{
    /**
     * 场景
     * @var string
     */
    protected string $scene = 'default';

    /**
     * 返回定义
     * @var array
     */
    protected array $hookResult = [
        'error' => 1,
        'msg' => 'hook failed'
    ];

    /**
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            if (!Cookie::has($this->scene . '_token')) {
                return json([
                    'error' => 1,
                    'msg' => 'please first authorize user login'
                ], 401);
            }

            $tokenString = Cookie::get($this->scene . '_token');
            $result = Token::verify($this->scene, $tokenString);
            assert($result->token instanceof Plain);
            $token = $result->token;
            $claims = $token->claims();
            $symbol = $claims->get('symbol');
            if ($result->expired) {
                $jti = $claims->get('jti');
                $ack = $claims->get('ack');
                $verify = RefreshToken::create()->verify($jti, $ack);
                if (!$verify) {
                    return json([
                        'error' => 1,
                        'msg' => 'refresh token verification expired'
                    ], 401);
                }
                $newToken = Token::create($this->scene, $jti, $ack, $symbol);
                Cookie::set($this->scene . '_token', $newToken->toString());
            }
            $result = $this->hook((object)$symbol);
            if (!$result) {
                return json($this->hookResult);
            }
            Context::set('auth', (object)$symbol);
            return $next($request);
        } catch (Exception $e) {
            return json([
                'error' => 1,
                'msg' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * @param stdClass $symbol
     * @return bool
     */
    protected function hook(stdClass $symbol): bool
    {
        return true;
    }
}