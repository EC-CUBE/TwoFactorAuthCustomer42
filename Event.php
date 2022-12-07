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

namespace Plugin\TwoFactorAuthCustomer42;

use Eccube\Entity\BaseInfo;
use Eccube\Repository\BaseInfoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Eccube\Event\EccubeEvents;
use Eccube\Event\EventArgs;
use Eccube\Event\TemplateEvent;
use Eccube\Request\Context;
use Plugin\TwoFactorAuthCustomer42\Repository\BaseTwoFactorAuthSettingRepository;
use Plugin\TwoFactorAuthCustomer42\Service\CustomerTwoFactorAuthService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Class Event.
 */
class Event implements EventSubscriberInterface
{
    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @var BaseInfo
     */
    protected $BaseInfo;

    /**
     * @var CustomerTwoFactorAuthService
     */
    protected $customerTwoFactorAuthService;

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * @var \Twig_Environment
     */
    private $twig;

    /**
     * @var NotifierInterface
     */
    //private $notifier;

    /**
     * Event constructor.
     *
     * @param ContainerInterface $container
     * @param BaseInfoRepository $baseInfoRepository
     * @param EntityManagerInterface $entityManager
     * @param CustomerTwoFactorAuthService $customerTwoFactorAuthService
     * @param \Twig_Environment $twig
     */
    public function __construct(
        ContainerInterface $container,
        BaseInfoRepository $baseInfoRepository,
        EntityManagerInterface $entityManager, 
        CustomerTwoFactorAuthService $customerTwoFactorAuthService,
        \Twig_Environment $twig
    )
    {
        $this->container = $container;
        $this->BaseInfo = $baseInfoRepository->get();
        $this->entityManager = $entityManager;
        $this->customerTwoFactorAuthService = $customerTwoFactorAuthService;
        $this->twig = $twig;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'Entry/activate.twig' => 'onActivateComplete',
            '@admin/Setting/Shop/shop_master.twig' => 'onRenderAdminShopSettingEdit',
            '@admin/Customer/edit.twig' => 'onRenderAdminCustomerEdit',
        ];
    }

    /**
     * [/default/Entry/activate.twig]表示の時のEvent Fork.
     * 会員登録完了画面を差し替える.
     *
     * @param TemplateEvent $event
     */
    public function onActivateComplete(TemplateEvent $event)
    {
        if ($this->BaseInfo->isOptionActivateSms()) {
            $templatePath = $this->container->getParameter('eccube_theme_front_dir')
                .'/TwoFactorAuthCustomer42/Resource/template/default';
            $source = file_get_contents($templatePath . '/device_auth/activate.twig');
            $event->setSource($source);
        }
    }

    /**
     * [/admin/setting/shop]表示の時のEvent Fork.
     * SMS関連項目を追加する.
     *
     * @param TemplateEvent $event
     */
    public function onRenderAdminShopSettingEdit(TemplateEvent $event)
    {
        // add twig
        $twig = 'TwoFactorAuthCustomer42/Resource/template/admin/shop_edit_sms.twig';
        $event->addSnippet($twig);

        // add twig
        $twig = 'TwoFactorAuthCustomer42/Resource/template/admin/shop_edit_tfa.twig';
        $event->addSnippet($twig);

        $activateFlg = $this->BaseInfo->isOptionCustomerActivate();
    }

    /**
     * [/admin/customer/edit]表示の時のEvent Fork.
     * 二段階認証関連項目を追加する.
     *
     * @param TemplateEvent $event
     */
    public function onRenderAdminCustomerEdit(TemplateEvent $event)
    {
        // add twig
        $twig = 'TwoFactorAuthCustomer42/Resource/template/admin/customer_edit.twig';
        $event->addSnippet($twig);
    }

    /**
     * 仮会員登録時のEvent Fork.
     *
     * @param EventArgs $event
     */
    public function onEntryComplete(EventArgs $event)
    {
        /*
        if ($this->BaseInfo->isOptionActivateSms() && $this->BaseInfo->isOptionCustomerActivate()) {
            $this->sendCustomerConfirmMessage($event['Customer']);
        }
        */
    }

    /**
     * 管理画面からの再送信時のEvent Fork.
     *
     * @param EventArgs $event
     */
    public function onResendComplete(EventArgs $event)
    {
        /*
        if ($this->BaseInfo->isOptionActivateSms() && $this->BaseInfo->isOptionCustomerActivate()) {
            $this->sendCustomerConfirmMessage($event['Customer']);
        }
        */
    }


    /**
     * 対象顧客へアクティベーションメッセージを送信.
     *
     * @param Eccube\Entity\Customer $Customer
     */
    private function sendCustomerConfirmMessage(\Eccube\Entity\Customer $Customer)
    {
        return $this->customerTwoFactorAuthService->sendActivationUrl($Customer);
    }

}
