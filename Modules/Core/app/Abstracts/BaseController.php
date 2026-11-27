<?php

namespace Modules\Core\Abstracts;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller;
use Modules\Core\Traits\ApiResponse;

abstract class BaseController extends Controller
{
    use AuthorizesRequests, ValidatesRequests, ApiResponse;
}