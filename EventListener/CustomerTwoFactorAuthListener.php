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

namespace Plugin\TwoFactorAuthCustomer42\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Eccube\Common\EccubeConfig;
use Eccube\Entity\Master\CustomerStatus;
use Eccube\Entity\Customer;
use Eccube\Repository\BaseInfoRepository;
use Eccube\Request\Context;
use Eccube\Service\TwoFactorAuthService;
use Plugin\TwoFactorAuthCustomer42\Service\CustomerTwoFactorAuthService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class CustomerTwoFactorAuthListener implements EventSubscriberInterface
{

    /**
     * 2段階認証で利用するルート.
     * @var array
     */
    public const TFA_ROUTE = [
        'plg_customer_2fa_device_auth_send_onetime',
        'plg_customer_2fa_device_auth_input_onetime',
        'plg_customer_2fa_device_auth_complete',
        'plg_customer_2fa_auth_type_select',
        'plg_customer_2fa_sms_send_onetime',
        'plg_customer_2fa_sms_input_onetime',
        'plg_customer_2fa_app_create',
        'plg_customer_2fa_app_challenge',
        'entry',
        'entry_confirm',
        'entry_activate',
    ];

    /**
     * @var int 2段階認証方式 SMS
     */
    private const AUTH_TYPE_SMS = 1;

    /**
     * @var int 2段階認証方式 アプリ
     */
    private const AUTH_TYPE_APP = 2;

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * @var EccubeConfig
     */
    protected $eccubeConfig;

    /**
     * @var Context
     */
    protected $requestContext;

    /**
     * @var UrlGeneratorInterface
     */
    protected $router;

    /**
     * @var CustomerTwoFactorAuthService
     */
    protected $customerTwoFactorAuthService;

    /**
     * @var BaseInfoRepository
     */
    protected BaseInfoRepository $baseInfoRepository;

    /**
     * @var \Eccube\Entity\BaseInfo|object|null
     */
    protected $baseInfo;

    /**
     * @var Session
     */
    protected $session;

    /**
     * 除外ルート.
     */
    protected $exclude_routes;

    /**
     * 個別認証ルート.
     */
    protected $include_routes;

    /**
     * @param EntityManagerInterface $entityManager
     * @param EccubeConfig $eccubeConfig
     * @param Context $requestContext
     * @param UrlGeneratorInterface $router
     * @param CustomerTwoFactorAuthService $customerTwoFactorAuthService
     * @param BaseInfoRepository $baseInfoRepository
     * @param SessionInterface $session
     */
    public function __construct(
        EntityManagerInterface       $entityManager, 
        EccubeConfig                 $eccubeConfig,
        Context                      $requestContext,
        UrlGeneratorInterface        $router,
        CustomerTwoFactorAuthService $customerTwoFactorAuthService,
        BaseInfoRepository           $baseInfoRepository,
        SessionInterface             $session
    ) {
        $this->entityManager = $entityManager;
        $this->eccubeConfig = $eccubeConfig;
        $this->requestContext = $requestContext;
        $this->router = $router;
        $this->customerTwoFactorAuthService = $customerTwoFactorAuthService;
        $this->baseInfoRepository = $baseInfoRepository;
        $this->baseInfo = $this->baseInfoRepository->find(1);
        $this->session = $session;

        $this->include_routes = $this->customerTwoFactorAuthService->getIncludeRoutes();
        $this->exclude_routes = $this->customerTwoFactorAuthService->getExcludeRoutes();
    }

    /**
     * 
     * @param ControllerArgumentsEvent $event
     */
    public function onKernelController(ControllerArgumentsEvent $event)
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if ($this->requestContext->isAdmin()) {
            // バックエンドURLの場合処理なし
            return;
        }

        if (($this->baseInfo->isOptionCustomerActivate() && !$this->baseInfo->isOptionActivateSms()) 
            && !$this->baseInfo->isTwoFactorAuthUse()) {
            // デバイス認証なし かつ 2段階認証使用しない場合は処理なし
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');
        $uri = $event->getRequest()->getRequestUri();

        if ($this->isExcludeRoute($route, $uri)) {
            // 2段階認証除外ルート or URIの場合は処理なし
            return;
        }

        $Customer = $this->requestContext->getCurrentUser();

        if ($Customer && $Customer instanceof Customer) {

            if ($Customer->getStatus()->getId() !== CustomerStatus::ACTIVE) {
                // 未ログインの場合、処理なし
                return;
            }

            if (!$Customer->isDeviceAuthed()) {
                // デバイス認証されていない場合
                if ($this->baseInfo->isOptionActivateSms() && $this->baseInfo->isOptionCustomerActivate()) {
                    // 仮会員登録機能:有効 / SMSによる本人認証:有効の場合　デバイス認証画面へリダイレクト
                    $url = $this->router->generate('plg_customer_2fa_device_auth_send_onetime', [], UrlGeneratorInterface::ABSOLUTE_PATH);
                    $event->setController(function () use ($url) {
                        return new RedirectResponse($url, $status = 302);
                    });
                }
            } else {
                if ($this->baseInfo->isTwoFactorAuthUse()) {
                    // [会員] ログイン時2段階認証状態
                    $is_login_authed = $this->customerTwoFactorAuthService->isAuth($Customer);
                    // [会員] ルート2段階認証状態
                    $is_route_authed = $this->customerTwoFactorAuthService->isAuth($Customer, $route);

                    if (!$is_login_authed) {
                        // TODO: ログインされていれば TOP/商品一覧/お問い合わせなど問わず必ず2段階認証を強制する？
                        // ログイン後の2段階認証未実施の場合
                        log_info('[ログイン時 2段階認証] 実施');
                        $this->dispatch($event, $Customer, $route);
                    } else if (!$is_route_authed) {
                        // 重要操作ルート/URIの場合、ログイン2段階認証されていても個別で認証
                        if ($this->isIncludeRoute($route, $uri)) {
                            log_info('[重要操作前 2段階認証] 実施 ルート：%s URI:%s', [$route, $uri]);
                            $this->dispatch($event, $Customer, $route);
                        }
                    }
                }
            }
        }

        return;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER_ARGUMENTS => ['onKernelController', 7],
        ];
    }

    /**
     * 2段階認証のディスパッチ.
     * 
     * @param ControllerArgumentsEvent $event
     * @param Customer $Customer
     * @param string|null $route
     */
    private function dispatch(ControllerArgumentsEvent $event, Customer $Customer, ?string $route)
    {
        // ログイン認証 = まだ または ルート認証要 + ルート認証まだの場合
        if (!$Customer->isTwoFactorAuth()) {
            // [会員] 2段階認証が未設定の場合
            // コールバックURLをセッションへ設定
            $this->setCallbackRoute($route);
            // 2段階認証選択画面へリダイレクト
            $url = $this->router->generate('plg_customer_2fa_auth_type_select', [], UrlGeneratorInterface::ABSOLUTE_PATH);
            $event->setController(function () use ($url) {
                return new RedirectResponse($url, 302);
            });
        } else {
            // 2段階認証 - 未認証の場合
            if ($Customer->getTwoFactorAuthType() == self::AUTH_TYPE_APP) {
                // コールバックURLをセッションへ設定
                $this->setCallbackRoute($route);
                // 2段階認証方式 = アプリ認証
                if (!empty($Customer->getTwoFactorAuthSecret())) {
                    // 秘密鍵あり = 認証
                    $url = $this->router->generate('plg_customer_2fa_app_challenge', [], UrlGeneratorInterface::ABSOLUTE_PATH);
                } else {
                    // 秘密鍵なし = 秘密鍵作成
                    $url = $this->router->generate('plg_customer_2fa_app_create', [], UrlGeneratorInterface::ABSOLUTE_PATH);
                }
                $event->setController(function () use ($url) {
                    return new RedirectResponse($url, 302);
                });
            }

            if ($Customer->getTwoFactorAuthType() == self::AUTH_TYPE_SMS) {
                // コールバックURLをセッションへ設定
                $this->setCallbackRoute($route);
                // 2段階認証方式 = SMS認証
                $url = $this->router->generate('plg_customer_2fa_sms_send_onetime', [], UrlGeneratorInterface::ABSOLUTE_PATH);
                $event->setController(function () use ($url) {
                    return new RedirectResponse($url, 302);
                });
            }
        }

        return;
    }


    /**
     * コールバックルートをセッションへ設定.
     * @param string|null $route
     */
    private function setCallbackRoute(?string $route)
    {
        if ($route) {
            $this->session->set(CustomerTwoFactorAuthService::SESSION_CALL_BACK_URL, $route);
        }
    }

    /**
     * ルート・URIが認証除外かチェック.
     * 
     * @param string $route
     * @param string $uri
     * @return bool
     */
    private function isExcludeRoute(string $route, string $uri): bool
    {
        if (in_array($route, self::TFA_ROUTE)) {
            // 2段階認証関連ルーティングに当たる場合は処理なし
            return true;
        }

        if (in_array($route, $this->exclude_routes)) {
            // 除外ルートに当たる場合は処理なし
            return true;
        }

        foreach($this->exclude_routes as $r) {
            if (strpos($uri, $r) === 0) {
                // 除外URLに当たる場合は処理なし
                return true;
            }
        }

        return false;
    }

    /**
     * ルート・URIが個別認証対象かチェック.
     *  
     * @param string $route
     * @param string $uri
     * @return bool
     */
    private function isIncludeRoute(string $route, string $uri): bool
    {
        // ルートで認証
        if (in_array($route, $this->include_routes)) {
            return true;
        }

        // URIで認証
        foreach($this->include_routes as $r) {
            if (strpos($uri, $r) === 0) {
                return true;
            }
        }

        return false;
    }
}
