<?php

namespace Modules\Merchant\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Core\Abstracts\BaseController;
use Modules\Merchant\Models\IndustryPackage;

class IndustryPackageController extends BaseController
{
    public function index(): JsonResponse
    {
        $pkgs = IndustryPackage::where('is_active', true)
                             ->orderBy('sort_order')
                             ->get(['code', 'name', 'description', 'icon']);

        return $this->success($pkgs);
    }
}