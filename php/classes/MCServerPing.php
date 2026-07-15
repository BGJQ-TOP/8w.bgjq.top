<?php

class MCServerPing {
    private $socket;
    private $host;
    private $port;
    private $timeout;

    public function __construct($host, $port = 25565, $timeout = 3) {
        $this->host = $host;
        $this->port = $port;
        $this->timeout = $timeout;
    }

    public function connect() {
        $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
        if (!$this->socket) {
            return false;
        }
        stream_set_timeout($this->socket, $this->timeout);
        return true;
    }

    public function ping() {
        if (!$this->connect()) {
            return null;
        }

        try {
            $data = $this->sendHandshake();
            if ($data) {
                return $this->parseResponse($data);
            }
        } catch (Exception $e) {
            error_log('MC服务器ping失败: ' . $e->getMessage());
        } finally {
            $this->close();
        }

        return null;
    }

    private function sendHandshake() {
        $host = $this->host;
        $port = $this->port;

        $packet = '';
        
        $packet .= $this->writeVarInt(0);
        $packet .= $this->writeVarInt(47);
        $packet .= $this->writeString($host);
        $packet .= pack('n', $port);
        $packet .= $this->writeVarInt(1);
        
        $this->writePacket($packet);
        
        $this->writePacket($this->writeVarInt(0));
        
        $length = $this->readVarInt();
        if ($length < 10) {
            return null;
        }
        
        $packetId = $this->readVarInt();
        if ($packetId !== 0) {
            return null;
        }
        
        $jsonLength = $this->readVarInt();
        $data = $this->read($jsonLength);
        
        return $data;
    }

    private function parseResponse($data) {
        $json = json_decode($data, true);
        if (!$json) {
            return null;
        }

        $players = [];
        if (isset($json['players']['sample']) && is_array($json['players']['sample'])) {
            foreach ($json['players']['sample'] as $player) {
                $players[] = [
                    'name' => $player['name'],
                    'uuid' => $player['id'] ?? null,
                    'country' => '未知',
                    'online' => true
                ];
            }
        }

        return [
            'motd' => $this->cleanText($json['description'] ?? '未知'),
            'online_players' => $players,
            'player_count' => $json['players']['online'] ?? 0,
            'max_players' => $json['players']['max'] ?? 100,
            'version' => $json['version']['name'] ?? '未知',
            'protocol' => $json['version']['protocol'] ?? 0
        ];
    }

    private function cleanText($text) {
        if (is_array($text)) {
            if (isset($text['text'])) {
                return $this->cleanText($text['text']);
            }
            if (isset($text['extra'])) {
                $result = '';
                foreach ($text['extra'] as $extra) {
                    $result .= $this->cleanText($extra);
                }
                return $result;
            }
        }
        $text = preg_replace('/§[0-9a-fk-or]/i', '', $text);
        return trim($text);
    }

    private function writeVarInt($value) {
        $data = '';
        do {
            $temp = $value & 0x7F;
            $value >>= 7;
            if ($value != 0) {
                $temp |= 0x80;
            }
            $data .= chr($temp);
        } while ($value != 0);
        return $data;
    }

    private function readVarInt() {
        $result = 0;
        $shift = 0;
        do {
            $byte = ord($this->read(1));
            $result |= ($byte & 0x7F) << $shift;
            $shift += 7;
            if ($shift > 32) {
                throw new Exception('VarInt too long');
            }
        } while (($byte & 0x80) != 0);
        return $result;
    }

    private function writeString($value) {
        return $this->writeVarInt(strlen($value)) . $value;
    }

    private function writePacket($data) {
        fwrite($this->socket, $this->writeVarInt(strlen($data)) . $data);
    }

    private function read($length) {
        $data = '';
        while (strlen($data) < $length) {
            $part = fread($this->socket, $length - strlen($data));
            if ($part === false || $part === '') {
                break;
            }
            $data .= $part;
        }
        return $data;
    }

    public function close() {
        if ($this->socket) {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    public function __destruct() {
        $this->close();
    }
}



