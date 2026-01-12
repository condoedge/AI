<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Traits;

use Condoedge\Utils\Facades\FileModel;
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
     * @return DisplayFileModal
     */
    public function viewFile($id)
    {
        return new DisplayFileModal(null, [
            'id' => $id,
        ]);
    }
}
