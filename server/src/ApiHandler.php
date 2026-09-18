<?php

declare(strict_types=1);

namespace Mojito\Label;

use InvalidArgumentException;
use Throwable;

final class ApiHandler
{
    public function __construct(
        private readonly LabelPrinterService $service,
        private readonly TemplateRepository $templates,
        private readonly ?PrintDestinations $destinations = null,
    ) {}

    /**
     * @param  array<string, string>  $headers  Intestazioni della richiesta (chiavi case-insensitive).
     * @return array{status: int, payload: array<string, mixed>}
     */
    public function handle(string $method, string $path, string $rawBody = '', array $headers = []): array
    {
        try {
            // Sul server di produzione il designer non deve essere aperto a
            // chiunque passi davanti alla postazione: con MOJITO_PASSWORD
            // impostata, tutte le API (salvo salute e login) chiedono la
            // password. Senza la variabile — sviluppo, uso locale — nulla
            // cambia.
            $password = self::requiredPassword();

            if ($method === 'GET' && $path === '/api/auth') {
                return $this->ok([
                    'passwordRequired' => $password !== '',
                    'authenticated' => $password === '' || $this->isAuthenticated($headers, $password),
                ]);
            }

            if ($method === 'POST' && $path === '/api/auth/check') {
                $body = $this->decodeBody($rawBody);
                $given = TypeCaster::string($body['password'] ?? '');

                if ($password !== '' && ! hash_equals($password, $given)) {
                    return $this->error(401, 'Password errata.');
                }

                return $this->ok(['status' => 'ok']);
            }

            if ($password !== '' && $path !== '/api/health' && ! $this->isAuthenticated($headers, $password)) {
                return $this->error(401, 'Password richiesta.');
            }

            return match (true) {
                $method === 'GET' && $path === '/api/health' => $this->ok(['status' => 'ok']),
                $method === 'GET' && $path === '/api/printers' => $this->ok($this->printersInfo()),
                $method === 'GET' && $path === '/api/template/default' => $this->ok($this->defaultTemplate()),
                $method === 'GET' && $path === '/api/templates' => $this->ok(['templates' => $this->templates->list()]),
                $method === 'GET' && preg_match('#^/api/templates/([^/]+)$#', $path, $matches) === 1 => $this->ok($this->templates->find($matches[1])),
                $method === 'POST' && $path === '/api/templates' => $this->handleSaveTemplate($this->decodeBody($rawBody)),
                $method === 'DELETE' && preg_match('#^/api/templates/([^/]+)$#', $path, $matches) === 1 => $this->handleDeleteTemplate($matches[1]),
                $method === 'POST' && $path === '/api/zpl/preview' => $this->handlePreview($this->decodeBody($rawBody)),
                $method === 'POST' && $path === '/api/label/preview' => $this->handleImagePreview($this->decodeBody($rawBody)),
                $method === 'POST' && $path === '/api/print' => $this->handlePrint($this->decodeBody($rawBody)),
                default => $this->error(404, 'Endpoint non trovato'),
            };
        } catch (Throwable $exception) {
            return $this->error(500, $exception->getMessage());
        }
    }

    /**
     * La password richiesta dall'installazione, se c'e'.
     *
     * Si legge da piu' fonti perche' i loader .env non popolano tutti
     * getenv(): Laravel (phpdotenv) riempie $_ENV/$_SERVER.
     */
    public static function requiredPassword(): string
    {
        foreach ([getenv('MOJITO_PASSWORD'), $_ENV['MOJITO_PASSWORD'] ?? null, $_SERVER['MOJITO_PASSWORD'] ?? null] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '';
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function isAuthenticated(array $headers, string $password): bool
    {
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'x-mojito-auth') {
                return hash_equals($password, trim($value));
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, payload: array<string, mixed>}
     */
    private function ok(array $payload): array
    {
        return ['status' => 200, 'payload' => $payload];
    }

    /**
     * @return array{status: int, payload: array<string, mixed>}
     */
    private function error(int $status, string $message): array
    {
        return ['status' => $status, 'payload' => ['error' => $message]];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(string $rawBody): array
    {
        if (trim($rawBody) === '') {
            return [];
        }

        $decoded = json_decode($rawBody, true);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('JSON non valido nel body della richiesta.');
        }

        return TypeCaster::stringKeyedArray($decoded);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function resolvePrintPayload(array $body): array
    {
        if (isset($body['templateId']) && is_string($body['templateId']) && $body['templateId'] !== '') {
            $body['template'] = $this->templates->find($body['templateId']);
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{status: int, payload: array<string, mixed>}
     */
    private function handlePreview(array $body): array
    {
        $body = $this->resolvePrintPayload($body);
        $printer = TypeCaster::string($body['printer'] ?? LabelPrinterService::DEFAULT_PRINTER, LabelPrinterService::DEFAULT_PRINTER);

        if ($printer !== '') {
            $this->service->setPrinterName($printer);
        }

        return $this->ok([
            'zpl' => $this->service->buildZpl($body),
            'printer' => $this->service->getPrinterName(),
        ]);
    }

    /**
     * L'anteprima di come verrà stampata l'etichetta sulle stampanti che non
     * parlano ZPL: la stessa immagine che finirà nella coda di stampa.
     *
     * @param  array<string, mixed>  $body
     * @return array{status: int, payload: array<string, mixed>}
     */
    private function handleImagePreview(array $body): array
    {
        $body = $this->resolvePrintPayload($body);
        $png = $this->service->renderPng($body);
        $media = LabelMedia::fromTemplate(
            isset($body['template']) && is_array($body['template'])
                ? TypeCaster::stringKeyedArray($body['template'])
                : []
        );

        return $this->ok([
            'png' => 'data:image/png;base64,'.base64_encode($png),
            'width' => $media->widthDots(),
            'height' => $media->heightDots(),
            'widthMm' => round($media->widthMillimetres(), 1),
            'heightMm' => round($media->heightMillimetres(), 1),
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{status: int, payload: array<string, mixed>}
     */
    private function handlePrint(array $body): array
    {
        $body = $this->resolvePrintPayload($body);
        $printer = TypeCaster::string($body['printer'] ?? LabelPrinterService::DEFAULT_PRINTER, LabelPrinterService::DEFAULT_PRINTER);

        if ($printer !== '') {
            $this->service->setPrinterName($printer);
        }

        $copies = max(1, TypeCaster::int($body['copies'] ?? 1, 1));
        $jobs = $this->resolveJobs($body);
        $total = count($jobs) * $copies;

        if ($total > LabelPrinterService::MAX_COPIES) {
            return $this->error(500, 'Stampa rifiutata: '.$total.' etichette superano il massimo di '.LabelPrinterService::MAX_COPIES.' per richiesta.');
        }

        // Una destinazione offerta da chi ospita Mojito: le si consegna lo ZPL
        // intero, e la stampa (o la coda) la fa lei.
        if ($this->destinations !== null && $this->destinations->handles($printer)) {
            $rawZpl = isset($body['zpl']) && is_string($body['zpl']) && $body['zpl'] !== '' ? $body['zpl'] : null;
            $zpl = $rawZpl !== null
                ? ZplBatch::repeat($rawZpl, $copies)
                : $this->service->buildSeriesZpl(
                    array_map(static fn (array $values): array => ['values' => $values] + $body, $jobs),
                    $copies
                );
            $labels = $rawZpl !== null ? $copies : count($jobs) * $copies;
            $result = $this->destinations->send($printer, $zpl, $labels);

            return $this->ok([
                'status' => $result['status'],
                'printed' => $labels,
                'copies' => $copies,
                'mode' => LabelPrinterService::MODE_ZPL,
                'printer' => $printer,
                'method' => 'destination',
            ]);
        }

        // Tutte le etichette della richiesta in un lavoro di stampa solo: uno
        // per etichetta fa ripartire la stampante ogni volta.
        if (isset($body['zpl']) && is_string($body['zpl']) && $body['zpl'] !== '') {
            $this->service->printZpl(ZplBatch::repeat($body['zpl'], $copies));

            $printed = $copies;
            $mode = LabelPrinterService::MODE_ZPL;
        } else {
            $mode = $this->service->resolvePrintMode($body);
            $this->service->printJobs(
                array_map(static fn (array $values): array => ['values' => $values] + $body, $jobs),
                $copies
            );
            $printed = count($jobs) * $copies;
        }

        $payload = [
            'status' => 'printed',
            'printed' => $printed,
            'copies' => $copies,
            'mode' => $mode,
            'printer' => $this->service->getPrinterName(),
            'method' => $this->service->getLastPrintMethod(),
        ];

        if (filter_var(getenv('MOJITO_PRINTER_DEBUG'), FILTER_VALIDATE_BOOL)) {
            $payload['printOutput'] = $this->service->getLastPrintOutput();
        }

        return $this->ok($payload);
    }

    /**
     * Le stampanti del server, piu' le destinazioni offerte da chi ospita
     * Mojito, con il nome da mostrare e la modalita' (sempre ZPL).
     *
     * @return array<string, mixed>
     */
    private function printersInfo(): array
    {
        $info = $this->service->listPrintersInfo();

        if ($this->destinations === null) {
            return $info;
        }

        $labels = [];

        foreach ($this->destinations->printers() as $destination) {
            $info['printers'][] = $destination['value'];
            $labels[$destination['value']] = $destination['label'];
            $info['printerModes'][$destination['value']] = LabelPrinterService::MODE_ZPL;
        }

        $info['printerLabels'] = $labels;

        return $info;
    }

    /**
     * Una stampa può valere per una etichetta sola o per una serie: `jobs` è
     * l'elenco dei valori che cambiano da un'etichetta all'altra (i numeri di
     * serie), sopra ai valori comuni di `values`.
     *
     * @param  array<string, mixed>  $body
     * @return list<array<string, mixed>>
     */
    private function resolveJobs(array $body): array
    {
        $shared = isset($body['values']) && is_array($body['values'])
            ? TypeCaster::stringKeyedArray($body['values'])
            : [];

        if (! isset($body['jobs'])) {
            return [$shared];
        }

        if (! is_array($body['jobs']) || $body['jobs'] === []) {
            throw new InvalidArgumentException('Il campo jobs deve essere un elenco non vuoto di valori per etichetta.');
        }

        $jobs = [];

        foreach ($body['jobs'] as $job) {
            if (! is_array($job)) {
                throw new InvalidArgumentException('Il campo jobs deve contenere solo insiemi di valori.');
            }

            $jobs[] = TypeCaster::stringKeyedArray($job) + $shared;
        }

        return $jobs;
    }

    /**
     * Il body e' il layout stesso, piu' il flag `overwrite`: senza (o `true`)
     * si sovrascrive come sempre, con `false` un layout gia' presente con lo
     * stesso identificativo resta intatto e si risponde 409.
     *
     * @param  array<string, mixed>  $body
     * @return array{status: int, payload: array<string, mixed>}
     */
    private function handleSaveTemplate(array $body): array
    {
        $overwrite = ($body['overwrite'] ?? true) !== false;
        unset($body['overwrite']);

        try {
            $saved = $this->templates->save($body, $overwrite);
        } catch (TemplateExistsException $exception) {
            return [
                'status' => 409,
                'payload' => [
                    'error' => $exception->getMessage(),
                    'conflict' => ['id' => $exception->templateId, 'name' => $exception->existingName],
                ],
            ];
        }

        return $this->ok([
            'status' => 'saved',
            'template' => $saved,
        ]);
    }

    /**
     * @return array{status: int, payload: array<string, mixed>}
     */
    private function handleDeleteTemplate(string $id): array
    {
        $this->templates->delete($id);

        return $this->ok(['status' => 'deleted', 'id' => $id]);
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultTemplate(): array
    {
        return [
            'id' => 'cavallini-service',
            'name' => 'Cavallini Service',
            'labelWidth' => 600,
            'labelHeight' => 400,
            'dpi' => 203,
            'dataSources' => [
                ['name' => 'title', 'label' => 'Titolo', 'defaultValue' => 'CAVALLINI SERVICE'],
                ['name' => 'product', 'label' => 'Prodotto', 'defaultValue' => 'Test'],
                ['name' => 'serial', 'label' => 'Seriale', 'defaultValue' => 'ABC123'],
                ['name' => 'barcode', 'label' => 'Barcode', 'defaultValue' => 'ABC123456789'],
            ],
            'elements' => [
                [
                    'id' => 'title',
                    'type' => 'text',
                    'x' => 40,
                    'y' => 40,
                    'font' => '0',
                    'fontHeight' => 40,
                    'fontWidth' => 40,
                    'dataSource' => 'title',
                ],
                [
                    'id' => 'product',
                    'type' => 'text',
                    'x' => 40,
                    'y' => 100,
                    'font' => '0',
                    'fontHeight' => 30,
                    'fontWidth' => 30,
                    'dataSource' => 'product',
                    'prefix' => 'Prodotto: ',
                ],
                [
                    'id' => 'serial',
                    'type' => 'text',
                    'x' => 40,
                    'y' => 145,
                    'font' => '0',
                    'fontHeight' => 30,
                    'fontWidth' => 30,
                    'dataSource' => 'serial',
                    'prefix' => 'Seriale: ',
                ],
                [
                    'id' => 'barcode',
                    'type' => 'barcode',
                    'x' => 40,
                    'y' => 200,
                    'barcodeType' => 'code128',
                    'moduleWidth' => 2,
                    'height' => 100,
                    'showText' => true,
                    'dataSource' => 'barcode',
                ],
            ],
        ];
    }
}
