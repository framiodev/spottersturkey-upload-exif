<?php

namespace SpottersTurkey\UploadExif\Api\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use Illuminate\Database\ConnectionInterface;

class ImageIndexController implements RequestHandlerInterface
{
    protected $db;

    public function __construct(ConnectionInterface $db)
    {
        $this->db = $db;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Flarum Query Builder prefix'i otomatik ekler: 'spotter_images' → 'flhg_spotter_images'
        // Sadece var olduğu kesin sütunları çek (SHOW COLUMNS'dan öğrenildi)
        $images = $this->db->table('spotter_images')
            ->select('id', 'thumb_path', 'filename')
            ->get();

        $activeFiles = [];
        foreach ($images as $img) {
            // thumb_path URL-encoded olabilir (%20 vs boşluk), urldecode ile normalize et
            if (!empty($img->thumb_path)) {
                $activeFiles[] = urldecode(basename($img->thumb_path));
            }
            // filename direkt plaka adı: "26 S 7326-3.jpg" — timestamp olmadan
            if (!empty($img->filename)) {
                $activeFiles[] = $img->filename;
                // thumb_ prefix'li halini de ekle (dashboard thumb varyantı ile eşleşmek için)
                $activeFiles[] = 'thumb_' . $img->filename;
            }
        }

        return new JsonResponse(
            array_values(array_unique($activeFiles)),
            200,
            [
                'Access-Control-Allow-Origin' => 'https://img.spottersturkey.com',
                'Access-Control-Allow-Methods' => 'GET',
                'Cache-Control' => 'no-store',
            ]
        );
    }
}
