<?php

namespace Modules\Permission\Services;

use Modules\Auth\Services\AuthService;
use Modules\Core\Abstracts\BaseService;
use Modules\Core\Helpers\MerchantContext;
use Modules\Permission\Models\Menu;

class MenuService extends BaseService
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}


    public function syncMenu() {
        $active_module = $this->authService->getAccessibleModules(MerchantContext::get())->pluck('id');

        $menus = Menu::with(['childrens', 'permission'])
            ->whereIn('module_id', $active_module)->get();

        return $menus;
    }
}
