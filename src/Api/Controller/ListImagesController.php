<?php
namespace SpottersTurkey\UploadExif\Api\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Flarum\Http\RequestUtil;

class ListImagesController implements RequestHandlerInterface {
    protected $db;

    public function __construct(ConnectionInterface $db) {
        $this->db = $db;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface {
        $actor = RequestUtil::getActor($request);
        $filters = $request->getQueryParams()['filter'] ?? [];
        $path = $request->getUri()->getPath(); 
        
        $query = $this->db->table('spotter_images')->select('spotter_images.*');

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

        $limit = 14; 
        $offset = Arr::get($request->getQueryParams(), 'page[offset]', 0);
        
        $results = $query->limit($limit)->offset($offset)->get();

        $this->loadUsersForResults($results);

        $data = [];
        foreach($results as $res) {
            $data[] = [
                'type' => 'spotter-images',
                'id' => (string)$res->id,
                'attributes' => [
                    'filename' => $res->filename,
                    'url' => $res->thumb_path ?: $res->path,
                    'original_url' => $res->path,
                    'thumb_path' => $res->thumb_path,
                    'title' => $res->title ?? null,
                    'description' => $res->description ?? null,
                    'createdAt' => $res->created_at ?? null,
                    'exif' => json_decode($res->exif_data ?? '{}', true)
                ],
                'relationships' => [
                    'user' => [
                        'data' => $res->user ? ['type' => 'users', 'id' => (string)$res->user_id] : null
                    ]
                ]
            ];
        }

        return new JsonResponse(['data' => $data]);
    }

    protected function loadUsersForResults($results) {
        $userIds = $results->pluck('user_id')->unique();
        if ($userIds->isEmpty()) return;

        $users = \Flarum\User\User::whereIn('id', $userIds)->get()->keyBy('id');

        foreach ($results as $result) {
            $result->user = $users->get($result->user_id);
        }
    }
}