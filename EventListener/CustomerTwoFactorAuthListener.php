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
use Eccube\Repository\CustomerRepository;
use Eccube\Request\Context;
use Plugin\TwoFactorAuthCustomer42\Service\CustomerTwoFactorAuthService;
use Plugin\TwoFactorAuthCustomer42\Repository\TwoFactorAuthTypeRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Exception as HttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

class CustomerTwoFactorAuthListener implements EventSubscriberInterface
{

    /**
     * アクティベーション
     */
    public const ACTIVATE_ROUTE = 'entry_activate';

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
     * @var CustomerRepository
     */
    protected CustomerRepository $customerRepository;

    /**
     * @var TwoFactorAuthTypeRepository
     */
    protected TwoFactorAuthTypeRepository $twoFactorAuthTypeRepository;

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
     * @param CustomerRepository $customerRepository
     * @param TwoFactorAuthTypeRepository $twoFactorAuthTypeRepository
     * @param SessionInterface $session
     */
    public function __construct(
        EntityManagerInterface       $entityManager,
        EccubeConfig                 $eccubeConfig,
        Context                      $requestContext,
        UrlGeneratorInterface        $router,
        CustomerTwoFactorAuthService $customerTwoFactorAuthService,
        BaseInfoRepository           $baseInfoRepository,
        CustomerRepository           $customerRepository,
        TwoFactorAuthTypeRepository  $twoFactorAuthTypeRepository,
        SessionInterface             $session
    ) {
        $this->entityManager = $entityManager;
        $this->eccubeConfig = $eccubeConfig;
        $this->requestContext = $requestContext;
        $this->router = $router;
        $this->customerTwoFactorAuthService = $customerTwoFactorAuthService;
        $this->baseInfoRepository = $baseInfoRepository;
        $this->baseInfo = $this->baseInfoRepository->find(1);
        $this->customerRepository = $customerRepository;
        $this->twoFactorAuthTypeRepository = $twoFactorAuthTypeRepository;
        $this->session = $session;

        $this->include_routes = $this->customerTwoFactorAuthService->getIncludeRoutes();
        $this->exclude_routes = $this->customerTwoFactorAuthService->getExcludeRoutes();
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER_ARGUMENTS => ['onKernelController', 7],
            LoginSuccessEvent::class => ['onLoginSuccess']
        ];
    }

    /**
     * リクエスト受信時イベントハンドラ.
     * @param ControllerArgumentsEvent $event
     */
    public function onKernelController(ControllerArgumentsEvent $event)
    {
        if (!$event->isMainRequest()) {
            // サブリクエストの場合、処理なし
            return;
        }

        if ($this->requestContext->isAdmin()) {
            // バックエンドURLの場合、処理なし
            return;
        }

        if (($this->baseInfo->isOptionCustomerActivate() && !$this->baseInfo->isOptionActivateDevice())
            && !$this->baseInfo->isTwoFactorAuthUse()) {
            // デバイス認証なし かつ 2段階認証使用しない場合は処理なし
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');
        $uri = $event->getRequest()->getRequestUri();

        if ($this->isActivationRoute($route)) {
            // デバイス認証（アクティベーション前に介入）
            $this->deviceAuth($event);
            return;
        }

        $Customer = $this->requestContext->getCurrentUser();

        if ($Customer && $Customer instanceof Customer) {

            if ($Customer->getStatus()->getId() !== CustomerStatus::ACTIVE) {
                // ログインしていない場合、処理なし
                return;
            }

            if (!$this->isIncludeRoute($route, $uri)) {
                // 重要操作指定ではなく、マイページ系列ではない場合、処理なし
                return;
            }

            $this->multifactorAuth($event, $Customer, $route, $uri);
        }

        return;
    }

    /**
     * ログイン完了 イベントハンドラ.
     * @param LoginSuccessEvent $event
     */
    public function onLoginSuccess(LoginSuccessEvent $event)
    {
        if ($this->requestContext->isAdmin()) {
            // バックエンドURLの場合処理なし
            return;
        }

        if (!$this->baseInfo->isTwoFactorAuthUse()) {
            // 2段階認証使用しない場合は処理なし
            return;
        }

        $this->multifactorAuth(
            $event,
            $this->requestContext->getCurrentUser(),
            $event->getRequest()->attributes->get('_route'),
            $event->getRequest()->getRequestUri());

        return;
    }

    /**
     * デバイス認証.
     * @param mixed $event
     * @throws HttpException\NotFoundHttpException
     */
    private function deviceAuth($event)
    {
        // アクティベーション
        $secret_key = $event->getRequest()->attributes->get('secret_key');
        $Customer = $this->customerRepository->getProvisionalCustomerBySecretKey($secret_key);
        if (is_null($Customer)) {
            throw new HttpException\NotFoundHttpException();
        }

        if ($Customer->isDeviceAuthed()) {
            return;
        }

        // デバイス認証されていない場合
        if ($this->baseInfo->isOptionActivateDevice() && $this->baseInfo->isOptionCustomerActivate()) {
            // 仮会員登録機能:有効 / SMSによる本人認証:有効の場合　デバイス認証画面へリダイレクト
            $url = $this->router->generate(
                'plg_customer_2fa_device_auth_send_onetime',
                ['secret_key' => $secret_key],
                UrlGeneratorInterface::ABSOLUTE_PATH
            );

            $event->setController(function () use ($url) {
                return new RedirectResponse($url, $status = 302);
            });
        }

        return;
    }

    /**
     * 多要素認証.
     * @param mixed $event
     * @throws HttpException\NotFoundHttpException
     * @return mixed
     */
    private function multifactorAuth($event, $Customer, $route, $uri)
    {
        if (!$this->baseInfo->isTwoFactorAuthUse()) {
            // MFA無効の場合処理なし
            return;
        }

        // [会員] ログイン時2段階認証状態
        $is_auth = $this->customerTwoFactorAuthService->isAuthed($Customer, $route);

        if (!$is_auth) {
            log_info('[2段階認証] 実施');
            if (!$Customer->isTwoFactorAuth()) {
                // 2段階認証未設定
                $this->selectAuthType($event, $Customer, $route);
            } else {
                // 2段階認証設定済み
                $this->auth($event, $Customer, $route);
            }
        }

        return;
    }

    /**
     * 多要素認証方式設定画面へリダイレクト.
     * @param Event $event
     * @param Customer $Customer
     * @param string|null $route
     */
    private function selectAuthType($event, Customer $Customer, ?string $route)
    {
        // [会員] 2段階認証が未設定の場合
        // コールバックURLをセッションへ設定
        $this->setCallbackRoute($route);
        // 2段階認証選択画面へリダイレクト
        $url = $this->router->generate('plg_customer_2fa_auth_type_select', [], UrlGeneratorInterface::ABSOLUTE_PATH);

        if ($event instanceof ControllerArgumentsEvent) {
            $event->setController(function () use ($url) {
                return new RedirectResponse($url, 302);
            });
        } else {
            $event->setResponse(new RedirectResponse($url, 302));
        }
        return;
    }

    /**
     * 2段階認証のディスパッチ.
     *
     * @param Event $event
     * @param Customer $Customer
     * @param string|null $route
     */
    private function auth($event, Customer $Customer, ?string $route)
    {
        // コールバックURLをセッションへ設定
        $this->setCallbackRoute($route);
        // 選択された多要素認証方式で指定されているルートへリダイレクト
        $url = $this->router->generate(
            $Customer->getTwoFactorAuthType()->getRoute(),
            [],
            UrlGeneratorInterface::ABSOLUTE_PATH);

        if ($event instanceof ControllerArgumentsEvent) {
            $event->setController(function () use ($url) {
                return new RedirectResponse($url, 302);
            });
        } else {
            $event->setResponse(new RedirectResponse($url, 302));
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
     * アクティベーションルートかチェック.
     *
     * @param string $route
     * @param string $uri
     * @return bool
     */
    private function isActivationRoute(string $route): bool
    {
        // ルートで認証
        return $route === self::ACTIVATE_ROUTE;
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
            if ($r != '' && $r !== '/' && strpos($uri, $r) === 0) {
                return true;
            }
        }

        return false;
    }

}
