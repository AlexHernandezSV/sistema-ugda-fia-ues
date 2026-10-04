<?php

namespace Tests\Unit;

use App\Support\PdfUpload;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\TestCase;

class PdfUploadTest extends TestCase
{
    public function test_accepts_an_uploaded_pdf_with_a_pdf_signature(): void
    {
        $file = UploadedFile::fake()->createWithContent('documento.pdf', "%PDF-1.7\ncontenido");

        $this->assertNull(PdfUpload::validateUploadedFile($file));
    }

    public function test_rejects_an_uploaded_file_without_a_pdf_signature(): void
    {
        $file = UploadedFile::fake()->createWithContent('documento.pdf', 'no es un PDF');

        $this->assertSame(PdfUpload::TYPE_ERROR_MESSAGE, PdfUpload::validateUploadedFile($file));
    }
}
