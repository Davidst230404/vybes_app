<?php

namespace App\Services;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

class QrCodeService
{
    /**
     * Generate QR Code sebagai Data URI.
     *
     * Contoh hasil:
     * data:image/png;base64,iVBORw0KGgo...
     */
    public function generateDataUri(string $data): string
    {
        $result = Builder::create()
            ->writer(new PngWriter())
            ->data($data)
            ->size(300)
            ->margin(10)
            ->build();

        return $result->getDataUri();
    }
}