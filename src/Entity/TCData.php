<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Entity\TCHistory;

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
    private $id;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $alliance_name;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $tower_name;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\Alliance", inversedBy="tcData")
     */
    private $alliance;

    /**
     * @ORM\OneToOne(targetEntity="App\Entity\TCHistory", cascade={"persist", "remove"}, fetch="LAZY")
     */
    private $history;

    public function __construct()
    {
        $this->alliance_name = "";
        $this->tower_name = "";
        $this->alliance = null;
        $this->history = new TCHistory();
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

    public function getHistory(): ?TCHistory
    {
        return $this->history;
    }

    public function addToHistory($newEvent)
    {
        $this->history->addToHistory($newEvent);
    }
}