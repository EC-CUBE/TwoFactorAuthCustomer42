<?php

namespace Plugin\TwoFactorAuthCustomer42\Form\Type\Extension\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Eccube\Form\Type\Admin\CustomerType;
use Eccube\Form\Type\ToggleSwitchType;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class TwoFactorAuthCustomerTypeExtension extends AbstractTypeExtension
{
    /**
     * @var EntityManagerInterface
     */
    protected EntityManagerInterface $entityManager;

    /**
     * CouponDetailType constructor.
     *
     * @param EntityManagerInterface $entityManager
     */
    public function __construct(
        EntityManagerInterface $entityManager
    )
    {
        $this->entityManager = $entityManager;
    }

    /**
     * buildForm.
     *
     * @param FormBuilderInterface $builder
     * @param array                $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        if (!empty($options['skip_add_form'])) {
            return;
        }

        $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event) {
            $form = $event->getForm();
            $form->add('two_factor_auth', ToggleSwitchType::class, [
                'required' => false,
                'mapped' => true
            ])
            ->add('two_factor_auth_type', ChoiceType::class, [
                'required' => true,
                'choices' => [
                    'SMS' => 1,
                    'APP認証' => 2
                ],
            ])
            ->add('two_factor_auth_secret', TextType::class, [
                'required' => false,
                'mapped' => true
            ])
            ->add('device_authed', ToggleSwitchType::class, [
                'required' => false,
                'mapped' => true
            ])
            ;
        });
    }

    /**
     * {@inheritDoc}
     */
    public static function getExtendedTypes(): iterable
    {
        yield CustomerType::class;
    }
}
