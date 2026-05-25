<?php
/***********************************************
* File      :   imap_idle_sink.php
* Project   :   Z-Push
* Descr     :   IMAP IDLE (RFC 2177) based ChangesSink helper.
*               Maintains one IMAP socket per watched folder, sends
*               IDLE, and uses stream_select() to wait for unsolicited
*               server notifications (EXISTS / EXPUNGE / RECENT / FETCH).
*               Each socket is reusable across multiple ChangesSink()
*               calls within the same PHP request (one EAS Ping).
*
* Created   :   2026
*
* Copyright 2007 - 2016 Zarafa Deutschland GmbH
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License, version 3,
* as published by the Free Software Foundation.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU Affero General Public License for more details.
*
* You should have received a copy of the GNU Affero General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
*
* Consult LICENSE file for details
************************************************/

class ImapIdleSink {
    private $sockets = array();    // imapid => array('sock' => resource, 'idleTag' => string, 'lastIo' => int)
    private $tagCounter = 0;
    private $host;
    private $port;
    private $username;
    private $password;
    private $connectTimeout = 5;
    private $ioTimeout = 30;

    /**
     * @param string $host       IMAP server hostname or IP
     * @param int    $port       IMAP server port (typically 143 or 993)
     * @param string $username   IMAP login username
     * @param string $password   IMAP login password
     */
    public function __construct($host, $port, $username, $password) {
        $this->host = $host;
        $this->port = (int)$port;
        $this->username = $username;
        $this->password = $password;
    }

    public function __destruct() {
        $this->closeAll();
    }

    /**
     * Open a new IMAP socket, LOGIN, SELECT the given folder, and start IDLE.
     * Throws Exception on any failure (caller decides whether to fall back).
     *
     * @param string $imapid     IMAP folder name as already used by BackendIMAP
     *                           (e.g. result of getImapIdFromFolderId)
     * @return bool              true on success
     * @throws Exception
     */
    public function addFolder($imapid) {
        if (isset($this->sockets[$imapid])) {
            return true;
        }

        $errno = 0;
        $errstr = '';
        $sock = @fsockopen($this->host, $this->port, $errno, $errstr, $this->connectTimeout);
        if (!$sock) {
            throw new Exception("ImapIdleSink: connect {$this->host}:{$this->port} failed: $errstr ($errno)");
        }
        stream_set_timeout($sock, $this->ioTimeout);

        // Greeting (untagged "* OK ...")
        $greeting = $this->readLine($sock);
        if (strpos($greeting, '* OK') !== 0) {
            @fclose($sock);
            throw new Exception("ImapIdleSink: unexpected greeting: " . trim($greeting));
        }

        // LOGIN
        $tag = $this->nextTag();
        $cmd = $tag . ' LOGIN ' . $this->quoteString($this->username) . ' ' . $this->quoteString($this->password) . "\r\n";
        @fwrite($sock, $cmd);
        $resp = $this->readTagged($sock, $tag);
        if (!preg_match('/^' . preg_quote($tag, '/') . ' OK/m', $resp)) {
            @fclose($sock);
            throw new Exception("ImapIdleSink: LOGIN failed for {$this->username}: " . trim($resp));
        }

        // SELECT folder
        $tag = $this->nextTag();
        @fwrite($sock, $tag . ' SELECT ' . $this->quoteString($imapid) . "\r\n");
        $resp = $this->readTagged($sock, $tag);
        if (!preg_match('/^' . preg_quote($tag, '/') . ' OK/m', $resp)) {
            @fclose($sock);
            throw new Exception("ImapIdleSink: SELECT failed for $imapid: " . trim($resp));
        }

        // IDLE - server sends "+ idling" continuation, then unsolicited responses
        $tag = $this->nextTag();
        @fwrite($sock, $tag . " IDLE\r\n");
        $cont = $this->readLine($sock);
        if (strpos($cont, '+') !== 0) {
            @fclose($sock);
            throw new Exception("ImapIdleSink: IDLE not accepted for $imapid: " . trim($cont));
        }

        // Switch to non-blocking so wait() can use stream_select() correctly
        stream_set_blocking($sock, false);

        $this->sockets[$imapid] = array(
            'sock'    => $sock,
            'idleTag' => $tag,
            'lastIo'  => time(),
        );
        return true;
    }

    /**
     * Block up to $timeout seconds waiting for any IDLE socket to receive a
     * server-side change notification. Returns the imapids that received one.
     *
     * @param int $timeout   maximum seconds to wait (clamped >= 1)
     * @return array         list of imapids that signalled a change
     */
    public function wait($timeout) {
        $changed = array();
        if (empty($this->sockets)) {
            sleep(max(1, (int)$timeout));
            return $changed;
        }

        $read = array();
        $byId = array();
        foreach ($this->sockets as $imapid => $entry) {
            $read[] = $entry['sock'];
            $byId[(int)$entry['sock']] = $imapid;
        }
        $write = null;
        $except = null;

        $ready = @stream_select($read, $write, $except, max(1, (int)$timeout), 0);
        if ($ready === false || $ready === 0) {
            return $changed; // timeout or interrupted
        }

        foreach ($read as $sock) {
            $imapid = isset($byId[(int)$sock]) ? $byId[(int)$sock] : null;
            if ($imapid === null) {
                continue;
            }

            $data = '';
            $deadline = microtime(true) + 0.5;
            while (microtime(true) < $deadline) {
                $chunk = @fread($sock, 8192);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $data .= $chunk;
                if (strlen($chunk) < 8192) {
                    break;
                }
            }

            if ($data === '') {
                // Socket readable but empty = remote closed; drop and continue
                $this->dropFolder($imapid);
                continue;
            }

            $this->sockets[$imapid]['lastIo'] = time();

            // Any of these untagged responses means "something changed in this folder"
            if (preg_match('/\*\s+\d+\s+(EXISTS|EXPUNGE|RECENT|FETCH)\b/i', $data)) {
                $changed[] = $imapid;
            }
        }

        return $changed;
    }

    /**
     * Cleanly close all IDLE sockets (DONE + LOGOUT).
     */
    public function closeAll() {
        foreach (array_keys($this->sockets) as $imapid) {
            $this->dropFolder($imapid);
        }
    }

    public function getFolderCount() {
        return count($this->sockets);
    }

    private function dropFolder($imapid) {
        if (!isset($this->sockets[$imapid])) {
            return;
        }
        $sock = $this->sockets[$imapid]['sock'];
        @stream_set_blocking($sock, true);
        @stream_set_timeout($sock, 2);
        @fwrite($sock, "DONE\r\n");
        @fread($sock, 4096);
        @fwrite($sock, "zlogout LOGOUT\r\n");
        @fread($sock, 4096);
        @fclose($sock);
        unset($this->sockets[$imapid]);
    }

    private function nextTag() {
        $this->tagCounter++;
        return 'z' . str_pad((string)$this->tagCounter, 4, '0', STR_PAD_LEFT);
    }

    private function quoteString($s) {
        return '"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), $s) . '"';
    }

    private function readLine($sock) {
        $line = @fgets($sock, 8192);
        return $line === false ? '' : $line;
    }

    private function readTagged($sock, $tag) {
        $resp = '';
        $end = time() + $this->ioTimeout;
        while (time() < $end) {
            $line = $this->readLine($sock);
            if ($line === '') {
                break;
            }
            $resp .= $line;
            if (preg_match('/^' . preg_quote($tag, '/') . ' (OK|NO|BAD)\b/m', $line)) {
                return $resp;
            }
        }
        return $resp;
    }
}
