<?php

namespace App\Http\Controllers\Superadmin;

use App\Filament\Resources\Orders\OrderResource;
use App\Http\Controllers\Controller;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class RedirectStoreTransactionsController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        if ($request->user() === null) {
            return redirect()->guest(Filament::getPanel('superadmin')->getLoginUrl());
        }
        abort_unless(OrderResource::canAccess(), 403);

        return redirect()->to(OrderResource::getUrl('index', Arr::only($request->query(), [
            'search', 'filters', 'sort', 'recordsPerPage', 'page', 'action', 'actionArguments', 'actionContext',
        ]), panel: 'superadmin'));
    }
}
