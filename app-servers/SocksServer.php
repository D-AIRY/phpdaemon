<?php
class SocksServer extends AsyncServer {

	public $sessions = array(); // Active sessions

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// listen to
			'listen'         => 'tcp://0.0.0.0',
			// listen port
			'listenport'     => 1080,
			// authentication required
			'auth'           => 0,
			// user name
			'username'       => 'User',
			// password
			'password'       => 'Password',
			// allowed clients ip list
			'allowedclients' => '',
			// disabled by default
			'enable'         => 0
		);
	}

	/**
	 * @method init
	 * @description Constructor.
	 * @return void
	 */
	public function init() {
		if ($this->config->enable->value) {
			Daemon::log(__CLASS__ . ' up.');

			if ($this->config->allowedclients->value !== '') {
					$this->allowedClients = explode(',', $this->config->allowedclients->value);
			}

			$this->bindSockets(
				$this->config->listen->value,
				$this->config->listenport->value,
				TRUE
			);
		}
	}

	/**
	 * @method onAccepted
	 * @description Called when new connection is accepted.
	 * @param integer Connection's ID.
	 * @param string Address of the connected peer.
	 * @return void
	 */
	public function onAccepted($connId, $addr) {
		$this->sessions[$connId] = new SocksSession($connId, $this);
		$this->sessions[$connId]->addr = $addr;
	}
}

class SocksSession extends SocketSession {

	public $ver; // protocol version (X'04' / X'05')
	public $state = 0; // (0 - start, 1 - aborted, 2 - handshaked, 3 - authorized, 4 - data exchange)
	public $slave;

	/**
	 * @method stdin
	 * @description Called when new data received.
	 * @param string New data.
	 * @return void
	 */
	public function stdin($buf) {
		if ($this->state === 4) {
			// Data exchange
			if ($this->slave) {
				$this->slave->write($buf);
			}

			return;
		}
		
		$this->buf .= $buf;

		start:

		$l = strlen($this->buf);
	
		if ($this->state === 0) {
			// Start
			if ($l < 2) {
				// Not enough data yet
				return;
			} 
			
			$n = ord(binarySubstr($this->buf, 1, 1));

			if ($l < $n + 2) {
				// Not enough data yet
				return;
			} 
			
			$this->ver = binarySubstr($this->buf, 0, 1);
			$methods = binarySubstr($this->buf, 2, $n);
			$this->buf = binarySubstr($this->buf, $n + 2);

			if (!$this->appInstance->config->auth->value) {
				// No auth
				$m = "\x00";
				$this->state = 3;
			} 
			elseif (strpos($methods, "\x02") !== FALSE) {
				// Username/Password authentication
				$m = "\x02";
				$this->state = 2;
			} else {
				// No allowed methods
				$m = "\xFF";
				$this->state = 1;
			}

			$this->write($this->ver.$m);

			if ($this->state === 1) {
				$this->finish();
			} else {
				goto start;
			}
		}
		elseif ($this->state === 2) {
			// Handshaked
			if ($l < 3) {
				// Not enough data yet
				return;
			} 

			$ver = binarySubstr($this->buf, 0, 1);

			if ($ver !== $this->ver) {
				$this->finish();
				return;
			}
	
			$ulen = ord(binarySubstr($this->buf, 1, 1));

			if ($l < 3 + $ulen) {
				// Not enough data yet
				return;
			} 

			$username = binarySubstr($this->buf, 2, $ulen);
			$plen = ord(binarySubstr($this->buf, 1, 1));

			if ($l < 3 + $ulen + $plen) {
				// Not enough data yet
				return;
			} 

			$password = binarySubstr($this->buf, 2 + $ulen, $plen);

			if (
				($username != $this->appInstance->config->username->value) 
				|| ($password != $this->appInstance->config->password->value)
			) {
				$this->state = 1;
				$m = "\x01";
			} else {
				$this->state = 3;
				$m = "\x00";
			}
			
			$this->buf = binarySubstr($this->buf, 3 + $ulen + $plen);
			$this->write($this->ver . $m);

			if ($this->state === 1) {
				$this->finish();
			} else {
				goto start;
			}
		} 
		elseif ($this->state === 3) {
			// Ready for query
			if ($l < 4) {
				// Not enough data yet
				return;
			}

			$ver = binarySubstr($this->buf, 0, 1);

			if ($ver !== $this->ver) {
				$this->finish();
				return;
			}
			
			$cmd = binarySubstr($this->buf, 1, 1);
			$atype = binarySubstr($this->buf, 3, 1);
			$pl = 4;

			if ($atype === "\x01") {
				$address = inet_ntop(binarySubstr($this->buf, $pl, 4)); 
				$pl += 4;
			}
			elseif ($atype === "\x03") {
				$len = ord(binarySubstr($this->buf, $pl, 1));
				++$pl;
				$address = binarySubstr($this->buf, $pl, $len);
				$pl += $len;
			}
			elseif ($atype === "\x04") {
				$address = inet_ntop(binarySubstr($this->buf, $pl, 16)); 
				$pl += 16;
			} else {
				$this->finish();
				return;
			}
			
			$u = unpack('nport', $bin = binarySubstr($this->buf, $pl, 2));
			$port = $u['port'];
			$pl += 2;
			$this->buf = binarySubstr($this->buf, $pl);

			$connId = $this->appInstance->connectTo($this->destAddr = $address, $this->destPort = $port);

			if (!$connId) {
				// Early connection error
				$this->write($this->ver . "\x05");
				$this->finish();
			} else {
				$this->slave = $this->appInstance->sessions[$connId] = new SocksServerSlaveSession($connId, $this->appInstance);
				$this->slave->client = $this;
				$this->slave->write($this->buf);
				$this->buf = '';
				$this->state = 4;
			}
		}
	}

	public function onSlaveReady($code) {
		$reply =
			$this->ver // Version
			. chr($code) // Status
			. "\x00"; // Reserved

		if (
			Daemon::$useSockets 
			&& socket_getsockname(Daemon::$worker->pool[$this->connId], $address, $port)
		) {
			$reply .=
				(strpos($address, ':') === FALSE ? "\x01" : "\x04") // IPv4/IPv6
				. inet_pton($address) // Address
				. "\x00\x00"; //pack('n',$port) // Port
		} else {
			$reply .=
				"\x01"
				. "\x00\x00\x00\x00"
				. "\x00\x00";
		}

		$this->write($reply);
	}

	/**
	 * @method onFinish
	 * @description Event of SocketSession (asyncServer).
	 * @return void
	 */
	public function onFinish() {
		if (isset($this->slave)) {
			$this->slave->finish();
		}

		unset($this->slave);
	}
}

class SocksServerSlaveSession extends SocketSession {

	public $client;
	public $ready = FALSE;

	/**
	 * @method onwrite
	 * @description Called when the connection is ready to accept new data.
	 * @return void
	 */
	public function onWrite() {
		if (!$this->ready) {
			$this->ready = TRUE;
		
			if (isset($this->client)) {
				$this->client->onSlaveReady(0x00);
			}
		}
	}
	
	/**
	 * @method stdin
	 * @description Called when new data received.
	 * @param string New data.
	 * @return void
	 */
	public function stdin($buf) {
		$this->client->write($buf);
	}

	/**
	 * @method onFinish
	 * @description Event of SocketSession (asyncServer).
	 * @return void
	 */
	public function onFinish() {
		if (isset($this->client)) {
			$this->client->finish();
		}
	
		unset($this->client);
	}
}
