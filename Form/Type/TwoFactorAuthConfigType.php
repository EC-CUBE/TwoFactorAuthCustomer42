<?php
namespace Plugin\TwoFactorAuthCustomer42\Form\Type;

use Eccube\Common\EccubeConfig;
use Plugin\TwoFactorAuthCustomer42\Entity\TwoFactorAuthConfig;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TwoFactorAuthConfigType extends AbstractType
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
     * TwoFactorAuthConfigType constructor.
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
          ])
          ->add('include_route', TextareaType::class, [
            'required' => false,
            'constraints' => [
              new Assert\Length([
                  'max' => $this->eccubeConfig['eccube_ltext_len'],
              ]),
            ],
          ])
          ;
      }

    /**
     * {@inheritDoc}
     * @see \Symfony\Component\Form\AbstractType::configureOptions()
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => TwoFactorAuthConfig::class,
        ]);
    }

}