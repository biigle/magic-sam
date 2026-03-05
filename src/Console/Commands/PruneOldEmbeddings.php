<?php

namespace Biigle\Modules\MagicSam\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneOldEmbeddings extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'magic-sam:prune-embeddings';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete old image embedding files';

    /**
     * Execute the command.
     *
     * @return void
     */
    public function handle()
    {
        $pruneBefore = now()->subDays(config('magic_sam.prune_age_days'));
        $disk = Storage::disk(config('magic_sam.embedding_storage_disk'));

        $files = $disk->getDriver()
            ->listContents('', true)
            ->filter(function ($attributes) {
                return $attributes->isFile();
            });

        // Prune old files
        foreach ($files as $file) {
            if ($file->lastModified() < $pruneBefore->timestamp) {
                $disk->delete($file->path());
            }
        }

        // Prune empty directories
        $directories = $disk->getDriver()
            ->listContents('', false)
            ->filter(function ($attributes) {
                return $attributes->isDir();
            });

        foreach ($directories as $directory) {
            $dirPath = $directory->path();
            if (empty($disk->files($dirPath))) {
                $disk->deleteDirectory($dirPath);
            }
        }
    }
}
