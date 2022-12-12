<?php

namespace Plugin\TwoFactorAuthCustomer42\Controller;

use Eccube\Controller\AbstractController;
use Eccube\Entity\Customer;
use Eccube\Form\Type\Admin\TwoFactorAuthType;
use Eccube\Repository\CustomerRepository;
use Eccube\Service\TwoFactorAuthService;
use Plugin\TwoFactorAuthCustomer42\Form\Type\TwoFactorAuthTypeCustomer;
use Plugin\TwoFactorAuthCustomer42\Form\Type\TwoFactorAuthAppTypeCustomer;
use Plugin\TwoFactorAuthCustomer42\Form\Type\TwoFactorAuthSmsTypeCustomer;
use Plugin\TwoFactorAuthCustomer42\Form\Type\TwoFactorAuthPhoneNumberTypeCustomer;
use Plugin\TwoFactorAuthCustomer42\Service\CustomerTwoFactorAuthService;
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
     * TwoFactorAuthCustomerController constructor.
     * 
     * @param EncoderFactoryInterface $encoderFactory,
     * @param CustomerRepository $customerRepository,
     * @param TokenStorageInterface $tokenStorage,
     * @param CustomerTwoFactorAuthService $customerTwoFactorAuthService,
     * 
     */
    public function __construct(
        EncoderFactoryInterface $encoderFactory,
        CustomerRepository $customerRepository,
        TokenStorageInterface $tokenStorage,
        CustomerTwoFactorAuthService $customerTwoFactorAuthService
    ) {
        $this->encoderFactory = $encoderFactory;
        $this->customerRepository = $customerRepository;
        $this->tokenStorage = $tokenStorage;
        $this->customerTwoFactorAuthService = $customerTwoFactorAuthService;
    }

    /**
     * (デバイス認証時)デバイス認証 送信先入力画面.
     * @Route("/mypage/two_factor_auth/device_auth/send_onetime", name="plg_customer_2fa_device_auth_send_onetime", methods={"GET", "POST"})
     * @Template("TwoFactorAuthCustomer42/Resource/template/default/device_auth/send_onetime.twig")
     */
    public function deviceAuthSendOneTime(Request $request) 
    {
        if ($this->isDeviceAuthed()) {
            // 認証済みならばマイページへ
            return $this->redirectToRoute('mypage');
        }

        $error = null;
        /** @var Customer $Customer */
        $Customer = $this->getUser();
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
                if ($this->customerRepository->findBy(['authed_phone_number' => $phoneNumber]) == null) {
                    // 認証されていない電話番号の場合
                    // 入力電話番号へワンタイムコードを送信
                    $this->customerTwoFactorAuthService->sendOnetimeToken($Customer, $phoneNumber);
                    // 送信電話番号をセッションへ一時格納
                    $this->session->set(CustomerTwoFactorAuthService::SESSION_AUTHED_PHONE_NUMBER, $phoneNumber);
                }
                $response = new RedirectResponse($this->generateUrl('plg_customer_2fa_device_auth_input_onetime'));
                return $response;
            } else {
                $error = trans('front.2fa.sms.send.failure_message');
            }
        }

        return [
            'form' => $form->createView(),
            'Customer' => $Customer,
            'error' => $error,
        ];
    }

    /**
     * (デバイス認証時)デバイス認証ワンタイムトークン入力画面.
     * @Route("/mypage/two_factor_auth/device_auth/input_onetime", name="plg_customer_2fa_device_auth_input_onetime", methods={"GET", "POST"})
     * @Template("TwoFactorAuthCustomer42/Resource/template/default/device_auth/input_onetime.twig")
     */
    public function deviceAuthInputOneTime(Request $request) 
    {
        if ($this->isDeviceAuthed()) {
            // 認証済みならばマイページへ
            return $this->redirectToRoute('mypage');
        }

        $error = null;
        /** @var Customer $Customer */
        $Customer = $this->getUser();
        $builder = $this->formFactory->createBuilder(TwoFactorAuthSmsTypeCustomer::class);
        $form = null;
        // 入力フォーム生成
        $form = $builder->getForm();
        if ('POST' === $request->getMethod()) {
            $form->handleRequest($request);
            $device_token = $form->get('device_token')->getData();
            if ($form->isSubmitted() && $form->isValid()) {
                if (!$this->customerTwoFactorAuthService->checkOneTime($Customer, $device_token)) {
                    // ワンタイムトークン不一致 or 有効期限切れ
                    $error = trans('front.2fa.onetime.invalid_message__reinput');
                } else {
                    // 送信電話番号をセッションより取得
                    $phoneNumber = $this->session->get(CustomerTwoFactorAuthService::SESSION_AUTHED_PHONE_NUMBER);
                    // ワンタイムトークン一致
                    // デバイス認証完了
                    $Customer->setDeviceAuthed(true);
                    $Customer->setAuthedPhoneNumber($phoneNumber);
                    $Customer->setOneTimeTokenExpire(null);
                    $this->entityManager->persist($Customer);
                    $this->entityManager->flush();
                    $this->session->remove(CustomerTwoFactorAuthService::SESSION_AUTHED_PHONE_NUMBER);

                    // 会員認証完了画面表示
                    $response = new RedirectResponse($this->generateUrl('plg_customer_2fa_device_auth_complete'));
                    return $response;
                }
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
     * (デバイス認証時)デバイス認証 完了画面.
     * @Route("/mypage/two_factor_auth/device_auth/complete", name="plg_customer_2fa_device_auth_complete", methods={"GET", "POST"})
     * @Template("TwoFactorAuthCustomer42/Resource/template/default/device_auth/complete.twig")
     */
    public function deviceAuthComplete(Request $request) 
    {
        // TODO: どうするか？
        return [
            'qtyInCart' => null,
        ];
    }

    /**
     * (ログイン時)二段階認証設定（選択）画面.
     * @Route("/mypage/two_factor_auth/select_type", name="plg_customer_2fa_auth_type_select", methods={"GET", "POST"})
     * @Template("TwoFactorAuthCustomer42/Resource/template/default/tfa/select_type.twig")
     */
    public function tfaSelectAuthType(Request $request) 
    {
        if ($this->isAuth()) {
            // 認証済みならばマイページへ
            return $this->redirectToRoute('mypage');
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
            $two_factor_auth_type = $form->get('two_factor_auth_type')->getData();
            if ($form->isSubmitted() && $form->isValid()) {
                // 選択された2段階認証方式を更新
                $Customer->setTwoFactorAuthType($two_factor_auth_type);
                // 2段階認証を有効に更新
                $Customer->setTwoFactorAuth(true);
                $this->entityManager->persist($Customer);
                $this->entityManager->flush();

                // 初回認証を実施
                return $this->redirectToRoute('mypage');
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
     * (ログイン時)SMS認証 送信先入力画面.
     * @Route("/mypage/two_factor_auth/tfa/sms/send_onetime", name="plg_customer_2fa_sms_send_onetime", methods={"GET", "POST"})
     * @Template("TwoFactorAuthCustomer42/Resource/template/default/tfa/sms/send_onetime.twig")
     */
    public function tfaSmsSendOneTime(Request $request) 
    {
        if ($this->isAuth()) {
            // 認証済みならばマイページへ
            return $this->redirectToRoute('mypage');
        }

        $error = null;
        /** @var Customer $Customer */
        $Customer = $this->getUser();
        $builder = $this->formFactory->createBuilder(TwoFactorAuthPhoneNumberTypeCustomer::class);
        $form = null;
        $auth_key = null;
        // 入力フォーム生成
        $form = $builder->getForm();
        if ('POST' === $request->getMethod()) {
            $form->handleRequest($request);
            $phoneNumber = $form->get('phone_number')->getData();
            if ($form->isSubmitted() && $form->isValid()) {
                // 入力された電話番号へワンタイムコードを送信
                $this->customerTwoFactorAuthService->sendOnetimeToken($Customer, $phoneNumber);
                $response = new RedirectResponse($this->generateUrl('plg_customer_2fa_sms_input_onetime'));
                return $response;
            } else {
                $error = trans('front.2fa.sms.send.failure_message');
            }
        }

        return [
            'form' => $form->createView(),
            'Customer' => $Customer,
            'error' => $error,
        ];
    }

    /**
     * (ログイン時)SMS認証 ワンタイムトークン入力画面.
     * @Route("/mypage/two_factor_auth/tfa/sms/input_onetime", name="plg_customer_2fa_sms_input_onetime", methods={"GET", "POST"})
     * @Template("TwoFactorAuthCustomer42/Resource/template/default/tfa/sms/input_onetime.twig")
     */
    public function tfaSmsInputOneTime(Request $request) 
    {
        if ($this->isAuth()) {
            // 認証済みならばマイページへ
            return $this->redirectToRoute('mypage');
        }

        $error = null;
        /** @var Customer $Customer */
        $Customer = $this->getUser();
        $builder = $this->formFactory->createBuilder(TwoFactorAuthSmsTypeCustomer::class);
        $form = null;
        $auth_key = null;
        // 入力フォーム生成
        $form = $builder->getForm();
        if ('POST' === $request->getMethod()) {
            $form->handleRequest($request);
            $device_token = $form->get('device_token')->getData();
            if ($form->isSubmitted() && $form->isValid()) {
                if (!$this->customerTwoFactorAuthService->checkOneTime($Customer, $device_token)) {
                    // ワンタイムトークン不一致 or 有効期限切れ
                    $error = trans('front.2fa.onetime.invalid_message__reinput');
                } else {
                    // ワンタイムトークン一致
                    // 二段階認証完了
                    $Customer->setTwoFactorAuth(true);
                    $Customer->setOneTimeToken(null);
                    $Customer->setOneTimeTokenExpire(null);
                    $this->entityManager->persist($Customer);
                    $this->entityManager->flush();

                    $response = new RedirectResponse($this->generateUrl($this->getCallbackRoute()));
                    $response->headers->setCookie($this->customerTwoFactorAuthService->createAuthedCookie($Customer));
                    return $response;
                }
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
     * (ログイン時)初回APP認証画面.
     * @Route("/mypage/two_factor_auth/app/create", name="plg_customer_2fa_app_create", methods={"GET", "POST"})
     * @Template("TwoFactorAuthCustomer42/Resource/template/default/tfa/app/register.twig")
     */
    public function tfaAppcreate(Request $request) 
    {
        if ($this->isAuth()) {
            // 認証済みならばマイページへ
            return $this->redirectToRoute('mypage');
        }

        $error = null;
        /** @var Customer $Customer */
        $Customer = $this->getUser();
        $builder = $this->formFactory->createBuilder(TwoFactorAuthAppTypeCustomer::class);
        $form = null;
        $auth_key = null;

        if ('GET' === $request->getMethod()) {
            if ($Customer->getTwoFactorAuthSecret()) {
                // 既に二段階認証(APP)設定済みの場合
                $this->addWarning('front.2fa.configured_warning');
            }
            $auth_key = $this->customerTwoFactorAuthService->createSecret();
            $builder->get('auth_key')->setData($auth_key);
            $form = $builder->getForm();
        } elseif ('POST' === $request->getMethod()) {
            $form = $builder->getForm();
            $form->handleRequest($request);
            $auth_key = $form->get('auth_key')->getData();
            $device_token = $form->get('device_token')->getData();
            if ($form->isSubmitted() && $form->isValid()) {
                if ($this->customerTwoFactorAuthService->verifyCode($auth_key, $device_token, 2)) {
                    $Customer->setTwoFactorAuthSecret($auth_key);
                    $this->entityManager->persist($Customer);
                    $this->entityManager->flush();
                    $this->addSuccess('front.2fa.complete_message');

                    $response = new RedirectResponse($this->generateUrl($this->getCallbackRoute()));
                    $response->headers->setCookie($this->customerTwoFactorAuthService->createAuthedCookie($Customer));
                    return $response;
                } else {
                    $error = trans('front.2fa.invalid_message__reinput');
                }
            } else {
                $error = trans('front.2fa.invalid_message__reinput');
            }
        }

        return [
            'form' => $form->createView(),
            'Customer' => $Customer,
            'auth_key' => $auth_key,
            'error' => $error,
        ];
    }

    /**
     * (ログイン時)APP認証画面.
     * @Route("/mypage/two_factor_auth/app/challenge", name="plg_customer_2fa_app_challenge", methods={"GET", "POST"})
     * @Template("TwoFactorAuthCustomer42/Resource/template/default/tfa/app/challenge.twig")
     */
    public function tfaAppchallenge(Request $request) 
    {
        if ($this->isAuth()) {
            // 認証済みならばマイページへ
            return $this->redirectToRoute('mypage');
        }

        $error = null;
        /** @var Customer $Customer */
        $Customer = $this->getUser();
        $builder = $this->formFactory->createBuilder(TwoFactorAuthType::class);
        $builder->remove('auth_key');
        $form = $builder->getForm();

        if ('POST' === $request->getMethod()) {
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                if ($Customer->getTwoFactorAuthSecret()) {
                    if ($this->customerTwoFactorAuthService->verifyCode($Customer->getTwoFactorAuthSecret(), $form->get('device_token')->getData())) {
                        $response = new RedirectResponse($this->generateUrl($this->getCallbackRoute()));
                        $response->headers->setCookie($this->customerTwoFactorAuthService->createAuthedCookie($Customer));
                        return $response;
                    } else {
                        $error = trans('front.2fa.invalid_message__reinput');
                    }
                } else {
                    return $this->redirectToRoute('plg_customer_2fa_create');
                }
            } else {
                $error = trans('front.2fa.invalid_message__reinput');
            }
        }

        return [
            'form' => $form->createView(),
            'error' => $error,
        ];
    }

    /**
     * デバイス認証済みか否か.
     * 
     * @return boolean
     */
    private function isDeviceAuthed() 
    {
        /** @var Customer $Customer */
        $Customer = $this->getUser();
        if ($Customer->isDeviceAuthed()) {
            return true;
        }
        return false;
    }

    /**
     * 認証済みか否か.
     * 
     * @return boolean
     */
    private function isAuth() 
    {
        /** @var Customer $Customer */
        $Customer = $this->getUser();
        if ($this->customerTwoFactorAuthService->isAuth($Customer)) {
            return true;
        }
        return false;
    }

    /**
     * コールバックルートの取得.
     * @return string
     */
    private function getCallbackRoute():string
    {
        $route = $this->session->get(CustomerTwoFactorAuthService::SESSION_CALL_BACK_URL);
        return ($route != null) ? $route : 'mypage';
    }
}
