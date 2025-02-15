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
use Log;
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
        $data = null;


        $this->setThread($thread)
            ->setMessageType(Message::MESSAGE)
            ->setMessageBody($this->emoji->toShort($params['message']) ?: null)
            ->setMessageOptionalParameters($params)
            ->setMessageOwner($this->messenger->getProvider())
            ->setSenderIp($senderIp)
            ->process()
            ->finalize();



            Log::info(Message::MESSAGE);
        if(Message::MESSAGE == 0){
            //check language
            $detect_language = $this->openai->detectLanguage($params['message']);
            if($detect_language == 'error'){
                $detect_language = 'English';
            }
            $messageori = $params['message'];       
            //get user language
            $user_language = 'Japanese'; //$this->messenger->getProvider()->language;
            if($detect_language != $user_language){
                $tranmessage = $this->openai->translateText($params['message'], $user_language);
            }   
      
            $message_id = $this->getMessage()->id;
            $message = Message::find($message_id);
            $data = array(
                'original' => array('message' => $messageori, 'language' => $detect_language),
                'translate' => array('message' => $tranmessage, 'language' => $user_language),
            );     
            $message->update(['body_translate' => json_encode($data)]);

        }

        return $this;
    }
}
