<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions;

/**
 * The intent of an {@see \Condoedge\Ai\Services\Actions\Results\AiActionResult}.
 *
 * An action's handle() returns data + intent — never a Kompo element. Consumers (the
 * presenter, the link handler, the chat Query) switch on this kind, not on the payload's
 * array shape, so a returned model surfaces as a link/table/download/approval card without
 * each consumer re-sniffing is_array(...)&&isset(...).
 *
 *  - Element : render a link/redirect element from the payload.
 *  - Table   : render the payload {headers, rows} as a table.
 *  - Export  : offer the payload {content, mime, filename} as a download.
 *  - Pending : a deferred mutating action — render an approval card from the payload token.
 *  - Error   : a graceful failure; the summary is shown, nothing actionable rendered.
 */
enum ActionKind
{
    case Element;
    case Table;
    case Export;
    case Pending;
    case Error;
}
