<?php

namespace SpottersTurkey\UploadExif\Api\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Laminas\Diactoros\Response\JsonResponse;
use SpottersTurkey\UploadExif\Database\SpotterImage;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Support\Arr;
use Flarum\Http\RequestUtil;

class UpdateImageController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $imageId = Arr::get($request->getQueryParams(), 'id');
        
        $image = SpotterImage::findOrFail($imageId);

        if ($actor->id !== $image->user_id && !$actor->isAdmin()) {
            throw new PermissionDeniedException;
        }

        $data = Arr::get($request->getParsedBody(), 'data.attributes', []);

        if (isset($data['title'])) {
            $image->title = $data['title'];
        }
        
        if (isset($data['description'])) {
            $image->description = $data['description'];
        }

        $image->save();

        $responsePayload = [
            'type' => 'spotter-images',
            'id' => (string)$image->id,
            'attributes' => [
                'filename' => $image->filename,
                'url' => $image->thumb_path ?: $image->path,
                'original_url' => $image->path,
                'thumb_path' => $image->thumb_path,
                'title' => $image->title ?? null,
                'description' => $image->description ?? null,
                'createdAt' => $image->created_at ?? null,
                'exif' => json_decode($image->exif_data ?? '{}', true)
            ]
        ];

        return new JsonResponse(['data' => $responsePayload]);
    }
}