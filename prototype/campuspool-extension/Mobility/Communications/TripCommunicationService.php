<?php

namespace STS\Mobility\Communications;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use STS\Mobility\Trust\CohortPolicy;
use STS\Models\Conversation;
use STS\Models\Message;
use STS\Models\Passenger;
use STS\Models\Trip;
use STS\Models\User;

/** In-app delivery only. Canonical Conversation/Message rows, never provider push. */
final class TripCommunicationService
{
    public function show(int $tripId, User $actor, ?int $after = null, ?int $before = null): array
    {
        return DB::transaction(function () use ($tripId, $actor, $after, $before): array {
            [$trip, $ids] = $this->authorize($tripId, $actor);
            $conversation = $this->conversationId($tripId);
            $messages = [];
            if ($conversation) {
                $query = Message::where('conversation_id', $conversation);
                if ($before !== null) $query->where('id', '<', $before);
                elseif ($after !== null) $query->where('id', '>', $after);
                $rows = $query->orderBy('id', $after !== null ? 'asc' : 'desc')->limit(50)->get();
                if ($after === null) $rows = $rows->reverse()->values();
                $messages = $rows->map(fn ($m) => $this->message($m))->all();
            }
            $first = $messages[0]['id'] ?? null;
            $older = $first && Message::where('conversation_id', $conversation)->where('id', '<', $first)->exists();
            $blocked = $this->anyBlocked($ids);
            return ['trip_id' => $tripId, 'messages' => $messages,
                'participants' => User::whereIn('id', $ids)->orderBy('id')->get()->map(fn ($u) => ['id' => (int) $u->id, 'name' => (string) $u->name, 'blocked_by_you' => (bool) DB::table('mobility_user_blocks')->where(['actor_id' => $actor->id, 'target_id' => $u->id, 'blocked' => true])->exists()])->all(),
                'next_after' => count($messages) === 50 ? $messages[49]['id'] : null, 'older_before' => $older ? $first : null,
                'blocked' => $blocked, 'can_send' => ! $blocked && ! $this->terminal($trip),
                'unread_count' => $this->unread($tripId, (int) $actor->id, $conversation)];
        });
    }

    public function send(int $tripId, User $actor, string $text, string $key): array
    {
        return DB::transaction(function () use ($tripId, $actor, $text, $key): array {
            [$trip, $ids] = $this->authorize($tripId, $actor);
            $this->lockPeople($ids, true);
            return $this->once($tripId, (int) $actor->id, 'send', $key, ['text' => $text], function () use ($trip, $tripId, $actor, $text, $ids): array {
                if ($this->terminal($trip)) throw new CommunicationException('TRIP_CHAT_CLOSED');
                if ($this->anyBlocked($ids)) throw new CommunicationException('PARTICIPANT_BLOCKED', 403);
                $conversationId = $this->conversationId($tripId);
                if (! $conversationId) {
                    $conversation = Conversation::create(['type' => Conversation::TYPE_TRIP_CONVERSATION, 'title' => 'Trip chat', 'trip_id' => $tripId]);
                    $conversationId = (int) $conversation->id;
                    DB::table('mobility_trip_conversations')->insert(['trip_id' => $tripId, 'conversation_id' => $conversationId, 'created_at' => now()]);
                } else $conversation = Conversation::findOrFail($conversationId);
                $conversation->users()->sync(array_fill_keys($ids, ['read' => false, 'notifications_enabled' => false]));
                $message = Message::create(['user_id' => $actor->id, 'conversation_id' => $conversationId, 'text' => $text, 'estado' => Message::STATE_NOLEIDO, 'is_system' => false]);
                foreach ($ids as $id) DB::table('user_message_read')->insert(['user_id' => $id, 'message_id' => $message->id, 'read' => $id === (int) $actor->id, 'created_at' => now(), 'updated_at' => now()]);
                return $this->message($message);
            });
        });
    }

    public function read(int $tripId, User $actor, int $through): array
    {
        return DB::transaction(function () use ($tripId, $actor, $through): array {
            $this->authorize($tripId, $actor);
            $conversation = $this->conversationId($tripId);
            if (! $conversation || ! Message::where('conversation_id', $conversation)->whereKey($through)->exists()) throw new CommunicationException('MESSAGE_NOT_FOUND', 404);
            $previous = (int) DB::table('mobility_trip_message_reads')->where(['trip_id' => $tripId, 'user_id' => $actor->id])->value('through_id');
            $through = max($previous, $through);
            DB::table('mobility_trip_message_reads')->updateOrInsert(['trip_id' => $tripId, 'user_id' => $actor->id], ['through_id' => $through, 'updated_at' => now()]);
            DB::table('user_message_read')->where('user_id', $actor->id)->whereIn('message_id', Message::where('conversation_id', $conversation)->where('id', '<=', $through)->select('id'))->update(['read' => true, 'updated_at' => now()]);
            return ['read_through_id' => $through];
        });
    }

    public function block(int $tripId, User $actor, int $target, bool $blocked, string $key): array
    {
        return DB::transaction(function () use ($tripId, $actor, $target, $blocked, $key): array {
            [, $ids] = $this->authorize($tripId, $actor);
            if ($target === (int) $actor->id || ! in_array($target, $ids, true)) throw new CommunicationException('BLOCK_TARGET_FORBIDDEN', 403);
            $this->lockPeople([(int) $actor->id, $target], true);
            return $this->once($tripId, (int) $actor->id, 'block', $key, ['target_user_id' => $target, 'blocked' => $blocked], function () use ($actor, $target, $blocked): array {
                DB::table('mobility_user_blocks')->updateOrInsert(['actor_id' => $actor->id, 'target_id' => $target], ['blocked' => $blocked, 'created_at' => now(), 'updated_at' => now()]);
                return ['target_user_id' => $target, 'blocked' => $blocked];
            });
        });
    }

    public function notifications(User $actor): array
    {
        if (! CohortPolicy::required() || ! CohortPolicy::approved((int) $actor->id)) throw new CommunicationException('CHAT_FORBIDDEN', 403);
        $rows = DB::table('mobility_trip_conversations as c')->join('conversations_users as u', 'u.conversation_id', '=', 'c.conversation_id')->where('u.user_id', $actor->id)->orderByDesc('c.trip_id')->limit(100)->select('c.*')->get();
        $trips = [];
        foreach ($rows as $row) {
            try { $this->authorize((int) $row->trip_id, $actor, false); } catch (CommunicationException) { continue; }
            $count = $this->unread((int) $row->trip_id, (int) $actor->id, (int) $row->conversation_id);
            if ($count) $trips[] = ['trip_id' => (int) $row->trip_id, 'unread_count' => $count, 'last_message_id' => (int) Message::where('conversation_id', $row->conversation_id)->max('id')];
        }
        return ['unread_messages' => array_sum(array_column($trips, 'unread_count')), 'trips' => $trips];
    }

    private function authorize(int $tripId, User $actor, bool $lock = true): array
    {
        if (! CohortPolicy::required() || ! CohortPolicy::approved((int) $actor->id)) throw new CommunicationException('CHAT_FORBIDDEN', 403);
        $query = Trip::whereKey($tripId); if ($lock) $query->lockForUpdate();
        $trip = $query->first();
        if (! $trip) throw new CommunicationException('TRIP_NOT_FOUND', 404);
        $ids = Passenger::where('trip_id', $tripId)->where('passenger_type', Passenger::TYPE_PASAJERO)->where('request_state', Passenger::STATE_ACCEPTED)->lock('for share nowait')->get()->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $assignment = DB::table('mobility_dispatch_assignments')->where('trip_id', $tripId)->first();
        if ($assignment) $ids = [(int) $assignment->rider_id];
        $ids[] = (int) $trip->user_id; $ids = array_values(array_unique($ids)); sort($ids);
        if (! in_array((int) $actor->id, $ids, true)) throw new CommunicationException('CHAT_FORBIDDEN', 403);
        return [$trip, $ids];
    }
    private function terminal(Trip $trip): bool
    {
        return $trip->state === Trip::STATE_CANCELED || in_array(DB::table('mobility_trip_lifecycles')->where('trip_id', $trip->id)->value('state'), ['completed', 'cancelled'], true);
    }
    private function conversationId(int $trip): ?int { $id = DB::table('mobility_trip_conversations')->where('trip_id', $trip)->value('conversation_id'); return $id ? (int) $id : null; }
    private function message(Message $m): array { return ['id' => (int) $m->id, 'user_id' => (int) $m->user_id, 'text' => (string) $m->text, 'created_at' => $m->created_at?->toISOString()]; }
    private function unread(int $trip, int $actor, ?int $conversation): int
    {
        if (! $conversation) return 0;
        $through = (int) DB::table('mobility_trip_message_reads')->where(['trip_id' => $trip, 'user_id' => $actor])->value('through_id');
        return Message::where('conversation_id', $conversation)->where('user_id', '!=', $actor)->where('id', '>', $through)->count();
    }
    private function anyBlocked(array $ids): bool { foreach ($ids as $a) foreach ($ids as $b) if ($a < $b && PairBlockPolicy::blocked($a, $b)) return true; return false; }
    private function lockPeople(array $ids, bool $exclusive): void
    {
        sort($ids);
        try { User::whereIn('id', $ids)->orderBy('id')->lock($exclusive ? 'for update nowait' : 'for share nowait')->get(); }
        catch (QueryException $e) { if ((int) ($e->errorInfo[1] ?? 0) === 3572) throw new CommunicationException('COMMUNICATION_BUSY'); throw $e; }
    }
    private function once(int $trip, int $actor, string $kind, string $key, array $input, callable $fn): array
    {
        if (! preg_match('/^[\x21-\x7E]{1,100}$/', $key)) throw new CommunicationException('INVALID_IDEMPOTENCY_KEY', 422);
        $hash = hash('sha256', $key); $fingerprint = hash('sha256', json_encode(['trip_id' => $trip] + $input, JSON_THROW_ON_ERROR));
        $receipt = DB::table('mobility_communication_receipts')->where(['actor_id' => $actor, 'kind' => $kind, 'key_hash' => $hash])->lockForUpdate()->first();
        if ($receipt) {
            if (! hash_equals($receipt->fingerprint, $fingerprint)) throw new CommunicationException('IDEMPOTENCY_CONFLICT');
            return json_decode($receipt->response, true, 512, JSON_THROW_ON_ERROR);
        }
        $result = $fn();
        DB::table('mobility_communication_receipts')->insert(['trip_id' => $trip, 'actor_id' => $actor, 'kind' => $kind, 'key_hash' => $hash, 'fingerprint' => $fingerprint, 'response' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        return $result;
    }
}
