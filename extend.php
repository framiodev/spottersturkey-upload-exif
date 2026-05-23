<?php

use Flarum\Extend;
use s9e\TextFormatter\Configurator;
use SpottersTurkey\UploadExif\Api\Controller\UploadImageController;
use SpottersTurkey\UploadExif\Api\Controller\ShowImageController;
use SpottersTurkey\UploadExif\Api\Controller\ListImagesController;
use SpottersTurkey\UploadExif\Api\Controller\UserGalleryController;
use SpottersTurkey\UploadExif\Api\Controller\DeleteImageController;
use SpottersTurkey\UploadExif\Api\Controller\EditImageController;
use SpottersTurkey\UploadExif\Api\Controller\ImageIndexController;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/resources/less/forum.less')
        ->css(__DIR__.'/resources/less/spotters-user-gallery.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    (new Extend\ApiResource(SpottersTurkey\UploadExif\Api\Resource\SpotterImageResource::class)),

    (new Extend\Routes('api'))
        ->post('/spotter-upload', 'spotters.upload', SpottersTurkey\UploadExif\Api\Controller\UploadImageController::class)
        ->get('/spotter-images/all', 'spotters.admin.list', SpottersTurkey\UploadExif\Api\Controller\ListImagesController::class) // Deprecated, but keeping for compatibility
        ->get('/spotter-images/user', 'spotters.user.gallery', SpottersTurkey\UploadExif\Api\Controller\UserGalleryController::class)
        ->patch('/spotter-image/edit', 'spotters.update', SpottersTurkey\UploadExif\Api\Controller\EditImageController::class)
        ->get('/spotter-images/index', 'spotters.images.index', SpottersTurkey\UploadExif\Api\Controller\ImageIndexController::class),

    (new Extend\Formatter)
        ->configure(function (Configurator $config) {
            $config->BBCodes->addCustom(
                '[spotter-image id={NUMBER} url={URL} alt={TEXT}]',
                '<div class="spotter-image-container SpotterCard" data-id="{NUMBER}">
                    <div class="SpotterCard-image-wrapper">
                        <a href="{URL}" class="spotter-image-link" target="_blank" data-fancybox="gallery">
                            <img src="{URL}" alt="{TEXT}" class="spotter-lazy" loading="lazy" />
                        </a>
                    </div>
                    <div class="spotter-exif-placeholder"></div>
                 </div>'
            );
            $config->BBCodes->addCustom(
                '[upl-image-preview uuid={TEXT1} url={URL} alt={TEXT2?}]',
                '<div class="fof-legacy-image"><img src="{URL}" alt="{TEXT2}" loading="lazy" style="max-width:100%;height:auto;" /></div>'
            );
        }),
];