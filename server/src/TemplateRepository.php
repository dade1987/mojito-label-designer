<?php

declare(strict_types=1);

namespace Mojito\Label;

use InvalidArgumentException;
use RuntimeException;

final class TemplateRepository
{
    public function __construct(
        private readonly string $storageDir,
    ) {
        if (! is_dir($this->storageDir) && ! @mkdir($this->storageDir, 0775, true) && ! is_dir($this->storageDir)) {
            throw new RuntimeException('Impossibile creare la directory dei template.');
        }
    }

    /**
     * @return list<array{id: string, name: string, updatedAt: string}>
     */
    public function list(): array
    {
        $files = glob($this->storageDir.'/*.json') ?: [];
        $templates = [];

        foreach ($files as $file) {
            // Un file illeggibile (permessi, o una cartella che finisce in
            // .json) si salta e basta: il warning PHP non deve arrivare a
            // PHPUnit, che con stop-on-defect fermerebbe l'intera suite
            // (e Infection segnerebbe "sfuggiti" mutanti mai eseguiti).
            $content = @file_get_contents($file);

            if ($content === false) {
                continue;
            }

            $decoded = json_decode($content, true);

            if (! is_array($decoded)) {
                continue;
            }

            $record = TypeCaster::stringKeyedArray($decoded);

            $templates[] = [
                'id' => TypeCaster::string($record['id'] ?? basename($file, '.json')),
                'name' => TypeCaster::string($record['name'] ?? basename($file, '.json')),
                'updatedAt' => date('c', (int) filemtime($file)),
            ];
        }

        usort($templates, static fn (array $a, array $b): int => strcmp($b['updatedAt'], $a['updatedAt']));

        return $templates;
    }

    /**
     * @return array<string, mixed>
     */
    public function find(string $id): array
    {
        $path = $this->pathFor($id);

        if (! is_file($path)) {
            throw new InvalidArgumentException('Template non trovato: '.$id);
        }

        $content = @file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException('Impossibile leggere il template: '.$id);
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Template JSON non valido: '.$id);
        }

        return TypeCaster::stringKeyedArray($decoded);
    }

    /**
     * Scrive il layout su disco.
     *
     * Con `$overwrite = false` un layout gia' presente con lo stesso
     * identificativo non viene toccato: si lancia TemplateExistsException.
     * E' la rete di sicurezza per "Salva con nome..." e per il salvataggio
     * di un layout che il client crede nuovo ma che un'altra postazione ha
     * gia' creato nel frattempo.
     *
     * @param  array<string, mixed>  $template
     * @return array<string, mixed>
     */
    public function save(array $template, bool $overwrite = true): array
    {
        $sanitized = $this->sanitize($template);
        $id = TypeCaster::string($sanitized['id']);
        $path = $this->pathFor($id);

        if (! $overwrite && is_file($path)) {
            throw new TemplateExistsException($id, $this->nameOf($id));
        }

        $encoded = json_encode($sanitized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($encoded === false || file_put_contents($path, $encoded) === false) {
            throw new RuntimeException('Impossibile salvare il template.');
        }

        return $sanitized;
    }

    public function delete(string $id): void
    {
        $path = $this->pathFor($id);

        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * @param  array<string, mixed>  $template
     * @return array<string, mixed>
     */
    private function sanitize(array $template): array
    {
        if (! isset($template['elements']) || ! is_array($template['elements'])) {
            throw new InvalidArgumentException('Il template deve contenere elements.');
        }

        $rawId = TypeCaster::string($template['id'] ?? uniqid('layout_', true));
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '_', $rawId) ?? uniqid('layout_', true);
        $dataSourcesInput = $template['dataSources'] ?? [];

        if (! is_array($dataSourcesInput)) {
            $dataSourcesInput = [];
        }

        return [
            'id' => $id,
            'name' => TypeCaster::string($template['name'] ?? 'Etichetta senza nome', 'Etichetta senza nome'),
            'labelWidth' => TypeCaster::int($template['labelWidth'] ?? 600, 600),
            'labelHeight' => TypeCaster::int($template['labelHeight'] ?? 400, 400),
            'dpi' => TypeCaster::int($template['dpi'] ?? 203, 203),
            // Tutto cio' che il designer salva deve tornare indietro uguale:
            // queste proprieta' venivano scartate e "Salva server" perdeva
            // offset origine, avanzamento carta, intensita' e velocita'.
            'originX' => TypeCaster::int($template['originX'] ?? 0, 0),
            'originY' => TypeCaster::int($template['originY'] ?? 0, 0),
            'mediaTracking' => TypeCaster::string($template['mediaTracking'] ?? 'gap', 'gap'),
            'darkness' => TypeCaster::int($template['darkness'] ?? 0, 0),
            'printSpeed' => TypeCaster::int($template['printSpeed'] ?? 0, 0),
            // Come va stampato: comandi ZPL (il default di sempre) oppure
            // disegnato e mandato alla coda di sistema, per le stampanti che
            // lo ZPL non lo parlano.
            'printMode' => TypeCaster::string($template['printMode'] ?? LabelPrinterService::MODE_ZPL, LabelPrinterService::MODE_ZPL) === LabelPrinterService::MODE_GRAPHIC
                ? LabelPrinterService::MODE_GRAPHIC
                : LabelPrinterService::MODE_ZPL,
            'dataSources' => array_map(static function (mixed $source): array {
                if (! is_array($source)) {
                    throw new InvalidArgumentException('Ogni data source deve essere un oggetto.');
                }

                $record = TypeCaster::stringKeyedArray($source);

                return [
                    'name' => TypeCaster::string($record['name'] ?? ''),
                    'label' => TypeCaster::string($record['label'] ?? $record['name'] ?? ''),
                    'defaultValue' => TypeCaster::string($record['defaultValue'] ?? ''),
                ];
            }, $dataSourcesInput),
            'elements' => $template['elements'],
        ];
    }

    /**
     * Il nome salvato nel file, o l'identificativo se il file non si legge:
     * serve solo per un messaggio, non deve far fallire nulla.
     */
    private function nameOf(string $id): string
    {
        try {
            return TypeCaster::string($this->find($id)['name'] ?? $id, $id);
        } catch (RuntimeException) {
            return $id;
        }
    }

    private function pathFor(string $id): string
    {
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $id) ?? $id;

        return $this->storageDir.'/'.$safeId.'.json';
    }
}
