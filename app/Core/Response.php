<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    public ?string $filePath = null;

    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = ['Content-Type' => 'text/html; charset=UTF-8'],
    ) {
    }

    public static function redirect(string $path, int $status = 302): self
    {
        return new self('', $status, ['Location' => url($path)]);
    }

    /** Stream a stored file as a download. The name is never used as a filesystem path. */
    public static function download(string $filePath, string $downloadName, string $mime): self
    {
        $fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName) ?: 'file';
        $response = new self('', 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) filesize($filePath),
            'Content-Disposition' => sprintf(
                'attachment; filename="%s"; filename*=UTF-8\'\'%s',
                $fallback,
                rawurlencode($downloadName)
            ),
            'Cache-Control' => 'private, no-store',
        ]);
        $response->filePath = $filePath;
        return $response;
    }

    public function isRedirect(): bool
    {
        return $this->status >= 300 && $this->status < 400;
    }

    public function location(): ?string
    {
        return $this->headers['Location'] ?? null;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        if ($this->filePath !== null) {
            readfile($this->filePath);
            return;
        }
        echo $this->body;
    }
}
