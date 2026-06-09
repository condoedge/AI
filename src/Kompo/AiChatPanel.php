<?php

namespace Condoedge\Ai\Kompo;

/**
 * AiChatPanel - Backward-compatible alias for the modal chat surface.
 *
 * Historically this class held the entire chat body. That body now lives on
 * AbstractChatSurface and the modal chrome on AiChatModal. AiChatPanel is kept
 * as a thin subclass so every existing call site (and the AiChatPanel::ID
 * constant) keeps working unchanged.
 *
 * Features: Conversation list, search, pin/archive, feedback, regenerate, edit,
 * export, file references, rich data display, response style selector.
 *
 * Usage:
 *   // Basic
 *   new AiChatPanel()
 *
 *   // With configuration
 *   new AiChatPanel(null, [
 *       'welcome_title' => 'Sales Assistant',
 *       'example_questions' => ['Show top customers', 'Monthly revenue'],
 *   ])
 */
class AiChatPanel extends AiChatModal
{
}
