<?php

namespace RTippin\Messenger\Repositories;

use Illuminate\Database\Eloquent\Collection;
use RTippin\Messenger\Messenger;
use RTippin\Messenger\Models\Message;
use RTippin\Messenger\Models\Thread;
use RTippin\Messenger\Support\Helpers;

class MessageRepository
{
    /**
     * @var Messenger
     */
    private Messenger $messenger;

    /**
     * MessageRepository constructor.
     *
     * @param  Messenger  $messenger
     */
    public function __construct(Messenger $messenger)
    {
        $this->messenger = $messenger;
    }

    /**
     * @param  Thread  $thread
     * @return Collection
     */
    public function getThreadMessagesIndex(Thread $thread): Collection
    {
        return $thread->messages()
            ->latest()
            ->limit($this->messenger->getMessagesIndexCount())
            ->with([
                'owner',
                'reactions.owner',
            ])
            ->cacheFor(now()->addMinutes(2))  // Cache for 2 minutes
            ->cacheTags(['messages', "thread_{$thread->id}"])  // Tag for easy invalidation
            ->get();
    }

    /**
     * @param  Thread  $thread
     * @param  Message  $message
     * @return Collection
     */
    public function getThreadMessagesPage(Thread $thread, Message $message): Collection
    {
        return $thread->messages()
            ->latest()
            ->with([
                'owner',
                'reactions.owner',
            ])
            ->where('created_at', '<=', Helpers::precisionTime($message->created_at))
            ->where('id', '!=', $message->id)
            ->limit($this->messenger->getMessagesPageCount())
            ->cacheFor(now()->addMinutes(2))  // Cache for 2 minutes
            ->cacheTags(['messages', "thread_{$thread->id}"])  // Tag for easy invalidation
            ->get();
    }

    /**
     * Find a specific message by ID and return its context with surrounding messages
     * This is used when jumping to a specific message in a reply scenario
     * 
     * @param  Thread  $thread
     * @param  Message  $message
     * @return array
     */
    public function findMessageByIdWithContext(Thread $thread, Message $message): array
    {
        // Get the target message with its relationships
        $targetMessage = $message->load(['owner', 'reactions.owner']);
        
        // Get messages before the target
        $messagesBefore = $thread->messages()
            ->latest()
            ->where('created_at', '>', Helpers::precisionTime($message->created_at))
            ->orWhere(function($query) use ($message) {
                $query->where('created_at', '=', Helpers::precisionTime($message->created_at))
                    ->where('id', '>', $message->id);
            })
            ->limit(floor($this->messenger->getMessagesPageCount() / 2))
            ->with(['owner', 'reactions.owner'])
            ->cacheFor(now()->addMinutes(2))
            ->cacheTags(['messages', "thread_{$thread->id}", "context_{$message->id}"])
            ->get()
            ->reverse();
        
        // Get messages after the target
        $messagesAfter = $thread->messages()
            ->latest()
            ->where('created_at', '<', Helpers::precisionTime($message->created_at))
            ->orWhere(function($query) use ($message) {
                $query->where('created_at', '=', Helpers::precisionTime($message->created_at))
                    ->where('id', '<', $message->id);
            })
            ->limit(floor($this->messenger->getMessagesPageCount() / 2))
            ->with(['owner', 'reactions.owner'])
            ->cacheFor(now()->addMinutes(2))
            ->cacheTags(['messages', "thread_{$thread->id}", "context_{$message->id}"])
            ->get();
            
        // Calculate pagination details
        $messagePosition = $thread->messages()
            ->where('created_at', '>=', Helpers::precisionTime($message->created_at))
            ->count();
        
        $totalMessages = $thread->messages()->count();
        $totalPages = ceil($totalMessages / $this->messenger->getMessagesPageCount());
        $currentPage = ceil($messagePosition / $this->messenger->getMessagesPageCount());
        
        // Merge messages for context, with target message in the middle
        $contextMessages = $messagesBefore->concat([$targetMessage])->concat($messagesAfter);
        
        return [
            'messages' => $contextMessages,
            'target_message_id' => $message->id,
            'pagination' => [
                'total_messages' => $totalMessages,
                'total_pages' => $totalPages,
                'current_page' => $currentPage,
                'per_page' => $this->messenger->getMessagesPageCount()
            ]
        ];
    }
}
