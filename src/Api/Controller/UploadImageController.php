<?php

namespace SpottersTurkey\UploadExif\Api\Controller;

use Flarum\User\User;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Foundation\Paths;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Laminas\Diactoros\Response\JsonResponse;
use SpottersTurkey\UploadExif\Database\SpotterImage;
use Google\Cloud\Storage\StorageClient;

class UploadImageController implements RequestHandlerInterface
{
    protected $uploadPath;
    protected $urlPath;
    protected $settings;
    protected $paths;

    const FIREBASE_BUCKET = 'spotters-turkey-storage.firebasestorage.app'; 

    public function __construct(SettingsRepositoryInterface $settings, Paths $paths)
    {
        $this->settings = $settings;
        $this->paths = $paths;
        $this->uploadPath = public_path('assets/temp_processing');
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // 1. AYARLAR
        $maxWidth   = (int) ($this->settings->get('spottersturkey-upload-exif.resize_width') ?: 3840);
        $quality    = (int) ($this->settings->get('spottersturkey-upload-exif.compression_quality') ?: 90);
        $thumbWidth = (int) ($this->settings->get('spottersturkey-upload-exif.thumb_width') ?: 1024);
        $miniWidth  = (int) ($this->settings->get('spottersturkey-upload-exif.mini_width') ?: 250);

        // Orijinal (Yedek) Ayarları
        $origResize = $this->settings->get('spottersturkey-upload-exif.original_resize_width');
        $origQuality = $this->settings->get('spottersturkey-upload-exif.original_compression_quality'); 

        @ini_set('memory_limit', '1024M');
        @ini_set('max_execution_time', 300);

        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $files = $request->getUploadedFiles();
        $file = $files['spotter_image'] ?? null;
        $body = $request->getParsedBody();
        $watermarkId = $body['watermark_type'] ?? 'none';
        
        // Admin Parametreleri
        $targetUsername = $body['target_username'] ?? null;
        // Spotters Turkey'de özel imza olmadığı için targetWatermarkType'a gerek yok,
        // ama parametre olarak gelirse de kod kırılmasın diye bırakıyoruz.

        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
             return new JsonResponse(['error' => 'Dosya yüklenemedi.'], 400);
        }

        // 2. DOSYA İŞLEMLERİ
        if (!is_dir($this->uploadPath)) mkdir($this->uploadPath, 0755, true);

        $originalName = $file->getClientFilename();
        $safeName = time() . '_' . $originalName; 
        $localFullPath = "$this->uploadPath/$safeName";
        
        $file->moveTo($localFullPath);

        // EXIF
        $exifRaw = @exif_read_data($localFullPath);
        $cleanExif = $this->parseExif($exifRaw);

        // --- ORİJİNAL YEDEK OLUŞTURMA ---
        $pathInfo = pathinfo($safeName);
        $ext = isset($pathInfo['extension']) ? '.' . $pathInfo['extension'] : '';
        $originalSafeName = $pathInfo['filename'] . '_orijinal' . $ext;
        $tempBackupPath = "$this->uploadPath/$originalSafeName";
        
        copy($localFullPath, $tempBackupPath); // Ham kopyayı al

        $manager = new ImageManager(extension_loaded('imagick') ? new ImagickDriver() : new GdDriver());

        // --- ORİJİNAL DOSYAYI İŞLE (YEDEK) ---
        // Ayar varsa işle, yoksa ham kalsın.
        if (($origResize && (int)$origResize > 0) || ($origQuality && (int)$origQuality < 100)) {
            $imgOrigBackup = $manager->read($tempBackupPath);
            if ($origResize) {
                $imgOrigBackup->scaleDown(width: $origResize);
            }
            $q = $origQuality ? (int)$origQuality : 100;
            $imgOrigBackup->save($tempBackupPath, quality: $q);
            $imgOrigBackup->destroy();
        }

        // --- ANA VİTRİN DOSYASINI İŞLE ---
        $imgOriginal = $manager->read($localFullPath);

        if ($imgOriginal->width() > $maxWidth) {
            $imgOriginal->scaleDown(width: $maxWidth);
        }

        // Watermark (Spotters Turkey Standart 9 İmza)
        if ($watermarkId !== 'none') {
            $wmPath = public_path('assets/watermarks/' . $watermarkId . '.png');
            if (file_exists($wmPath)) {
                $wm = $manager->read($wmPath);
                $targetWidth = $imgOriginal->width();
                $targetHeight = $imgOriginal->height();
                $wmWidth = $wm->width();
                $wmHeight = $wm->height();
                $targetRatio = $targetWidth / $targetHeight;
                $wmRatio = $wmWidth / $wmHeight;

                if ($targetRatio > $wmRatio) {
                    $wm->scaleDown(width: $targetWidth);
                } else {
                    $wm->scaleDown(height: $targetHeight);
                }

                $cropX = intval(($wm->width() - $targetWidth) / 2);
                $cropY = intval($wm->height() - $targetHeight);
                $wm->crop($targetWidth, $targetHeight, $cropX, $cropY);
                $imgOriginal->place($wm, 'center');
            }
        }

        $imgOriginal->save($localFullPath, quality: $quality); 
        $this->transferExifData($tempBackupPath, $localFullPath); // EXIF'i orijinalden aktar

        // Thumbnails
        $imgThumb = clone $imgOriginal; 
        if ($imgThumb->width() > $thumbWidth) {
            $imgThumb->scaleDown(width: $thumbWidth);
        }
        $thumbName = 'thumb_' . $safeName;
        $localThumbPath = "$this->uploadPath/$thumbName";
        $imgThumb->save($localThumbPath, quality: 80);

        $imgMini = clone $imgOriginal;
        if ($imgMini->width() > $miniWidth) {
            $imgMini->scaleDown(width: $miniWidth);
        }
        $miniName = 'mini_' . $safeName;
        $localMiniPath = "$this->uploadPath/$miniName";
        $imgMini->save($localMiniPath, quality: 70);

        // --- FIREBASE UPLOAD ---
        try {
            $storagePath = $this->paths->storage; 
            $jsonKeyPath = $storagePath . '/firebase-auth.json';

            if (!file_exists($jsonKeyPath)) {
                throw new \Exception("Kimlik dosyası (firebase-auth.json) bulunamadı!");
            }

            // Manuel Token Al
            $accessTokenArray = $this->getManualAccessToken($jsonKeyPath);
            $accessToken = $accessTokenArray['access_token'];

            $storage = new StorageClient([
                'accessToken' => $accessToken,
                'projectId' => $this->getProjectIdFromJson($jsonKeyPath)
            ]);

            $bucket = $storage->bucket(self::FIREBASE_BUCKET);
            $subDir = date('Y/m');
            $cloudFolder = 'assets/spotters/' . $subDir . '/';

            // Upload - Ana, Thumb, Mini
            $bucket->upload(fopen($localFullPath, 'r'), ['name' => $cloudFolder . $safeName, 'predefinedAcl' => 'publicRead']);
            $bucket->upload(fopen($localThumbPath, 'r'), ['name' => $cloudFolder . $thumbName, 'predefinedAcl' => 'publicRead']);
            $bucket->upload(fopen($localMiniPath, 'r'), ['name' => $cloudFolder . $miniName, 'predefinedAcl' => 'publicRead']);

            // Upload - Orijinal (Yedek)
            $bucket->upload(fopen($tempBackupPath, 'r'), ['name' => $cloudFolder . $originalSafeName, 'predefinedAcl' => 'publicRead']);

            $firebaseBaseUrl = 'https://img.spottersturkey.com';
            $finalMainUrl = $firebaseBaseUrl . '/' . $cloudFolder . $safeName;
            $finalThumbUrl = $firebaseBaseUrl . '/' . $cloudFolder . $thumbName;
            $finalOriginalUrl = $firebaseBaseUrl . '/' . $cloudFolder . $originalSafeName;

        } catch (\Exception $e) {
            @unlink($localFullPath); @unlink($localThumbPath); @unlink($localMiniPath); @unlink($tempBackupPath);
            return new JsonResponse(['error' => 'Upload Hatası: ' . $e->getMessage()], 500);
        }

        // Temizlik ve Kayıt
        @unlink($localFullPath); @unlink($localThumbPath); @unlink($localMiniPath); @unlink($tempBackupPath);

        // SAHİPLİK AYARI (Admin Override)
        $ownerId = $actor->id;
        if ($actor->isAdmin() && !empty($targetUsername)) {
            $targetUserObj = User::where('username', trim($targetUsername))->first();
            if ($targetUserObj) {
                $ownerId = $targetUserObj->id;
            }
        }

        $imageModel = new SpotterImage();
        $imageModel->user_id = $ownerId;
        $imageModel->filename = $originalName;
        $imageModel->path = $finalMainUrl;
        $imageModel->thumb_path = $finalThumbUrl;
        $imageModel->original_path = $finalOriginalUrl; // YENİ: Orijinal yol kaydedildi
        $imageModel->exif_data = json_encode($cleanExif);
        $imageModel->save();

        $pathInfo = pathinfo($originalName);
        $altText = str_replace(['-', '_'], ' ', $pathInfo['filename']);
        $bbcode = "[spotter-image id={$imageModel->id} url={$imageModel->thumb_path} alt=\"\"]";

        return new JsonResponse([
            'id' => $imageModel->id,
            'url' => $imageModel->path,
            'original_url' => $imageModel->original_path,
            'bbcode' => $bbcode
        ]);
    }

    // --- YARDIMCI METODLAR ---
    private function getManualAccessToken($jsonPath) {
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
    private function getProjectIdFromJson($jsonPath) { $data = json_decode(file_get_contents($jsonPath), true); return $data['project_id'] ?? null; }
    private function base64UrlEncode($data) { return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data)); }
    private function parseExif($exif) { if (!$exif || !is_array($exif)) return null; $make = isset($exif['Make']) ? trim($exif['Make']) : 'Bilinmiyor'; $model = isset($exif['Model']) ? trim($exif['Model']) : 'Bilinmiyor'; if ($make !== 'Bilinmiyor' && stripos($model, $make) === 0) { $model = trim(substr($model, strlen($make))); } $focal = isset($exif['FocalLength']) ? $this->evalFraction($exif['FocalLength']) . ' mm' : null; $aperture = $exif['COMPUTED']['ApertureFNumber'] ?? $exif['FNumber'] ?? null; $exposure = $exif['ExposureTime'] ?? null; $iso = $exif['ISOSpeedRatings'] ?? $exif['ISOSpeed'] ?? null; if (is_array($iso)) $iso = $iso[0]; $lens = $exif['LensModel'] ?? $exif['LensInfo'] ?? $exif['UndefinedTag:0xA434'] ?? null; $lat = $this->getGps($exif['GPSLatitude'] ?? null, $exif['GPSLatitudeRef'] ?? null); $lon = $this->getGps($exif['GPSLongitude'] ?? null, $exif['GPSLongitudeRef'] ?? null); return [ 'make' => $make, 'model' => $model, 'lens' => $lens, 'aperture' => $aperture, 'exposure' => $exposure, 'iso' => $iso, 'focal' => $focal, 'date' => $exif['DateTimeOriginal'] ?? null, 'lat' => $lat, 'lon' => $lon ]; }
    private function getGps($exifCoord, $hemi) { if (!isset($exifCoord) || !isset($hemi)) return null; $degrees = count($exifCoord) > 0 ? $this->evalFraction($exifCoord[0]) : 0; $minutes = count($exifCoord) > 1 ? $this->evalFraction($exifCoord[1]) : 0; $seconds = count($exifCoord) > 2 ? $this->evalFraction($exifCoord[2]) : 0; $flip = ($hemi == 'W' || $hemi == 'S') ? -1 : 1; return $flip * ($degrees + ($minutes / 60) + ($seconds / 3600)); }
    private function evalFraction($fraction) { $parts = explode('/', $fraction); if (count($parts) == 2 && $parts[1] != 0) { return $parts[0] / $parts[1]; } return (float)$fraction; }
    private function transferExifData($s, $d) { try { $srcContent = file_get_contents($s); $destContent = file_get_contents($d); if (substr($srcContent, 0, 2) !== "\xFF\xD8" || substr($destContent, 0, 2) !== "\xFF\xD8") return; $exifData = null; $len = strlen($srcContent); $pos = 2; while ($pos < $len) { $marker = substr($srcContent, $pos, 2); $size = unpack('n', substr($srcContent, $pos + 2, 2))[1]; if ($marker === "\xFF\xE1") { $exifData = substr($srcContent, $pos, $size + 2); break; } $pos += 2 + $size; } if ($exifData) { $newDestContent = substr($destContent, 0, 2) . $exifData . substr($destContent, 2); file_put_contents($d, $newDestContent); } } catch (\Exception $e) { } }
}