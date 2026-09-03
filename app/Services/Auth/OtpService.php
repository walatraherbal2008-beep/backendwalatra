<?php
namespace App\Services\Auth;

use App\Model\OtpChallenge;
use App\Suport\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;


class OtpService
{
    public function issue(
        string $phone,
        string $purpose,
        ?string $ip,
        ?string $userAgent,

    ): array{
        $phone = PhoneNumber::normalize($phone);

        OtpChallenge::query()
            ->forPhone($phone, $purpose)
            ->whereNull('verified_at')
            ->whereNull('invalidate_at')
            ->update([
                'invalidated_at'=>now()
            ]);

            $plainCode =(string) random_int(100000,999999);

            $chalenge= OtpChallenge::query()->create([
                'phone'=>$phone,
                'purpose'=>$purpose,
                'code_hash'=>Hash::make($plainCode),
                'expires_at'=>Carbon::now()->addMinutes(5),
                'attempts'=>0,
                'max_attempts'=>5,
                'request_ip'=>$ip,
                'user_agent'=>$userAgent,
            ]);
            return [
                'challenge'=>$chalenge,
                'plain_code'=>$plainCode,
            ];
    }
}

?>
