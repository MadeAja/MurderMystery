<?php

namespace mm\tasks;

use pocketmine\scheduler\Task;
use mm\arena\Arena;
use pocketmine\level\Position;
use pocketmine\Player;

class UpdatePlayerPositionTask extends Task{

    public function __construct(Arena $plugin){
        $this->plugin = $plugin;
    }

    public function onRun(int $ct){
        foreach($this->plugin->players as $player){
            if($player === $this->plugin->getMurderer()){
                $closest = null;
                if($player instanceof Position){
                    $lastSquare = -1;
                    foreach($this->plugin->map->getPlayers() as $p){
                        if($p !== $this->plugin->getMurderer() && !isset($this->plugin->spectators[$p->getName()]) && $this->plugin->isPlaying($p)){
                            $square = $player->distanceSquared($p);
                            if($lastSquare === -1 or $lastSquare > $square){
                                $closest = $p;
                                $lastSquare = $square;
                            }
                        }
                    }
                }
                if($closest != null){
                    $this->plugin->setSpawnPositionPacket($player, $closest->asVector3());
                }
            }
        }
    }
}
