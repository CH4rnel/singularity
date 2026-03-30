<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;

class CataasApiService {
    private const BASE_URL = 'https://cataas.com/';    
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];

    private function http($options = [])
    {
        $options = array_merge(env('PROXY_URL') ? ['proxy' => env('PROXY_URL', 'http://127.0.0.1:2080')] : [], $options);   

        return Http::withOptions($options);
    }

    public function cat()
    {
        return $this->saveFileByUrl(self::BASE_URL . 'cat');
    }

    public function catSays($text)
    {
        return $this->saveFileByUrl(self::BASE_URL . "cat/says/$text");
    }

    public function catById($id)
    {
        return $this->saveFileByUrl(self::BASE_URL . "cat/$id");
    }

    public function catByIdSays($id, $text)
    {
        return $this->saveFileByUrl(self::BASE_URL . "cat/$id/says/$text");
    }

    public function catByTag($tag)
    {
        return $this->saveFileByUrl(self::BASE_URL . "cat/$tag");
    }

    public function catByTagSays($tag, $text)
    {
        return $this->saveFileByUrl(self::BASE_URL . "cat/$tag/says/$text");
    }

    public function cats()
    {
        return $this->http()->get(self::BASE_URL . 'api/cats')->object();
    }

    public function count()
    {
        return $this->http()->get(self::BASE_URL . 'count')->object();
    }

    public function tags()
    {
        return $this->http()->get(self::BASE_URL . 'api/tags')->object();
    }

    public function saveFileByUrl($url)
    {
        $tmpPath = storage_path('app/public/' . uniqid() . '.tmp');

        $this->http(['sink' => $tmpPath])->get($url);

        $mimeType  = mime_content_type($tmpPath);
        $extension = self::MIME_EXTENSIONS[$mimeType] ?? throw new \RuntimeException("Unsupported mime: $mimeType");

        $hash = hash_file('sha256', $tmpPath);
        $finalPath = storage_path("app/public/{$hash}.{$extension}");
        rename($tmpPath, $finalPath);

        return "/storage/{$hash}.{$extension}";
    }
}