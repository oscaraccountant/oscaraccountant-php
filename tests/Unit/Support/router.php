<?php

declare(strict_types=1);

// The router of the PHP built-in server that the transport tests start.

$uri = \is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
$path = parse_url($uri, \PHP_URL_PATH);
$query = [];
parse_str((string) parse_url($uri, \PHP_URL_QUERY), $query);
$headers = [];
foreach ($_SERVER as $name => $value) {
    if (str_starts_with((string) $name, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr((string) $name, 5)))] = $value;
    }
}
if (isset($_SERVER['CONTENT_TYPE'])) {
    $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
}
$wait = static function (mixed $seconds): void {
    usleep((int) (1_000_000 * (is_numeric($seconds) ? (float) $seconds : 0.0)));
};

switch ($path) {
    case '/echo':
        $wait($query['sleep'] ?? 0);
        header('Content-Type: application/json');
        header('X-Request-Id: req_echo');
        echo json_encode([
            'method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
            'headers' => $headers,
            'body' => file_get_contents('php://input'),
        ]);
        break;
    case '/problem':
        http_response_code(404);
        header('Content-Type: application/problem+json');
        echo json_encode(['code' => 'not_found', 'title' => 'Not found', 'detail' => 'No such thing.']);
        break;
    case '/stream':
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="a.pdf"');
        echo 'first-';
        flush();
        $wait($query['sleep'] ?? 0);
        echo 'second';
        break;
    case '/slow-headers':
        $wait($query['sleep'] ?? 0);
        header('Content-Type: application/pdf');
        echo 'late';
        break;
    default:
        http_response_code(500);
        echo 'unknown path';
}
