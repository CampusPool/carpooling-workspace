<?php

namespace STS\Mobility\Identity;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use STS\Models\User;

final class CollegeEmailAuth
{
    public function domains(): array { return array_values(config('college.domains', ['stu.pes.edu'])); }

    public function normalizeEmail(mixed $email): ?string
    {
        if (! is_string($email)) return null;
        $email = strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
        $parts = explode('@', $email, 2);
        return count($parts) === 2 && in_array($parts[1], $this->domains(), true) ? $email : null;
    }

    public function deliveryAvailable(): bool
    {
        $default = (string) config('mail.default', '');
        $smtp = config('mail.mailers.smtp', []);
        return $default === 'smtp' && is_array($smtp) && trim((string) ($smtp['host'] ?? '')) !== ''
            && trim((string) ($smtp['username'] ?? '')) !== '' && trim((string) ($smtp['password'] ?? '')) !== '';
    }

    public function request(mixed $email, ?string $ip): array
    {
        $email = $this->normalizeEmail($email);
        if ($email === null) throw new CollegeEmailAuthException('COLLEGE_EMAIL_INVALID', 422);
        if (! $this->deliveryAvailable()) throw new CollegeEmailAuthException('COLLEGE_MAIL_UNAVAILABLE', 503);
        $this->consumeRate([['send-email:'.$email,3],['send-ip:'.($ip?:'unknown'),10]],60);
        $now = now();
        $code = (string) random_int(100000, 999999);
        $challenge = (string) Str::uuid();
        $ttl = (int) config('college.otp_ttl', 300);
        $resend = (int) config('college.otp_resend_after', 30);
        $hash = hash('sha256', $challenge.':'.$code.':'.(string) config('app.key'));
        DB::transaction(function()use($challenge,$email,$hash,$ip,$now,$ttl,$resend){
            // Serialise challenge allocation across PHP workers for this email.
            DB::table('mobility_college_otp_rate_windows')->where('bucket_hash',hash('sha256','send-email:'.$email))->lockForUpdate()->first();
            if(DB::table('mobility_college_email_challenges')->where('email',$email)->where('resend_after','>',$now)->exists())throw new CollegeEmailAuthException('COLLEGE_OTP_RETRY_LATER',429);
            DB::table('mobility_college_email_challenges')->insert(['challenge_id'=>$challenge,'email'=>$email,'code_hash'=>$hash,'ip_address'=>$ip,'expires_at'=>$now->copy()->addSeconds($ttl),'resend_after'=>$now->copy()->addSeconds($resend),'created_at'=>$now,'updated_at'=>$now]);
        });
        try {
            Mail::raw('Your college sign-in code is '.$code.'. It expires in five minutes.', function ($message) use ($email): void {
                $message->to($email)->subject('Your college sign-in code');
            });
        } catch (\Throwable) {
            DB::table('mobility_college_email_challenges')->where('challenge_id',$challenge)->update(['consumed_at'=>now(),'updated_at'=>now()]);
            throw new CollegeEmailAuthException('COLLEGE_MAIL_UNAVAILABLE',503);
        }
        return ['challenge_id' => $challenge, 'expires_in' => $ttl, 'resend_after' => $resend];
    }

    /** Database rate windows survive per-request array caches and multiple PHP workers. */
    public function consumeRate(array $buckets,int $seconds):void
    {
        $denied=DB::transaction(function()use($buckets,$seconds){
            usort($buckets,fn($a,$b)=>strcmp($a[0],$b[0]));
            foreach($buckets as [$key,$limit]) {
                $hash=hash('sha256',$key);DB::table('mobility_college_otp_rate_windows')->insertOrIgnore(['bucket_hash'=>$hash,'hits'=>0,'window_until'=>now()->addSeconds($seconds),'created_at'=>now(),'updated_at'=>now()]);
                $row=DB::table('mobility_college_otp_rate_windows')->where('bucket_hash',$hash)->lockForUpdate()->first();
                $hits=now()->gte($row->window_until)?0:(int)$row->hits;
                if($hits>=$limit)return true;
                DB::table('mobility_college_otp_rate_windows')->where('bucket_hash',$hash)->update(['hits'=>$hits+1,'window_until'=>$hits===0?now()->addSeconds($seconds):$row->window_until,'updated_at'=>now()]);
            }return false;
        });
        if($denied)throw new CollegeEmailAuthException('COLLEGE_OTP_THROTTLED',429);
    }

    public function verify(string $challengeId, string $code): array
    {
        $result = DB::transaction(function () use ($challengeId, $code): array {
            $challenge = DB::table('mobility_college_email_challenges')->where('challenge_id', $challengeId)->lockForUpdate()->first();
            if (! $challenge || $challenge->consumed_at || now()->greaterThanOrEqualTo($challenge->expires_at)) throw new CollegeEmailAuthException('COLLEGE_OTP_EXPIRED', 422);
            if ((int) $challenge->attempts >= (int) config('college.otp_max_attempts', 5)) throw new CollegeEmailAuthException('COLLEGE_OTP_ATTEMPTS_EXCEEDED', 422);
            $expected = hash('sha256', $challengeId.':'.$code.':'.(string) config('app.key'));
            if (! hash_equals((string) $challenge->code_hash, $expected)) {
                DB::table('mobility_college_email_challenges')->where('id', $challenge->id)->increment('attempts');
                return ['error' => 'COLLEGE_OTP_INVALID'];
            }
            $user = User::query()->where('email', $challenge->email)->lockForUpdate()->first();
            if (! $user) {
                try {
                    $user = User::query()->create(['name' => Str::before($challenge->email, '@'), 'email' => $challenge->email, 'password' => Hash::make(Str::random(64)), 'active' => true, 'banned' => false]);
                } catch (QueryException $e) {
                    // A concurrent verifier may have won the unique email race.
                    $user = User::query()->where('email', $challenge->email)->lockForUpdate()->first();
                    if (! $user) throw $e;
                }
            }
            if (! (bool) $user->active || (bool) $user->banned) throw new CollegeEmailAuthException('ACCOUNT_RESTRICTED', 403);
            $membership = DB::table('mobility_memberships')->where('user_id', $user->id)->lockForUpdate()->first();
            if ($membership && ($membership->college_code !== config('college.code') || ! in_array($membership->status, ['pending', 'approved'], true))) {
                throw new CollegeEmailAuthException('COLLEGE_MEMBERSHIP_INELIGIBLE', 403);
            }
            DB::table('mobility_verified_identities')->insertOrIgnore(['user_id' => $user->id, 'provider' => 'college_email_otp', 'email' => $challenge->email, 'domain' => Str::after($challenge->email, '@'), 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            if (! $membership) {
                $id = DB::table('mobility_memberships')->insertGetId(['user_id' => $user->id, 'college_code' => config('college.code'), 'affiliation' => 'student', 'status' => 'approved', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('mobility_membership_audits')->insert(['membership_id' => $id, 'user_id' => $user->id, 'actor_id' => $user->id, 'new_status' => 'approved', 'version' => 1, 'reason' => 'college_email_otp_verification', 'created_at' => now(), 'updated_at' => now()]);
            } elseif ($membership->status === 'pending') {
                DB::table('mobility_memberships')->where('id', $membership->id)->update(['status' => 'approved', 'version' => (int) $membership->version + 1, 'updated_at' => now()]);
                DB::table('mobility_membership_audits')->insert(['membership_id' => $membership->id, 'user_id' => $user->id, 'actor_id' => $user->id, 'previous_status' => 'pending', 'new_status' => 'approved', 'version' => (int) $membership->version + 1, 'reason' => 'college_email_otp_verification', 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('mobility_college_email_challenges')->where('id', $challenge->id)->update(['consumed_at' => now(), 'updated_at' => now()]);
            return ['token' => auth('api')->fromUser($user), 'user' => ['id' => (int) $user->id, 'name' => (string) $user->name, 'email' => (string) $user->email], 'verified_college_email' => true];
        });
        if (($result['error'] ?? null) === 'COLLEGE_OTP_INVALID') throw new CollegeEmailAuthException('COLLEGE_OTP_INVALID', 422);
        return $result;
    }
}

final class CollegeEmailAuthException extends \RuntimeException
{
    public function __construct(public string $machineCode, public int $httpStatus, public array $meta = []) { parent::__construct($machineCode); }
}
