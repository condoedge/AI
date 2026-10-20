<?php

namespace Condoedge\Ai\Contracts;

interface PdfParserInterface
{
    /**
     * Extract text from a PDF file.
     */
    public function extractText(string $filename, ?int $pageLimit = null): string;
}
