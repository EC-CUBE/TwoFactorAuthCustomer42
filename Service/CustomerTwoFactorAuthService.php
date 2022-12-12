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
use Plugin\TwoFactorAuthCustomer42\Repository\SmsConfigRepository;

class CustomerTwoFactorAuthService
{
    /**
     * @var int 2段階認証方式 SMS
     */
    private const AUTH_TYPE_SMS = 1;

    /**
     * @var int 2段階認証方式 アプリ
     */
    private const AUTH_TYPE_APP = 2;

    /**
     * @var int デフォルトの認証の有効日数
     */
    public const DEFAULT_EXPIRE_DATE = 14;

    /**
     * @var string Cookieに保存する時のキー名
     */
    public const DEFAULT_COOKIE_NAME = 'plugin_eccube_customer_2fa';

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
     * @var int
     */
    protected $expire = self::DEFAULT_EXPIRE_DATE;

    /**
     * @var TwoFactorAuth
     */
    protected $tfa;
    /**
     * @var \Eccube\Entity\BaseInfo|object|null
     */
    private $baseInfo;

    /**
     * @var SmsConfig
     */
    private $smsConfig;

    /**
     * @var \Twig_Environment
     */
    private $twig;

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
     * @param SmsConfigRepository     $smsConfigRepository
     * @param \Twig_Environment       $twig
     */
    public function __construct(
        EntityManagerInterface  $entityManager,
        EccubeConfig            $eccubeConfig,
        EncoderFactoryInterface $encoderFactory,
        RequestStack            $requestStack,
        BaseInfoRepository      $baseInfoRepository,
        SmsConfigRepository     $smsConfigRepository,
        \Twig_Environment $twig
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

        $expire = $this->eccubeConfig->get('plugin_eccube_2fa_customer_expire');
        if ($expire || $expire === '0') {
            $this->expire = (int)$expire;
        }
        $this->smsConfig = $smsConfigRepository->findOne();
        $this->twig = $twig;
    }

    /**
     * 認証済みか？
     * 
     * @param \Eccube\Entity\Customer $Customer
     *
     * @return boolean
     */
    public function isAuth($Customer)
    {
        if (!$Customer->isTwoFactorAuth()) {
            return false;
        }
        if (($json = $this->request->cookies->get($this->cookieName))) {
            $configs = json_decode($json);
            $encodedString = $this->encoder->encodePassword($Customer->getId() . $Customer->getTwoFactorAuthSecret(), $Customer->getSalt());
            if (
                $configs
                && isset($configs->{$Customer->getId()})
                && ($config = $configs->{$Customer->getId()})
                && property_exists($config, 'key')
                && $config->key === $encodedString
                && (
                    $this->expire == 0
                    || (property_exists($config, 'date') && ($config->date && $config->date > date('U', strtotime('-' . $this->expire . ' day'))))
                )
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * アプリ認証用Cookie生成.
     * 
     * @param \Eccube\Entity\Customer $Customer
     *
     * @return Cookie
     */
    public function createAuthedCookie($Customer)
    {
        $encodedString = $this->encoder->encodePassword($Customer->getId() . $Customer->getTwoFactorAuthSecret(), $Customer->getSalt());

        $configs = json_decode('{}');
        if (($json = $this->request->cookies->get($this->cookieName))) {
            $configs = json_decode($json);
        }
        $configs->{$Customer->getId()} = [
            'key' => $encodedString,
            'date' => time(),
        ];

        $cookie = new Cookie(
            $this->cookieName, // name
            json_encode($configs), // value
            ($this->expire == 0 ? 0 : time() + ($this->expire * 24 * 60 * 60)), // expire
            $this->request->getBasePath(), // path
            null, // domain
            ($this->eccubeConfig->get('eccube_force_ssl') ? true : false), // secure
            true, // httpOnly
            false, // raw
            ($this->eccubeConfig->get('eccube_force_ssl') ? Cookie::SAMESITE_NONE : null) // sameSite
        );

        return $cookie;
    }

    /**
     * 認証コードを取得.
     * 
     * @param string $authKey
     * @param string $token
     *
     * @return boolean
     */
    public function verifyCode($authKey, $token)
    {
        return $this->tfa->verifyCode($authKey, $token, 2);
    }

    /**
     * 秘密鍵生成.
     * 
     * @return string
     */
    public function createSecret()
    {
        return $this->tfa->createSecret();
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
     * ワンタイムトークンチェック.
     * 
     * @return boolean
     */
    public function checkOneTime($Customer, $one_time_token)
    {
        $now = new \DateTime();
        if ($Customer->getOneTimeToken() !== $one_time_token || $Customer->getOneTimeTokenExpire() < $now) {
            return false;
        }
        return true;
    }

    /**
     * ワンタイムトークンを送信.
     * 
     * @param \Eccube\Entity\Customer $Customer
     * @param string $phoneNumber 
     * 
     */
    public function sendOnetimeToken($Customer, $phoneNumber) 
    {
        // ワンタイムトークン生成・保存
        $token = $Customer->createOneTimeToken();
        $this->entityManager->persist($Customer);
        $this->entityManager->flush();

        // ワンタイムトークン送信メッセージをレンダリング
        $twig = 'TwoFactorAuthCustomer42/Resource/template/default/sms/onetime_message.twig';
        $body = $this->twig->render($twig , [
            'Customer' => $Customer,
            'token' => $token,
        ]);

        // SMS送信
        return $this->sendBySms($Customer, $phoneNumber, $body);
    }

    /**
     * SMSで顧客電話番号へメッセージを送信.
     * 
     * @param \Eccube\Entity\Customer $Customer
     * 
     */
    public function sendBySms($Customer, $phoneNumber, $body) 
    {
        // TODO : https://symfony.com/doc/current/notifier.html でまとめたい
        // Twilio
        $twilio = new \Twilio\Rest\Client($this->smsConfig->getApiKey(), $this->smsConfig->getApiSecret()); 
        // SMS送信
        $message = $twilio->messages 
                    ->create('+81' . $phoneNumber,
                        array(
                            "from" => $this->smsConfig->getFromTel(),
                            "body" => $body
                        ) 
                    );

        return $message;
    }
}

