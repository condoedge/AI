<?php

declare(strict_types=1);

namespace Condoedge\Ai\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * FileSearch Facade — static access to FileSearchService.
 *
 * @method static array searchByContent(string $query, array $options = [])
 * @method static array searchByFilename(string $filename, array $options = [])
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
