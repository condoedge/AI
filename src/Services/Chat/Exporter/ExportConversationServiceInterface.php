<?php

namespace Condoedge\Ai\Services\Chat\Exporter;

interface ExportConversationServiceInterface
{
    public function export($conversation);

    public function getFileExtension(): string;
}