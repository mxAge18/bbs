<?php

namespace App\Http\Controllers\Api;


use App\Http\Requests\Api\WeappAuthorizationRequest;
use App\Models\User;
use App\Traits\PassportToken;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Overtrue\Socialite\AccessToken;
use Illuminate\Auth\AuthenticationException;
use App\Http\Requests\Api\SocialAuthorizationRequest;
use App\Http\Requests\Api\AuthorizationRequest;
use Zend\Diactoros\Response as Psr7Response;
use Psr\Http\Message\ServerRequestInterface;

class AuthorizationsController extends Controller
{
//    use PassportToken;
    //
    public function socialStore(SocialAuthorizationRequest $request, $type)
    {

        $driver = \Socialite::driver($type);

        try {
            if ($code = $request->code) {
                $accessToken = $driver->getAccessToken($code);
            } else {
                $tokenData['access_token'] = $request->access_token;
                // 微信需要增加 openid
                if ($type == 'wechat') {
                    $tokenData['openid'] = $request->openid;
                }

                $accessToken = new AccessToken($tokenData);
            }

            $oauthUser = $driver->user($accessToken);

        } catch (\Exception $e) {
            throw new AuthenticationException('参数错误，未获取用户信息');
        }

        switch ($type) {
            case 'wechat':
                $unionid = $oauthUser->getOriginal()['unionid'] ?? null;

                if ($unionid) {
                    $user = User::where('weixin_unionid', $unionid)->first();
                } else {
                    $user = User::where('weixin_openid', $oauthUser->getId())->first();
                }

                // 没有用户，默认创建一个用户
                if (!$user) {
                    $user = User::create([
                        'name' => $oauthUser->getNickname(),
                        'avatar' => $oauthUser->getAvatar(),
                        'weixin_openid' => $oauthUser->getId(),
                        'weixin_unionid' => $unionid,
                    ]);
                }

                break;
        }
        $result = $this->getBearerTokenByUser($user, '1', false);
        return response()->json($result)->setStatusCode(201);

    }




    public function store(AuthorizationRequest $request)
    {
        $username = $request->username;
        filter_var($username, FILTER_VALIDATE_EMAIL) ?
            $credentials['email'] = $username :
            $credentials['phone'] = $username;

        $credentials['password'] = $request->password;

        if (!$token = \Auth::guard('api')->attempt($credentials)) {
            throw new AuthenticationException('用户名或密码错误');
        }


        return $this->respondWithToken($token)->setStatusCode(201);
    }

    public function update()
    {
        $token = auth('api')->refresh();
        return $this->respondWithToken($token);
    }

    public function destroy()
    {
        auth('api')->logout();
        return response(null, 204);
    }

    protected function respondWithToken($token)
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60
        ]);
    }

//    public function store(AuthorizationRequest $originRequest, AuthorizationServer $server, ServerRequestInterface $serverRequest)
//    {
//        try {
//            return $server->respondToAccessTokenRequest($serverRequest, new Psr7Response)->withStatus(201);
//        } catch(OAuthServerException $e) {
//            throw new AuthenticationException($e->getMessage());
//        }
//    }
//
//    public function update(AuthorizationServer $server, ServerRequestInterface $serverRequest)
//    {
//        try {
//            return $server->respondToAccessTokenRequest($serverRequest, new Psr7Response);
//        } catch(OAuthServerException $e) {
//            throw new AuthenticationException($e->getmessage());
//        }
//    }
//
//    public function destroy()
//    {
//        if (auth('api')->check()) {
//            auth('api')->user()->token()->revoke();
//            return response(null, 204);
//        } else {
//            throw new AuthenticationException('The token is invalid.');
//        }
//    }


    public function weappStore(WeappAuthorizationRequest $request)
    {
        $code = $request->code;

        // 根据 code 获取微信 openid 和 session_key
        $miniProgram = \EasyWeChat::miniProgram();
        $data = $miniProgram->auth->session($code);

        // 如果结果错误，说明 code 已过期或不正确，返回 401 错误

        if (isset($data['errcode'])) {
            throw new AuthenticationException('code 不正确');
        }

        // 找到 openid 对应的用户
        $user = User::where('weapp_openid', $data['openid'])->first();

        $attributes['weixin_session_key'] = $data['session_key'];

        // 未找到对应用户则需要提交用户名密码进行用户绑定
        if (!$user) {
            // 如果未提交用户名密码，403 错误提示
            if (!$request->username) {
                throw new AuthenticationException('用户不存在');
            }

            $username = $request->username;

            // 用户名可以是邮箱或电话
            filter_var($username, FILTER_VALIDATE_EMAIL) ?
                $credentials['email'] = $username :
                $credentials['phone'] = $username;

            $credentials['password'] = $request->password;

            // 验证用户名和密码是否正确
            if (!auth('api')->once($credentials)) {
                throw new AuthenticationException('用户名或密码错误');
            }

            // 获取对应的用户
            $user = auth('api')->getUser();
            $attributes['weapp_openid'] = $data['openid'];
        }

        // 更新用户数据
        $user->update($attributes);

        // 为对应用户创建 JWT
        $token = auth('api')->login($user);

        return $this->respondWithToken($token)->setStatusCode(201);
    }
}
