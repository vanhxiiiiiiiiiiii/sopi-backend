<?php

namespace Tests\Feature;

use App\Http\Requests\Product\Review\GetReviewRequest;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ProductionExceptionResponseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
    }

    public function test_form_request_validation_keeps_its_response_in_production(): void
    {
        Route::get('/_test/review-validation', function (GetReviewRequest $request) {
            return response()->json($request->validated());
        });

        $this->getJson('/_test/review-validation?limit=0')
            ->assertStatus(400)
            ->assertJsonStructure(['error' => ['limit']]);
    }

    public function test_standard_validation_keeps_field_errors_in_production(): void
    {
        Route::post('/_test/validation', function (Request $request) {
            return $request->validate(['quantity' => 'required|integer|min:1']);
        });

        $this->postJson('/_test/validation', ['quantity' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');
    }

    public function test_authentication_exception_is_unauthorized_in_production(): void
    {
        Route::get('/_test/authentication', function () {
            throw new AuthenticationException();
        });

        $this->getJson('/_test/authentication')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_unexpected_errors_remain_private_in_production(): void
    {
        Route::get('/_test/unexpected-error', function () {
            throw new RuntimeException('Private database connection details');
        });

        $this->getJson('/_test/unexpected-error')
            ->assertStatus(500)
            ->assertExactJson(['status' => false, 'message' => 'An unknown error occurred!']);
    }
}
