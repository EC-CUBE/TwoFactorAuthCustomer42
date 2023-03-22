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

use Eccube\Event\TemplateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Class Event.
 */
class Event implements EventSubscriberInterface
{
    /**
     * Event constructor.
     *
     * @throws \Exception
     */
    public function __construct()
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            '@admin/Setting/Shop/shop_master.twig' => 'onRenderAdminShopSettingEdit',
            '@admin/Customer/edit.twig' => 'onRenderAdminCustomerEdit',
        ];
    }

    /**
     * [/admin/setting/shop]表示の時のEvent Hook.
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
    }

    /**
     * [/admin/customer/edit]表示の時のEvent Hook.
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
}
