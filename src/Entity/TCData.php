<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Entity\TowerChange;

/**
 * @ORM\Entity(repositoryClass="App\Repository\TCDataRepository")
 */
class TCData
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    protected $id;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    protected $alliance_name;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    protected $tower_name;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\Alliance", inversedBy="island")
     */
    protected $alliance;

    /**
     * @ORM\OneToOne(targetEntity="TowerChange", cascade={"persist", "remove"}, fetch="EXTRA_LAZY")
     */
    protected $history;

    public function __construct()
    {
        $this->alliance_name = "";
        $this->tower_name = "";
        $this->alliance = null;
        $this->history = new TowerChange();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAllianceName(): ?string
    {
        return $this->alliance_name;
    }

    public function setAllianceName(?string $alliance_name): self
    {
        $this->alliance_name = $alliance_name;

        return $this;
    }

    public function getTowerName(): ?string
    {
        return $this->tower_name;
    }

    public function setTowerName(?string $tower_name): self
    {
        $this->tower_name = $tower_name;

        return $this;
    }

    public function getAlliance(): ?Alliance
    {
        return $this->alliance;
    }

    public function setAlliance(?Alliance $alliance): self
    {
        $this->alliance = $alliance;

        return $this;
    }

    public function getHistory(): ?TowerChange
    {
        return $this->history;
    }

    public function addToHistory($newEvent)
    {
        $this->history->addToHistory($newEvent);
    }
}
