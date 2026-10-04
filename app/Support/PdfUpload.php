<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

class PdfUpload
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    public const TYPE_ERROR_MESSAGE = 'El archivo seleccionado debe ser un PDF válido.';

    public const SIZE_ERROR_MESSAGE = 'El archivo PDF no puede superar los 20 MB.';

    public static function validateUploadedFile(UploadedFile $file): ?string
    {
        if (!$file->isValid() || !preg_match('/\.pdf$/i', $file->getClientOriginalName())) {
            return self::TYPE_ERROR_MESSAGE;
        }

        if (($file->getSize() ?? 0) > self::MAX_BYTES) {
            return self::SIZE_ERROR_MESSAGE;
        }

        $path = $file->getRealPath();

        if (!is_string($path)) {
            return self::TYPE_ERROR_MESSAGE;
        }

        $handle = fopen($path, 'rb');
        $signature = $handle === false ? false : fread($handle, 5);

        if ($handle !== false) {
            fclose($handle);
        }

        return $signature === '%PDF-' ? null : self::TYPE_ERROR_MESSAGE;
    }
}
