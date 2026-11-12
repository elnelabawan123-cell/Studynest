<?php

namespace App\Services;

use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pure-PHP native Filipino neural TTS client using Microsoft Read Aloud
 * (`fil-PH-BlessicaNeural`) with Sec-MS-GEC v2 token generation and clock-skew
 * adjustment so Railway needs no external Python/binary dependency.
 */
class FilipinoNeuralTtsService
{
    private const HOST = 'speech.platform.bing.com';
    private const BASE_PATH = '/consumer/speech/synthesize/readaloud/edge/v1';
    private const TRUSTED_CLIENT_TOKEN = '6A5AA1D4EAFF4E9FB37E23D68491D6F4';
    private const CHROMIUM_FULL_VERSION = '143.0.3650.75';
    private const CHROMIUM_MAJOR_VERSION = '143';
    private const SEC_MS_GEC_VERSION = '2-143.0.3650.75';
    private const DEFAULT_VOICE = 'fil-PH-BlessicaNeural';
    private const OUTPUT_FORMAT = 'audio-24khz-48kbitrate-mono-mp3';
    private const WIN_EPOCH = 11644473600;

    private static int $clockSkewSeconds = 0;

    public function synthesize(
        string $text,
        string $voice = self::DEFAULT_VOICE,
        string $rate = '-4%',
    ): ?string {
        $cleanText = $this->normalizeFilipinoSpeechText($text);
        if ($cleanText === '') {
            return null;
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $result = $this->synthesizeOnce($cleanText, $voice, $rate);
            if ($result['audio'] !== null && strlen($result['audio']) > 128) {
                return $result['audio'];
            }
            if (!($result['retryWithSkew'] ?? false)) {
                break;
            }
        }

        return null;
    }

    /**
     * Normalize ALL-CAPS Filipino words/syllables so TTS pronounces syllables
     * ("ba", "bahay") naturally rather than spelling letters ("B. A.").
     */
    public function normalizeFilipinoSpeechText(string $raw): string
    {
        $text = preg_replace('/\s+/u', ' ', trim($raw)) ?? '';
        if ($text === '') {
            return '';
        }

        // Convert hyphenated uppercase syllable chains ("BA - HAY" / "BA-HAY") into gentle spoken pauses.
        $text = preg_replace_callback(
            '/\b[A-Z\x{00D1}]{1,6}(?:\s*[-\x{2013}\x{2014}]\s*[A-Z\x{00D1}]{1,6})+\b/u',
            static function (array $m): string {
                $parts = preg_split('/\s*[-\x{2013}\x{2014}]\s*/u', $m[0]) ?: [$m[0]];
                $parts = array_map(static fn (string $p): string => mb_strtolower($p, 'UTF-8'), $parts);
                return implode(', ', $parts);
            },
            $text
        ) ?? $text;

        // Lowercase standalone ALL-CAPS Filipino words/syllables >= 2 chars (preserve Roman numerals & OK/AI/TV).
        $preserved = ['II' => true, 'III' => true, 'IV' => true, 'VI' => true, 'VII' => true, 'VIII' => true, 'OK' => true, 'AI' => true, 'TV' => true];
        $text = preg_replace_callback(
            '/\b[A-Z\x{00D1}]{2,}\b/u',
            static function (array $m) use ($preserved): string {
                $word = $m[0];
                return isset($preserved[$word]) ? $word : mb_strtolower($word, 'UTF-8');
            },
            $text
        ) ?? $text;

        return trim($text);
    }

    /**
     * @return array{audio: ?string, retryWithSkew: bool}
     */
    private function synthesizeOnce(string $text, string $voice, string $rate): array
    {
        $connId = strtolower(bin2hex(random_bytes(16)));
        $reqId = strtoupper(bin2hex(random_bytes(16)));
        $muid = strtoupper(bin2hex(random_bytes(16)));
        $secMsGec = $this->generateSecMsGecV2();
        $secKey = base64_encode(random_bytes(16));

        $query = http_build_query([
            'TrustedClientToken' => self::TRUSTED_CLIENT_TOKEN,
            'Sec-MS-GEC' => $secMsGec,
            'Sec-MS-GEC-Version' => self::SEC_MS_GEC_VERSION,
            'ConnectionId' => $connId,
        ]);
        $path = self::BASE_PATH . '?' . $query;

        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $fp = @stream_socket_client(
            'ssl://' . self::HOST . ':443',
            $errno,
            $errstr,
            8,
            STREAM_CLIENT_CONNECT,
            $ctx
        );
        if (!$fp) {
            Log::warning('FilipinoNeuralTtsService socket connect failed', [
                'errno' => $errno,
                'error' => $errstr,
            ]);
            return ['audio' => null, 'retryWithSkew' => false];
        }

        stream_set_timeout($fp, 9);

        try {
            $handshake = "GET {$path} HTTP/1.1\r\n"
                . 'Host: ' . self::HOST . "\r\n"
                . "Upgrade: websocket\r\n"
                . "Connection: Upgrade\r\n"
                . "Sec-WebSocket-Key: {$secKey}\r\n"
                . "Sec-WebSocket-Version: 13\r\n"
                . "Pragma: no-cache\r\n"
                . "Cache-Control: no-cache\r\n"
                . "Origin: chrome-extension://jdiccldimpdaibmpdkjnbmckianbfold\r\n"
                . "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/" . self::CHROMIUM_MAJOR_VERSION . ".0.0.0 Safari/537.36 Edg/" . self::CHROMIUM_MAJOR_VERSION . ".0.0.0\r\n"
                . "Accept-Language: en-US,en;q=0.9\r\n"
                . "Cookie: muid={$muid};\r\n"
                . "\r\n";

            if (@fwrite($fp, $handshake) === false) {
                return ['audio' => null, 'retryWithSkew' => false];
            }

            $responseHeaders = '';
            $serverDate = null;
            while (($line = @fgets($fp)) !== false) {
                $responseHeaders .= $line;
                if (stripos($line, 'Date:') === 0) {
                    $serverDate = trim(substr($line, 5));
                }
                if (rtrim($line) === '') {
                    break;
                }
            }

            if (stripos($responseHeaders, '101 ') === false) {
                if ($serverDate !== null && $this->adjustClockSkewFromHeader($serverDate)) {
                    return ['audio' => null, 'retryWithSkew' => true];
                }
                Log::warning('FilipinoNeuralTtsService handshake rejected', [
                    'status_line' => strtok($responseHeaders, "\r\n") ?: 'unknown',
                ]);
                return ['audio' => null, 'retryWithSkew' => false];
            }

            $timestamp = gmdate('D M d Y H:i:s \G\M\T+0000 (Coordinated Universal Time)');
            $configPayload = json_encode([
                'context' => [
                    'synthesis' => [
                        'audio' => [
                            'metadataoptions' => [
                                'sentenceBoundaryEnabled' => 'false',
                                'wordBoundaryEnabled' => 'false',
                            ],
                            'outputFormat' => self::OUTPUT_FORMAT,
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR);

            $configMsg = "X-Timestamp:{$timestamp}\r\n"
                . "Content-Type:application/json; charset=utf-8\r\n"
                . "Path:speech.config\r\n\r\n"
                . $configPayload;
            $this->writeWsTextFrame($fp, $configMsg);

            $safeText = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $safeVoice = htmlspecialchars($voice, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $safeRate = htmlspecialchars($rate, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $ssml = "<speak version='1.0' xmlns='http://www.w3.org/2001/10/synthesis' xml:lang='fil-PH'>"
                . "<voice name='{$safeVoice}'><prosody pitch='+0Hz' rate='{$safeRate}'>{$safeText}</prosody></voice>"
                . '</speak>';

            $ssmlMsg = "X-RequestId:{$reqId}\r\n"
                . "Content-Type:application/ssml+xml\r\n"
                . "X-Timestamp:{$timestamp}Z\r\n"
                . "Path:ssml\r\n\r\n"
                . $ssml;
            $this->writeWsTextFrame($fp, $ssmlMsg);

            $audio = '';
            $done = false;
            while (!$done && !feof($fp)) {
                $header = $this->readExact($fp, 2);
                if ($header === null) {
                    break;
                }
                $opcode = ord($header[0]) & 0x0F;
                $masked = (ord($header[1]) & 0x80) !== 0;
                $len = ord($header[1]) & 0x7F;
                if ($len === 126) {
                    $ext = $this->readExact($fp, 2);
                    if ($ext === null) {
                        break;
                    }
                    $len = unpack('n', $ext)[1];
                } elseif ($len === 127) {
                    $ext = $this->readExact($fp, 8);
                    if ($ext === null) {
                        break;
                    }
                    $len = unpack('J', $ext)[1];
                }

                $maskKey = $masked ? $this->readExact($fp, 4) : '';
                if ($masked && $maskKey === null) {
                    break;
                }

                $payload = $len > 0 ? $this->readExact($fp, $len) : '';
                if ($payload === null) {
                    break;
                }
                if ($masked && $maskKey !== '') {
                    $unmasked = '';
                    for ($i = 0; $i < $len; $i++) {
                        $unmasked .= $payload[$i] ^ $maskKey[$i % 4];
                    }
                    $payload = $unmasked;
                }

                if ($opcode === 0x8) {
                    break;
                }
                if ($opcode === 0x9) {
                    $this->writeWsControlFrame($fp, 0xA, $payload);
                    continue;
                }
                if ($opcode === 0x1 && str_contains($payload, 'Path:turn.end')) {
                    $done = true;
                } elseif ($opcode === 0x2 && strlen($payload) >= 2) {
                    $hdrLen = unpack('n', substr($payload, 0, 2))[1];
                    if (2 + $hdrLen <= strlen($payload)) {
                        $audio .= substr($payload, 2 + $hdrLen);
                    }
                }
            }

            return ['audio' => strlen($audio) > 128 ? $audio : null, 'retryWithSkew' => false];
        } catch (Throwable $e) {
            Log::warning('FilipinoNeuralTtsService exception', ['error' => $e->getMessage()]);
            return ['audio' => null, 'retryWithSkew' => false];
        } finally {
            @fclose($fp);
        }
    }

    private function readExact($fp, int $bytes): ?string
    {
        if ($bytes === 0) {
            return '';
        }
        $buf = '';
        while (strlen($buf) < $bytes) {
            $chunk = @fread($fp, $bytes - strlen($buf));
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    private function writeWsTextFrame($fp, string $msg): void
    {
        $len = strlen($msg);
        $mask = random_bytes(4);
        $frame = chr(0x81);
        if ($len <= 125) {
            $frame .= chr(0x80 | $len);
        } elseif ($len <= 65535) {
            $frame .= chr(0x80 | 126) . pack('n', $len);
        } else {
            $frame .= chr(0x80 | 127) . pack('J', $len);
        }
        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= $msg[$i] ^ $mask[$i % 4];
        }
        @fwrite($fp, $frame . $mask . $masked);
    }

    private function writeWsControlFrame($fp, int $opcode, string $payload = ''): void
    {
        $len = strlen($payload) & 0x7F;
        $mask = random_bytes(4);
        $frame = chr(0x80 | ($opcode & 0x0F)) . chr(0x80 | $len) . $mask;
        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }
        @fwrite($fp, $frame . $masked);
    }

    private function adjustClockSkewFromHeader(string $serverDate): bool
    {
        try {
            $dt = new DateTimeImmutable($serverDate);
            $serverTs = $dt->getTimestamp();
            $clientTs = time() + self::$clockSkewSeconds;
            $diff = $serverTs - $clientTs;
            if (abs($diff) >= 5) {
                self::$clockSkewSeconds += $diff;
                return true;
            }
        } catch (Throwable) {
            // ignore parse error
        }
        return false;
    }

    public function generateSecMsGecV2(?int $unixTimestamp = null): string
    {
        $ticks = $unixTimestamp ?? (time() + self::$clockSkewSeconds);
        $ticks += self::WIN_EPOCH;
        $ticks -= ($ticks % 300);
        $ticks *= 10000000;
        [$cs64, $sha256] = $this->tokenHash($ticks . self::TRUSTED_CLIENT_TOKEN);
        return "{$cs64}-{$sha256}";
    }

    private function mul32(int $a, int $b): int
    {
        $a &= 0xFFFFFFFF;
        $b &= 0xFFFFFFFF;
        $aLo = $a & 0xFFFF;
        $aHi = ($a >> 16) & 0xFFFF;
        $bLo = $b & 0xFFFF;
        $bHi = ($b >> 16) & 0xFFFF;
        $lo = $aLo * $bLo;
        $mid = (($aHi * $bLo) & 0xFFFF) + (($aLo * $bHi) & 0xFFFF);
        return ($lo + (($mid & 0xFFFF) << 16)) & 0xFFFFFFFF;
    }

    private function rol16(int $v): int
    {
        $v &= 0xFFFFFFFF;
        return (($v << 16) | ($v >> 16)) & 0xFFFFFFFF;
    }

    private function ror32(int $v, int $n): int
    {
        $v &= 0xFFFFFFFF;
        return (($v >> $n) | ($v << (32 - $n))) & 0xFFFFFFFF;
    }

    private function sigma0(int $x): int
    {
        $x &= 0xFFFFFFFF;
        return ($this->ror32($x, 7) ^ $this->ror32($x, 18) ^ ($x >> 3)) & 0xFFFFFFFF;
    }

    private function sigma1(int $x): int
    {
        $x &= 0xFFFFFFFF;
        return ($this->ror32($x, 17) ^ $this->ror32($x, 19) ^ ($x >> 10)) & 0xFFFFFFFF;
    }

    private function expandSha256Schedule(array &$words, int $firstWord = 16): void
    {
        for ($i = $firstWord; $i < 64; $i++) {
            $words[$i] = ($this->sigma1($words[$i - 2]) + $words[$i - 7] + $this->sigma0($words[$i - 15]) + $words[$i - 16]) & 0xFFFFFFFF;
        }
    }

    private function sha256Rounds(array $state, array $words, int $firstRound = 0): array
    {
        static $k = [
            0x428A2F98, 0x71374491, 0xB5C0FBCF, 0xE9B5DBA5, 0x3956C25B, 0x59F111F1, 0x923F82A4, 0xAB1C5ED5,
            0xD807AA98, 0x12835B01, 0x243185BE, 0x550C7DC3, 0x72BE5D74, 0x80DEB1FE, 0x9BDC06A7, 0xC19BF174,
            0xE49B69C1, 0xEFBE4786, 0x0FC19DC6, 0x240CA1CC, 0x2DE92C6F, 0x4A7484AA, 0x5CB0A9DC, 0x76F988DA,
            0x983E5152, 0xA831C66D, 0xB00327C8, 0xBF597FC7, 0xC6E00BF3, 0xD5A79147, 0x06CA6351, 0x14292967,
            0x27B70A85, 0x2E1B2138, 0x4D2C6DFC, 0x53380D13, 0x650A7354, 0x766A0ABB, 0x81C2C92E, 0x92722C85,
            0xA2BFE8A1, 0xA81A664B, 0xC24B8B70, 0xC76C51A3, 0xD192E819, 0xD6990624, 0xF40E3585, 0x106AA070,
            0x19A4C116, 0x1E376C08, 0x2748774C, 0x34B0BCB5, 0x391C0CB3, 0x4ED8AA4A, 0x5B9CCA4F, 0x682E6FF3,
            0x748F82EE, 0x78A5636F, 0x84C87814, 0x8CC70208, 0x90BEFFFA, 0xA4506CEB, 0xBEF9A3F7, 0xC67178F2,
        ];
        static $kBlock = null;
        if ($kBlock === null) {
            $kBlock = $k;
            $kBlock[14] = 0xC9DC06A7;
        }
        $consts = ($firstRound === 14) ? $kBlock : $k;
        [$a, $b, $c, $d, $e, $f, $g, $h] = $state;
        for ($i = $firstRound; $i < 64; $i++) {
            $s1 = ($this->ror32($e, 6) ^ $this->ror32($e, 11) ^ $this->ror32($e, 25)) & 0xFFFFFFFF;
            $ch = ($g ^ ($e & ($f ^ $g))) & 0xFFFFFFFF;
            $t1 = ($h + $s1 + $ch + $consts[$i] + $words[$i]) & 0xFFFFFFFF;
            $s0 = ($this->ror32($a, 2) ^ $this->ror32($a, 13) ^ $this->ror32($a, 22)) & 0xFFFFFFFF;
            $maj = (($a & $b) | ($c & ($a | $b))) & 0xFFFFFFFF;
            $t2 = ($s0 + $maj) & 0xFFFFFFFF;
            $h = $g;
            $g = $f;
            $f = $e;
            $e = ($d + $t1) & 0xFFFFFFFF;
            $d = $c;
            $c = $b;
            $b = $a;
            $a = ($t1 + $t2) & 0xFFFFFFFF;
        }
        return [$a, $b, $c, $d, $e, $f, $g, $h];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function tokenHash(string $data): array
    {
        static $init = [0x6A09E667, 0xBB67AE85, 0x3C6EF372, 0xA54FF53A, 0x510E527F, 0x9B05688C, 0x1F83D9AB, 0x5BE0CD19];
        static $state0 = [0xFD9FBEA2, 0x91243CE7, 0xBE499094, 0xCD2E1E0A, 0x6C006F49, 0x04BD35AB, 0xFEB0A7E4, 0x03EFFBD2];
        static $sched = [0xEB24BF9E, 0x1558691F, 0xA0C09EE2, 0x0CFA32BA, 0xA622EE8C, 0xD1DD9491, 0xC0DD25A0, 0xF9E70AB6, 0xF0C31573, 0x7058CE4E, 0x7188E606, 0x00E5AB33, 0xD258B060, 0x72766564];
        static $coeffs = [
            [0xE82E91B1, 0xB32700D0], [0x44DE24C6, 0x88DFF51D], [0x3BEB65BC, 0x29514038], [0xC89C5933, 0xB328CBDF],
            [0x8D1EC211, 0xD405B4D5], [0x314EF5D5, 0x26E55F3C], [0xE92491B1, 0x607801C6], [0xF0A5B9D5, 0x82B2C1FB],
            [0x06064CF3, 0xFA51A3DA], [0xDA79FA60, 0x207CC464], [0xBED20560, 0x3C3C74F3], [0x89F9C9F8, 0xD2D14B61],
            [0x35FA38C6, 0xBB04A2C1], [0xCB49E3C6, 0xC5626167],
        ];

        $words = array_fill(0, 14, 0);
        $words[14] = (ord($data[0]) << 16) | (ord($data[1]) << 8) | ord($data[2]);
        $words[15] = unpack('N', substr($data, 3, 4))[1];
        for ($i = 16; $i < 30; $i++) {
            $val = $sched[$i - 16];
            $s1 = $this->sigma1($words[$i - 2]);
            if ($i === 16) {
                $s1 ^= 0x000B92C0;
            }
            $val += $s1 + $words[$i - 7];
            $s0 = $this->sigma0($words[$i - 15]);
            if ($i === 29) {
                $s0 ^= 0x059C0B80;
            }
            $words[$i] = ($val + $s0) & 0xFFFFFFFF;
        }
        $words[30] = ($this->sigma1($words[28]) + $words[23] + $this->sigma0($words[15]) + $words[14] + 0xC9DC06A7 - 0x9BDC06A7) & 0xFFFFFFFF;
        $this->expandSha256Schedule($words, 31);
        $w = $this->sha256Rounds($state0, $words, 14);
        $st = [];
        for ($i = 0; $i < 8; $i++) {
            $st[$i] = ($init[$i] + $w[$i]) & 0xFFFFFFFF;
        }

        $length = 57 + strlen($data);
        $zeroPad = (55 - $length) % 64;
        if ($zeroPad < 0) {
            $zeroPad += 64;
        }
        $padded = substr($data, 7) . "\x80" . str_repeat("\x00", $zeroPad) . pack('J', $length * 8);
        for ($off = 0; $off < strlen($padded); $off += 64) {
            $blk = array_values(unpack('N16', substr($padded, $off, 64)));
            $this->expandSha256Schedule($blk, 16);
            $wk = $this->sha256Rounds($st, $blk, 0);
            for ($i = 0; $i < 8; $i++) {
                $st[$i] = ($st[$i] + $wk[$i]) & 0xFFFFFFFF;
            }
        }
        $digest = pack('N8', ...$st);

        [$ka, $kb] = array_values(unpack('V2', substr($digest, 0, 8)));
        $ka = ($ka | 1) & 0xFFFFFFFF;
        $kb = ($kb | 1) & 0xFFFFFFFF;
        $factors = [$this->mul32($ka, 0x82893933), $this->mul32($kb, 0x82893933)];
        $terms = [];
        $offsets = [];
        foreach ($coeffs as $idx => [$c, $o]) {
            $terms[] = $this->mul32($c, $factors[$idx % 2]);
            $offsets[] = $o;
        }

        $rawWords = "\x00" . $data;
        $padLen = (-strlen($rawWords)) % 4;
        if ($padLen < 0) {
            $padLen += 4;
        }
        $rawWords .= str_repeat("\x00", $padLen);
        $wordsLe = array_values(unpack('V*', $rawWords));
        if (count($wordsLe) % 2 !== 0) {
            $wordsLe[] = 0;
        }

        foreach ($wordsLe as $idx => $word) {
            $term = $this->mul32($word, ($idx % 2 === 0) ? $ka : $kb);
            $off = $this->mul32($this->rol16($word), ($idx % 2 === 0) ? 0xEF0569FB : 0xC31713DB);
            if ($idx === 0) {
                $term = ($term + $this->mul32(0xF2F90B1A, $factors[0])) & 0xFFFFFFFF;
                $off = ($off + 0x0B1A0000) & 0xFFFFFFFF;
            }
            $terms[] = $term & 0xFFFFFFFF;
            $offsets[] = $off & 0xFFFFFFFF;
        }

        $swapAcc = 0;
        $swapLast = 0;
        $s = 0;
        $acc = 0;
        for ($idx = 0; $idx < count($terms); $idx += 2) {
            $x = $terms[$idx];
            $y = $terms[$idx + 1];
            $t = $this->rol16(($offsets[$idx] + $x) & 0xFFFFFFFF);
            $q = $this->mul32($t, 0x76F50F34);
            $swapAcc = ($swapAcc + (($this->mul32($this->rol16($q), 0xC3EFEA97) + $q) & 0xFFFFFFFF)) & 0xFFFFFFFF;
            $t = $this->rol16(($offsets[$idx + 1] + $y) & 0xFFFFFFFF);
            $q = $this->mul32($t, 0x1881CE3C);
            $swapLast = ($this->mul32($this->rol16($q), 0x35BD1EC9) + $q) & 0xFFFFFFFF;
            $swapAcc = ($swapAcc + $swapLast) & 0xFFFFFFFF;

            $s = ($this->mul32($s, $ka) + $x) & 0xFFFFFFFF;
            $s = $this->mul32($this->rol16($s), 0xCF98B111);
            $s = $this->mul32($this->rol16($s), 0x87085B9F);
            $s = $this->mul32($this->rol16($s), 0x12CEB96D);
            $s = $this->mul32($this->rol16($s), 0x257E1D83);
            $acc = ($acc + $s) & 0xFFFFFFFF;
            $t = ($this->mul32($s, $kb) + $y) & 0xFFFFFFFF;
            $t = $this->mul32($this->rol16($t), 0xA27416F5);
            $t = $this->mul32($this->rol16($t), 0xD38396FF);
            $s = $this->mul32($this->rol16($this->mul32($this->rol16($t), 0x7C932B89)), 0xBFA49F69);
            $acc = ($acc + $s) & 0xFFFFFFFF;
        }

        $cs64Hex = strtoupper(bin2hex(pack('VV', $swapLast ^ $s, $swapAcc ^ $acc)));
        return [$cs64Hex, strtoupper(bin2hex($digest))];
    }
}
