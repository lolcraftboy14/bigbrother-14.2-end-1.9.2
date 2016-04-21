<?php

/*
 * BigBrother plugin for PocketMine-MP
 * Copyright (C) 2014 shoghicp <https://github.com/shoghicp/BigBrother>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
*/

namespace shoghicp\BigBrother;

use pocketmine\plugin\PluginBase;

use phpseclib\Crypt\RSA;
use pocketmine\network\protocol\Info;
use pocketmine\network\protocol\PlayerActionPacket;
use shoghicp\BigBrother\network\Info as MCInfo;
use shoghicp\BigBrother\network\ProtocolInterface;
use shoghicp\BigBrother\network\translation\Translator;
use shoghicp\BigBrother\network\translation\Translator_46;
use shoghicp\BigBrother\network\protocol\Play\RespawnPacket;
use shoghicp\BigBrother\network\protocol\Play\ResourcePackSendPacket;

use pocketmine\block\Block;
use pocketmine\math\Vector3;
use pocketmine\tile\Sign;
use pocketmine\Achievement;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerPreLoginEvent;
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\event\player\PlayerInteractEvent;

class BigBrother extends PluginBase implements Listener{

	/** @var ProtocolInterface */
	private $interface;

	/** @var RSA */
	protected $rsa;

	protected $privateKey;

	protected $publicKey;

	protected $onlineMode;

	/** @var Translator */
	protected $translator;

	public function onEnable(){
		@mkdir($this->getDataFolder(), 0777, true);
		$this->reloadConfig();

		$this->onlineMode = (bool) $this->getConfig()->get("online-mode");
		if($this->onlineMode and !function_exists("mcrypt_generic_init")){
			$this->onlineMode = false;
			$this->getLogger()->notice("no mcrypt detected, online-mode has been disabled. Try using the latest PHP binaries");
		}

		if(!$this->getConfig()->exists("motd")){
			$this->getLogger()->warning("No motd has been set. The server description will be empty.");
			return;
		}

		switch(Info::CURRENT_PROTOCOL){
			case 46:
				$this->translator = new Translator_46();
			break;
			default:
				$this->getLogger()->critical("Couldn't find a protocol translator for #".Info::CURRENT_PROTOCOL .", disabling plugin");
				$this->getPluginLoader()->disablePlugin($this);
				return;
			break;
		}

		$this->rsa = new RSA();

		$this->getServer()->getPluginManager()->registerEvents($this, $this);

		Achievement::add("openInventory","Taking Inventory"); //this for DesktopPlayer

		if($this->onlineMode){
			$this->getLogger()->info("Server is being started in the background");
			$this->getLogger()->info("Generating keypair");
			$this->rsa->setPrivateKeyFormat(CRYPT_RSA_PRIVATE_FORMAT_PKCS1);
			$this->rsa->setPublicKeyFormat(CRYPT_RSA_PUBLIC_FORMAT_PKCS1);
			$keys = $this->rsa->createKey(1024);
			$this->privateKey = $keys["privatekey"];
			$this->publicKey = $keys["publickey"];
			$this->rsa->setEncryptionMode(CRYPT_RSA_ENCRYPTION_PKCS1);
			$this->rsa->loadKey($this->privateKey);
		}
		$this->enableServer();
	}

	protected function enableServer(){
		$this->getLogger()->info("Starting Minecraft: PC server on: ".($this->getIp() === "0.0.0.0" ? "*" : $this->getIp()).":".$this->getPort()." version ".MCInfo::VERSION);

		$disable = true;
		foreach($this->getServer()->getInterfaces() as $interface){
			if($interface instanceof ProtocolInterface){
				$disable = false;
			}
		}
		if($disable){
			$this->interface = new ProtocolInterface($this, $this->getServer(), $this->translator);
			$this->getServer()->addInterface($this->interface);
		}
	}

	public function getIp(){
		return $this->getConfig()->get("interface");
	}

	public function getPort(){
		return (int) $this->getConfig()->get("port");
	}

	public function getMotd(){
		return (string) $this->getConfig()->get("motd");
	}

	public function getResourcePackURL(){
		return (string) $this->getConfig()->get("resourcepackurl");
	}

	/**
	 * @return bool
	 */
	public function isOnlineMode(){
		return $this->onlineMode;
	}

	public function getASN1PublicKey(){
		$key = explode("\n", $this->publicKey);
		array_pop($key);
		array_shift($key);
		return base64_decode(implode(array_map("trim", $key)));
	}

	public function decryptBinary($secret){
		return $this->rsa->decrypt($secret);
	}

	/**
	 * @param PlayerPreLoginEvent $event
	 *
	 * @priority NORMAL
	 */
	public function onPreLogin(PlayerPreLoginEvent $event){
		$player = $event->getPlayer();
		if($player instanceof DesktopPlayer){
			$threshold = $this->getConfig()->get("network-compression-threshold");
			if($threshold === false){
				$threshold = -1;
			}
			$player->bigBrother_setCompression($threshold);
			echo "PreLogin\n";
		}
	}

	/**
	 * @param PlayerRespawnEvent $event
	 *
	 * @priority NORMAL
	 */
	public function onRespawn(PlayerRespawnEvent $event){
		$player = $event->getPlayer();
		if($player instanceof DesktopPlayer){
			$pk = new RespawnPacket();
			$pk->dimension = 0;
			$pk->difficulty = $player->getServer()->getDifficulty();
			$pk->gamemode = $player->getGamemode();
			$pk->levelType = "default";
			$player->putRawPacket($pk);
		}
	}

	public function onTouch(PlayerInteractEvent $event){
		$player = $event->getPlayer();
		if($player instanceof DesktopPlayer){
			$block = $event->getBlock();
			switch($block->getID()){
				case Block::SIGN_POST:
				case Block::WALL_SIGN:
					$tile = $player->getLevel()->getTile(new Vector3($block->getX(), $block->getY(), $block->getZ()));
					if($tile instanceof Sign){
						$text = $tile->getText();
						if($text[0] === "ResourcePack" and $text[1] === "Download" and $this->getResourcePackURL() !== "false"){
							$pk = new ResourcePackSendPacket();
							$pk->url = $this->getResourcePackURL();
							$player->putRawPacket($pk);
						}
					}
				break;
			}
		}
	}

}
