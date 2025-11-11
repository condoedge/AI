<?php

namespace Condoedge\Ai\Models;

use Condoedge\Utils\Models\Files\File as BaseFile;
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\Traits\HasNodeableConfig;
use Condoedge\Ai\Contracts\FileProcessorInterface;
use Condoedge\Ai\Contracts\ChunkStoreInterface;

/**
 * Extended File model with AI capabilities
 *
 * This model extends the base File from condoedge/utils and adds:
 * - Neo4j graph storage (metadata, relationships)
 * - Qdrant vector storage (content chunks for semantic search)
 * - Auto-sync to both stores on create/update/delete
 */
class File extends BaseFile implements Nodeable
{
    use HasNodeableConfig;

    /**
     * Disable auto-sync for File model
     * We'll handle sync manually via FileProcessingPlugin
     *
     * @var bool
     */
    protected $aiAutoSync = false;

    /**
     * Get the config key for this model
     * Maps to config/ai/entities.php => 'File'
     *
     * @return string
     */
    protected function getConfigKey(): string
    {
        return 'File';
    }

    /**
     * Check if this file should have its content processed
     *
     * @return bool
     */
    public function shouldProcessContent(): bool
    {
        $processor = app(FileProcessorInterface::class);

        $extension = pathinfo($this->name, PATHINFO_EXTENSION);

        return $processor->supportsFileType($extension);
    }

    /**
     * Get all chunks for this file
     *
     * @return array
     */
    public function chunks(): array
    {
        $chunkStore = app(ChunkStoreInterface::class);

        return $chunkStore->getFileChunks($this->id);
    }

    /**
     * Get the count of chunks for this file
     *
     * @return int
     */
    public function getChunkCount(): int
    {
        return count($this->chunks());
    }

    /**
     * Check if this file has been processed
     *
     * @return bool
     */
    public function isProcessed(): bool
    {
        $chunkStore = app(ChunkStoreInterface::class);

        return $chunkStore->hasFileChunks($this->id);
    }

    /**
     * Search within this file's content
     *
     * @param string $query
     * @param int $limit
     * @return array
     */
    public function searchContent(string $query, int $limit = 10): array
    {
        $chunkStore = app(ChunkStoreInterface::class);

        return $chunkStore->searchByContent($query, $limit, [
            'file_id' => $this->id,
        ]);
    }

    /**
     * Get processing statistics for this file
     *
     * @return array
     */
    public function getProcessingStats(): array
    {
        $processor = app(FileProcessorInterface::class);

        return $processor->getFileStats($this);
    }

    /**
     * Get the file extension
     *
     * @return string
     */
    public function getExtension(): string
    {
        return pathinfo($this->name, PATHINFO_EXTENSION);
    }

    /**
     * Get the full file path
     *
     * @return string
     */
    public function getFullPath(): string
    {
        $disk = $this->disk ?? 'local';
        $storagePath = config("filesystems.disks.{$disk}.root");

        if (!$storagePath) {
            throw new \RuntimeException("Storage disk '{$disk}' not configured");
        }

        return $storagePath . DIRECTORY_SEPARATOR . $this->path;
    }

    /**
     * Check if file exists on disk
     *
     * @return bool
     */
    public function existsOnDisk(): bool
    {
        try {
            return file_exists($this->getFullPath());
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get MIME type category (document, image, video, etc.)
     *
     * @return string
     */
    public function getMimeCategory(): string
    {
        $mimeType = $this->mime_type ?? '';

        return match (true) {
            str_starts_with($mimeType, 'image/') => 'image',
            str_starts_with($mimeType, 'video/') => 'video',
            str_starts_with($mimeType, 'audio/') => 'audio',
            str_starts_with($mimeType, 'text/') => 'text',
            in_array($mimeType, ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']) => 'document',
            in_array($mimeType, ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']) => 'spreadsheet',
            in_array($mimeType, ['application/zip', 'application/x-rar-compressed', 'application/x-7z-compressed']) => 'archive',
            default => 'other',
        };
    }
}
