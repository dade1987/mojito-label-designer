<?php

declare(strict_types=1);

use Mojito\Label\ApiHandler;
use Mojito\Label\LabelPrinterService;
use Mojito\Label\TemplateRepository;

require dirname(__DIR__).'/vendor/autoload.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($requestMethod === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$service = new LabelPrinterService;
$templates = new TemplateRepository(dirname(__DIR__).'/storage/templates');
$handler = new ApiHandler($service, $templates);

$requestUri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])
    ? $_SERVER['REQUEST_URI']
    : '/';
$path = parse_url($requestUri, PHP_URL_PATH);
$path = is_string($path) && $path !== '' ? $path : '/';
$method = is_string($requestMethod) ? $requestMethod : 'GET';
$rawBody = file_get_contents('php://input') ?: '';

$result = $handler->handle($method, $path, $rawBody);

http_response_code($result['status']);
echo json_encode($result['payload'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
