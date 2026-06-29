<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Talks to the Liquidsoap telnet control interface.
 *
 * Liquidsoap exposes a line-based protocol on a TCP port. We connect,
 * send a command terminated by newline, and close with "quit".
 */
class LiquidsoapClient
{
    public function __construct(
        private readonly ?string $host = null,
        private readonly ?int $port = null,
    ) {
    }

    /** Force-skip the currently playing track. */
    public function skip(): bool
    {
        $command = (string) config('radio.liquidsoap.skip_command', 'radio.skip');

        return $this->send($command) !== null;
    }

    /**
     * Send a single command to Liquidsoap and return the response text,
     * or null if the connection failed.
     */
    public function send(string $command): ?string
    {
        $host = $this->host ?? (string) config('radio.liquidsoap.telnet_host');
        $port = $this->port ?? (int) config('radio.liquidsoap.telnet_port');

        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($host, $port, $errno, $errstr, 3.0);

        if ($socket === false) {
            Log::error('Liquidsoap telnet connection failed', [
                'host' => $host, 'port' => $port, 'error' => $errstr,
            ]);

            return null;
        }

        try {
            stream_set_timeout($socket, 3);
            fwrite($socket, $command."\n");

            $response = '';
            // Liquidsoap terminates command output with a line containing "END".
            while (! feof($socket)) {
                $line = fgets($socket);
                if ($line === false) {
                    break;
                }
                if (trim($line) === 'END') {
                    break;
                }
                $response .= $line;
            }

            fwrite($socket, "quit\n");

            return $response;
        } finally {
            fclose($socket);
        }
    }
}
