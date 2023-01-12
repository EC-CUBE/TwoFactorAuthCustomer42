<?php

namespace Plugin\TwoFactorAuthCustomer42\Form\Type\Extension\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Eccube\Form\Type\Admin\CustomerType;
use Eccube\Form\Type\PhoneNumberType;
use Eccube\Form\Type\ToggleSwitchType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Plugin\TwoFactorAuthCustomer42\Entity\TwoFactorAuthType;

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
            ->add('two_factor_auth_type', EntityType::class, [
                'class' => TwoFactorAuthType::class,
                'required' => true,
                'choice_label' => 'name',
                'mapped' => true,
            ])
            ->add('device_authed', ToggleSwitchType::class, [
                'required' => false,
                'mapped' => true
            ])
            ->add('device_authed_phone_number', PhoneNumberType::class, [
                'required' => false,
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
