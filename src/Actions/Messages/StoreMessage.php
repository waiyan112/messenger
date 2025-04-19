<?php

namespace RTippin\Messenger\Actions\Messages;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Cache;
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
     * @var OpenAIService|null
     */
    private $openai;

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
        // အချိန်ကုန်လွန်းသောကြောင့် ပထမတွင် message ကို အရင်သိမ်းပြီးမှ 
        // ဘာသာပြန်ခြင်းကို နောက်မှ နောက်ခံလုပ်ငန်းစဉ်အဖြစ် ဆောင်ရွက်ရန်

        // ပေးပို့သူ၏ language preference ကို cache မှရယူခြင်း (မရှိပါက ရှာဖွေခြင်း)
        $otherParticipantId = null;
        $user_language = 'English';
        $user_language_mode = '0';
        
        // Cache key များအတွက် participant သတ်မှတ်ခြင်း
        $senderId = $this->messenger->getProvider()->id;
        $threadId = $thread->id;
        $cacheKey = "thread_{$threadId}_participant_language_{$senderId}";
        
        // Cache ထဲမှ language settings ရယူ
        $cachedSettings = Cache::remember($cacheKey, 60 * 24, function () use ($thread, $senderId) {
            $otherParticipant = $thread->participants()
                ->where('owner_id', '!=', $senderId)
                ->first();
                
            $settings = [
                'language' => 'English',
                'translate_mode' => '0'
            ];
            
            if ($otherParticipant && $otherParticipant->owner) {
                try {
                    $settings['language'] = $otherParticipant->owner->language ?? 'English';
                    $settings['translate_mode'] = $otherParticipant->translate_mode ?? '0';
                } catch (\Throwable $e) {
                    // Default settings will be used
                }
                
                $settings['participant_id'] = $otherParticipant->id;
            }
            
            return $settings;
        });
        
        // Cache ထဲမှ settings များကို အသုံးပြု
        $user_language = $cachedSettings['language'];
        $user_language_mode = $cachedSettings['translate_mode'];
        $otherParticipantId = $cachedSettings['participant_id'] ?? null;
        
        $translationData = [
            'original' => ['message' => $params['message'], 'language' => 'auto-detect'],
            'translate' => ['message' => $params['message'], 'language' => $user_language],
            'translate_status' => false,
            'language_mode' => $user_language_mode
        ];
        
        // ဘာသာပြန်ရန် မလိုအပ်ပါက သို့မဟုတ် အသုံးပြုသူ၏ ဘာသာပြန်ဆိုမှု mode ပိတ်ထားပါက
        if ($user_language_mode == '0') {
            // translation မလိုအပ်ပါ - အချိန်ကုန်လွန်းခြင်းမှ ရှောင်ကြဉ်ရန်
        } 
        // ဘာသာပြန်ရန် လိုအပ်ပါက
        else {
            // Language detection နှင့် translation ကို နောက်ခံလုပ်ငန်းစဉ်အဖြစ် queue ပေါ်တင်ပြီးမှ ဆောင်ရွက်မည်
            // For now, we'll use simple delay - in production, this should be a proper queued job
            dispatch(function() use ($params, $user_language, $threadId, $otherParticipantId) {
                try {
                    $detect_language = $this->openai->detectLanguage($params['message']);
                    
                    // သတ်မှတ်ဘာသာစကားနှင့် အသုံးပြုသူ ဘာသာစကား မတူပါက ဘာသာပြန်ရန်
                    if ($detect_language != 'error' && $detect_language != $user_language) {
                        $tranmessage = $this->openai->translateText($params['message'], $user_language);
                        
                        // ဘာသာပြန်ပြီးသော message ကို update လုပ်ရမည်
                        $translationData = [
                            'original' => ['message' => $params['message'], 'language' => $detect_language],
                            'translate' => ['message' => $tranmessage, 'language' => $user_language],
                            'translate_status' => true,
                            'language_mode' => $user_language_mode
                        ];
                        
                        // Find the message and update it
                        $message = Message::where('thread_id', $threadId)
                            ->where('body', $params['message'])
                            ->orderByDesc('created_at')
                            ->first();
                            
                        if ($message) {
                            $message->body_translate = json_encode($translationData);
                            $message->save();
                            
                            // Broadcast the updated message with translation
                            // (optional, depends on your needs)
                        }
                    }
                } catch (\Exception $e) {
                    \Log::error('Translation failed: ' . $e->getMessage());
                }
            })->delay(now()->addSeconds(1));
        }
        
        // Add simplified body_translate to the original params
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
