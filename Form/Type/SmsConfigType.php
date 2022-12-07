<?php
namespace Plugin\TwoFactorAuthCustomer42\Form\Type;

use Eccube\Common\EccubeConfig;
use Plugin\TwoFactorAuthCustomer42\Entity\SmsConfig;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SmsConfigType extends AbstractType
{
    /**
     * @var EccubeConfig
     */
    protected $eccubeConfig;

    /**
    * @var ContainerInterface
    */
    protected $containerInterface;

    /**
     * SmsConfigType constructor.
     *
     * @param EccubeConfig $eccubeConfig
     */
    public function __construct(EccubeConfig $eccubeConfig)
    {
        $this->eccubeConfig = $eccubeConfig;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {

        $builder
          ->add('api_key', TextType::class, [
            'required' => true,
            'constraints' => [
                  new Assert\NotBlank(),
              ],
          ])
          ->add('api_secret', TextType::class, [
            'required' => true,
            'constraints' => [
                new Assert\NotBlank(),
            ],
          ])
          ->add('from_tel', TextType::class, [
            'required' => true,
            'constraints' => [
                new Assert\NotBlank(),
            ],
          ]);
      }

    /**
     * {@inheritDoc}
     * @see \Symfony\Component\Form\AbstractType::configureOptions()
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => SmsConfig::class,
        ]);
    }

}