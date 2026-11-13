<?php

namespace App\Filament\Resources\PaymentCommunicationLogs\Schemas;

use App\Support\PaymentPayloadSanitizer;
use Filament\Infolists\Components\CodeEntry;

class PaymentAuditEntries
{
    public static function payloads(): array
    {
        return [
            CodeEntry::make('request_payload')->label('Request ke Midtrans')
                ->state(fn ($record) => PaymentPayloadSanitizer::sanitize($record->request_payload))
                ->jsonFlags(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                ->placeholder('Tidak ada body JSON.')->columnSpanFull(),
            CodeEntry::make('response_payload')->label('Respons Midtrans')
                ->state(fn ($record) => PaymentPayloadSanitizer::sanitize($record->response_payload))
                ->jsonFlags(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                ->placeholder('Belum ada respons JSON.')->columnSpanFull(),
        ];
    }
}
