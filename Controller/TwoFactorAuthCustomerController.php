<?php

namespace Plugin\TwoFactorAuthCustomer42\Controller;

use Eccube\Controller\AbstractController;
use Eccube\Entity\Customer;
use Eccube\Repository\CustomerRepository;
use Plugin\TwoFactorAuthCustomer42\Form\Type\TwoFactorAuthConfigType;
use Plugin\TwoFactorAuthCustomer42\Form\Type\TwoFactorAuthTypeCustomer;
use Plugin\TwoFactorAuthCustomer42\Form\Type\TwoFactorAuthAppTypeCustomer;
use Plugin\TwoFactorAuthCustomer42\Form\Type\TwoFactorAuthSmsTypeCustomer;
use Plugin\TwoFactorAuthCustomer42\Form\Type\TwoFactorAuthPhoneNumberTypeCustomer;
use Plugin\TwoFactorAuthCustomer42\Service\CustomerTwoFactorAuthService;
use Plugin\TwoFactorAuthCustomer42\Entity\TwoFactorAuthType;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Encoder\EncoderFactoryInterface;


class TwoFactorAuthCustomerController extends AbstractController
{
    /**
     * @var TokenStorageInterface
     */
    protected $tokenStorage;

    /**
     * @var CustomerRepository
     */
    protected $customerRepository;

    /**
     * @var EncoderFactoryInterface
     */
    protected $encoderFactory;

    /**
     * @var CustomerTwoFactorAuthService
     */
    protected $customerTwoFactorAuthService;

    /**
     * @var \Twig_Environment
     */
    protected $twig;

    /**
     * TwoFactorAuthCustomerController constructor.
     * 
     * @param EncoderFactoryInterface $encoderFactory,
     * @param CustomerRepository $customerRepository,
     * @param TokenStorageInterface $tokenStorage,
     * @param CustomerTwoFactorAuthService $customerTwoFactorAuthService,
     * @param \Twig_Environment       $twig
     */
    public function __construct(
        EncoderFactoryInterface $encoderFactory,
        CustomerRepository $customerRepository,
        TokenStorageInterface $tokenStorage,
        CustomerTwoFactorAuthService $customerTwoFactorAuthService,
        \Twig_Environment $twig
        ) {
        $this->encoderFactory = $encoderFactory;
        $this->customerRepository = $customerRepository;
        $this->tokenStorage = $tokenStorage;
        $this->customerTwoFactorAuthService = $customerTwoFactorAuthService;
        $this->twig = $twig;
    }

    /**
     * (デバイス認証時)デバイス認証 送信先入力画面.
     * @Route("/two_factor_auth/device_auth/send_onetime/{secret_key}", name="plg_customer_2fa_device_auth_send_onetime", methods={"GET", "POST"})
     * @Template("TwoFactorAuthCustomer42/Resource/template/default/device_auth/send.twig")
     */
    public function deviceAuthSendOneTime(Request $request, $secret_key) 
    {
        if ($this->isDeviceAuthed()) {
            // 認証済みならばマイページへ
            return $this->redirectToRoute('mypage');
        }

        $error = null;
        /** @var Customer $Customer */
        $Customer = $this->customerRepository->getProvisionalCustomerBySecretKey($secret_key);
        $builder = $this->formFactory->createBuilder(TwoFactorAuthPhoneNumberTypeCustomer::class);
        $form = null;
        $auth_key = null;
        // 入力フォーム生成
        $form = $builder->getForm();
        if ('POST' === $request->getMethod()) {
            $form->handleRequest($request);
            $phoneNumber = $form->get('phone_number')->getData();
            if ($form->isSubmitted() && $form->isValid()) {
                // 他のデバイスで既に認証済みの電話番号かチェック
                if ($this->customerRepository->findBy(['device_authed_phone_number' => $phoneNumber]) == null) {
                    // 認証されていない電話番号の場合
                    // 入力電話番号へワンタイムコードを送信
                    $this->sendDeviceToken($Customer, $phoneNumber);
                    // 送信電話番号をセッションへ一時格納
                    $this->session->set(
                        CustomerTwoFactorAuthService::SESSION_AUTHED_PHONE_NUMBER, 
                        $phoneNumber
                    );
                } else {
                    log_warning('[デバイス認証(SMS)] 既に認証済みの電話番号指定');
                }
                $response = new RedirectResponse(
                    $this->generateUrl(
                        'plg_customer_2fa_device_auth_input_onetime', 
                        ['secret_key' => $secret_key]
                    )
                );
                return $response;
            } else {
                $error = trans('front.2fa.sms.send.failure_message');
            }
        }

        return [
            'form' => $form->createView(),
            'secret_key' => $secret_key,
            'Customer' => $Customer,
            'error' => $error,
        ];
    }

    /**
     * (デバイス認証時)デバイス認証ワンタイムトークン入力画面.
     * @Route("/two_factor_auth/device_auth/input_onetime/{secret_key}", name="plg_customer_2fa_device_auth_input_onetime", methods={"GET", "POST"})
     * @Template("TwoFactorAuthCustomer42/Resource/template/default/device_auth/input.twig")
     */
    public function deviceAuthInputOneTime(Request $request, $secret_key) 
    {
        if ($this->isDeviceAuthed()) {
            // 認証済みならばマイページへ
            return $this->redirectToRoute('mypage');
        }

        $error = null;
        /** @var Customer $Customer */
        $Customer = $this->customerRepository->getProvisionalCustomerBySecretKey($secret_key);
        $builder = $this->formFactory->createBuilder(TwoFactorAuthSmsTypeCustomer::class);
        $form = null;
        // 入力フォーム生成
        $form = $builder->getForm();
        if ('POST' === $request->getMethod()) {
            $form->handleRequest($request);
            $token = $form->get('one_time_token')->getData();
            if ($form->isSubmitted() && $form->isValid()) {
                if (!$this->checkDeviceToken($Customer, $token)) {
                    // ワンタイムトークン不一致 or 有効期限切れ
                    $error = trans('front.2fa.onetime.invalid_message__reinput');
                } else {
                    // 送信電話番号をセッションより取得
                    $phoneNumber = $this->session->get(CustomerTwoFactorAuthService::SESSION_AUTHED_PHONE_NUMBER);
                    // ワンタイムトークン一致
                    // デバイス認証完了
                    $Customer->setDeviceAuthed(true);
                    $Customer->setDeviceAuthedPhoneNumber($phoneNumber);
                    $Customer->setDeviceAuthOneTimeTokenExpire(null);
                    $this->entityManager->persist($Customer);
                    $this->entityManager->flush();
                    $this->session->remove(CustomerTwoFactorAuthService::SESSION_AUTHED_PHONE_NUMBER);

                    // アクティベーション実行
                    $response = new RedirectResponse(
                        $this->generateUrl(
                            'entry_activate',
                            ['secret_key' => $secret_key]
                        )
                    );
                    return $response;
                }
            } else {
                $error = trans('front.2fa.onetime.invalid_message__reinput');
            }
        }

        return [
            'form' => $form->createView(),
            'secret_key' => $secret_key,
            'Customer' => $Customer,
            'error' => $error,
        ];
    }

    /**
     * (ログイン時)二段階認証設定（選択）画面.
     * @Route("/two_factor_auth/select_type", name="plg_customer_2fa_auth_type_select", methods={"GET", "POST"})
     * @Template("TwoFactorAuthCustomer42/Resource/template/default/tfa/select_type.twig")
     */
    public function selectAuthType(Request $request) 
    {
        if ($this->isAuth()) {
            return $this->redirectToRoute($this->getCallbackRoute());
        }

        $error = null;
        /** @var Customer $Customer */
        $Customer = $this->getUser();
        $builder = $this->formFactory->createBuilder(TwoFactorAuthTypeCustomer::class);
        $form = null;
        $auth_key = null;
        // 入力フォーム生成
        $form = $builder->getForm();
        if ('POST' === $request->getMethod()) {
            $form = $builder->getForm();
            $form->handleRequest($request);
            $TwoFactorAuthType = $form->get('two_factor_auth_type')->getData();
            if ($form->isSubmitted() && $form->isValid()) {
                // 選択された2段階認証方式を更新
                $Customer->setTwoFactorAuthType($TwoFactorAuthType);
                // 2段階認証を有効に更新
                $Customer->setTwoFactorAuth(true);
                $this->entityManager->persist($Customer);
                $this->entityManager->flush();
                // 初回認証を実施
                return $this->redirectToRoute($TwoFactorAuthType->getRoute());
            } else {
                $error = trans('front.2fa.onetime.invalid_message__reinput');
            }
        }

        return [
            'form' => $form->createView(),
            'Customer' => $Customer,
            'error' => $error,
        ];
    }

    /**
     * デバイス認証済みか否か.
     * 
     * @return boolean
     */
    protected function isDeviceAuthed() 
    {
        /** @var Customer $Customer */
        $Customer = $this->getUser();
        if ($Customer != null && $Customer->isDeviceAuthed()) {
            return true;
        }
        return false;
    }

    /**
     * 認証済みか否か.
     * 
     * @return boolean
     */
    protected function isAuth() 
    {
        /** @var Customer $Customer */
        $Customer = $this->getUser();
        if (!$this->customerTwoFactorAuthService->isAuth($Customer, $this->getCallbackRoute())) {
            return false;
        }
        return true;
    }

    /**
     * コールバックルートの取得.
     * @return string
     */
    protected function getCallbackRoute():string
    {
        $route = $this->session->get(CustomerTwoFactorAuthService::SESSION_CALL_BACK_URL);
        return ($route != null) ? $route : 'mypage';
    }

    /**
     * デバイス認証用のワンタイムトークンを送信.
     * 
     * @param \Eccube\Entity\Customer $Customer
     * @param string $phoneNumber 
     * 
     */
    private function sendDeviceToken($Customer, $phoneNumber) 
    {
        // ワンタイムトークン生成・保存
        $token = $Customer->createDeviceAuthOneTimeToken();
        $this->entityManager->persist($Customer);
        $this->entityManager->flush();

        // ワンタイムトークン送信メッセージをレンダリング
        $twig = 'TwoFactorAuthCustomer42/Resource/template/default/sms/onetime_message.twig';
        $body = $this->twig->render($twig , [
            'Customer' => $Customer,
            'token' => $token,
        ]);

        // SMS送信
        return $this->customerTwoFactorAuthService->sendBySms($Customer, $phoneNumber, $body);
    }

    /**
     * デバイス認証用のワンタイムトークンチェック.
     * 
     * @return boolean
     */
    private function checkDeviceToken($Customer, $token)
    {
        $now = new \DateTime();
        if ($Customer->getDeviceAuthOneTimeToken() !== $token || $Customer->getDeviceAuthOneTimeTokenExpire() < $now) {
            return false;
        }
        return true;
    }
}
