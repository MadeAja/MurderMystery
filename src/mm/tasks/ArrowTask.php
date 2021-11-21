<?php

namespace mm\tasks;

use mm\arena\Arena;
use pocketmine\scheduler\Task;

class ArrowTask extends Task{

    public function __construct(Arena $plugin){
        $this->plugin = $plugin;
    }

    public function onRun(int $ct){
        $this->plugin->giveArrow();
    }
}