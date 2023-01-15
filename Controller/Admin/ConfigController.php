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

namespace Plugin\TwoFactorAuthCustomer42\Controller\Admin;

use Eccube\Repository\BaseInfoRepository;
use Eccube\Controller\AbstractController;
use Plugin\TwoFactorAuthCustomer42\Form\Type\TwoFactorAuthConfigType;
use Plugin\TwoFactorAuthCustomer42\Repository\TwoFactorAuthConfigRepository;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class SmsController
 */
class ConfigController extends AbstractController
{
    /**
     * @var BaseInfoRepository
     */
    protected $baseInfoRepository;

    /**
     * @var TwoFactorAuthConfigRepository
     */
    private $smsConfigRepository;

    /**
     * ConfigController constructor.
     *
     */
    public function __construct(BaseInfoRepository $baseInfoRepository, TwoFactorAuthConfigRepository $smsConfigRepository)
    {
        $this->baseInfoRepository = $baseInfoRepository;
        $this->smsConfigRepository = $smsConfigRepository;
    }

    /**
     * @Route("/%eccube_admin_route%/two_factor_auth_customer42/config", name="two_factor_auth_customer42_admin_config")
     * @Template("TwoFactorAuthCustomer42/Resource/template/admin/config.twig")
     *
     * @param Request $request
     *
     * @return array
     */
     public function index(Request $request)
    {
        // 設定情報、フォーム情報を取得
        $SmsConfig = $this->smsConfigRepository->findOne();
        $form = $this->createForm(TwoFactorAuthConfigType::class, $SmsConfig);
        $form->handleRequest($request);

        // 設定画面で登録ボタンが押されたらこの処理を行う
        if ($form->isSubmitted() && $form->isValid()) {
            // フォームの入力データを取得
            $SmsConfig = $form->getData();

            // フォームの入力データを保存
            $this->entityManager->persist($SmsConfig);
            $this->entityManager->flush($SmsConfig);

            // 完了メッセージを表示
            log_info('config', ['status' => 'Success']);
            $this->addSuccess('プラグインの設定を保存しました。', 'admin');

            // 設定画面にリダイレクト
            return $this->redirectToRoute('two_factor_auth_customer42_admin_config');
        }

        return [
            'SmsConfig' => $SmsConfig,
            'form' => $form->createView(),
        ];

    }

}
