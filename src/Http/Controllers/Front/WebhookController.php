<?php

namespace ME\Abs\Http\Controllers\Front;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use ME\Abs\Exceptions\InvalidWebhookSignature;
use ME\Abs\Models\Business;
use ME\Abs\Payments\GatewayManager;
use ME\Abs\Services\Payments\WebhookProcessor;

class WebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, GatewayManager $gateways, WebhookProcessor $processor, ?int $business = null): JsonResponse
    {
        $owner = $business ? Business::query()->find($business) : null;
        abort_if($business && ! $owner, 404);
        $gateway = $gateways->driver($provider, $owner);
        abort_unless($gateway, 404);

        try {
            $event = $gateway->parseWebhook($request);
        } catch (InvalidWebhookSignature $e) {
            Log::warning('abs: rejected webhook', ['provider' => $provider, 'reason' => $e->getMessage(), 'ip' => $request->ip()]);

            return response()->json(['error' => 'invalid signature'], 400);
        }

        $result = $processor->handle($gateway, $event);

        // 500 makes the provider retry later; every other outcome is final for this event.
        return response()->json(['result' => $result], $result === 'failed' ? 500 : 200);
    }
}
