<?php

namespace Plugin\TwoFactorAuthCustomer42\Entity;

use Doctrine\ORM\Mapping as ORM;
use Eccube\Annotation\EntityExtension;

/**
 * @EntityExtension("Eccube\Entity\Customer")
 */
trait CustomerTrait
{
    /**
     * @var boolean
     *
     * @ORM\Column(name="two_factor_auth", type="boolean", nullable=false, options={"default":false})
     */
    private bool $two_factor_auth = false;

    /**
     * 2段階認証機能の設定
     * @var int
     * @ORM\Column(name="two_factor_auth_type", type="integer", nullable=false, options={"default":1})
     */
    private int $two_factor_auth_type = 1;

    /**
     * @var ?string
     *
     * @ORM\Column(name="two_factor_auth_secret", type="string", length=255, nullable=true)
     */
    private ?string $two_factor_auth_secret;

    /**
     * @var ?string
     *
     * @ORM\Column(name="one_time_token", type="string", length=10, nullable=true)
     */
    private ?string $one_time_token;

    /**
     * @var \DateTime|null
     *
     * @ORM\Column(name="one_time_token_expire", type="datetimetz", nullable=true)
     */
    private $one_time_token_expire;

    /**
     * @var boolean
     *
     * @ORM\Column(name="device_authed", type="boolean", nullable=false, options={"default":false})
     */
    private bool $device_authed = false;

    /**
     * @var string|null
     *
     * @ORM\Column(name="authed_phone_number", type="string", length=14, nullable=true)
     */
    private $authed_phone_number;

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
     * @return int
     */
    public function getTwoFactorAuthType(): int
    {
        return $this->two_factor_auth_type;
    }

    /**
     * @param int $two_factor_auth_type
     */
    public function setTwoFactorAuthType(int $two_factor_auth_type): void
    {
        $this->two_factor_auth_type = $two_factor_auth_type;
    }

    /**
     * @return string
     */
    public function getTwoFactorAuthSecret(): ?string
    {
        return $this->two_factor_auth_secret;
    }

    /**
     * @param string $two_factor_auth_secret
     */
    public function setTwoFactorAuthSecret(?string $two_factor_auth_secret): void
    {
        $this->two_factor_auth_secret = $two_factor_auth_secret;
    }

    /**
     * @return string
     */
    public function createOneTimeToken(): ?string
    {
        $now = new \DateTime();

        if ($this->one_time_token_expire != null && $this->one_time_token_expire > $now) {
            return $this->getOneTimeToken();
        }

        // TODO: なんちゃって
        $token = '';
        for ($i = 0; $i < 6; $i++) {
            $token .= (string)rand(0, 9);
        }

        $this->setOneTimeToken($token);
        $this->setOneTimeTokenExpire($now->modify('+5 mins'));
        return $token;
    }

    /**
     * @return string
     */
    public function getOneTimeToken(): ?string
    {
        return $this->one_time_token;
    }

    /**
     * @param string $one_time_token
     */
    public function setOneTimeToken(?string $one_time_token): void
    {
        $this->one_time_token = $one_time_token;
    }

    /**
     * Set oneTimeTokenExpire.
     *
     * @param \DateTime|null $resetExpire
     *
     * @return Customer
     */
    public function setOneTimeTokenExpire($oneTimeTokenExpire = null)
    {
        $this->one_time_token_expire = $oneTimeTokenExpire;

        return $this;
    }

    /**
     * Get resetExpire.
     *
     * @return \DateTime|null
     */
    public function getOneTimeTokenExpire()
    {
        return $this->one_time_token_expire;
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
    public function getAuthedPhoneNumber(): ?string
    {
        return $this->authed_phone_number;
    }

    /**
     * @param string $authed_phone_number
     */
    public function setAuthedPhoneNumber(string $authed_phone_number): void
    {
        $this->authed_phone_number = $authed_phone_number;
    }

}
