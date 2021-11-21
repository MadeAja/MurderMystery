<?php

namespace mm\arena;

use pocketmine\event\Listener;
use pocketmine\block\Block;
use pocketmine\item\Item;
use pocketmine\event\entity\{
    EntityLevelChangeEvent,
    EntityDamageEvent,
    ProjectileHitBlockEvent,
    EntityDamageByEntityEvent,
    EntityDamageByChildEntityEvent,
    ProjectileLaunchEvent,
    EntityInventoryChangeEvent
};
use pocketmine\event\player\{
    PlayerInteractEvent,
    PlayerQuitEvent,
    PlayerExhaustEvent,
    PlayerChatEvent,
    PlayerDropItemEvent
};
use pocketmine\event\inventory\InventoryPickupItemEvent;
use pocketmine\level\{
    Level,
    Position
};
use pocketmine\Player;
use pocketmine\tile\Tile;
use pocketmine\entity\projectile\Arrow;
use pocketmine\entity\Creature;
use pocketmine\network\mcpe\protocol\SetSpawnPositionPacket;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\math\Vector3;
use pocketmine\entity\Entity;
use pocketmine\nbt\tag\StringTag;

use pocketmine\network\mcpe\protocol\RemoveObjectivePacket;
use pocketmine\network\mcpe\protocol\SetDisplayObjectivePacket;
use pocketmine\network\mcpe\protocol\SetScorePacket;
use pocketmine\network\mcpe\protocol\types\ScorePacketEntry;

use xenialdan\apibossbar\BossBar;

use mm\MurderMystery;
use mm\arena\ArenaScheduler;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use mm\math\{Vector, SwordEntity};
use mm\tasks\{ArrowTask, CollideTask, CooldownTask, DespawnSwordEntity, SpawnGoldTask, UpdatePlayerPositionTask};

class Arena implements Listener{

    const PHASE_LOBBY = 0;
    const PHASE_GAME = 1;
    const PHASE_RESTART = 2;

    public static $bossbar;
    
    public $plugin;
    public $task;

    public $phase = 0;

    public $setup = false;
    public $map = null;

    public $data = [];
    public $players = [];
    public $spectators = [];
    public $cooldown = [];
    public $interactDelay = [];
    public $changeInv = [];

    public $shooter;
    public $murderer;
    public $detective;
    public $deadMurderer;

    public $startTime = 31;
    public $gameTime = 5 * 60;

    public function __construct(MurderMystery $plugin, array $file){
        $this->plugin = $plugin;
        $this->data = $file;
        $this->setup = !$this->enable(false);

        $this->plugin->getScheduler()->scheduleRepeatingTask(new CooldownTask($this), 2);
        $this->plugin->getScheduler()->scheduleRepeatingTask(new UpdatePlayerPositionTask($this), 2);
        $this->plugin->getScheduler()->scheduleRepeatingTask($this->task = new GameTask($this), 20);

        $spawnRate = $this->plugin->getConfig()->get("GoldSpawnRate");
        if(is_numeric($spawnRate)){
            $spawnRate = $spawnRate;
        } else {
            $this->plugin->getLogger()->error("Could not set gold spawn rate to $spawnRate! Value has to be a number!");
            $spawnRate = 4;
        }
        $this->plugin->getScheduler()->scheduleRepeatingTask(new SpawnGoldTask($this), 20 * $spawnRate);

        if($this->setup){
            if(empty($this->data)){
                $this->createBasicData();
            }
        } else {
            $this->loadGame();
        }
    }

    public function createScoreboard(Player $player, string $title, array $entries){
        $createPacket = new SetDisplayObjectivePacket();

        $createPacket->displaySlot = "sidebar";
        $createPacket->objectiveName = "MurderMystery";
        $createPacket->displayName = $title;
        $createPacket->criteriaName = "dummy";
        $createPacket->sortOrder = 0;
        $player->sendDataPacket($createPacket);

        foreach($entries as $entry){
            $this->setEntry($player, array_search($entry, $entries), $entry);
        }
    }

    public function setEntry(Player $player, int $line, string $msg){
        $entry = new ScorePacketEntry();
        $packet = new SetScorePacket();

        if(count($this->players) < 2){
            $status = str_replace("{sec}", $this->task->startTime, $this->plugin->getConfig()->get("WaitingStatus"));
        } else {
            $status = $this->plugin->getConfig()->get("StartingStatus");
        }
	   
    public function setEntry(Player $player, int $line, string $msg){
        $entry = new ScorePacketEntry();
        $packet = new SetScorePacket();

        if(count($this->players) < 2){
            $status = str_replace("{gamet}", $this->task->gameTime, $this->plugin->getConfig()->get("ArenaStatus"));
        } else {
            $status = $this->plugin->getConfig()->get("GameStatus");
        }

        $msg = str_replace([
            "{players}", "{innocents}", "{detec_status}", "{role}", "{map}", "{status}" 
        ], [
            count($this->players), (count($this->players) - 1), $this->getDetectiveStatus(), $this->getRole($player), $this->map->getFolderName(), $status
        ], $msg);

        $entry->objectiveName = "MurderMystery";
        $entry->type = 3;
        $entry->customName = " $msg ";
        $entry->score = $line;
        $entry->scoreboardId = $line;

        $packet->type = 0;
        $packet->entries[$line] = $entry;
        $player->sendDataPacket($packet);
    }

    public function removeScoreboard(Player $player){
        $removePacket = new RemoveObjectivePacket();

        $removePacket->objectiveName = "MurderMystery";
        $player->sendDataPacket($removePacket);
    }

    public function scoreboard(){
        foreach($this->plugin->getServer()->getOnlinePlayers() as $player){
            if(!$this->isPlaying($player) && !isset($this->spectators[$player->getName()])){
                return;
            }

            $this->removeScoreboard($player);
            switch($this->phase){
                case Game::PHASE_GAME:
                    $this->createScoreboard($player, "§l§eMURDER MYSTERY", $this->plugin->getConfig()->get("GameScoreboard"));
                break;

                case Game::PHASE_RESTART:
                    $this->createScoreboard($player, "§l§eMURDER MYSTERY", $this->plugin->getConfig()->get("RestartScoreboard"));
                break;

                case Game::PHASE_LOBBY:
                    $this->createScoreboard($player, "§l§eMURDER MYSTERY", $this->plugin->getConfig()->get("LobbyScoreboard"));
                break;
            }
        }
    }

    public function getDetectiveStatus(){
        if(isset($this->detective)){
            if($this->isPlaying($this->getDetective())){
                return "Alive";
            } else {
                return "Dead";
            }
        } else {
            return "Dead";
        }
    }

    public function getRole(Player $player){
        $role = "Dead";

        if($player === $this->getMurderer()){
            $role = "Murderer";
        }

        if($player === $this->getDetective()){
            $role = "Detective";
        }

        if(
            $this->isPlaying($player) &&
            ($player !== $this->getMurderer()) &&
            ($player !== $this->getDetective())
        ){
            $role = "Innocent";
        }

        return $role;
    }

    public function joinLobby(Player $player){
        if(!$this->data["enabled"]){
            $player->sendMessage("§cThis game is currently unavailable, please try again later!");
            return;
        }

        if(count($this->players) >= 16){
            $player->sendMessage("§cThis game is full!");
            return;
        }

        if($this->isPlaying($player)){
            $player->sendMessage("§cYou are already in a game of murder mystery!");
            return;
        }

        $player->teleport(Position::fromObject(Vector::fromString($this->data["lobby"]), $this->map));
        $this->unsetSpectator($player);
        $this->players[$player->getName()] = $player;

        foreach($this->players as $pl){
            $pl->sendMessage("§e" . $player->getName() . " has joined (§b" . count($this->players) . "§e/§b16§e)");
        }

        $this->defaultSettings($player);
    }

    public function removeArrow(ProjectileHitBlockEvent $event){
        $entity = $event->getEntity();
        if($entity instanceof Arrow){
            if($entity->getLevel()->getFolderName() == $this->map->getFolderName()){
                $entity->flagForDespawn();
            }
        }
    }

    public function getCoreItems(Player $player){
        $inv = $player->getInventory();

        $lobby = Item::get(355, 14, 1);
        $lobby->setCustomName("§r§l§cBack to lobby§r");
		$lobby->setNamedTagEntry(new StringTag("MurderMystery", "lobby"));

        $start = Item::get(76, 0, 1);
        $start->setCustomName("§r§l§bStart Game§r");
		$start->setNamedTagEntry(new StringTag("MurderMystery", "start"));

        $inv->setItem(8, $lobby);
        if($player->hasPermission("murdermystery.forcestart")){
            if($this->task->startTime > 10){
                $inv->setItem(4, $start);
            }
        }
    }

    public function getSpectatorCore(Player $player){
        $inv = $player->getInventory();

        $lobby = Item::get(355, 14, 1);
        $lobby->setCustomName("§r§l§cBack to lobby§r");
		$lobby->setNamedTagEntry(new StringTag("MurderMystery", "lobby"));

        $tp = Item::get(345, 14, 1);
        $tp->setCustomName("§r§l§aTeleporter§r");
		$tp->setNamedTagEntry(new StringTag("MurderMystery", "tp"));

        $inv->setItem(8, $lobby);
        $inv->setItem(0, $tp);
    }

    public function defaultSettings(Player $player){
        $this->changeInv[$player->getName()] = $player->getName();
        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->getCursorInventory()->clearAll();
        $player->removeAllEffects();
        $player->setFood(20);
        $player->setHealth(20);
        $player->setGamemode(2);
        $this->getCoreItems($player);
        $player->setFlying(false);
        $player->setAllowFlight(false);
        unset($this->changeInv[$player->getName()]);
    }

    public function removeFromGame(Player $player){
        $this->disconnectPlayer($player);
        $this->changeInv[$player->getName()] = $player->getName();
        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        self::$bossbar->removePlayer($player);
        $player->getCursorInventory()->clearAll();
        $player->removeAllEffects();
        $player->setFood(20);
        $player->setHealth(20);
        $player->setGamemode(0);
        $player->setFlying(false);
        $player->setAllowFlight(false);
        unset($this->changeInv[$player->getName()]);
        $player->teleport($this->plugin->getServer()->getDefaultLevel()->getSpawnLocation());
        $this->removeScoreboard($player);
    }

    public function disconnectPlayer(Player $player, string $quitMsg = ""){
        unset($this->players[$player->getName()]);
        $player->setNameTagAlwaysVisible(true);
        $player->setNameTagVisible();

        if($quitMsg != ""){
            foreach($this->players as $ingame){
                $ingame->sendMessage($quitMsg);
            }
        }
    }

    public function broadcastMessage($player, $message){
        $player->sendMessage($message);
    }

    public function broadcastTitle($player, $title, $subtitle){
        $player->addTitle($title, $subtitle);
    }

    public function startGame(){
        foreach($this->players as $player){
            $spawn = rand(1, 16);
            $player->teleport(Position::fromObject(Vector::fromString($this->data["spawns"]["spawn-$spawn"]), $this->map));
            $player->setNameTagAlwaysVisible(false);
            $player->setNameTagVisible(false);
            
            $this->changeInv[$player->getName()] = $player->getName();
            $player->getInventory()->clearAll();
            $player->getArmorInventory()->clearAll();
            self::$bossbar = (new BossBar())->setPercentage(1);
            self::$bossbar->setTitle(TextFormat::YELLOW . TextFormat::BOLD . "Playing" . TextFormat::WHITE . "MURDER" . TextFormat::WHITE . "MYSTERY" . TextFormat::GOLD . "on" . TextFormat::WHITE . "DCTX NETWORK" . TextFormat::RESET . TextFormat::WHITE);
            self::$bossbar->addPlayer($player);
            $player->getCursorInventory()->clearAll();
            unset($this->changeInv[$player->getName()]);
        }

        $this->phase = 1;

        $this->giveRoles();
    }

    public function giveRoles(){
        $innocents = [];
        foreach($this->players as $player){
            $innocents[$player->getName()] = $player;

        }
        $murderer = $innocents[array_rand($innocents)];
        $this->murderer = $murderer;
        unset($innocents[$murderer->getName()]);

        $detective = $innocents[array_rand($innocents)];
        $this->detective = $detective;
        unset($innocents[$detective->getName()]);

        $this->broadcastTitle($murderer, "§cYOU ARE THE MURDERER", "§6GOAL: §eKill all players!");
        $this->broadcastTitle($detective, "§bYOU ARE THE DETECTIVE", "§6GOAL: §eFind and kill the Murderer!");
        foreach($innocents as $innocent){
            $this->broadcastTitle($innocent, "§aYOU ARE INNOCENT", "§6GOAL: §eStay alive as long as possible!");
        }
    }

    public function giveItems(){
        $murderer = $this->getMurderer();
        $detective = $this->getDetective();

        $this->setItem(267, 1, $murderer, "§aKnife");
        $this->setItem(261, 1, $detective, "§aBow");

        if($this->isPlayer($murderer)){
            $murderer->getInventory()->setHeldItemIndex(0);
        }
        if($this->isPlayer($detective)){
            $detective->getInventory()->setHeldItemIndex(0);
        }

        $this->giveArrow();
    }

    public function getMurderer(){
        if(isset($this->murderer) && $this->isPlayer($this->murderer)){
            return $this->murderer;
        }
    }

    public function getDetective(){
        if(isset($this->detective) && $this->isPlayer($this->detective)){
            return $this->detective;
        }
    }

    public function isPlayer($player){
        if($player !== null && $player instanceof Player && $player->isOnline()){
            return true;
        } else {
            return false;
        }
    }

    public function setItem(int $id, int $slot, $player, string $name = null){
        if($this->isPlayer($player)){
            $this->changeInv[$player->getName()] = $player->getName();
            $item = Item::get($id, 0, 1);
            if($name != null){
                $item->setCustomName("§r" . $name);
            }
            $player->getInventory()->setItem($slot, $item);
            unset($this->changeInv[$player->getName()]);
        }
    }

    public function playSound(Player $player, string $sound){
        $pk = new PlaySoundPacket();
        $pk->x = $player->getX();
        $pk->y = $player->getY();
        $pk->z = $player->getZ();
        $pk->volume = 1;
        $pk->pitch = 1;
        $pk->soundName = $sound;
        $player->dataPacket($pk);
    }

    public function giveArrow(){
        $this->setItem(262, 9, $this->getDetective());
    }

    public function murdererWin(){
        foreach($this->spectators as $spectator){
            $this->broadcastMessage($spectator, "§cYOU LOSE! §7The Murderer killed everyone!");
            $this->broadcastTitle($spectator, "§cYOU LOSE!", "§7The Murderer killed everyone!");
        }

        $murderer = $this->getMurderer();
        if($this->isPlayer($murderer)){
            $this->playSound($murderer, "random.levelup");
        }
        $this->broadcastMessage($murderer, " §aYOU WIN! §6You have killed everyone!");
        $this->broadcastTitle($murderer, "§6VICTORY!", "§7You have killed everyone!");

        $this->startRestart();
    }

    public function innocentWin(){
        foreach($this->spectators as $spectator){
            if($this->deadMurderer !== $spectator){
                $this->playSound($spectator, "random.levelup");
                $this->broadcastMessage($spectator, " §aYOU WIN! §6The Murderer has been stopped!");
                $this->broadcastTitle($spectator, "§6VICTORY!", "§7The Murderer has been stopped!");
            }
        }

        foreach($this->players as $player){
            $this->playSound($player, "random.levelup");
            $this->broadcastMessage($player, " §aYOU WIN! §6The Murderer has been stopped!");
            $this->broadcastTitle($player, "§6VICTORY!", "§7The Murderer has been stopped!");
        }
        $this->startRestart();
    }

    public function startRestart(){
        $this->phase = self::PHASE_RESTART;

        foreach($this->map->getEntities() as $entity){
            if(!$entity instanceof Creature && !$entity instanceof Arrow){
                $entity->close();
            }
        }
        unset($this->murderer);
        unset($this->detective);
    }

    public function isPlaying(Player $player){
        return isset($this->players[$player->getName()]);
    }

    public function onInteract(PlayerInteractEvent $event){
        $player = $event->getPlayer();
        $block = $event->getBlock();
        $level = $player->getLevel();
        $item = $event->getItem();

        if($this->isPlaying($player)){
            if($block->getId() == Block::CHEST or $block->getId() == Block::CRAFTING_TABLE or $block->getId() == Block::TRAPDOOR or $block->getId() == Block::ANVIL or $block->getId() == Block::BED_BLOCK){
                $event->setCancelled(true);
                return;
            }
            if($event->getItem()->getId() == Item::BOW){
                $this->shooter = $player;
            }
            if($event->getItem()->getId() == Item::IRON_SWORD){
                if(!isset($this->cooldown[$player->getName()])){
                    if($this->phase == 1){
                        $this->createSwordEntity($player);
                    }
                }
            }
        }

        if(
            $event->getAction() == PlayerInteractEvent::RIGHT_CLICK_AIR or
            $event->getAction() == PlayerInteractEvent::RIGHT_CLICK_BLOCK or
            $event->getAction() == PlayerInteractEvent::LEFT_CLICK_BLOCK
        ){
            if($item->getNamedTag()->hasTag("MurderMystery")){
                $string = $item->getNamedTag()->getString("MurderMystery");
                if(isset($this->interactDelay[$player->getName()])){
                    if(microtime(true) >= $this->interactDelay[$player->getName()]){
                        unset($this->interactDelay[$player->getName()]);
                    } else {
                        return;
                    }
                }
                if($string == "start"){
                    $this->interactDelay[$player->getName()] = microtime(true) + 0.5;
                    if(count($this->players) > 1){
                        $this->task->startTime = 10;
                        $this->setItem(0, 4, $player);
                    } else {
                        $player->sendMessage("§cThere aren't enough players to start a game!");
                    }
                }

                if($string == "lobby"){
                    $this->interactDelay[$player->getName()] = microtime(true) + 0.5;
                    $this->removeFromGame($player);
                }

                if($string == "tp"){
                    if($this->phase == 1){
                        $this->interactDelay[$player->getName()] = microtime(true) + 0.5;
                        $this->openTeleporter($player);
                    }
                }
                return;
            }
        }

        if($level == null){
            return;
        }

        $signPos = Position::fromObject(Vector::fromString($this->data["joinsign"][0]), $this->plugin->getServer()->getLevelByName($this->data["joinsign"][1]));

        if((!$signPos->equals($block)) || $signPos->getLevel()->getId() != $level->getId()){
            return;
        }

        if($this->phase == self::PHASE_GAME){
            $player->sendMessage("§cThis game has already started!");
            return;
        }

        if($this->phase == self::PHASE_RESTART){
            $player->sendMessage("§cThis game is restarting!");
            return;
        }

        if($this->setup){
            return;
        }

        $this->joinLobby($player);
    }

    public function openTeleporter($player){
        $api = $this->plugin->getServer()->getPluginManager()->getPlugin("FormAPI");
        $form = $api->createSimpleForm(function (Player $player, $data = null){
            if($data === null){
                return true;
            }
            foreach($this->players as $ingame){
                $players[] = $ingame;
            }
            $players[$player->getName()] = $players;
            $target = $players[$player->getName()][$data];
			if($target instanceof Player){
                if($this->isPlaying($target)){
                    $player->teleport($target);
                } else {
                    $player->sendMessage("§cThis player is no longer in this game!");
                }
            } else {
                $player->sendMessage("§cInvalid player!");
            }
        });
        $form->setTitle("§l§aTeleporter");
        $form->setContent("§7Choose the player you want to spectate:");
        foreach($this->players as $p){
            $form->addButton($p->getName(), -1, $p->getName());
        }
        $form->sendToPlayer($player);
        return $form;
    }

    public function onQuit(PlayerQuitEvent $event){
        $player = $event->getPlayer();
        if($this->isPlaying($player)){
            if($this->phase == 0){
                $this->disconnectPlayer($player, "§e" . $player->getName() . " has left (§b" . (count($this->players) - 1) . "§e/§b16§e)");
            } else {
                $this->disconnectPlayer($player, "§7" . $player->getName() . " disconnected.");
            }
        }
        if(isset($this->spectators[$player->getName()])){
            $this->unsetSpectator($player);
        }
        $this->removeScoreboard($player);
    }

    public function onLevelChange(EntityLevelChangeEvent $event){
        $player = $event->getEntity();
        if($player instanceof Player){
            if($this->isPlaying($player)){
                if($this->phase == 0){
                    $this->disconnectPlayer($player, "§e" . $player->getName() . " has left (§b" . (count($this->players) - 1) . "§e/§b16§e)");
                } else {
                    $this->disconnectPlayer($player, "§7" . $player->getName() . " disconnected.");
                }
            }
            if(isset($this->spectators[$player->getName()])){
                $this->unsetSpectator($player);
            }
            $this->removeScoreboard($player);
        }
    }

    public function onShoot(ProjectileLaunchEvent $event){
        if(!isset($this->shooter)){
            return;
        }
        if($this->getDetective() === null){
            return;
        }
        if(!$this->isPlaying($this->shooter)){
            return;
        }
        if($event->getEntity() instanceof Arrow){
            $detective = $this->getDetective();
            $this->arrow = time();
            $arrow = Item::get(262, 0, 1);
            $this->changeInv[$this->shooter->getName()] = $this->shooter->getName();
            $this->shooter->getInventory()->removeItem($arrow);
            unset($this->changeInv[$this->shooter->getName()]);
            if($this->shooter === $detective){
                $this->plugin->getScheduler()->scheduleDelayedTask(new ArrowTask($this), 140);
                $this->cooldown[$detective->getName()] = microtime(true) + 7;
            }
        }
    }

    public function playerKillPlayer($killer, $victim){
        if($this->isPlayer($killer) && $this->isPlayer($victim)){
            if($killer === $this->getMurderer()){
                $this->killPlayer($victim);
                return;
            }
            if($victim === $killer){
                $this->killPlayer($victim, "§eYou killed yourself!");
                return;
            }
            if($victim !== $this->getMurderer()){
                $this->killPlayer($killer, "§eYou killed an innocent player!");
            }
            $this->killPlayer($victim, "§eA player killed you!");
        }
    }

    public function onDamage(EntityDamageEvent $event){
        $player = $event->getEntity();
        if($player instanceof Player){
            if($this->isPlaying($player)){
                if($event instanceof EntityDamageByEntityEvent){
                    $murderer = $event->getDamager();
                    if($murderer === $this->getMurderer()){
                        if($murderer->getInventory()->getItemInHand()->getId() == Item::IRON_SWORD){
                            $this->killPlayer($player);
                        }
                    }
                }
                if($event instanceof EntityDamageByChildEntityEvent){
                    $entity = $event->getChild();
                    if($entity instanceof Arrow){
                        if(isset($this->shooter)){
                            $this->playerKillPlayer($this->shooter, $player);
                        }
                    }
                }
                $event->setCancelled();
            }
        }
    }

    public function onHunger(PlayerExhaustEvent $event){
        $player = $event->getPlayer();
        if($player instanceof Player){
            if($this->isPlaying($player)){
                $event->setCancelled();
            }
        }
    }

    public function onChat(PlayerChatEvent $event){
        $player = $event->getPlayer();
        if(isset($this->spectators[$player->getName()])){
            $event->setCancelled();
            foreach($this->spectators as $spectator){
                $spectator->sendMessage("§7[Spectator] " . $player->getName() . ": " . $event->getMessage());
            }
        }
    }
    
    public function killPlayer($player, $subtitle = "§eThe Murderer stabbed you!"){
        if($this->isPlayer($player)){
            $this->changeInv[$player->getName()] = $player;
            $player->getInventory()->clearAll();
            $player->getArmorInventory()->clearAll();
            self::$bossbar->removePlayer($player);
            $player->getCursorInventory()->clearAll();
            $this->getSpectatorCore($player);
            unset($this->changeInv[$player->getName()]);
            $player->removeAllEffects();
            $player->setGamemode(3);

            foreach($this->players as $ingame){
                $this->playSound($ingame, "game.player.die");
            }

            if($player === $this->getMurderer()){
                $this->deadMurderer = $player;
                $this->spectators[$player->getName()] = $player;
                $player->addTitle("§cYOU LOSE", "§6You have been killed!");
                $player->sendMessage("§cYOU LOSE §6You have been killed!");
                $this->disconnectPlayer($player);
                $this->innocentWin();
                return;
            }
            $player->addTitle("§cYOU DIED!", $subtitle);
            $player->sendMessage("§cYOU DIED! $subtitle");
            $player->sendMessage("§eAs a Spectator, you can chat with fellow Spectators.");
            $player->sendMessage("§6Alive players can't see dead players' chat.");
            $this->spectators[$player->getName()] = $player;
            if($player === $this->getDetective()){
                if(count($this->players) > 2){
                    $this->detectiveDied();

                    foreach($this->players as $inno){
                        if(($inno !== $this->getDetective()) && ($inno !== $this->getMurderer())){
                            $this->setSpawnPositionPacket($inno, $player->asVector3());
                        }
                    }

                    $this->dropItem($this->map, 261, $player);
                }
            }
            $this->disconnectPlayer($player);
            if(count($this->players) == 2){
                $this->setItem(345, 4, $this->getMurderer());
                foreach($this->players as $player){
                    if($player !== $this->getMurderer()){
                        $player->addTitle("§cWatch out!", "§eThe murderer got a compass!");
                    } else {
                        $player->addTitle("§cYou got a compass!", "§eThe compass points to the last player!");
                    }
                }
            }
        }
        $this->checkPlayers();
    }

    public function setSpawnPositionPacket(Player $player, Vector3 $pos){
        $pk = new SetSpawnPositionPacket();
        $pk->x = $pos->getFloorX();
        $pk->y = $pos->getFloorY();
        $pk->z = $pos->getFloorZ();
        $pk->x2 = $pos->getFloorX();
        $pk->y2 = $pos->getFloorY();
        $pk->z2 = $pos->getFloorZ();
        $pk->dimension = DimensionIds::OVERWORLD;
        $pk->spawnType = SetSpawnPositionPacket::TYPE_WORLD_SPAWN;
        $player->dataPacket($pk);
	}

    public function onDrop(PlayerDropItemEvent $event){
        $player = $event->getPlayer();
        if($this->isPlaying($player)){
            $event->setCancelled();
        }
    }

    public function onPickup(InventoryPickupItemEvent $event){
        $player = $event->getInventory()->getHolder();
        $item = $event->getItem()->getItem()->getId();
        $inv = $player->getInventory();

        if($this->isPlaying($player)){
            if($item == Item::BOW){
                if($player !== $this->getMurderer()){
                    $this->newDetective($player);
                } else {
                    $event->setCancelled();
                }
            }
            if($item == Item::GOLD_INGOT){
                if($inv->contains(Item::get(Item::GOLD_INGOT))){
                    $this->changeInv[$player->getName()] = $player;
                    $inv->addItem(Item::get(Item::GOLD_INGOT, 0, 1));
                    unset($this->changeInv[$player->getName()]);
                } else {
                    $this->setItem(Item::GOLD_INGOT, 8, $player);
                }
                if($player !== $this->getDetective()){
                    $this->checkGold($player);
                }
            }
        }
    }

    public function checkGold(Player $player){
        if($this->isPlaying($player)){
            if($player->getInventory()->contains(Item::get(Item::GOLD_INGOT, 0, 10))){
                $this->setItem(Item::BOW, 0, $player);
                $this->changeInv[$player->getName()] = $player;
                $player->getInventory()->addItem(Item::get(Item::ARROW, 0, 1));
                $player->getInventory()->removeItem(Item::get(Item::GOLD_INGOT, 0, 10));
                unset($this->changeInv[$player->getName()]);

                $player->addTitle("§a+1 Bow Shot!", "§eYou collected 10 gold and got an arrow!");
            }
        }
    }

    public function onInvChange(EntityInventoryChangeEvent $event){
        $player = $event->getEntity();
        if($player instanceof Player){
            if($this->isPlaying($player)){
                if($this->phase == self::PHASE_GAME){
                    if(!isset($this->changeInv[$player->getName()])){
                        $event->setCancelled();
                    }
                }
            }
        }
    }

    public function newDetective(Player $player){
        foreach($this->players as $ingame){
            if($ingame !== $this->getMurderer()){
                $this->setItem(0, 4, $ingame);
            }
            if($ingame !== $player){
                $ingame->addTitle("§r§l§e", "§eA players has picked up the bow!");
                $ingame->sendMessage("§eA player has picked up the bow!");
            }
        }
        $this->changeInv[$player->getName()] = $player;
        $player->getInventory()->removeItem(Item::get(Item::BOW));
        $player->getInventory()->removeItem(Item::get(Item::ARROW));
        $player->addTitle("§aYou picked up the bow!", "§6GOAL: §eFind and kill the murderer!");
        $this->detective = $player;
        $this->setItem(261, 1, $player, "§aBow");
        $this->giveArrow();
    }

    public function dropItem(Level $level, int $id, $pos){
        $item = Item::get($id);

        $level->dropItem($pos, $item);
    }

    public function unsetSpectator($player){
        unset($this->spectators[$player->getName()]);
        $player->setNameTagAlwaysVisible(true);
        $player->setNameTagVisible();
    }

    public function checkPlayers(){
        if(count($this->players) == 1){
            foreach($this->players as $player){
                if($player === $this->getMurderer()){
                    $this->murdererWin();
                } else {
                    $this->innocentWin();
                }
            }
        }
    }

    public function detectiveDied(){
        foreach($this->players as $player){
            if(($player !== $this->getMurderer()) && ($player !== $this->getDetective())){
                $this->setItem(345, 4, $player);
            }
            $player->addTitle("§6The Detective has been killed!", "§eFind the bow for a chance to kill the Murderer.");
            $player->sendMessage("§6The Detective has been killed! §eFind the bow for a chance to kill the Murderer.");
        }
    }

    public function createSwordEntity(Player $player){
        $nbt = Entity::createBaseNBT(
            #$player->add(0, $player->getEyeHeight() - 1.5, 0),
            $player->getTargetBlock(1),
            $player->getDirectionVector(),
            $player->yaw - 75,
            $player->pitch
        );
        
        $sword = new SwordEntity($player->getLevel(), $nbt);
        $sword->setMotion($sword->getMotion()->multiply(1.4));
        $sword->setPose();
        $sword->setInvisible();
        $sword->spawnToAll();
        $this->plugin->getScheduler()->scheduleRepeatingTask(new CollideTask($this, $sword), 0);
        $this->plugin->getScheduler()->scheduleDelayedTask(new DespawnSwordEntity($sword), 100);
        $this->cooldown[$player->getName()] = microtime(true) + 7;
    }

    public function loadGame(bool $restart = false){
        if(!$this->data["enabled"]){
            $this->plugin->getLogger()->error("A disabled game has been found! Enable it and restart the server to load it!");
            return;
        }

        if(!$restart){
            $this->plugin->getServer()->getPluginManager()->registerEvents($this, $this->plugin);

            if(!$this->plugin->getServer()->isLevelLoaded($this->data["map"])){
                $this->plugin->getServer()->loadLevel($this->data["map"]);
            }

            $this->map = $this->plugin->getServer()->getLevelByName($this->data["map"]);
        } else {
            $this->task->reloadTimer();
        }

        if(!$this->map instanceof Level){
            $this->map = $this->plugin->getServer()->getLevelByName($this->data["map"]);
        }

        $this->phase = static::PHASE_LOBBY;
        $this->players = [];
    }

    public function enable(bool $loadArena = true){
        if(empty($this->data)){
            return false;
        }

        if($this->data["map"] == null){
            return false;
        }

        if(!$this->plugin->getServer()->isLevelGenerated($this->data["map"])){
            return false;
        } else {
            if(!$this->plugin->getServer()->isLevelLoaded($this->data["map"]))
                $this->plugin->getServer()->loadLevel($this->data["map"]);
            $this->map = $this->plugin->getServer()->getLevelByName($this->data["map"]);
        }

        if($this->data["lobby"] == null){
            return false;
        }

        if(!is_array($this->data["spawns"])){
            return false;
        }

        if(!is_array($this->data["gold"])){
            return false;
        }

        if(count($this->data["spawns"]) != 16){
            return false;
        }

        if(count($this->data["gold"]) != $this->data["goldspawns"]){
            return false;
        }

        $this->plugin->provider->saveGames();
        $this->data["enabled"] = true;
        $this->setup = false;
        if($loadArena){
            $this->loadGame();
        }
        return true;
    }

    private function createBasicData(){
        $this->data = [
            "map" => null,
            "lobby" => null,
            "spawns" => [],
            "gold" => [],
            "goldspawns" => $this->plugin->getConfig()->get("GoldSpawns"),
            "joinsign" => null,
            "enabled" => false,
        ];
    }

    public function __destruct(){
        unset($this->task);
    }
}
