<?php

namespace App\Services\Security;

use App\Contracts\VirusScanner;
use Illuminate\Support\Facades\Log;

/**
 * Step I-16.2 / M-1.2 — streams the file to a clamd daemon over TCP with the `INSTREAM`
 * command (length-prefixed chunks, then a zero-length chunk) and reads the verdict
 * (`stream: OK` or `stream: <Signature> FOUND`). The file never has to be readable by clamd.
 *
 * If clamd can't be reached, `fail_closed` (default) rejects the upload rather than storing an
 * unscanned file.
 */
class ClamAvVirusScanner implements VirusScanner
{
    private const CHUNK_BYTES = 8192;

    public function scan(string $path): ?string
    {
        $config = (array) config('security.uploads.clamav');
        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (int) ($config['port'] ?? 3310);
        $timeout = (int) ($config['timeout'] ?? 30);

        $verdict = $this->instream($path, $host, $port, $timeout);

        if ($verdict === null) {
            Log::error('ClamAV: scan could not run', ['host' => $host, 'port' => $port]);

            return ($config['fail_closed'] ?? true) ? 'scanner unavailable' : null;
        }

        if (str_ends_with($verdict, 'OK')) {
            return null;
        }

        Log::warning('ClamAV: upload rejected', ['verdict' => $verdict]);

        return $verdict;
    }

    /**
     * The daemon's reply line, or null when the scan couldn't complete.
     */
    private function instream(string $path, string $host, int $port, int $timeout): ?string
    {
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $error, $timeout);
        $file = @fopen($path, 'rb');

        if ($socket === false || $file === false) {
            is_resource($socket) && fclose($socket);
            is_resource($file) && fclose($file);

            return null;
        }

        try {
            stream_set_timeout($socket, $timeout);
            fwrite($socket, "zINSTREAM\0");

            while (! feof($file)) {
                $chunk = (string) fread($file, self::CHUNK_BYTES);

                if ($chunk === '') {
                    break;
                }

                fwrite($socket, pack('N', strlen($chunk)).$chunk);
            }

            fwrite($socket, pack('N', 0));
            $reply = trim((string) stream_get_contents($socket), "\0\r\n ");

            return $reply === '' ? null : $reply;
        } finally {
            fclose($socket);
            fclose($file);
        }
    }
}
