<?php

namespace App\Filament\Resources\ApiRequestAuditLogs\Pages;

use App\Filament\Resources\ApiRequestAuditLogs\ApiRequestAuditLogResource;
use Filament\Resources\Pages\ListRecords;

class ListApiRequestAuditLogs extends ListRecords
{
    protected static string $resource = ApiRequestAuditLogResource::class;

    protected function authorizeAccess(): void
    {
        abort_unless(ApiRequestAuditLogResource::canAccess(), 403);
    }
}
