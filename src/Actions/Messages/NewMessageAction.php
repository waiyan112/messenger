<?php

namespace RTippin\Messenger\Actions\Messages;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use RTippin\Messenger\Actions\BaseMessengerAction;
use RTippin\Messenger\Actions\Threads\MarkParticipantRead;
use RTippin\Messenger\Broadcasting\NewMessageBroadcast;
use RTippin\Messenger\Contracts\BroadcastDriver;
use RTippin\Messenger\Contracts\MessengerProvider;
use RTippin\Messenger\Events\NewMessageEvent;
use RTippin\Messenger\Http\Request\BaseMessageRequest;
use RTippin\Messenger\Http\Resources\MessageResource;
use RTippin\Messenger\Models\Bot;
use RTippin\Messenger\Models\Message;
use Throwable;
use Illuminate\Support\Facades\Log;

abstract class NewMessageAction extends BaseMessengerAction
{
    /**
     * @var BroadcastDriver
     */
    protected BroadcastDriver $broadcaster;

    /**
     * @var Dispatcher
     */
    protected Dispatcher $dispatcher;

    /**
     * @var DatabaseManager
     */
    protected DatabaseManager $database;

    /**
     * @var int
     */
    private int $messageType;

    /**
     * @var string|null
     */
    private ?string $messageBody;
    private ?string $messageBodyTranslate = null;

    /**
     * @var string|null
     */
    private ?string $messageTemporaryId = null;

    /**
     * @var array|null
     */
    private ?array $messageExtraData = null;

    /**
     * @var Message|null
     */
    private ?Message $replyingTo = null;

    /**
     * @var string|null
     */
    private ?string $senderIp = null;

    /**
     * @var MessengerProvider
     */
    private MessengerProvider $messageOwner;

    /**
     * NewMessageAction constructor.
     *
     * @param  BroadcastDriver  $broadcaster
     * @param  DatabaseManager  $database
     * @param  Dispatcher  $dispatcher
     */
    public function __construct(BroadcastDriver $broadcaster,
                                DatabaseManager $database,
                                Dispatcher $dispatcher)
    {
        $this->broadcaster = $broadcaster;
        $this->dispatcher = $dispatcher;
        $this->database = $database;
    }

    /**
     * @param  int  $type
     * @return $this
     */
    protected function setMessageType(int $type): self
    {
        $this->messageType = $type;

        return $this;
    }

    /**
     * @param  string|null  $body
     * @return $this
     */
    protected function setMessageBody(?string $body): self
    {
        $this->messageBody = $body;

        return $this;
    }

    /**
     * @param  array  $parameters
     * @return $this
     *
     * @see BaseMessageRequest
     */
    protected function setMessageOptionalParameters(array $parameters): self
    {
        $this->messageTemporaryId = $parameters['temporary_id'] ?? null;

        $this->messageExtraData = $parameters['extra'] ?? null;
        $this->messageBodyTranslate = $parameters['body_translate'] ?? null;

        $this->setReplyingToMessage($parameters['reply_to_id'] ?? null);

        return $this;
    }

    /**
     * @param  MessengerProvider  $owner
     * @return $this
     */
    protected function setMessageOwner(MessengerProvider $owner): self
    {
        $this->messageOwner = $owner;

        return $this;
    }

    /**
     * @param  string|null  $senderIp
     * @return $this
     */
    protected function setSenderIp(?string $senderIp): self
    {
        $this->senderIp = $senderIp;

        return $this;
    }

    /**
     * @return $this
     *
     * @throws Throwable
     */
    protected function process(): self
    {
        $this->isChained()
            ? $this->handle()
            : $this->database->transaction(fn () => $this->handle(), 5);

        return $this;
    }

    /**
     * Complete the cycle.
     *
     * @return void
     */
    protected function finalize(): void
    {
        // Generate the resource first (required for immediate response)
        $this->generateResource();      

        
        // Extract only necessary data to avoid serializing the entire object
        $messageId = $this->getMessage()->id;
        $threadId = $this->getThread()->id;
        $resource = $this->getJsonResource()->resolve();
        
        // Send broadcasts and fire events without serializing the entire object
        dispatch(function() use ($messageId, $threadId, $resource) {            
            try {
                // Get fresh instances from the database instead of serializing
                $message = Message::findOrFail($messageId);
                $thread = $message->thread;
                
                // Get broadcaster instance
                $broadcaster = app(BroadcastDriver::class);               

                $broadcaster
                    ->toAllInThread($thread)
                    ->with($resource)
                    ->broadcast(NewMessageBroadcast::class);               

                
                // Fire events
                event(new NewMessageEvent(
                    $message,
                    $thread,
                    $thread->isGroup() && $message->notFromBot() && $message->notSystemMessage() && $thread->isAdmin(),
                    request()->ip()
                ));                

            } catch (\Exception $e) {
                Log::error("NewMessageAction: Error in broadcast/event dispatch: {$e->getMessage()}", [
                    'message_id' => $messageId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        })->afterResponse()->onQueue('messenger-high');
    }

    /**
     * Generate the message resource.
     *
     * @return $this
     */
    private function generateResource(): self
    {
        $this->setJsonResource(new MessageResource(
            $this->getMessage(),
            $this->getThread(),
            true
        )
        );

        return $this;
    }

    /**
     * @return $this
     */
    private function fireBroadcast(): self
    {
        
        if ($this->shouldFireBroadcast()) {
            try {
                $this->broadcaster
                    ->toAllInThread($this->getThread())
                    ->with($this->getJsonResource()->resolve())
                    ->broadcast(NewMessageBroadcast::class);                   

            } catch (\Exception $e) {
                Log::error('NewMessageAction: Broadcasting failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }
        
        return $this;
    }

    /**
     * @return void
     */
    private function fireEvents(): void
    {        
        if ($this->shouldFireEvents()) {
            try {
                $this->dispatcher->dispatch(new NewMessageEvent(
                    $this->getMessage(true),
                    $this->getThread(true),
                    $this->isGroupAdmin(),
                    $this->senderIp
                ));
                
            } catch (\Exception $e) {
                Log::error('NewMessageAction: Event dispatching failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }
    }

    /**
     * Add the admin flag when not from bot and not a system message.
     *
     * @return bool
     */
    private function isGroupAdmin(): bool
    {
        return $this->getThread()->isGroup()
            && $this->getMessage()->notFromBot()
            && $this->getMessage()->notSystemMessage()
            && $this->getThread()->isAdmin();
    }

    /**
     * @param  string|null  $replyToId
     * @return void
     */
    private function setReplyingToMessage(?string $replyToId): void
    {
        if (! is_null($replyToId)) {
            $this->replyingTo = $this->getThread()
                ->messages()
                ->nonSystem()
                ->with('owner')
                ->find($replyToId);

            return;
        }

        $this->replyingTo = null;
    }

    /**
     * Store message. If not chained, touch thread and mark sender as read.
     *
     * @return void
     */
    private function handle(): void
    {
        $this->storeMessage();

        if ($this->shouldExecuteChains()) {
            $this->getThread()->touch();

            if ($this->shouldMarkRead()) {
                $this->chain(MarkParticipantRead::class)
                    ->withoutDispatches()
                    ->execute($this->getThread()->currentParticipant());
            }
        }
    }

    /**
     * Only mark read when not a system message and not a message sent from a bot.
     *
     * @return bool
     */
    private function shouldMarkRead(): bool
    {
        return in_array($this->messageType, Message::NonSystemTypes)
            && ! $this->messageOwner instanceof Bot;
    }

    /**
     * Store message, attach owner relation from
     * provider in memory, add temp ID.
     *
     * @return void
     */
    private function storeMessage(): void
    {
        $this->setMessage(
            $this->getThread()->messages()->create([
                'type' => $this->messageType,
                'owner_id' => $this->messageOwner->getKey(),
                'owner_type' => $this->messageOwner->getMorphClass(),
                'body' => $this->messageBody,
                'body_translate' => $this->messageBodyTranslate,
                'reply_to_id' => optional($this->replyingTo)->id,
                'extra' => $this->messageExtraData,
            ])
            ->setRelations([
                'owner' => $this->messageOwner,
                'thread' => $this->getThread(),
                'replyTo' => $this->replyingTo,
            ])
            ->setTemporaryId($this->messageTemporaryId)
        );
    }
}
