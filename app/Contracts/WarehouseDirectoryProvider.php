<?php

namespace App\Contracts;

interface WarehouseDirectoryProvider
{
    /**
     * @return list<array{code: string, initial: string, codename: string, realname: string, area_group: string}>
     */
    public function list(): array;

    /**
     * @return list<array{code: string, initial: string, codename: string, realname: string, area_group: string}>
     */
    public function listByGroup(string $areaGroup): array;
}
