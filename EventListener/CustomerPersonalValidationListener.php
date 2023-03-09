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
use Eccube\Repository\BaseInfoRepository;
use Eccube\Repository\CustomerRepository;
use Eccube\Request\Context;
use Plugin\TwoFactorAuthCustomer42\Repository\TwoFactorAuthTypeRepository;
use Plugin\TwoFactorAuthCustomer42\Service\CustomerTwoFactorAuthService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class CustomerPersonalValidationListener implements EventSubscriberInterface
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
        EntityManagerInterface $entityManager,
        EccubeConfig $eccubeConfig,
        Context $requestContext,
        UrlGeneratorInterface $router,
        CustomerTwoFactorAuthService $customerTwoFactorAuthService,
        BaseInfoRepository $baseInfoRepository,
        CustomerRepository $customerRepository,
        TwoFactorAuthTypeRepository $twoFactorAuthTypeRepository,
        SessionInterface $session
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
        ];
    }

    /**
     * リクエスト受信時イベントハンドラ.
     *
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

        if (
            ($this->baseInfo->isOptionCustomerActivate() && !$this->baseInfo->isOptionActivateDevice())
            ||
            !$this->baseInfo->isOptionCustomerActivate()
        ) {
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

        return;
    }

    /**
     * デバイス認証.
     *
     * @param mixed $event
     *
     * @throws NotFoundHttpException
     */
    private function deviceAuth($event)
    {
        // アクティベーション
        $secret_key = $event->getRequest()->attributes->get('secret_key');

        $Customer = $this->customerRepository->getProvisionalCustomerBySecretKey($secret_key);
        if (is_null($Customer)) {
            throw new NotFoundHttpException();
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
     * アクティベーションルートかチェック.
     *
     * @param string $route
     * @param string $uri
     *
     * @return bool
     */
    private function isActivationRoute(string $route): bool
    {
        // ルートで認証
        return $route === self::ACTIVATE_ROUTE;
    }
}
