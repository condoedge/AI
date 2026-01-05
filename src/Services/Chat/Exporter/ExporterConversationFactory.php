<?php

namespace Condoedge\Ai\Services\Chat\Exporter;

class ExporterConversationFactory
{
    protected $types = [
        // 'pdf' => PdfExporterConversation::class,
        // 'txt' => TxtExporterConversation::class,
        'md'  => ExportConversationMdService::class,
    ];

    public function registerExporter($type, $class)
    {
        $this->types[$type] = $class;
    }

    public function make($type)
    {
        if (!isset($this->types[$type])) {
            throw new \InvalidArgumentException("Exporter type '{$type}' is not supported.");
        }

        $class = $this->types[$type];
        return new $class();
    }
}