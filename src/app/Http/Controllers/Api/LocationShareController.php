<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLocationShareRequest;
use App\Models\User;
use App\Models\UserLocationShare;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LocationShareController extends Controller
{
    private const SHARE_TTL_MINUTES = 30;
}
