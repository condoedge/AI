<?php

declare(strict_types=1);

namespace Condoedge\Ai\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * FileSearch Facade — static access to FileSearchService.
 *
 * @method static array searchByContent(string $query, array $options = [])
 * @method static array searchByFilename(string $filename, array $options = [])
 * @method static array searchByMetadata(array $criteria, int $limit = 10)
 * @method static array hybridSearch(string $contentQuery, array $metadataFilters = [], array $options = [])
 * @method static array getRelatedFiles(\Illuminate\Database\Eloquent\Model $file, ?string $relationshipType = null, int $limit = 10)
 * @method static array getFilesByUser(int $userId, int $limit = 10)
 * @method static array getFilesByTeam(int $teamId, int $limit = 10)
 *
 * @see \Condoedge\Ai\Services\FileSearchService
 * @package Condoedge\Ai\Facades
 */
class FileSearch extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'file-search';
    }
}
