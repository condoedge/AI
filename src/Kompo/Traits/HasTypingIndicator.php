<?php

namespace Condoedge\Ai\Kompo\Traits;

trait HasTypingIndicator
{
    protected function typingIndicator()
    {
        return _Flex(
            _Html($this->assistantAvatarHtml())->class('mr-3 flex-shrink-0'),
            _Rows(
                _Flex(
                    _Html('<span class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0ms"></span>'),
                    _Html('<span class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 150ms"></span>'),
                    _Html('<span class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 300ms"></span>'),
                )->class('gap-1 items-center'),
            )->class('px-4 py-3 rounded-2xl rounded-tl-md bg-white border border-gray-100 shadow-sm inline-flex'),
        )->class('items-start')->id('typing-indicator');
    }

    protected function typingIndicatorScript(): string
    {
        return "() => {
            const el = document.getElementById('typing-indicator');
            if (el) el.classList.remove('hidden');
        }";
    }

    protected function hideTypingScript(): string
    {
        return "() => {
            const el = document.getElementById('typing-indicator');
            if (el) el.classList.add('hidden');
        }";
    }
}
