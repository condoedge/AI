<?php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

/**
 * Which record an UPLOADED file is filed on.
 *
 * The default ({@see \Condoedge\Ai\Services\Files\ConfigFileOwnerResolver})
 * needs nothing from the host: it reads the File entity's morph parent and its
 * declared many-to-one relationships. A host whose files are linked another way
 * binds its own implementation of this interface.
 */
interface FileOwnerResolverInterface
{
    /**
     * @param object $file The file as the processor receives it: a model, or a
     *        plain object of its attributes.
     * @return array{owner: ?string, ancestors: list<string>} `owner`: the key
     *         (`<entity label, lower case>:<id>`, {@see \Condoedge\Ai\Support\FileLink::key()})
     *         of the record the file is filed on, null when it belongs to none;
     *         `ancestors`: the keys of the records that own that record, most
     *         specific first. The tenant / the uploader are never a key.
     */
    public function ownerOf(object $file): array;
}
