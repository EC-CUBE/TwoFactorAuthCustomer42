<?php

/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) EC-CUBE CO.,LTD. All Rights Reserved.
 *
 * http://www.ec-cube.co.jp/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Plugin\TwoFactorAuthCustomer42\Service;

use Psr\Container\ContainerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Eccube\Common\EccubeConfig;
use Eccube\Repository\BaseInfoRepository;
use RobThree\Auth\TwoFactorAuth;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Encoder\EncoderFactoryInterface;
use Plugin\TwoFactorAuthCustomer42\Repository\TwoFactorAuthConfigRepository;

class CustomerTwoFactorAuthService
{
    /**
     * @var int デフォルトの認証の有効時間
     */
    public const DEFAULT_EXPIRE_TIME = 3600;

    /**
     * @var int ルート認証の有効時間
     */
    public const ROUTE_EXPIRE_TIME = 3600;

    /**
     * @var string Cookieに保存する時のキー名
     */
    public const DEFAULT_COOKIE_NAME = 'plugin_eccube_customer_2fa';

    /**
     * @var string ルート認証Cookieに保存する時のキー名
     */
    public const ROUTE_COOKIE_NAME = 'plugin_eccube_route_customer_2fa';

    /**
     * @var string 認証電話番号を保存する時のキー名
     */
    public const SESSION_AUTHED_PHONE_NUMBER = 'plugin_eccube_customer_2fa_authed_phone_number';

    /**
     * @var string コールバックURL
     */
    public const SESSION_CALL_BACK_URL = 'plugin_eccube_customer_2fa_call_back_url';

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @var EccubeConfig
     */
    protected $eccubeConfig;

    /**
     * @var EncoderFactoryInterface
     */
    protected $encoderFactory;

    /**
     * @var RequestStack
     */
    protected $requestStack;

    /**
     * @var Request
     */
    protected $request;

    /**
     * @var Encoder
     */
    protected $encoder;

    /**
     * @var string
     */
    protected $cookieName = self::DEFAULT_COOKIE_NAME;
    /**
     * @var string
     */
    protected $routeCookieName = self::ROUTE_COOKIE_NAME;

    /**
     * @var int
     */
    protected $expire = self::DEFAULT_EXPIRE_TIME;

    /**
     * @var int
     */
    protected $route_expire = self::ROUTE_EXPIRE_TIME;

    /**
     * @var TwoFactorAuth
     */
    protected $tfa;

    /**
     * @var \Eccube\Entity\BaseInfo|object|null
     */
    private $baseInfo;

    /**
     * @var TwoFactorAuthConfig
     */
    private $twoFactorAuthConfig;

    /**
     * @var array
     */
    private $default_tfa_routes = [
        'login',
        'mypage_login',
        'mypage',
        'mypage_order',
        'shopping',
        'shopping_login',
    ];

    /**
     * @return array
     */
    public function getDefaultAuthRoutes() {
        return $this->default_tfa_routes;
    }

    /**
     * @required
     */
    public function setContainer(ContainerInterface $container): ?ContainerInterface
    {
        $previous = $this->container;
        $this->container = $container;

        return $previous;
    }

    /**
     * constructor.
     *
     * @param EntityManagerInterface  $entityManager
     * @param EccubeConfig            $eccubeConfig
     * @param EncoderFactoryInterface $encoderFactory
     * @param RequestStack            $requestStack
     * @param BaseInfoRepository      $baseInfoRepository
     * @param TwoFactorAuthConfigRepository     $twoFactorAuthConfigRepository
     */
    public function __construct(
        EntityManagerInterface  $entityManager,
        EccubeConfig            $eccubeConfig,
        EncoderFactoryInterface $encoderFactory,
        RequestStack            $requestStack,
        BaseInfoRepository      $baseInfoRepository,
        TwoFactorAuthConfigRepository     $twoFactorAuthConfigRepository
    ) {
        $this->entityManager = $entityManager;
        $this->eccubeConfig = $eccubeConfig;
        $this->encoderFactory = $encoderFactory;
        $this->requestStack = $requestStack;
        $this->request = $requestStack->getCurrentRequest();
        $this->encoder = $this->encoderFactory->getEncoder('Eccube\\Entity\\Customer');
        $this->tfa = new TwoFactorAuth();
        $this->baseInfo = $baseInfoRepository->find(1);

        if ($this->eccubeConfig->get('plugin_eccube_2fa_customer_cookie_name')) {
            $this->cookieName = $this->eccubeConfig->get('plugin_eccube_2fa_customer_cookie_name');
        }
        if ($this->eccubeConfig->get('plugin_eccube_2fa_route_customer_cookie_name')) {
            $this->routeCookieName = $this->eccubeConfig->get('plugin_eccube_2fa_route_customer_cookie_name');
        }
        $expire = $this->eccubeConfig->get('plugin_eccube_2fa_customer_expire');
        if ($expire || $expire === '0') {
            $this->expire = (int)$expire;
        }

        $route_expire = $this->eccubeConfig->get('plugin_eccube_2fa_route_customer_expire');
        if ($route_expire || $route_expire === '0') {
            $this->route_expire = (int)$route_expire;
        }

        $this->twoFactorAuthConfig = $twoFactorAuthConfigRepository->findOne();
    }

    /**
     * 認証済みか？
     *
     * @param \Eccube\Entity\Customer $Customer
     *
     * @return boolean
     */
    public function isAuthed($Customer, $route = null)
    {
        if (!$Customer->isTwoFactorAuth()) {
            return false;
        }

        $cookieName = $this->cookieName;
        $expire = $this->expire;
        if ($route != null) {
            if (in_array($route, $this->default_tfa_routes)) {
                $cookieName = $this->cookieName;
            } else {
                $cookieName = $this->routeCookieName . '_' . $route;
                $expire = $this->route_expire;
            }
        }

        if (($json = $this->request->cookies->get($cookieName))) {
            $configs = json_decode($json);
            $encodedString = $this->encoder->encodePassword($Customer->getId() . $Customer->getSecretKey(), $Customer->getSalt());
            if (
                $configs
                && isset($configs->{$Customer->getId()})
                && ($config = $configs->{$Customer->getId()})
                && property_exists($config, 'key')
                && $config->key === $encodedString
                && (
                    $this->expire == 0
                    || (property_exists($config, 'date') && ($config->date && $config->date > date('U', strtotime('-' . $expire))))
                )
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * 2段階認証用Cookie生成.
     *
     * @param \Eccube\Entity\Customer $Customer
     *
     * @return Cookie
     */
    public function createAuthedCookie($Customer, $route = null)
    {
        $encodedString = $this->encoder->encodePassword($Customer->getId() . $Customer->getSecretKey(), $Customer->getSalt());

        $cookieName = $this->cookieName;
        $expire = $this->expire;
        if ($route != null) {
            if (in_array($route, $this->default_tfa_routes)) {
                $cookieName = $this->cookieName;
            } else {
                $cookieName = $this->routeCookieName . '_' . $route;
                $expire = $this->route_expire;
            }
        }

        $configs = json_decode('{}');
        if (($json = $this->request->cookies->get($cookieName))) {
            $configs = json_decode($json);
        }
        $configs->{$Customer->getId()} = [
            'key' => $encodedString,
            'date' => time(),
        ];

        $cookie = new Cookie(
            $cookieName, // name
            json_encode($configs), // value
            ($expire == 0 ? 0 : time() + $expire), // expire
            $this->request->getBasePath(), // path
            null, // domain
            ($this->eccubeConfig->get('eccube_force_ssl') ? true : false), // secure
            true, // httpOnly
            false, // raw
            ($this->eccubeConfig->get('eccube_force_ssl') ? Cookie::SAMESITE_NONE : null) // sameSite
        );

        if ($route == null && !$this->isAuthed($Customer)) {
            // 直リンクで重要操作ルートを指定された場合、ログイン認証済みCookieが存在しない為、このタイミングで生成する
            $login_cookie = $this->createAuthedCookie($Customer, 'mypage');
        }

        return $cookie;
    }

    /**
     * 二段階認証設定が有効か?
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->baseInfo->isTwoFactorAuthUse();
    }

    /**
     * SMSで顧客電話番号へメッセージを送信.
     *
     * @param \Eccube\Entity\Customer $Customer
     *
     */
    public function sendBySms($Customer, $phoneNumber, $body)
    {
        // Twilio
        $twilio = new \Twilio\Rest\Client(
            $this->twoFactorAuthConfig->getApiKey(),
            $this->twoFactorAuthConfig->getApiSecret()
        );
        // SMS送信(現在国内電話番号のみ対象)
        $message = $twilio->messages
                    ->create('+81' . $phoneNumber,
                        array(
                            "from" => $this->twoFactorAuthConfig->getFromPhonenumber(),
                            "body" => $body
                        )
                    );

        return $message;
    }

    /**
     * 要認証ルートを取得.
     * @return array
     */
    public function getIncludeRoutes() : array
    {
        $routes = [];
        $include = $this->twoFactorAuthConfig->getIncludeRoutes();
        if ($include) {
            $routes = preg_split('/\R/', $include);
        }
        return $routes;
    }

    /**
     * 認証除外ルートを取得.
     *
     * @return array
     */
    public function getExcludeRoutes() : array
    {
        $routes = [];
        $include = $this->twoFactorAuthConfig->getExcludeRoutes();
        if ($include) {
            $routes = preg_split('/\R/', $include);
        }
        return $routes;
    }
}

