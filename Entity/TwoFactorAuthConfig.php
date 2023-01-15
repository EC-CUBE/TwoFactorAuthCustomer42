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
 * TwoFactorConfig
 *
 * @ORM\Table(name="plg_two_factor_auth_config")
 * @ORM\InheritanceType("SINGLE_TABLE")
 * @ORM\DiscriminatorColumn(name="discriminator_type", type="string", length=255)
 * @ORM\HasLifecycleCallbacks()
 * @ORM\Entity(repositoryClass="Plugin\TwoFactorAuthCustomer42\Repository\TwoFactorAuthConfigRepository")
 * @UniqueEntity("id")
 */
class TwoFactorAuthConfig extends AbstractEntity
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
    private $api_key = null;

    /**
     * @var string
     *
     * @ORM\Column(name="api_secret", type="string", nullable=true, length=200)
     */
    private $api_secret = null;

    /**
     * @var string
     *
     * @ORM\Column(name="from_tel", type="string", nullable=true, length=200)
     */
    private $from_tel = null;

    /**
     * @var string
     *
     * @ORM\Column(name="include_route", type="text", nullable=true)
     */
    private $include_route = null;

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
     * @return TwoFactorAuthConfig
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
     * @return TwoFactorAuthConfig
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
     * @return TwoFactorAuthConfig
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

    /**
     * Set include_route.
     *
     * @param string|null $include_route
     *
     * @return TwoFactorAuthConfig
     */
    public function setIncludeRoute($include_route = null)
    {
        $this->include_route = $include_route;

        return $this;
    }

    /**
     * Get include_route.
     *
     * @return string|null
     */
    public function getIncludeRoute()
    {
        return $this->include_route;
    }

    // TODO
    public function addIncludeRoute(string $route)
    {
        $routes = $this->getRoutes($this->getIncludeRoute());

        if (!in_array($route, $routes)) {
            $this->setIncludeRoute($this->include_route . PHP_EOL . $route);
        }

        return $this;
    }

    public function removeIncludeRoute(string $route)
    {
        $routes = $this->getRoutes($this->getIncludeRoute());

        if (in_array($route, $routes)) {
            $routes = array_splice(array_search($route, $routes, true));
            $this->setIncludeRoute($this->getRoutesAsString($routes));
        }

        return $this;
    }

    private function getRoutes(?string $routes): array
    {
        if (!$routes) {
            return [];
        }
        return explode(PHP_EOL, $routes);
    }

    private function getRoutesAsString(array $routes): string
    {
        return implode(PHP_EOL, $routes);
    }

}
