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

        // fresh(), not load(): is_active and cooldown_seconds are rarely in
        // the request body, so create() leaves them null in memory even
        // though the migration defaults them (true / 60) at the database
        // level — Eloquent's insert does not read column defaults back onto
        // the model. A full re-fetch is what update() below already does;
        // store() needs the same round trip so the response the frontend
        // renders the new rule's toggle from reflects the real stored value.
        return (new AlertRuleResource($rule->fresh('symbol')))
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
