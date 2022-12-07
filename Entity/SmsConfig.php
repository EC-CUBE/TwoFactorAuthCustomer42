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

namespace Plugin\TwoFactorAuthCustomer42\Entity;

use Doctrine\ORM\Mapping as ORM;
use Eccube\Entity\AbstractEntity;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

/**
 * SmsConfig
 *
 * @ORM\Table(name="plg_sms_config")
 * @ORM\InheritanceType("SINGLE_TABLE")
 * @ORM\DiscriminatorColumn(name="discriminator_type", type="string", length=255)
 * @ORM\HasLifecycleCallbacks()
 * @ORM\Entity(repositoryClass="Plugin\TwoFactorAuthCustomer42\Repository\SmsConfigRepository")
 * @UniqueEntity("id")
 */
class SmsConfig extends AbstractEntity
{
    /**
     * @var int
     *
     * @ORM\Column(name="id", type="integer", options={"unsigned":true})
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     */
    private $id;

    /**
     * @var string
     *
     * @ORM\Column(name="api_key", type="string", nullable=true, length=200)
     */
    private $api_key = "ACae86d0224d3c0fbdb292bb7e6d467bcb";

    /**
     * @var string
     *
     * @ORM\Column(name="api_secret", type="string", nullable=true, length=200)
     */
    private $api_secret = "db93fbbc95e74c9c363043d28adf2fd3";

    /**
     * @var string
     *
     * @ORM\Column(name="from_tel", type="string", nullable=true, length=200)
     */
    private $from_tel = "18563862532";

    /**
     * Constructor.
     */
    public function __construct()
    {
    }

    /**
     * Get id.
     *
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set api_key.
     *
     * @param string $apiKey
     *
     * @return SmsConfig
     */
    public function setApiKey($apiKey)
    {
        $this->api_key = $apiKey;

        return $this;
    }

    /**
     * Get api_key.
     *
     * @return string
     */
    public function getApiKey()
    {
        return $this->api_key;
    }

    /**
     * Set api_secret.
     *
     * @param string $apiSecret
     *
     * @return SmsConfig
     */
    public function setApiSecret($apiSecret)
    {
        $this->api_secret = $apiSecret;

        return $this;
    }

    /**
     * Get api_secret.
     *
     * @return string
     */
    public function getApiSecret()
    {
        return $this->api_secret;
    }

    /**
     * Set from_tel.
     *
     * @param string $fromTel
     *
     * @return SmsConfig
     */
    public function setFromTel($fromTel)
    {
        $this->from_tel = $fromTel;

        return $this;
    }

    /**
     * Get from_tel.
     *
     * @return string
     */
    public function getFromTel()
    {
        return $this->from_tel;
    }

}
