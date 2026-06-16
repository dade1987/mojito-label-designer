<?php

declare(strict_types=1);

namespace Mojito\Label\Tests\Unit;

use InvalidArgumentException;
use Mojito\Label\TemplateRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TemplateRepositoryTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/mojito_templates_'.uniqid('', true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir.'/*.json') ?: [];

        foreach ($files as $file) {
            unlink($file);
        }

        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function test_save_and_find_template(): void
    {
        $repository = new TemplateRepository($this->tempDir);

        $saved = $repository->save([
            'id' => 'test-layout',
            'name' => 'Test Layout',
            'dataSources' => [
                ['name' => 'barcode', 'label' => 'Barcode', 'defaultValue' => 'ABC123'],
            ],
            'elements' => [
                ['id' => 'bc', 'type' => 'barcode', 'dataSource' => 'barcode', 'x' => 10, 'y' => 10],
            ],
        ]);

        $loaded = $repository->find('test-layout');

        $this->assertSame('test-layout', $saved['id']);
        $this->assertSame('Test Layout', $loaded['name']);
        $this->assertCount(1, $loaded['elements']);
        $this->assertSame('ABC123', $loaded['dataSources'][0]['defaultValue']);
    }

    public function test_list_skips_invalid_and_unreadable_files(): void
    {
        $repository = new TemplateRepository($this->tempDir);
        file_put_contents($this->tempDir.'/broken.json', '{ invalid');

        $blocked = $this->tempDir.'/blocked.json';
        file_put_contents($blocked, '{}');
        chmod($blocked, 0000);

        $repository->save([
            'id' => 'valid',
            'name' => 'Valid',
            'elements' => [],
            'dataSources' => [],
        ]);

        $list = $repository->list();
        $this->assertCount(1, $list);
        $this->assertSame('valid', $list[0]['id']);

        chmod($blocked, 0644);
        unlink($blocked);
    }

    public function test_find_missing_template_throws(): void
    {
        $repository = new TemplateRepository($this->tempDir);

        $this->expectException(InvalidArgumentException::class);
        $repository->find('missing');
    }

    public function test_find_invalid_json_throws(): void
    {
        $repository = new TemplateRepository($this->tempDir);
        file_put_contents($this->tempDir.'/bad.json', '{ invalid');

        $this->expectException(RuntimeException::class);
        $repository->find('bad');
    }

    public function test_save_requires_elements(): void
    {
        $repository = new TemplateRepository($this->tempDir);

        $this->expectException(InvalidArgumentException::class);
        $repository->save(['name' => 'No elements']);
    }

    public function test_save_requires_valid_data_sources(): void
    {
        $repository = new TemplateRepository($this->tempDir);

        $this->expectException(InvalidArgumentException::class);
        $repository->save([
            'elements' => [],
            'dataSources' => ['invalid'],
        ]);
    }

    public function test_delete_removes_file(): void
    {
        $repository = new TemplateRepository($this->tempDir);
        $repository->save([
            'id' => 'to-delete',
            'name' => 'Delete me',
            'elements' => [],
            'dataSources' => [],
        ]);

        $repository->delete('to-delete');

        $this->expectException(InvalidArgumentException::class);
        $repository->find('to-delete');
    }

    public function test_delete_missing_file_is_no_op(): void
    {
        $repository = new TemplateRepository($this->tempDir);
        $repository->delete('missing');
        $this->assertSame([], $repository->list());
    }

    public function test_constructor_fails_when_directory_cannot_be_created(): void
    {
        $blockedPath = tempnam(sys_get_temp_dir(), 'mojito_block_');
        $this->assertNotFalse($blockedPath);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Impossibile creare la directory dei template.');

        try {
            new TemplateRepository($blockedPath);
        } finally {
            unlink($blockedPath);
        }
    }

    public function test_find_unreadable_template_throws(): void
    {
        $repository = new TemplateRepository($this->tempDir);
        $path = $this->tempDir.'/unreadable.json';
        file_put_contents($path, '{}');
        chmod($path, 0000);

        try {
            $this->expectException(RuntimeException::class);
            $repository->find('unreadable');
        } finally {
            chmod($path, 0644);
            unlink($path);
        }
    }
}
