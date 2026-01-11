<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Traits;

use Condoedge\Utils\Kompo\Files\DisplayFileModal;

/**
 * Provides file preview modal functionality for Kompo components.
 *
 * Used by components that need to display file citations with clickable
 * preview links. The viewFile method is called via selfGet from file
 * citation links created by FileCitationHandler.
 */
trait HasFilePreview
{
    /**
     * Open file preview modal.
     *
     * Called via selfGet from file citation links [1], [2], etc.
     *
     * @param int|string $id The file ID
     * @param string $type The morphable type (default: 'file')
     * @param string|null $mime The MIME type
     * @return DisplayFileModal
     */
    public function viewFile($id, $type = 'file', $mime = null)
    {
        return new DisplayFileModal(null, [
            'mime' => $mime ?? 'application/octet-stream',
            'type' => $type,
            'id' => $id,
        ]);
    }
}
