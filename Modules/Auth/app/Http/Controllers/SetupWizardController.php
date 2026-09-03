<?php

namespace Modules\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Auth\Http\Requests\SetupWizardRequest;
use Modules\Auth\Services\AuthService;
use Modules\Auth\Services\SetupWizardService;
use Modules\Operational\Transformers\BranchResource;
use Modules\Core\Abstracts\BaseController;
use Modules\Merchant\Transformers\MerchantResource;

class SetupWizardController extends BaseController
{

    public function __construct(
        private readonly SetupWizardService $setupService,
        private readonly AuthService $authService
    ) {
    }


    public function setup(SetupWizardRequest $request): JsonResponse
    {
        $this->setupService->setup(
            $request->validated()
        );

        $data = $this->authService->getAccessible($request->user('merchant')->fresh());


        return $this->created(
            $data,
            'Setup Wizard completed.'
        );
    }


}
