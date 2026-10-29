<?php

declare(strict_types=1);

namespace Condoedge\Ai\DTOs\Tasks;

/**
 * What a task's write produced; stored on the run so a repeated confirm returns it again.
 */
final class TaskOutcome
{
    public function __construct(
        public readonly bool $succeeded,
        public readonly ?string $message = null,
        public readonly array $data = [],
        /** @var array{url?: string, label?: string} where the chat's button leads, and its label's translation key */
        public readonly array $link = [],
        /** @var list<array{url: string, label: string}|array{task: string, label: string, groups?: list<string>}> chips after the link: a page, or a task's start (at those optional groups) */
        public readonly array $followUps = [],
    ) {
    }

    public static function success(array $data = [], ?string $message = null): self
    {
        return new self(true, $message, $data);
    }

    public static function failure(string $message, array $data = []): self
    {
        return new self(false, $message, $data);
    }

    public function withLink(string $url, string $labelKey): self
    {
        return new self($this->succeeded, $this->message, $this->data, ['url' => $url, 'label' => $labelKey], $this->followUps);
    }

    /** Keeps only the well-formed items: a `url` or a `task` key, each with a `label`; a task's may name the `groups` it starts at. */
    public function withFollowUps(array $items): self
    {
        $kept = [];
        foreach ($items as $item) {
            $target = is_array($item) ? (is_string($item['url'] ?? null) ? 'url' : (is_string($item['task'] ?? null) ? 'task' : null)) : null;
            if ($target !== null && is_string($item['label'] ?? null)) {
                $groups = $target === 'task' && is_array($item['groups'] ?? null) ? array_values(array_filter($item['groups'], 'is_string')) : [];
                $kept[] = [$target => $item[$target], 'label' => $item['label']] + ($groups !== [] ? ['groups' => $groups] : []);
            }
        }

        return new self($this->succeeded, $this->message, $this->data, $this->link, $kept);
    }

    public static function fromArray(array $stored): self
    {
        return (new self((bool) ($stored['succeeded'] ?? false), $stored['message'] ?? null, $stored['data'] ?? [], $stored['link'] ?? []))
            ->withFollowUps((array) ($stored['follow_ups'] ?? []));
    }

    /** @return array{succeeded: bool, message: ?string, data: array, link: array, follow_ups: list<array>} */
    public function toArray(): array
    {
        return ['succeeded' => $this->succeeded, 'message' => $this->message, 'data' => $this->data, 'link' => $this->link, 'follow_ups' => $this->followUps];
    }
}
