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

use Doctrine\ORM\EntityManagerInterface;
use Eccube\Common\EccubeConfig;
use Eccube\Entity\Customer;
use Eccube\Repository\BaseInfoRepository;
use Plugin\TwoFactorAuthCustomer42\Entity\TwoFactorAuthCustomerCookie;
use Plugin\TwoFactorAuthCustomer42\Repository\TwoFactorAuthConfigRepository;
use Plugin\TwoFactorAuthCustomer42\Repository\TwoFactorAuthCustomerCookieRepository;
use Psr\Container\ContainerInterface;
use RobThree\Auth\TwoFactorAuth;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Security\Core\Encoder\EncoderFactoryInterface;

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
        'mypage_favorite',
        'mypage_change',
        'mypage_delivery',
        'mypage_withdraw',
        'shopping',
        'shopping_login',
    ];
    private TwoFactorAuthCustomerCookieRepository $twoFactorCustomerCookieRepository;

    /**
     * @var PasswordHasherFactoryInterface
     */
    private PasswordHasherFactoryInterface $hashFactory;

    /**
     * @return array
     */
    public function getDefaultAuthRoutes()
    {
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
        EntityManagerInterface $entityManager,
        EccubeConfig $eccubeConfig,
        EncoderFactoryInterface $encoderFactory,
        RequestStack $requestStack,
        BaseInfoRepository $baseInfoRepository,
        TwoFactorAuthConfigRepository $twoFactorAuthConfigRepository,
        TwoFactorAuthCustomerCookieRepository $twoFactorCustomerCookieRepository,
        PasswordHasherFactoryInterface $hashFactory
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
            $this->expire = (int) $expire;
        }

        $route_expire = $this->eccubeConfig->get('plugin_eccube_2fa_route_customer_expire');
        if ($route_expire || $route_expire === '0') {
            $this->route_expire = (int) $route_expire;
        }

        $this->twoFactorAuthConfig = $twoFactorAuthConfigRepository->findOne();
        $this->twoFactorCustomerCookieRepository = $twoFactorCustomerCookieRepository;
        $this->hashFactory = $hashFactory;
    }

    /**
     * 認証済みか？
     *
     * @param \Eccube\Entity\Customer $Customer
     *
     * @return boolean
     */
    public function isAuthed(Customer $Customer, $route = null): bool
    {
        if (!$Customer->getTwoFactorAuthType() === null) {
            return false;
        }

        $expire = $this->expire;
        if ($route != null) {
            $includeRouts = $this->getIncludeRoutes();
            if (in_array($route, $includeRouts) && $this->isAuthed($Customer, 'mypage')) {
                // 重要操作ルーティングの場合、
                $cookieName = $this->routeCookieName.'_'.$route;
                $expire = $this->route_expire;
            } else {
                // デフォルトルーティングの場合、
                $cookieName = $this->cookieName;
            }
            return $this->isRouteAuthed($Customer, $cookieName, $expire);
        }

        return false;
    }

    /**
     * デフォルトルート・重要操作ルーティングは認証済みか
     * データベースの中に保存しているデータとクッキー値を比較する
     *
     * @param Customer $Customer
     * @param string $cookieName
     * @param int $expire
     *
     * @return bool
     */
    public function isRouteAuthed(Customer $Customer, string $cookieName, int $expire): bool
    {
        if ($json = $this->request->cookies->get($cookieName)) {
            $configs = json_decode($json);

            /** @var TwoFactorAuthCustomerCookie[]|null $activeCookies */
            $activeCookies = $this
                ->twoFactorCustomerCookieRepository
                ->searchForCookie($Customer, $cookieName);

            foreach ($activeCookies as $activeCookie) {
                if (
                    $configs
                    && isset($configs->{$Customer->getId()})
                    && ($config = $configs->{$Customer->getId()})
                    && property_exists($config, 'key')
                    && $config->key === $activeCookie->getCookieValue()
                    && (
                        $this->expire == 0
                        || (property_exists($config, 'date') && ($config->date && $config->date > date('U', strtotime('-'.$expire))))
                    )
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 2段階認証用Cookie生成.
     *
     * @param Customer $Customer
     * @param null $route
     *
     * @return Cookie
     */
    public function createAuthedCookie($Customer, $route = null): Cookie
    {
        $expire = $this->expire;
        $cookieName = $this->cookieName;
        if ($route != null) {
            $includeRouts = $this->getIncludeRoutes();
            if (in_array($route, $includeRouts) && $this->isAuthed($Customer, 'mypage')) {
                $cookieName = $this->routeCookieName.'_'.$route;
                $expire = $this->route_expire;
            }
        }
        return $this->createRouteAuthCookie($Customer, $cookieName, $expire);
    }

    /**
     * ２段階認証用Cookie生成.
     * クッキーデータをデータベースに保存する
     *
     * @param Customer $Customer
     * @param string $cookieName
     * @param int $expire
     *
     * @return mixed
     */
    public function createRouteAuthCookie(Customer $Customer, string $cookieName, int $expire)
    {
        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $em) use ($expire, $cookieName, $Customer) {
            $cookieData = $this->twoFactorCustomerCookieRepository->generateCookieData(
                $Customer,
                $cookieName,
                $expire,
                $this->eccubeConfig->get('plugin_eccube_2fa_route_cookie_value_character_length')
            );

            $configs = json_decode('{}');
            if ($json = $this->request->cookies->get($cookieName)) {
                $configs = json_decode($json);
            }

            $configs->{$Customer->getId()} = [
                'key' => $cookieData->getCookieValue(),
                'date' => time(),
            ];

            $em->persist($cookieData);
            $em->flush();

            return new Cookie(
                $cookieData->getCookieName(), // name
                json_encode($configs), // value
                $cookieData->getCookieExpireDate()->getTimestamp(), // expire
                $this->request->getBasePath(), // path
                null, // domain
                $this->eccubeConfig->get('eccube_force_ssl') ? true : false, // secure
                true, // httpOnly
                false, // raw
                $this->eccubeConfig->get('eccube_force_ssl') ? Cookie::SAMESITE_NONE : null // sameSite
            );
        });
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
                    ->create('+81'.$phoneNumber,
                        [
                            'from' => $this->twoFactorAuthConfig->getFromPhonenumber(),
                            'body' => $body,
                        ]
                    );

        return $message;
    }

    /**
     * 要認証ルートを取得.
     *
     * @return array
     */
    public function getIncludeRoutes(): array
    {
        $routes = [];
        $include = $this->twoFactorAuthConfig->getIncludeRoutes();
        if ($include) {
            $routes = preg_split('/\R/', $include);
        }

        return $routes;
    }

    /**
     * ２段階認証に関係しているクッキーだけを消す
     *
     * @param Request $request
     * @param Response $response
     *
     * @return void
     */
    public function clear2AuthCookies(Request $request, Response $response)
    {
        foreach ($request->cookies->all() as $key => $cookie) {
            if (
                $this->str_contains($key, self::DEFAULT_COOKIE_NAME) ||
                $this->str_contains($key, self::ROUTE_COOKIE_NAME)
            ) {
                // クッキーを消す
                $response->headers->clearCookie($key);
            }
        }
    }

    public function generateOneTimeToken(): string
    {
        $token = '';
        for ($i = 0; $i < 6; $i++) {
            $token .= (string) random_int(0, 9);
        }

        return $token;
    }

    /***
     * @param string $input
     * @return string
     */
    public function readOneTimeToken(string $input): string
    {
        $passwordEncoder = $this->hashFactory->getPasswordHasher(Customer::class);

        return $passwordEncoder->hash($input);
    }

    public function hashOneTimeToken($token): string
    {
        // ハッシュジェネレーターをエンティティに持って来る
        return $this->hashFactory->getPasswordHasher(Customer::class)->hash($token);
    }

    /***
     * @param string $haystack
     * @param string $needle
     * @return bool
     *
     * @deprecated ECCUBEの最低PHPバージョンは8.0になったら, この関数を消してphp8.0からのstr_containsを利用する
     */
    private function str_contains(string $haystack, string $needle)
    {
        return $needle !== '' && mb_strpos($haystack, $needle) !== false;
    }
}
