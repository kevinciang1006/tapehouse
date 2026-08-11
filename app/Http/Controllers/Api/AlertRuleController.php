<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAlertRuleRequest;
use App\Http\Requests\UpdateAlertRuleRequest;
use App\Http\Resources\AlertRuleResource;
use App\Models\AlertRule;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class AlertRuleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return AlertRuleResource::collection($user->alertRules()->with('symbol')->get());
    }

    public function store(StoreAlertRuleRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $rule = $user->alertRules()->create($request->validated());

        return (new AlertRuleResource($rule->load('symbol')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateAlertRuleRequest $request, AlertRule $alertRule): AlertRuleResource
    {
        $this->authorize('update', $alertRule);

        $alertRule->update($request->validated());

        return new AlertRuleResource($alertRule->fresh('symbol'));
    }

    public function destroy(AlertRule $alertRule): Response
    {
        $this->authorize('delete', $alertRule);

        $alertRule->delete();

        return response()->noContent();
    }
}
