<?php

namespace RTippin\Messenger\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use RTippin\Messenger\Messenger;
use RTippin\Messenger\Models\Thread;
use RTippin\Messenger\Support\Helpers;

class ThreadRepository
{
    /**
     * @var Messenger
     */
    private Messenger $messenger;

    /**
     * ThreadRepository constructor.
     *
     * @param  Messenger  $messenger
     */
    public function __construct(Messenger $messenger)
    {
        $this->messenger = $messenger;
    }

    /**
     * @return Collection
     */
    public function getProviderThreadsIndex(): Collection
    {
        return Thread::hasProvider($this->messenger->getProvider())
            ->latest('updated_at')
            ->with([
                'participants.owner',
                'latestMessage.owner',
                'activeCall.participants.owner',
            ])
            ->limit($this->messenger->getThreadsIndexCount())
            ->cacheFor(now()->addMinutes(5))  // Cache for 5 minutes
            ->get();
    }

    /**
     * @param  Thread  $thread
     * @return Collection
     */
    public function getProviderThreadsPage(Thread $thread): Collection
    {
        return Thread::hasProvider($this->messenger->getProvider())
            ->latest('updated_at')
            ->with([
                'participants.owner',
                'latestMessage.owner',
                'activeCall.participants.owner',
            ])
            ->where('threads.updated_at', '<=', Helpers::precisionTime($thread->updated_at))
            ->where('threads.id', '!=', $thread->id)
            ->limit($this->messenger->getThreadsPageCount())
            ->cacheFor(now()->addMinutes(5))  // Cache for 5 minutes
            ->get();
    }

    /**
     * @return Thread|Builder
     */
    public function getProviderThreadsWithActiveCallsBuilder(): Builder
    {
        return Thread::hasProvider($this->messenger->getProvider())->has('activeCall');
    }

    /**
     * @return Collection
     */
    public function getProviderThreadsWithActiveCalls(): Collection
    {
        return $this->getProviderThreadsWithActiveCallsBuilder()
            ->cacheFor(now()->addMinute())  // Cache for 1 minute since active calls may change frequently
            ->get();
    }

    /**
     * @return Thread|null
     */
    public function getProviderOldestThread(): ?Thread
    {
        return Thread::hasProvider($this->messenger->getProvider())
            ->oldest('updated_at')
            ->cacheFor(now()->addHour())  // Cache for longer since this rarely changes
            ->first();
    }

    /**
     * @return Thread|Builder
     */
    public function getProviderUnreadThreadsBuilder(): Builder
    {
        return Thread::hasProvider($this->messenger->getProvider())
            ->where(function (Builder $query) {
                return $query->whereNull('participants.last_read')
                    ->orWhere('threads.updated_at', '>', $query->raw('participants.last_read'));
            });
    }
}
