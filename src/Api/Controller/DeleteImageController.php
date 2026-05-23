<?php

namespace SpottersTurkey\UploadExif\Api\Controller;

use Flarum\Http\RequestUtil;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Laminas\Diactoros\Response\EmptyResponse;
use SpottersTurkey\UploadExif\Database\SpotterImage;
use Illuminate\Support\Arr;

class DeleteImageController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $id = Arr::get($request->getQueryParams(), 'id');
        $image = SpotterImage::findOrFail($id);

        if ($image->user_id !== $actor->id && !$actor->isAdmin()) {
            throw new \Flarum\User\Exception\PermissionDeniedException;
        }

        $basePath = public_path(); 
        $relativePath = ltrim($image->path, '/');
        $relativeThumb = ltrim($image->thumb_path, '/');

        if (file_exists("$basePath/$relativePath")) @unlink("$basePath/$relativePath");
        if (file_exists("$basePath/$relativeThumb")) @unlink("$basePath/$relativeThumb");

        $image->delete();

        return new EmptyResponse(204);
    }
}