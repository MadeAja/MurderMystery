<?php

namespace mm\tasks;

use pocketmine\scheduler\Task;
use mm\arena\Arena;
use mm\math\SwordEntity;

class CollideTask extends Task{

    public function __construct(Arena $plugin, SwordEntity $sword){
        $this->plugin = $plugin;
        $this->sword = $sword;
    }

    public function onRun(int $ct){
        if(!$this->sword->isClosed()){
            foreach($this->plugin->players as $player){
                if($this->sword->asVector3()->distance($player) < 2){
                    if($this->plugin->getMurderer() !== $player){
                        $this->plugin->killPlayer($player, "§eThe Murderer threw their knife at you");
                        $this->plugin->plugin->getScheduler()->scheduleDelayedTask(new DespawnSwordEntity($this->sword), 0);
                    }
                }
            }
        }
        if($this->sword->isCollided == true){
            $this->plugin->plugin->getScheduler()->scheduleDelayedTask(new DespawnSwordEntity($this->sword), 0);
        }
    }
}
