<?php

namespace App\Http\Controllers\Api\Account\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\Profile\UpdateUserProfileRequest;
use App\Models\Account\User\Profile;
use Carbon\Carbon;
use Illuminate\Http\Request;

class UserProfileController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Show the current user's profile.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function me(Request $request)
    {
        return response()->json([
            'data' => [
                ...$request->user()->toArray(),
                'profile' => auth()->user()->profile,
            ],
        ]);
    }

    /**
     * Update the user's profile.
     *
     * @param \App\Http\Requests\Account\Profile\UpdateUserProfileRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateUserProfileRequest $request)
    {
        $data = $request->validated();
        if (isset($data['date_of_birth'])) {
            $data['date_of_birth'] = Carbon::createFromFormat('m/d/Y', $data['date_of_birth'])->format('Y-m-d');
        }
        Profile::updateOrCreate(['user_id' => $request->user()->id], $data);

        return response()->json([
            'status' => true,
            'message' => 'Profile has been updated!',
        ]);
    }
}
