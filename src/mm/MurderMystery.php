<?php

namespace mm;

/**
 * Credits : Leykey
 */


use pocketmine\plugin\PluginBase;
use pocketmine\command\{CommandSender, Command};
use pocketmine\Player;
use pocketmine\event\Listener;
use pocketmine\event\player\{PlayerInteractEvent, PlayerChatEvent};
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\entity\Entity;

use mm\math\{GameChooser, SwordEntity, Vector};
use mm\provider\Provider;
use mm\arena\Arena;

class MurderMystery extends PluginBase implements Listener{

    public $provider;
    private $gamechooser;

    public $setupData = [];
    public $editors = [];
    public $games = [];

    public $spawns = [];
    public $gold = [];

    public $prefix;
    public $noPerms = "§cYou don't have permission to use this command!";

    public function onLoad(){
        $this->provider = new Provider($this);
        $this->gamechooser = new GameChooser($this);
    }

    public function onEnable(){
        $this->saveDefaultConfig();
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->provider->loadGames();
        Entity::registerEntity(SwordEntity::class, true);
        $this->prefix = $this->getConfig()->get("Prefix") . " ";
    }

    public function onCommand(CommandSender $sender, Command $cmd, string $str, array $args) : bool{
        if(strtolower($cmd) == "murdermystery"){
            if(count($args) == 0){
                $sender->sendMessage("§7Use §f/mm help");
                return true;
            }
            switch($args[0]){
                case "help":
                    $sender->sendMessage("§6/mm help§f: §7Shows a list of available commands");
                    if($sender->hasPermission("murdermystery.edit")){
                        $sender->sendMessage("§c/mm remove");
                        $sender->sendMessage("§c/mm create");
                        $sender->sendMessage("§c/mm set");
                        $sender->sendMessage("§c/mm");
                        $sender->sendMessage("§c/mm savegames");
                    }
                    $sender->sendMessage("§c/mm join");
                break;

                case "create":
                    if(!$sender->hasPermission("murdermystery.edit")){
                        $sender->sendMessage($this->noPerms);
                        break;
                    }

                    if(!isset($args[1])){
                        $sender->sendMessage($this->prefix . "§7Use /mm create <name>");
                        break;
                    }

                    if(isset($this->games[$args[1]])){
                        $sender->sendMessage($this->prefix . "§7" . $args[1] . "§r§7 already exists!");
                        break;
                    }

                    $this->games[$args[1]] = new Arena($this, []);
                    $sender->sendMessage($this->prefix . "§7" . $args[1] . "§r§7 has been created!");
                break;

                case "remove":
                    if(!$sender->hasPermission("murdermystery.edit")){
                        $sender->sendMessage($this->noPerms);
                        break;
                    }

                    if(!isset($args[1])){
                        $sender->sendMessage($this->prefix . "§7Use /mm remove <name>");
                        break;
                    }

                    if(!isset($this->games[$args[1]])){
                        $sender->sendMessage($this->prefix . "§7" . $args[1] . "§r§7 does not exist!");
                        break;
                    }

                    $games = $this->games[$args[1]];

                    foreach($games->players as $player){
                        $player->teleport($this->getServer()->getDefaultLevel()->getSafeSpawn());
                        $player->sendMessage("§cThe game you were in just crashed! You have been teleported to the lobby!");
                    }

                    if(is_file($file = $this->getDataFolder() . "games" . DIRECTORY_SEPARATOR . $args[1] . ".yml")){
                        unlink($file);
                    }
                    unset($this->games[$args[1]]);

                    $sender->sendMessage($this->prefix . "§7" . $args[1] . "§r§7 has been removed!");
                break;

                case "set":
                    if(!$sender->hasPermission("murdermystery.set")){
                        $sender->sendMessage($this->noPerms);
                        break;
                    }

                    if(!isset($args[1])){
                        $sender->sendMessage($this->prefix . "§7Use /mm set <name>");
                        break;
                    }

                    if(!isset($this->games[$args[1]])){
                        $sender->sendMessage($this->prefix . "§7" . $args[1] . "§r§7 does not exist!");
                        break;
                    }

                    if(!$sender instanceof Player){
                        $sender->sendMessage($this->prefix . "§7Use this command in-game!");
                    }

                    if(isset($this->editors[$sender->getName()])){
                        $sender->sendMessage($this->prefix . "§7You are already in setup mode!");
                        break;
                    }

                    $sender->sendMessage("§l§7-= §cMurder Mystery §7=-");
                    $sender->sendMessage("§chelp §f: §7View a list of available setup commands");
                    $sender->sendMessage("§cdone §f: §7Leave setup mode");
                    $this->editors[$sender->getName()] = $this->games[$args[1]];
                break;

                case "list":
                    if(!$sender->hasPermission("murdermystery.edit")){
                        $sender->sendMessage($this->noPerms);
                        break;
                    }

                    if(count($this->games) == 0){
                        $sender->sendMessage($this->prefix . "§7There aren't any arenas!");
                        break;
                    }

                    $list = "§l§7-= §cMurder Mystery §7=-§r\n";
                    foreach($this->games as $name => $arena){
                        if($arena->setup){
                            $list .= "§7$name §r§f: §cdisabled\n";
                        } else {
                            $list .= "§7$name §r§f: §aenabled\n";
                        }
                    }

                    $sender->sendMessage($list);
                break;

                case "savegames":
                    $this->provider->saveGames();
                    $sender->sendMessage($this->prefix . "§7All games have been saved!");
                break;

                case "join":
                    if(!$sender instanceof Player){
                        $sender->sendMessage("§cUse this command in-game!");
                        break;
                    }

                    $this->joinGame($sender);
                break;

                default:
                    $sender->sendMessage("§cThis is not a valid Murder Mystery command. Type §6/murdermystery help§c to view a list of available murder mystery commands.");
                break;
            }
        }
        return true;
    }

    public function onChat(PlayerChatEvent $event){
        $player = $event->getPlayer();

        if(isset($this->editors[$player->getName()])){
            $event->setCancelled();
            $args = explode(" ", $event->getMessage());
            $game = $this->editors[$player->getName()];

            switch($args[0]){
                case "help":
                    $player->sendMessage("§l§7-= §cMurder Mystery §7=-");
                    $player->sendMessage("§fhelp§f: §7Shows a list of available setup commands");
                    $player->sendMessage("§cmap <name>§f: §7Set the map");
                    $player->sendMessage("§clobby§f: §7Set waiting lobby");
                    $player->sendMessage("§cspawn§f: §7Set the spawn positions");
                    $player->sendMessage("§cgold§f: §7Set the gold spawn positions");
                    $player->sendMessage("§cjoinsign§f: §7Set the joinsign for the game");
                    $player->sendMessage("§cenable§f: §7Enable the game");
                break;

                case "map":
                    if(!isset($args[1])){
                        $game->data["map"] = $player->getLevel()->getFolderName();
                        $player->sendMessage($this->prefix . "§7Game map has been set to §6" . $player->getLevel()->getFolderName());
                        break;
                    }

                    if(!$this->getServer()->isLevelGenerated($args[1])){
                        $player->sendMessage($this->prefix . "§7World §6" . $args[1] . "§r§7 does not exist!");
                        break;
                    }

                    $game->data["map"] = $args[1];
                    $player->sendMessage($this->prefix . "§7Game map has been set to §6" . $args[1]);
                break;

                case "lobby":
                    $game->data["lobby"] = (new Vector($player->getX(), $player->getY(), $player->getZ()))->__toString();
                    $player->sendMessage($this->prefix . "§7Waiting lobby has been set to §6" . round($player->getX()) . ", " . round($player->getY()) . ", " . round($player->getZ()));
                break;

                case "spawn":
                    $this->spawns[$player->getName()] = 1;
                    $player->sendMessage($this->prefix . "§7Touch the spaws for the players!");
                break;

                case "gold":
                    $this->gold[$player->getName()] = 1;
                    $player->sendMessage($this->prefix . "§7Touch the spaws for the gold!");
                break;

                case "joinsign":
                    $player->sendMessage($this->prefix . "§7Break a sign to set the joinsign");
                    $this->setupData[$player->getName()] = 0;
                break;

                case "enable":
                    if(!$game->setup){
                        $player->sendMessage($this->prefix . "§7This arena is already enabled!");
                        break;
                    }

                    if(!$game->enable()){
                        $player->sendMessage($this->prefix . "§7Could not enable the arena, complete the setup first!");
                        break;
                    }

                    $player->sendMessage($this->prefix . "§7Arena has been enabled!");
                break;

                case "done":
                    $player->sendMessage($this->prefix . "§7You have left the setup mode");
                    unset($this->editors[$player->getName()]);
                    if(isset($this->setupData[$player->getName()])){
                        unset($this->setupData[$player->getName()]);
                    }
                break;

                default:
                    $player->sendMessage("§l§7-= §cMurder Mystery §7=-");
                    $player->sendMessage("§6help §f: §7View a list of available setup commands");
                    $player->sendMessage("§6done §f: §7Leave setup mode");
                break;
            }
        }
    }

    public function onBreak(BlockBreakEvent $event){
        $player = $event->getPlayer();
        $block = $event->getBlock();

        if(isset($this->setupData[$player->getName()])){
            switch($this->setupData[$player->getName()]){
                case 0:
                    $this->editors[$player->getName()]->data["joinsign"] = [(new Vector($block->getX(), $block->getY(), $block->getZ()))->__toString(), $block->getLevel()->getFolderName()];
                    $player->sendMessage($this->prefix . "§7Join sign updated!");
                    unset($this->setupData[$player->getName()]);
                    $event->setCancelled();
                break;
            }
        }
    }

    public function onTouch(PlayerInteractEvent $event){
        $player = $event->getPlayer();
        $block = $event->getBlock();

        if(isset($this->editors[$player->getName()])){
            $game = $this->editors[$player->getName()];
        } else {
            return;
        }

        if(isset($this->spawns[$player->getName()])){
            $index = $this->spawns[$player->getName()];

            $game->data["spawns"]["spawn-" . $index] = (new Vector($block->getX(), $block->getY() + 1.5, $block->getZ()))->__toString();
            $player->sendMessage($this->prefix . "§7Spawn " . $index . " has been set to§6 " . round($block->getX()) . ", " . round($block->getY() + 1) . ", " . round($block->getZ()));
            if($index > 15){
                $player->sendMessage($this->prefix . "§7All spawns have been set!");
                unset($this->spawns[$player->getName()]);
                return;
            }
            $this->spawns[$player->getName()] = ($index + 1);
            return;
        }

        if(isset($this->gold[$player->getName()])){
            $index = $this->gold[$player->getName()];

            $max = $this->getConfig()->get("GoldSpawns");

            $game->data["gold"]["gold-" . $index] = (new Vector($block->getX(), $block->getY() + 1, $block->getZ()))->__toString();
            $player->sendMessage($this->prefix . "§7Gold spawn " . $index . " has been set to§6 " . round($block->getX()) . ", " . round($block->getY() + 1) . ", " . round($block->getZ()));
            if($index > ($max - 1)){
                $player->sendMessage($this->prefix . "§cAll gold spawns have been set Nice!");
                unset($this->gold[$player->getName()]);
                return;
            }
            $this->gold[$player->getName()] = ($index + 1);
            return;
        }
    }

    public function joinGame(Player $player){
        $arena = $this->gamechooser->getRandomGame();
        if(!is_null($arena)){
            $arena->joinLobby($player);
            return;
        }
        $player->sendMessage("§cSomething went wrong while connecting to an available game, please try again!");
    }
}
