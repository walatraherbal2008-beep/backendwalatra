<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RequestCustomerOtpRequest;
use App\Services\Auth\OtpService;
use Illuminate\Http\JsonResponse;

class CustomerAuthController extends Controller
{
    public function requestOtp(
        RequestCustomerOtpRequest $request,
        OtpService $otpService,
    ): JsonResponse{
        $otpService->issue(
            phone: $request->string('phone')->toString(),
            purpose: 'customer_login',
            ip: $request->ip(),
            userAgents: $request->userAgent(),
        );

        return response()->json([
            'success'=>true,
            'message'=>'Jika nomor dapat menerima Whatsapp, Otp akan di kirim.',
        ]);
    }
}

?>
