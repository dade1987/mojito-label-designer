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
            $content = file_get_contents($file);

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

        $content = file_get_contents($path);

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
     * @param  array<string, mixed>  $template
     * @return array<string, mixed>
     */
    public function save(array $template): array
    {
        $sanitized = $this->sanitize($template);
        $id = TypeCaster::string($sanitized['id']);
        $path = $this->pathFor($id);

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
            'dataSources' => array_map(static function (mixed $source): array {
                if (! is_array($source)) {
                    throw new InvalidArgumentException('Ogni data source deve essere un oggetto.');
                }

                $record = TypeCaster::stringKeyedArray($source);

                return [
                    'name' => TypeCaster::string($record['name'] ?? ''),
                    'label' => TypeCaster::string($record['label'] ?? $record['name'] ?? ''),
                ];
            }, $dataSourcesInput),
            'elements' => $template['elements'],
        ];
    }

    private function pathFor(string $id): string
    {
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $id) ?? $id;

        return $this->storageDir.'/'.$safeId.'.json';
    }
}
