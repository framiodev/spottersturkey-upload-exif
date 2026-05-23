<?php

namespace SpottersTurkey\UploadExif\Api\Resource;

use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use SpottersTurkey\UploadExif\Database\SpotterImage;

/**
 * @extends AbstractDatabaseResource<SpotterImage>
 */
class SpotterImageResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'spotter-images';
    }

    public function model(): string
    {
        return SpotterImage::class;
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Show::make(),
            Endpoint\Index::make()
                ->paginate()
                ->defaultInclude(['user']),
            Endpoint\Update::make()
                ->visible(function (SpotterImage $image, Context $context) {
                    $actor = $context->getActor();
                    return $actor->isAdmin() || $actor->id === $image->user_id;
                }),
            Endpoint\Delete::make()
                ->visible(function (SpotterImage $image, Context $context) {
                    $actor = $context->getActor();
                    return $actor->isAdmin() || $actor->id === $image->user_id;
                }),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('filename')
                ->writable(false),
            Schema\Str::make('url')
                ->get(function (SpotterImage $image) {
                    return $image->thumb_path ?: $image->path;
                }),
            Schema\Str::make('original_url')
                ->get(function (SpotterImage $image) {
                    return $image->path;
                }),
            Schema\Str::make('thumb_path')
                ->writable(false),
            Schema\Str::make('title')
                ->writable(function (SpotterImage $image, Context $context) {
                    $actor = $context->getActor();
                    return $actor->isAdmin() || $actor->id === $image->user_id;
                }),
            Schema\Str::make('description')
                ->writable(function (SpotterImage $image, Context $context) {
                    $actor = $context->getActor();
                    return $actor->isAdmin() || $actor->id === $image->user_id;
                }),
            Schema\DateTime::make('createdAt')
                ->property('created_at')
                ->writable(false),
            Schema\Arr::make('exif')
                ->get(function (SpotterImage $image) {
                    $raw = $image->exif_data;
                    if (is_string($raw)) $raw = json_decode($raw, true);
                    if (!is_array($raw)) $raw = [];

                    $make = $raw['make'] ?? $raw['Make'] ?? '';
                    $modelName = $raw['model'] ?? $raw['Model'] ?? '';
                    
                    if (stripos($modelName, $make) !== false) {
                        $fullCameraName = $modelName;
                    } else {
                        $fullCameraName = trim($make . ' ' . $modelName);
                    }
                    if (empty($fullCameraName)) $fullCameraName = null;

                    $lensVal = $raw['lens'] ?? $raw['Lens'] ?? $raw['LensModel'] ?? null;
                    $apertureVal = $raw['aperture'] ?? $raw['ApertureValue'] ?? $raw['FNumber'] ?? null;
                    if ($apertureVal && is_numeric($apertureVal)) $apertureVal = 'f/' . $apertureVal;

                    $isoVal = $raw['iso'] ?? $raw['ISO'] ?? $raw['ISOSpeedRatings'] ?? null;
                    if (is_array($isoVal)) $isoVal = $isoVal[0] ?? null;

                    $expVal = $raw['exposure'] ?? $raw['ExposureTime'] ?? null;
                    $focalVal = $raw['focal'] ?? $raw['FocalLength'] ?? null;
                    if ($focalVal && is_numeric($focalVal)) $focalVal .= ' mm';

                    $dateVal = $raw['date'] ?? $raw['DateTimeOriginal'] ?? null;
                    $latVal = $raw['lat'] ?? $raw['GPSLatitude'] ?? null;
                    $lonVal = $raw['lon'] ?? $raw['GPSLongitude'] ?? null;

                    return [
                        'Model'           => $fullCameraName,
                        'Make'            => $make,
                        'LensModel'       => $lensVal,
                        'Lens'            => $lensVal,
                        'FNumber'         => $apertureVal,
                        'ISOSpeedRatings' => $isoVal,
                        'ISO'             => $isoVal,
                        'ExposureTime'    => $expVal,
                        'FocalLength'     => $focalVal,
                        'DateTimeOriginal'=> $dateVal,
                        'GPSLatitude'     => $latVal,
                        'GPSLongitude'    => $lonVal,
                        
                        'camera'          => $fullCameraName,
                        'lens'            => $lensVal,
                        'aperture'        => $apertureVal,
                        'iso'             => $isoVal,
                        'exposure'        => $expVal,
                        'focal'           => $focalVal,
                        'date'            => $dateVal,
                        'lat'             => $latVal,
                        'lon'             => $lonVal
                    ];
                }),

            Schema\Relationship\ToOne::make('user')
                ->includable(),
        ];
    }

    public function sorts(): array
    {
        return [
            SortColumn::make('id'),
            SortColumn::make('createdAt')->property('created_at'),
        ];
    }
    
    public function scope(Builder $query, \Tobyz\JsonApiServer\Context $context): void
    {
        $actor = $context->getActor();
        $filters = $context->request->getQueryParams()['filter'] ?? [];
        $path = $context->request->getUri()->getPath(); 

        if ($q = Arr::get($filters, 'q')) {
            $query->where(function ($query) use ($q) {
                $query->where('filename', 'like', "%{$q}%")
                      ->orWhereIn('user_id', function($subQuery) use ($q) {
                          $subQuery->select('id')
                                   ->from('users')
                                   ->where('username', 'like', "%{$q}%");
                      });
            });
        }

        if ($userId = Arr::get($filters, 'user')) {
            $query->where('user_id', $userId);
        }
        
        $query->orderBy('id', 'desc');
    }
    
    public function deleting(object $model, \Tobyz\JsonApiServer\Context $context): void
    {
        $basePath = public_path(); 
        $relativePath = ltrim($model->path, '/');
        $relativeThumb = ltrim($model->thumb_path, '/');

        if (file_exists("$basePath/$relativePath")) @unlink("$basePath/$relativePath");
        if (file_exists("$basePath/$relativeThumb")) @unlink("$basePath/$relativeThumb");
    }
}
