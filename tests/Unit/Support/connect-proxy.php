<?php

declare(strict_types=1);

// A fake HTTP proxy for the transport tests: it answers each CONNECT with `200 Connection established`,
// then closes the tunnel before the TLS handshake. Run as `php connect-proxy.php <port>`.

$server = stream_socket_server('tcp://127.0.0.1:' . (int) ($argv[1] ?? 0));
if ($server === false) {
    exit(1);
}
for (;;) {
    $client = @stream_socket_accept($server, 3600);
    if ($client === false) {
        continue;
    }
    $head = '';
    while (!str_contains($head, "\r\n\r\n") && !feof($client)) {
        $head .= (string) fread($client, 1024);
    }
    fwrite($client, "HTTP/1.1 200 Connection established\r\n\r\n");
    usleep(200_000);
    fclose($client);
}
