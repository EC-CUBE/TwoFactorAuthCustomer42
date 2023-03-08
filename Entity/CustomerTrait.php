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
use Eccube\Annotation\EntityExtension;
use Plugin\TwoFactorAuthCustomer42\Entity\TwoFactorAuthType;

/**
 * @EntityExtension("Eccube\Entity\Customer")
 */
trait CustomerTrait
{
    /**
     * @var ?string
     *
     * @ORM\Column(name="device_auth_one_time_token", type="string", length=10, nullable=true)
     */
    private ?string $device_auth_one_time_token;

    /**
     * @var \DateTime|null
     *
     * @ORM\Column(name="device_auth_one_time_token_expire", type="datetimetz", nullable=true)
     */
    private $device_auth_one_time_token_expire;

    /**
     * @var boolean
     *
     * @ORM\Column(name="device_authed", type="boolean", nullable=false, options={"default":false})
     */
    private bool $device_authed = false;

    /**
     * @var string|null
     *
     * @ORM\Column(name="device_authed_phone_number", type="string", length=14, nullable=true)
     */
    private ?string $device_authed_phone_number;

    /**
     * @var boolean
     *
     * @ORM\Column(name="two_factor_auth", type="boolean", nullable=false, options={"default":false})
     */
    private bool $two_factor_auth = false;

    /**
     * TODO: 2FATypeへ
     * 2段階認証機能の設定
     *
     * @var int
     *
     * @ORM\Column(name="two_factor_auth_type", type="integer", nullable=true)
     */
    private ?int $two_factor_auth_type;

    /**
     * @var TwoFactorAuthType
     *
     * @ORM\ManyToOne(targetEntity="\Plugin\TwoFactorAuthCustomer42\Entity\TwoFactorAuthType")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="two_factor_auth_type_id", referencedColumnName="id")
     * })
     */
    private $TwoFactorAuthType;

    /**
     * @return string
     */
    public function createDeviceAuthOneTimeToken(): ?string
    {
        $now = new \DateTime();

        $token = '';
        for ($i = 0; $i < 6; $i++) {
            $token .= (string) random_int(0, 9);
        }

        $this->setDeviceAuthOneTimeToken($token);
        $this->setDeviceAuthOneTimeTokenExpire($now->modify('+5 mins'));

        return $token;
    }

    /**
     * @return string
     */
    public function getDeviceAuthOneTimeToken(): ?string
    {
        return $this->device_auth_one_time_token;
    }

    /**
     * @param string $device_auth_one_time_token
     */
    public function setDeviceAuthOneTimeToken(?string $device_auth_one_time_token): void
    {
        $this->device_auth_one_time_token = $device_auth_one_time_token;
    }

    /**
     * Set oneTimeTokenExpire.
     *
     * @param \DateTime|null $resetExpire
     *
     * @return Customer
     */
    public function setDeviceAuthOneTimeTokenExpire($deviceAuthOneTimeTokenExpire = null)
    {
        $this->device_auth_one_time_token_expire = $deviceAuthOneTimeTokenExpire;

        return $this;
    }

    /**
     * Get resetExpire.
     *
     * @return \DateTime|null
     */
    public function getDeviceAuthOneTimeTokenExpire()
    {
        return $this->device_auth_one_time_token_expire;
    }

    /**
     * @return bool
     */
    public function isDeviceAuthed(): bool
    {
        return $this->device_authed;
    }

    /**
     * @param bool $two_factor_auth
     */
    public function setDeviceAuthed(bool $device_authed): void
    {
        $this->device_authed = $device_authed;
    }

    /**
     * @return string
     */
    public function getDeviceAuthedPhoneNumber(): ?string
    {
        return $this->device_authed_phone_number;
    }

    /**
     * @param string|null $device_authed_phone_number
     */
    public function setDeviceAuthedPhoneNumber(?string $device_authed_phone_number): void
    {
        $this->device_authed_phone_number = $device_authed_phone_number;
    }

    /**
     * @return bool
     */
    public function isTwoFactorAuth(): bool
    {
        return $this->two_factor_auth;
    }

    /**
     * @param bool $two_factor_auth
     */
    public function setTwoFactorAuth(bool $two_factor_auth): void
    {
        $this->two_factor_auth = $two_factor_auth;
    }

    /**
     * Set two factor auth type.
     *
     * @param TwoFactorAuthType|null $sex
     *
     * @return Customer
     */
    public function setTwoFactorAuthType(TwoFactorAuthType $twoFactorAuthType = null)
    {
        $this->TwoFactorAuthType = $twoFactorAuthType;

        return $this;
    }

    /**
     * Get sex.
     *
     * @return TwoFactorAuthType|null
     */
    public function getTwoFactorAuthType()
    {
        return $this->TwoFactorAuthType;
    }
}
