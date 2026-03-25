<?php

namespace App\Services;

use RuntimeException;

/**
 * Raw PHP MikroTik RouterOS API client.
 *
 * Bypasses the evilfreelancer/routeros-api-php library which has a
 * stream timeout bug on multi-result queries (RouterOS 7.x).
 */
class MikrotikRawClient
{
    /** @var resource */
    private $socket;

    public function __construct(
        private string $host,
        private string $user,
        private string $pass,
        private int    $port = 8728,
        private bool   $ssl  = false,
        private int    $connectTimeout = 30,
        private int    $readTimeout = 120,
    ) {
        $this->connect();
    }

    /* ─── Connection ─────────────────────────────────────── */

    private function connect(): void
    {
        $proto = $this->ssl ? 'ssl://' : '';
        $this->socket = @stream_socket_client(
            "{$proto}{$this->host}:{$this->port}",
            $errno, $errstr,
            $this->connectTimeout,
            STREAM_CLIENT_CONNECT,
        );

        if (!$this->socket) {
            throw new RuntimeException("Mikrotik connect failed: {$errstr} ({$errno})");
        }

        stream_set_timeout($this->socket, $this->readTimeout);

        $this->login();
    }

    private function login(): void
    {
        $this->writeSentence(['/login', '=name=' . $this->user, '=password=' . $this->pass]);
        $resp = $this->readSentence();

        if ($resp === null || empty($resp) || $resp[0] !== '!done') {
            $msg = $resp ? implode(' ', $resp) : 'no response';
            throw new RuntimeException("Mikrotik login failed: {$msg}");
        }
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    /* ─── Public API ─────────────────────────────────────── */

    /**
     * Send a RouterOS API command and return parsed associative-array response.
     *
     * @param  string $command  e.g. '/ppp/secret/print'
     * @param  array  $params   key=>value pairs for query parameters (e.g. ['?list' => 'CORTE MOROSO'])
     * @return array  Array of associative arrays (one per !re reply)
     */
    public function command(string $command, array $params = []): array
    {
        $words = [$command];
        foreach ($params as $key => $value) {
            // If key already starts with ? or = keep it, otherwise prefix with =
            if (str_starts_with($key, '?') || str_starts_with($key, '=')) {
                $words[] = $key . '=' . $value;
            } else {
                $words[] = '=' . $key . '=' . $value;
            }
        }

        $this->writeSentence($words);
        return $this->readResponse();
    }

    /* ─── Wire protocol ──────────────────────────────────── */

    private function encodeLength(int $len): string
    {
        if ($len < 0x80)       return chr($len);
        if ($len < 0x4000)     return chr(($len >> 8) | 0x80) . chr($len & 0xFF);
        if ($len < 0x200000)   return chr(($len >> 16) | 0xC0) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
        if ($len < 0x10000000) return chr(($len >> 24) | 0xE0) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
        return chr(0xF0) . chr(($len >> 24) & 0xFF) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
    }

    private function writeWord(string $word): void
    {
        fwrite($this->socket, $this->encodeLength(strlen($word)) . $word);
    }

    private function writeSentence(array $words): void
    {
        foreach ($words as $w) {
            $this->writeWord($w);
        }
        $this->writeWord(''); // zero-terminator
    }

    private function readLen(): int
    {
        $c = $this->readBytes(1);
        if ($c === null) return -1;
        $b = ord($c);

        if (($b & 0x80) === 0) return $b;
        if (($b & 0xC0) === 0x80) return (($b & 0x3F) << 8) | ord($this->readBytes(1));
        if (($b & 0xE0) === 0xC0) { $d = $this->readBytes(2); return (($b & 0x1F) << 16) | (ord($d[0]) << 8) | ord($d[1]); }
        if (($b & 0xF0) === 0xE0) { $d = $this->readBytes(3); return (($b & 0x0F) << 24) | (ord($d[0]) << 16) | (ord($d[1]) << 8) | ord($d[2]); }

        $d = $this->readBytes(4);
        return (ord($d[0]) << 24) | (ord($d[1]) << 16) | (ord($d[2]) << 8) | ord($d[3]);
    }

    private function readBytes(int $len): ?string
    {
        $data = '';
        while (strlen($data) < $len) {
            $chunk = @fread($this->socket, $len - strlen($data));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->socket);
                if ($meta['timed_out'] ?? false) {
                    throw new RuntimeException('Mikrotik stream read timeout');
                }
                if ($meta['eof'] ?? false) {
                    return null;
                }
                return null;
            }
            $data .= $chunk;
        }
        return $data;
    }

    private function readWord(): ?string
    {
        $len = $this->readLen();
        if ($len < 0) return null;
        if ($len === 0) return '';
        return $this->readBytes($len);
    }

    private function readSentence(): ?array
    {
        $words = [];
        while (true) {
            $w = $this->readWord();
            if ($w === null) return null;
            if ($w === '') break;
            $words[] = $w;
        }
        return $words;
    }

    private function readResponse(): array
    {
        $response = [];
        while (true) {
            $sentence = $this->readSentence();
            if ($sentence === null) break;
            if (empty($sentence)) continue;

            $type = $sentence[0];
            $attrs = [];
            for ($i = 1; $i < count($sentence); $i++) {
                $w = $sentence[$i];
                if (str_starts_with($w, '=')) {
                    $eq = strpos($w, '=', 1);
                    if ($eq !== false) {
                        $attrs[substr($w, 1, $eq - 1)] = substr($w, $eq + 1);
                    }
                }
            }

            if ($type === '!re') {
                $response[] = $attrs;
            } elseif ($type === '!done') {
                break;
            } elseif ($type === '!fatal' || $type === '!trap') {
                $msg = $attrs['message'] ?? json_encode($attrs);
                throw new RuntimeException("Mikrotik error: {$msg}");
            }
        }
        return $response;
    }
}
