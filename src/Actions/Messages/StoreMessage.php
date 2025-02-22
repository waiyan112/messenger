<?php

namespace RTippin\Messenger\Actions\Messages;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use RTippin\Messenger\Contracts\BroadcastDriver;
use RTippin\Messenger\Contracts\EmojiInterface;
use RTippin\Messenger\Http\Request\MessageRequest;
use RTippin\Messenger\Messenger;
use RTippin\Messenger\Models\Message;
use RTippin\Messenger\Models\Thread;
use RTippin\Messenger\Services\OpenAIService;
use Throwable;

class StoreMessage extends NewMessageAction
{
    /**
     * @var Messenger
     */
    private Messenger $messenger;

    /**
     * @var EmojiInterface
     */
    private EmojiInterface $emoji;

    /**
     * StoreMessage constructor.
     *
     * @param  BroadcastDriver  $broadcaster
     * @param  DatabaseManager  $database
     * @param  Dispatcher  $dispatcher
     * @param  Messenger  $messenger
     * @param  EmojiInterface  $emoji
     */
    public function __construct(BroadcastDriver $broadcaster,
                                DatabaseManager $database,
                                Dispatcher $dispatcher,
                                Messenger $messenger,
                                EmojiInterface $emoji)
    {
        parent::__construct(
            $broadcaster,
            $database,
            $dispatcher
        );

        $this->messenger = $messenger;
        $this->emoji = $emoji;
        $this->openai = new OpenAIService();
    }

    /**
     * Store new message, update thread updated_at,
     * mark read for participant, broadcast.
     *
     * @param  Thread  $thread
     * @param  array  $params
     * @param  string|null  $senderIp
     * @return $this
     *
     * @see MessageRequest
     *
     * @throws Throwable
     */
    public function execute(Thread $thread,
                            array $params,
                            ?string $senderIp = null): self
    {
        $detect_language = $this->openai->detectLanguage($params['message']);
        if ($detect_language == 'error') {
            $detect_language = 'English';
        }
        
        $otherParticipant = $thread->participants()
            ->where('owner_id', '!=', $this->messenger->getProvider()->id)
            ->first();

        $user_language = 'English';
        $user_language_mode = '0';
        
        if ($otherParticipant && $otherParticipant->owner) {
            try {
                $user_language = $otherParticipant->owner->language ?? 'English';
                $user_language_mode = $otherParticipant->translate_mode ?? '0';
            } catch (\Throwable $e) {
                $user_language = 'English';
                $user_language_mode = '0';
            }
        }
       
        $tranmessage = $params['message'];
        $translate = false;

        if ($detect_language != $user_language && $user_language_mode != '0') {
            $translate = true;
            $tranmessage = $this->openai->translateText($params['message'], $user_language);
        }   

        // Create translation data
        $translationData = [
            'original' => ['message' => $params['message'], 'language' => $detect_language],
            'translate' => ['message' => $tranmessage, 'language' => $user_language],
            'translate_status' => $translate,
            'language_mode' => $user_language_mode
        ];
        
        // Add body_translate to the original params
        $params['body_translate'] = json_encode($translationData);

        // Set the thread and message properties with all params
        $this->setThread($thread)
            ->setMessageType(Message::MESSAGE)
            ->setMessageBody($this->emoji->toShort($params['message']) ?: null)
            ->setMessageOptionalParameters($params)
            ->setMessageOwner($this->messenger->getProvider())
            ->setSenderIp($senderIp);

        // Process and finalize the message
        $this->process()->finalize();

        return $this;
    }
}
