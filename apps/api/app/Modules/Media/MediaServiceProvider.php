<?php

declare(strict_types=1);

namespace App\Modules\Media;

use App\Modules\Media\Application\Services\MediaService;
use App\Modules\Media\Domain\Contracts\HostResolverInterface;
use App\Modules\Media\Domain\Contracts\MediaAssetRepositoryInterface;
use App\Modules\Media\Domain\Contracts\MediaAttachmentRepositoryInterface;
use App\Modules\Media\Domain\Contracts\MediaStorageInterface;
use App\Modules\Media\Domain\Contracts\PinnedImageDownloaderInterface;
use App\Modules\Media\Domain\Contracts\RenditionGeneratorInterface;
use App\Modules\Media\Infrastructure\Net\CurlPinnedImageDownloader;
use App\Modules\Media\Infrastructure\Net\DnsHostResolver;
use App\Modules\Media\Infrastructure\Persistence\EloquentMediaAssetRepository;
use App\Modules\Media\Infrastructure\Persistence\EloquentMediaAttachmentRepository;
use App\Modules\Media\Infrastructure\Rendition\ImageRenditionGenerator;
use App\Modules\Media\Infrastructure\Storage\MediaStorageAdapter;
use App\Modules\Media\Presentation\Console\PromoteFailedImageAssetsCommand;
use App\Shared\Contracts\MediaServiceInterface;
use Illuminate\Support\ServiceProvider;

class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MediaAttachmentRepositoryInterface::class, EloquentMediaAttachmentRepository::class);
        $this->app->bind(MediaAssetRepositoryInterface::class, EloquentMediaAssetRepository::class);
        $this->app->bind(MediaStorageInterface::class, MediaStorageAdapter::class);
        $this->app->bind(RenditionGeneratorInterface::class, ImageRenditionGenerator::class);
        $this->app->bind(MediaServiceInterface::class, MediaService::class);
        $this->app->bind(HostResolverInterface::class, DnsHostResolver::class);
        $this->app->bind(PinnedImageDownloaderInterface::class, CurlPinnedImageDownloader::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');

        if ($this->app->runningInConsole()) {
            // Operator-invoked only — deliberately NOT scheduled (see the
            // command's docblock and the A2 backfill migration).
            $this->commands([
                PromoteFailedImageAssetsCommand::class,
            ]);
        }
    }
}
