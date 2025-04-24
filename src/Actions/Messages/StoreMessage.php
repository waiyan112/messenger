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
        try {
            // Make sure thread exists
            if (!$thread || !$thread->id) {
                \Log::warning('Invalid thread provided to StoreMessage');
                throw new \Exception('Invalid thread');
            }
            
            // SIMPLIFIED - Use auth directly without trying to get from messenger
            $user = auth()->user();
            
            if (!$user) {
                \Log::warning('No authenticated user found in StoreMessage');
                throw new \Exception('User not authenticated');
            }
            
            // Create simplified translation data
            $translationData = [
                'original' => ['message' => $params['message'] ?? '', 'language' => 'auto-detect'],
                'translate' => ['message' => $params['message'] ?? '', 'language' => 'English'], 
                'translate_status' => false,
                'language_mode' => '0'
            ];
            
            // Add translation data to params
            $params['body_translate'] = json_encode($translationData);
            
            // Set message properties and owner
            $this->setThread($thread)
                ->setMessageType(Message::MESSAGE)
                ->setMessageBody($this->emoji->toShort($params['message'] ?? '') ?: null)
                ->setMessageOptionalParameters($params)
                ->setMessageOwner($user)
                ->setSenderIp($senderIp);
            
            // Process and finalize the message
            $this->process()->finalize();
            
            // Get the created message
            $message = $this->getMessage();
            
            // If message was created successfully, schedule background translation
            if ($message && $message->id) {
                // Use a non-blocking approach using dispatch or queue
                dispatch(function() use ($thread, $message, $params) {
                    try {
                        $this->handleTranslation($thread, $message, $params);
                    } catch (\Exception $e) {
                        \Log::error("Error in scheduled translation: " . $e->getMessage());
                    }
                })->afterResponse();
            }
        } catch (\Throwable $e) {
            \Log::error('Error in StoreMessage: ' . $e->getMessage());
            throw $e;
        }
        
        return $this;
    }
    
    /**
     * Handle translations outside the main request flow
     * 
     * @param Thread $thread
     * @param Message $message
     * @param array $params
     * @return void
     */
    private function handleTranslation($thread, $message, $params)
    {
        try {
            // Skip if no message body
            if (empty($params['message'])) {
                return;
            }
            
            // Find participants who need translation (only those with translate_mode = 1)
            $participants = \Illuminate\Support\Facades\DB::table('participants')
                ->where('thread_id', $thread->id)
                ->where('translate_mode', 1) // Only those with translation enabled
                ->where('owner_id', '!=', $message->owner_id) // Skip message sender
                ->get();
            
            if ($participants->isEmpty()) {
                return;
            }
            
            // Get all participant user IDs to fetch their language preferences
            $userIds = $participants->pluck('owner_id')->toArray();
            
            // Get language preferences for these users
            $userLanguages = \Illuminate\Support\Facades\DB::table('users')
                ->whereIn('id', $userIds)
                ->select('id', 'language_id')
                ->get()
                ->keyBy('id');
                
            // Get available languages
            $languages = \Illuminate\Support\Facades\Cache::remember('available_languages', 60*24, function() {
                return \Illuminate\Support\Facades\DB::table('languages')->get()->keyBy('id');
            });
            
            // Create translation placeholders for each participant based on their language preference
            foreach ($participants as $participant) {
                // Get user's language preference
                $user = $userLanguages[$participant->owner_id] ?? null;
                
                if (!$user) {
                    continue; // Skip if user not found
                }
                
                // Get language code from language_id
                $languageId = $user->language_id ?? 2; // Default to Burmese (ID: 2) if not set
                $language = $languages[$languageId] ?? null;
                $targetLanguage = $language ? $language->code : 'Burmese'; // Default to Burmese if language not found
                
                // Insert translation record with user's target language
                \Illuminate\Support\Facades\DB::table('message_translations')->insertOrIgnore([
                    'message_id' => $message->id,
                    'user_id' => $participant->owner_id,
                    'source_language' => null,
                    'target_language' => $targetLanguage,
                    'translated_text' => '[Translation pending]',
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }
            
            // Use app URL for API call
            $baseUrl = config('app.url', 'http://localhost');
            $message_id = $message->id;
            
            // Make HTTP request to translation endpoint
            try {
                \Illuminate\Support\Facades\Http::post($baseUrl . '/api/process-translations', [
                    'message_id' => $message_id
                ]);
            } catch (\Exception $e) {
                \Log::error("Failed to call translation API: " . $e->getMessage());
            }
        } catch (\Exception $e) {
            \Log::error("Error handling translation: " . $e->getMessage());
        }
    }
}
