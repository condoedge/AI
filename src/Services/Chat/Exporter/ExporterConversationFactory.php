<?php

namespace Condoedge\Ai\Services\Chat\Exporter;

class ExporterConversationFactory
{
    protected $types = [
        'md'   => ExportConversationMdService::class,
        'pdf'  => ExportConversationPdfService::class,
        'xlsx' => ExportConversationXlsxService::class,
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
        return app()->make($class);
    }
}