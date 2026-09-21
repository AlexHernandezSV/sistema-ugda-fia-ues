<?php

namespace App\Support;

class PdfUpload
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    public const TYPE_ERROR_MESSAGE = 'El archivo seleccionado debe ser un PDF válido.';

    public const SIZE_ERROR_MESSAGE = 'El archivo PDF no puede superar los 20 MB.';

    public static function validateBase64(string $fileName, string $payload): ?string
    {
        if (!preg_match('/\.pdf$/i', trim($fileName))) {
            return self::TYPE_ERROR_MESSAGE;
        }

        $payload = self::withoutDataUriPrefix(trim($payload));

        if (strlen($payload) > self::maxEncodedLength()) {
            return self::SIZE_ERROR_MESSAGE;
        }

        $decodedContent = base64_decode($payload, true);

        if ($decodedContent === false) {
            return self::TYPE_ERROR_MESSAGE;
        }

        if (strlen($decodedContent) > self::MAX_BYTES) {
            return self::SIZE_ERROR_MESSAGE;
        }

        if (!str_starts_with($decodedContent, '%PDF-')) {
            return self::TYPE_ERROR_MESSAGE;
        }

        return null;
    }

    public static function decodeBase64(string $payload): ?string
    {
        $payload = self::withoutDataUriPrefix(trim($payload));

        if (strlen($payload) > self::maxEncodedLength()) {
            return null;
        }

        $decodedContent = base64_decode($payload, true);

        if ($decodedContent === false
            || strlen($decodedContent) > self::MAX_BYTES
            || !str_starts_with($decodedContent, '%PDF-')) {
            return null;
        }

        return $decodedContent;
    }

    private static function maxEncodedLength(): int
    {
        return (int) ceil(self::MAX_BYTES / 3) * 4 + 64;
    }

    private static function withoutDataUriPrefix(string $payload): string
    {
        return preg_replace('/^data:application\/pdf;base64,/i', '', $payload) ?? $payload;
    }
}
