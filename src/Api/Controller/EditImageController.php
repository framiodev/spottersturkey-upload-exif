<?php

namespace SpottersTurkey\UploadExif\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Foundation\Paths;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Laminas\Diactoros\Response\JsonResponse;
use SpottersTurkey\UploadExif\Database\SpotterImage;
use Google\Cloud\Storage\StorageClient;

class EditImageController implements RequestHandlerInterface
{
    protected $settings;
    protected $paths;

    const FIREBASE_BUCKET = 'spotters-turkey-storage.firebasestorage.app';

    public function __construct(SettingsRepositoryInterface $settings, Paths $paths)
    {
        $this->settings = $settings;
        $this->paths = $paths;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        $id = \Illuminate\Support\Arr::get($request->getQueryParams(), 'id');
        $data = $request->getParsedBody();
        $newNameRaw = $data['name'] ?? null;

        if (!$id || !$newNameRaw) {
            return new JsonResponse(['error' => 'Eksik parametre.'], 400);
        }

        $image = SpotterImage::find($id);
        if (!$image) {
            return new JsonResponse(['error' => 'Görsel bulunamadı.'], 404);
        }

        $info = pathinfo($image->filename);
        $ext = $info['extension'] ?? 'jpg';

        $safeName = preg_replace('/[^a-zA-Z0-9\s\-_]/u', '', $newNameRaw);
        $safeName = trim($safeName);

        if (empty($safeName)) {
            $safeName = time();
        }

        $newFilename = $safeName . '.' . $ext;

        $urlSafeName = str_replace([' ', '_'], '-', $safeName);

        $newSafeName = time() . '_' . $urlSafeName . '.' . $ext;
        $newOriginalSafeName = time() . '_' . $urlSafeName . '_orijinal.' . $ext;

        $bucketPrefix = self::FIREBASE_BUCKET . '/';
        $relativeOldPath = str_replace('https://storage.googleapis.com/' . $bucketPrefix, '', $image->path);
        $folderPath = dirname($relativeOldPath);

        $oldName = basename($image->path);
        $oldThumbName = basename($image->thumb_path);
        $oldMiniName = 'mini_' . str_replace('thumb_', '', $oldThumbName);
        $oldOriginalName = $image->original_path ? basename($image->original_path) : null;

        $storagePath = $this->paths->storage;
        $jsonKeyPath = $storagePath . '/firebase-auth.json';

        if (!file_exists($jsonKeyPath)) {
            return new JsonResponse(['error' => 'Firebase kimlik dosyası bulunamadı!'], 500);
        }

        try {
            $accessTokenArray = $this->getManualAccessToken($jsonKeyPath);
            $accessToken = $accessTokenArray['access_token'];

            $storage = new StorageClient([
                'accessToken' => $accessToken,
                'projectId' => $this->getProjectIdFromJson($jsonKeyPath)
            ]);
            $bucket = $storage->bucket(self::FIREBASE_BUCKET);

            $this->renameOnCloud($bucket, $folderPath . '/' . $oldName, $folderPath . '/' . $newSafeName);

            $newThumbName = 'thumb_' . $newSafeName;
            $this->renameOnCloud($bucket, $folderPath . '/' . $oldThumbName, $folderPath . '/' . $newThumbName);

            $newMiniName = 'mini_' . $newSafeName;
            $this->renameOnCloud($bucket, $folderPath . '/' . $oldMiniName, $folderPath . '/' . $newMiniName);

            $newOriginalUrl = null;
            if ($oldOriginalName) {
                $this->renameOnCloud($bucket, $folderPath . '/' . $oldOriginalName, $folderPath . '/' . $newOriginalSafeName);
                $newOriginalUrl = 'https://storage.googleapis.com/' . self::FIREBASE_BUCKET . '/' . $folderPath . '/' . $newOriginalSafeName;
            }

            $baseUrl = 'https://storage.googleapis.com/' . self::FIREBASE_BUCKET . '/' . $folderPath . '/';
            $newPath = $baseUrl . $newSafeName;
            $newThumbPath = $baseUrl . $newThumbName;

            $oldThumbUrl = $image->thumb_path;
            $oldMainUrl = $image->path;

            $posts = Post::where('content', 'like', '%' . $image->id . '%')
                ->where(function ($q) use ($oldThumbUrl, $oldMainUrl, $image) {
                    $q->where('content', 'like', '%[spotter-image id=' . $image->id . '%')
                      ->orWhere('content', 'like', '%' . basename($oldThumbUrl) . '%')
                      ->orWhere('content', 'like', '%' . basename($oldMainUrl) . '%');
                })
                ->get();

            foreach ($posts as $post) {
                $newContent = $post->content;

                $pattern = '/\[spotter-image id=' . $image->id . ' url="([^"]+)" alt="([^"]+)"\]/';
                $replacement = '[spotter-image id=' . $image->id . ' url="' . $newThumbPath . '" alt="' . $safeName . '"]';
                $newContent = preg_replace($pattern, $replacement, $newContent);

                $patternNoQuote = '/\[spotter-image id=' . $image->id . ' url=([^\s\]]+) alt=([^\]]+)\]/';
                $replacementNoQuote = '[spotter-image id=' . $image->id . ' url=' . $newThumbPath . ' alt=' . $safeName . ']';
                $newContent = preg_replace($patternNoQuote, $replacementNoQuote, $newContent);

                if ($oldThumbUrl) {
                    $newContent = str_replace(basename($oldThumbUrl), $newThumbName, $newContent);
                }
                if ($oldMainUrl && $oldMainUrl !== $oldThumbUrl) {
                    $newContent = str_replace(basename($oldMainUrl), $newSafeName, $newContent);
                }

                if ($newContent !== $post->content) {
                    $post->content = $newContent;
                    $post->save();
                }
            }

            $image->filename = $newFilename;
            $image->path = $newPath;
            $image->thumb_path = $newThumbPath;
            if ($newOriginalUrl) {
                $image->original_path = $newOriginalUrl;
            }
            $image->save();

            return new JsonResponse([
                'success' => true,
                'filename' => $newFilename,
                'url' => $newPath,
                'thumb_path' => $newThumbPath
            ]);

        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'İsim değiştirme hatası: ' . $e->getMessage()], 500);
        }
    }

    private function renameOnCloud($bucket, $oldPath, $newPath)
    {
        $object = $bucket->object($oldPath);
        if ($object->exists()) {
            $object->copy($bucket, [
                'name' => $newPath,
                'predefinedAcl' => 'publicRead'
            ]);
            $object->delete();
        }
    }

    private function getManualAccessToken($jsonPath)
    {
        $jsonContent = file_get_contents($jsonPath);
        $data = json_decode($jsonContent, true);
        if (!isset($data['client_email']) || !isset($data['private_key'])) throw new \Exception("JSON geçersiz.");
        $privateKey = str_replace('\\n', "\n", $data['private_key']);
        $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
        $headerBase64 = $this->base64UrlEncode($header);
        $now = time();
        $iat = $now - 3600;
        $exp = $iat + 3600;
        $payload = json_encode(['iss' => $data['client_email'], 'scope' => 'https://www.googleapis.com/auth/cloud-platform', 'aud' => 'https://oauth2.googleapis.com/token', 'exp' => $exp, 'iat' => $iat]);
        $payloadBase64 = $this->base64UrlEncode($payload);
        $signature = '';
        openssl_sign($headerBase64 . "." . $payloadBase64, $signature, $privateKey, 'SHA256');
        $signatureBase64 = $this->base64UrlEncode($signature);
        $jwt = $headerBase64 . "." . $payloadBase64 . "." . $signatureBase64;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);
        $responseData = json_decode($response, true);
        if (!isset($responseData['access_token'])) throw new \Exception("Google Token Reddedildi.");
        return $responseData;
    }

    private function getProjectIdFromJson($jsonPath)
    {
        $data = json_decode(file_get_contents($jsonPath), true);
        return $data['project_id'] ?? null;
    }

    private function base64UrlEncode($data)
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }
}