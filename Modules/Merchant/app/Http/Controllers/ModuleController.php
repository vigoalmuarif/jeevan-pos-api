<?php

namespace Modules\Merchant\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Core\Abstracts\BaseController;
use Modules\Merchant\Http\Requests\ToggleModuleRequest;
use Modules\Merchant\Transformers\ModuleResource;
use Modules\Merchant\Services\ModuleService;

class ModuleController extends BaseController
{
    public function __construct(
        private readonly ModuleService $moduleService
    ) {}

 
}