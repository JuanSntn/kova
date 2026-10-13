<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class BackupFiscalDocuments extends Command
{
    protected $signature = 'fiscal:backup {--verify=}';

    protected $description = 'Copia y verifica artefactos fiscales cifrados en el almacenamiento de respaldo';

    public function handle(): int
    {
        $verify = $this->option('verify');
        if (is_string($verify) && $verify !== '') {
            return $this->verify($verify);
        }
        $source = Storage::disk(config('fiscal.storage_disk'));
        $backup = Storage::disk(config('fiscal.backup_disk'));
        $root = 'backups/fiscal/'.now('UTC')->format('Ymd_His').'_'.bin2hex(random_bytes(4));
        $manifest = [];
        foreach ($source->allFiles('fiscal') as $path) {
            $contents = $source->get($path);
            $target = $root.'/'.$path;
            $backup->put($target, $contents);
            $manifest[] = ['path' => $path, 'bytes' => strlen($contents), 'sha256' => hash('sha256', $contents)];
        }
        $backup->put($root.'/manifest.json', json_encode(['created_at' => now('UTC')->toIso8601String(), 'files' => $manifest], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        $this->components->info("Respaldo creado en {$root} con ".count($manifest).' archivos cifrados.');

        return $this->verify($root);
    }

    private function verify(string $root): int
    {
        $disk = Storage::disk(config('fiscal.backup_disk'));
        if (! $disk->exists($root.'/manifest.json')) {
            $this->components->error('No existe el manifiesto del respaldo.');

            return self::FAILURE;
        }
        $manifest = json_decode($disk->get($root.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($manifest['files'] ?? [] as $file) {
            $path = $root.'/'.$file['path'];
            if (! $disk->exists($path) || ! hash_equals($file['sha256'], hash('sha256', $disk->get($path)))) {
                $this->components->error('El respaldo fiscal no superó la verificación de integridad.');

                return self::FAILURE;
            }
        }
        $this->components->info('El respaldo fiscal superó la verificación de integridad.');

        return self::SUCCESS;
    }
}
