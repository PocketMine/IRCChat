<?php

/*
__PocketMine Plugin__
name=IRCChat
description=Connects to an IRC channel to act as a bridge for the server chat.
version=0.3
author=shoghicp
class=IRCChat
apiversion=6,7
*/

/*

Small Changelog
===============

0.1:
- PocketMine-MP Alpha_1.3dev release

0.2:
- Added the IRC pass sub-command to run commands as the real Console.

0.3:
- Removed NOTICE from chat broadcast
- Added Player join

*/

class IRCChat implements Plugin{
	private $config, $api, $socket, $thread;
	
	public function __construct(ServerAPI $api, $server = false){
		$this->api = $api;
	}
	
	public function init(){
		$this->config = new Config($this->api->plugin->configPath($this)."config.yml", CONFIG_YAML, array(
			"server" => "chat.freenode.net",
			"port" => 6667,
			"nickname" => "YourNicknameHere",
			"password" => "",
			"channel" => "#example,#example2",
			"authpassword" => substr(base64_encode(Utils::getRandomBytes(20, false)), 3, 8) //To use in IRC
		));

		$this->workers = array();
		console("[INFO] Starting IRCChat");
		$this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		socket_set_option($this->socket, SOL_SOCKET, SO_KEEPALIVE, 1);
		if($this->socket === false or !socket_connect($this->socket, $this->config->get("server"), (int) $this->config->get("port"))){
			console("[ERROR] IRCChat can't be started: ".socket_strerror(socket_last_error()));
			return;
		}
		socket_getpeername($this->socket, $addr, $port);
		socket_set_nonblock($this->socket);
		$this->thread = new IRCChatClient($this->socket, $this->config->get("nickname"), $this->config->get("password"), $this->config->get("channel"));
		$this->api->console->register("irc", "<message ...>", array($this, "commandHandler"));
		console("[INFO] IRCChat connected to /$addr:$port");
		$this->api->schedule(2, array($this, "check"), array(), true);
		$this->api->addHandler("server.chat", array($this, "sendMessage"));
		$this->api->event("player.join", array($this, "eventHandler"));
	}
	
	public function commandHandler($cmd, $params, $issuer, $alias){
		$output = "";
		switch($cmd){
			case "irc":
				if($params[0]{0} === "/"){
					$command = strtolower(substr(array_shift($params), 1));
					switch($command){
						case "tell":
						case "msg":
							socket_write($this->socket, "PRIVMSG ".array_shift($params)." :".implode(" ", $params)."\r\n");
							break;
						case "list":
							socket_write($this->socket, "LIST ".array_shift($params)."\r\n");
							break;
						case "me":
							socket_write($this->socket, "PRIVMSG ".$this->config->get("channel")." :\x01ACTION ".implode(" ", $params)."\x01\r\n");
							break;
						case "join":
							socket_write($this->socket, "JOIN ".array_shift($params)."\r\n");
							break;
					}
				}else{
					$mes = implode(" ", $params);
					socket_write($this->socket, "PRIVMSG ".$this->config->get("channel")." :".$mes."\r\n");
					$this->api->chat->send(false, "<".$this->config->get("channel").":".$this->config->get("nickname")."> $mes", false, array("IRCChat", "ircchat"));
				}
				break;
		}
		return $output;
	}
	
	public function eventHandler($data, $event){
		switch($event){
			case "player.join":
				socket_write($this->socket, "PRIVMSG ".$this->config->get("channel")." :".$data->username." joined the game\r\n");
				break;
		}
	}
	
	public function sendMessage($data, $event){
		if($data->check("IRCChat") or $data->check("ircchat")){
			$m = preg_replace('/\x1b\[[0-9;]*m/', "", $data->get());
			socket_write($this->socket, "PRIVMSG ".$this->config->get("channel")." : ".$m."\r\n");
		}
	}
	
	public function __destruct(){
		$this->stop();
	}
	
	public function stop(){
		$this->thread->stop = true;
		$this->thread->notify();
		$this->thread->join();
		@socket_close($this->socket);
	}
	
	public function check(){
		if($this->thread->isWaiting()){
			switch($this->thread->type){
				case 0:
					console($this->thread->msg);
					break;
				case 1:
					$this->api->chat->send(false, $this->thread->msg, false, array("IRCChat", "ircchat"));
					break;
				case 2:
					$len = Utils::readShort(substr($this->thread->msg, 0, 2));
					$owner = substr($this->thread->msg, 2, $len);
					$cmd = explode(" ", substr($this->thread->msg, 2 + $len));
					if(strtolower($cmd[0]) === "pass"){
						array_shift($cmd);
						$pass = array_shift($cmd);
						if($pass != $this->config->get("authpassword")){
							break;
						}
						$m = preg_replace('/\x1b\[[0-9;]*m/', "", $this->api->console->run(implode(" ", $cmd), "console"));
					}else{
						$m = preg_replace('/\x1b\[[0-9;]*m/', "", $this->api->console->run(implode(" ", $cmd), ":$owner"));
					}
					foreach(explode("\n", $m) as $l){
						if($l != ""){
							socket_write($this->socket, "PRIVMSG $owner : ".trim($l)."\r\n");
						}
					}
					break;
			}
			$this->thread->notify();
		}
	}

}

class IRCChatClient extends Thread{
	public $msg;
	public $response;
	public $type;
	private $socket, $nickname, $password, $stop, $status, $channel;
	public function __construct($socket, $nickname, $password, $channel){
		$this->stop = false;
		$this->msg = "";
		$this->response = "";
		$this->type = 0;
		$this->socket = $socket;
		$this->nickname = $nickname;
		$this->password = $password === "" ? false:$password;
		$this->channel = $channel;
		$this->status = 0;
		$this->start();
	}
	
	private function notification($msg, $type = 0){
		$this->type = (int) $type;
		$this->msg = $msg;
		$this->wait();
		return $this->response;
	}
	
	public function run(){
		$connect = "";
		if($this->password !== false){
			$connect .= "PASS ".$this->password."\r\n";
		}
		$connect .= "NICK ".$this->nickname."\r\n";
		$connect .= "USER ".$this->nickname." a a :".$this->nickname."\r\n";

		socket_write($this->socket, $connect);
		$host = "";
		while(true){
			$txt = socket_read($this->socket, 65535);
			if($txt != ""){
				$txt = explode("\r\n", $txt);
				foreach($txt as $line){
					if(trim($line) == ""){
						continue;
					}
					$line = explode(" ", $line);
					$cmd = array_shift($line);
					$sender = "";
					if($cmd{0} == ":"){
						$end = strpos($cmd, "!");
						if($end === false){
							$end = strlen($cmd);
						}
						$sender = substr($cmd, 1, $end - 1);
						if($host === ""){
							$host = $sender;
						}
						$cmd = array_shift($line);
					}
					$msg = implode(" ", $line);
					switch(strtoupper($cmd)){
						case "JOIN":
							if($from === $this->nickname){
								$this->notification("[INFO] [IRCChat] Joined channel $msg", 0);
							}else{
								$this->notification(":$sender joined $msg", 1);
							}
							break;
						case "332": //Topic
							array_shift($line);
							$from = array_shift($line);
							$mes = substr($msg, strpos($msg, ":") + 1);
							$this->notification("[INFO] [IRCChat] $from topic: $mes", 0);
							break;
						case "QUIT":
						case "PART":
							$this->notification(":$sender left the channel", 1);
							break;
						case "MODE":
							$this->status = 1;
							$this->notification("[INFO] [IRCChat] Mode $msg", 0);
							break;
						case "PING":
							socket_write($this->socket, "PONG ".$msg."\r\n");
							break;
						case "NOTICE":
							break;
						case "PRIVMSG":	
							$from = array_shift($line);
							$mes = substr($msg, strpos($msg, ":") + 1);
							if($mes{0} === "\x01"){
								$mes = str_replace(array("\x01", "ACTION "), array("", "*** "), $mes);
							}
							if($from{0} === "#"){
								$this->notification("<".$from.":$sender> $mes", 1);
							}elseif($from === $this->nickname){
								$this->notification(Utils::writeShort(strlen($sender)).$sender.$mes, 2);
							}
							break;
						default:
							break;
					}
					if($this->status === 1){
						socket_write($this->socket, "JOIN ".$this->channel."\r\n");
						$this->status = 2;
					}
				}
			}
			usleep(1);
		}
	}
}