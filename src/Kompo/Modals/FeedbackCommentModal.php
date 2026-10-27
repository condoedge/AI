<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Modals;

use Condoedge\Ai\Kompo\Support\ConversationActions;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Utils\Kompo\Common\Modal;

/**
 * The optional comment after a 👎 (opened by the not-helpful button next to the rating it
 * already saved). Never required: closing the modal keeps the rating. Pre-filled with the
 * comment already given; saved through ConversationActions, which keeps it only on the
 * owner's negatively rated answer.
 */
class FeedbackCommentModal extends Modal
{
    protected $_Title = 'ai.feedback.title';
    public $class = 'overflow-hidden max-w-lg rounded-2xl';

    protected $noHeaderButtons = true;
    protected $hasSubmitButton = false;
    public $preventSubmit = true;

    protected ?string $comment = null;

    public function created()
    {
        $message = AiConversation::where('user_id', auth()->id())
            ->find($this->store('conversation_id'))
            ?->messages()->find($this->store('message_id'));
        $comment = $message?->metadata['feedback_comment'] ?? null;
        $this->comment = is_string($comment) ? $comment : null;
    }

    public function body()
    {
        return _Rows(
            _Rows(
                _Html(__('ai.feedback.comment-intro'))->class('text-sm text-gray-500 mb-3'),
                _Textarea()->name('comment', false)
                    ->value($this->comment)
                    ->attr(['maxlength' => ConversationActions::FEEDBACK_COMMENT_MAX])
                    ->placeholder(__('ai.feedback.comment-placeholder'))
                    ->class('w-full min-h-[110px] p-3 border border-gray-200 rounded-xl resize-none'),
            )->class('p-6'),
            _FlexBetween(
                _Link(__('ai.feedback.skip'))
                    ->class('px-4 py-2 text-gray-500 hover:text-gray-700 transition-all')
                    ->closeModal(),
                _Link(__('ai.feedback.send'))->button()
                    ->selfPost('saveComment')
                    ->withAllFormValues()
                    ->alert('ai.messages.feedback-received')
                    ->closeModal(),
            )->class('px-6 py-4 border-t border-gray-200 bg-gray-50'),
        );
    }

    public function saveComment()
    {
        $comment = request('comment');

        app(ConversationActions::class)->recordFeedbackComment(
            (int) $this->store('conversation_id'),
            (int) $this->store('message_id'),
            is_string($comment) ? $comment : null,
            auth()->user(),
        );
    }
}
