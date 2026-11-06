<?php

namespace App\Support;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator;

class OrderImagePathGenerator extends DefaultPathGenerator
{
    protected function getBasePath(Media $media): string
    {
        return $media->getCustomProperty('snapshot_directory') ?? parent::getBasePath($media);
    }
}
