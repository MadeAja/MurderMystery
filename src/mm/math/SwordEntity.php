<?php

namespace mm\math;

use pocketmine\entity\Entity;
use pocketmine\Player;
use pocketmine\network\mcpe\protocol\MobEquipmentPacket;
use pocketmine\item\Item;

class SwordEntity extends Entity{
    public const NETWORK_ID = self::ARMOR_STAND;

    public $width = 1.5;
    public $height = 1.5;
    
    protected function sendSpawnPacket(Player $player) : void{
        parent::sendSpawnPacket($player);
        $pk = new MobEquipmentPacket();
        $pk->entityRuntimeId = $this->getId();
        $pk->item = new Item(Item::IRON_SWORD);
        $pk->inventorySlot = 0;
        $pk->hotbarSlot = 0;
        $player->dataPacket($pk);
    }

    public function setPose() : void{
        $this->propertyManager->setInt(self::DATA_ARMOR_STAND_POSE_INDEX, 8);
    }
}