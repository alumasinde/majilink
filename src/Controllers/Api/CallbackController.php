<?php
declare(strict_types=1);

namespace MajiLink\Controllers\Api;

use MajiLink\Http\Request;
use MajiLink\Http\Response;
use MajiLink\Services\PaymentService;

final class CallbackController
{
    public function mpesa(Request $request): void
    {
        Response::ok((new PaymentService())->handleCallback($request->data()));
    }
}
