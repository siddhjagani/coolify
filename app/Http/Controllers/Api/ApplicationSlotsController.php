<?php

namespace App\Http\Controllers\Api;

use App\Actions\Application\CreateApplicationSlot;
use App\Actions\Application\SetSlotTrafficSplit;
use App\Actions\Application\SwapApplicationSlot;
use App\Exceptions\ApplicationSlotException;
use App\Http\Controllers\Api\Concerns\RequiresDeployAbility;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Support\ValidationPatterns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use OpenApi\Attributes as OA;

class ApplicationSlotsController extends Controller
{
    use RequiresDeployAbility;

    #[OA\Post(
        summary: 'Create Deployment Slot',
        description: 'Create a deployment slot of an application: a clone of production on the same server with a generated domain, its own empty volumes, and no network aliases, scheduled tasks, or traffic. The slot is not deployed. An application can have many slots; called with a slot, it adds a slot to the same production application. Docker Compose applications, applications on more than one server, and applications with host port mappings or manually managed container labels cannot have a slot.',
        path: '/applications/{uuid}/slots',
        operationId: 'create-application-slot',
        security: [['bearerAuth' => []]],
        tags: ['Applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the production application.', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', nullable: true, description: 'Name of the slot. The default is the application name with `-staging`.'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Deployment slot created.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'uuid', type: 'string', description: 'UUID of the slot application.'),
                        new OA\Property(property: 'message', type: 'string', example: 'Deployment slot created.'),
                    ]
                )
            ),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The token or user may not create resources in the environment of the application.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function create(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $validator = customApiValidator($request->all(), [
            'name' => ValidationPatterns::nameRules(required: false),
        ], ValidationPatterns::combinedMessages());
        $extraFields = array_diff(array_keys($request->all()), ['name']);
        if ($validator->fails() || $extraFields !== []) {
            $errors = $validator->errors();
            foreach ($extraFields as $field) {
                $errors->add($field, 'This field is not allowed.');
            }

            return response()->json(['message' => 'Validation failed.', 'errors' => $errors], 422);
        }

        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->route('uuid'))->first();
        if (! $application) {
            return response()->json(['message' => 'Application not found.'], 404);
        }
        $production = $application->slotOf ?? $application;
        $this->authorize('update', $production);
        $this->authorize('create', [Application::class, $production->environment]);
        $this->authorize('placeResource', $production->destination->server);

        try {
            $slot = CreateApplicationSlot::run($application, $request->string('name')->toString() ?: null, 'api');
        } catch (ApplicationSlotException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json(['uuid' => $slot->uuid, 'message' => 'Deployment slot created.'], 201);
    }

    #[OA\Post(
        summary: 'Swap Deployment Slots',
        description: 'Make a slot production without downtime. Call it with the UUID of the slot, or with the UUID of production and `slot_uuid` (needed when production has more than one slot). The slot first takes the production domains, proxy settings, HTTP basic auth, network aliases, and automatic deployment, and restarts at the commit it runs. When it runs, the old production application gets the staging routing and restarts, and the two applications swap their names, roles, and scheduled tasks. If the staging restart fails, the staging slot gets its own routing back. Code, environment variables, volumes, Git settings, deployment history, and UUIDs stay with each application, so a deployment by UUID reaches the other role after a swap. Both applications must run on the same server.',
        path: '/applications/{uuid}/slots/swap',
        operationId: 'swap-application-slots',
        security: [['bearerAuth' => []]],
        tags: ['Applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of production or of the slot to make production.', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'slot_uuid', type: 'string', nullable: true, description: 'UUID of the slot to make production, when the path names production.'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 202,
                description: 'Swap started.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Swap started.'),
                        new OA\Property(property: 'deployment_uuid', type: 'string', description: 'UUID of the staging slot deployment that starts the swap.'),
                    ]
                )
            ),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The token needs the deploy ability, or the user may not update both applications.'),
            new OA\Response(response: 404, description: 'The application or its deployment slot was not found.'),
            new OA\Response(response: 409, description: 'A swap or a deployment of one of the applications is running.'),
            new OA\Response(response: 422, description: 'The applications cannot swap, for example because the staging slot is not running.'),
        ]
    )]
    public function swap(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->route('uuid'))->first();
        $group = $application?->slotGroup() ?? collect();
        if ($group->isEmpty()) {
            return response()->json(['message' => $application ? 'The application has no deployment slot.' : 'Application not found.'], 404);
        }
        $slot = null;
        if ($request->filled('slot_uuid')) {
            $slot = $group->skip(1)->firstWhere('uuid', (string) $request->input('slot_uuid'));
            if ($slot === null) {
                return response()->json(['message' => 'The slot is not a deployment slot of this application.'], 404);
            }
        }
        // The slot that becomes production, as SwapApplicationSlot picks it. With several slots, the action asks for one.
        $target = $slot ?? match (true) {
            $application->isNot($group->first()) => $application,
            $group->count() === 2 => $group->last(),
            default => null,
        };
        $this->authorize('update', $group->first());
        if ($target !== null) {
            $this->authorize('update', $target);
        }
        if ($response = $this->deployAbilityForbiddenResponse($request, 'A swap restarts both applications.')) {
            return $response;
        }

        try {
            $deploymentUuid = SwapApplicationSlot::run($application, 'api', $slot);
        } catch (ApplicationSlotException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json(['message' => 'Swap started.', 'deployment_uuid' => $deploymentUuid], 202);
    }

    #[OA\Get(
        summary: 'List Deployment Slots',
        description: 'List the slot group of an application: production first, then its slots, with their status and share of the production traffic. `traffic_weight` is null when the traffic split is off.',
        path: '/applications/{uuid}/slots',
        operationId: 'list-application-slots',
        security: [['bearerAuth' => []]],
        tags: ['Applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of production or of one of its slots.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The slot group.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'swapping', type: 'boolean'),
                        new OA\Property(property: 'traffic_split', type: 'boolean'),
                        new OA\Property(property: 'slots', type: 'array', items: new OA\Items(
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'uuid', type: 'string'),
                                new OA\Property(property: 'name', type: 'string'),
                                new OA\Property(property: 'role', type: 'string', enum: ['production', 'slot']),
                                new OA\Property(property: 'status', type: 'string'),
                                new OA\Property(property: 'fqdn', type: 'string', nullable: true),
                                new OA\Property(property: 'traffic_weight', type: 'integer', nullable: true),
                            ]
                        )),
                    ]
                )
            ),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->route('uuid'))->first();
        if (! $application) {
            return response()->json(['message' => 'Application not found.'], 404);
        }
        $this->authorize('view', $application);

        $group = $application->slotGroup();
        $production = $group->first();

        return response()->json([
            'swapping' => $production !== null && Cache::has(SwapApplicationSlot::lockKey($production->id)),
            'traffic_split' => $production?->settings->slot_traffic_weight !== null,
            'slots' => $group
                ->filter(fn (Application $member) => $request->user()->can('view', $member))
                ->map(fn (Application $member) => [
                    'uuid' => $member->uuid,
                    'name' => $member->name,
                    'role' => $member->is($production) ? 'production' : 'slot',
                    'status' => $member->status,
                    'fqdn' => $member->fqdn,
                    'traffic_weight' => $member->settings->slot_traffic_weight,
                ])
                ->values(),
        ]);
    }

    #[OA\Patch(
        summary: 'Set Traffic Split',
        description: 'Split the traffic of the production domains between the applications of a slot group, in percent. Give a weight to every application of the group; the weights must add up to 100. A cookie keeps each visitor on one slot. `null` turns the split off, and production gets all the traffic. Applications that get traffic must run on the production server and be deployed.',
        path: '/applications/{uuid}/slots/traffic',
        operationId: 'set-application-slot-traffic',
        security: [['bearerAuth' => []]],
        tags: ['Applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of production or of one of its slots.', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['weights'],
                properties: [
                    new OA\Property(property: 'weights', type: 'object', nullable: true, additionalProperties: new OA\AdditionalProperties(type: 'integer', minimum: 0, maximum: 100), description: 'Application UUID => percent.', example: ['production-uuid' => 70, 'slot-uuid' => 30]),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Traffic split saved.', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'Traffic split saved.')])),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The token needs the deploy ability, or the user may not update every application of the group.'),
            new OA\Response(response: 404, description: 'The application or its deployment slots were not found.'),
            new OA\Response(response: 409, description: 'A swap of the slot group is running.'),
            new OA\Response(response: 422, description: 'The weights are not valid.'),
        ]
    )]
    public function traffic(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        if (! $request->has('weights') || ($request->input('weights') !== null && ! is_array($request->input('weights')))) {
            return response()->json(['message' => 'Validation failed.', 'errors' => ['weights' => ['Give the weights as an object of application UUID and percent, or null.']]], 422);
        }

        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->route('uuid'))->first();
        $group = $application?->slotGroup() ?? collect();
        if ($group->isEmpty()) {
            return response()->json(['message' => $application ? 'The application has no deployment slot.' : 'Application not found.'], 404);
        }
        foreach ($group as $member) {
            $this->authorize('update', $member);
        }
        if ($response = $this->deployAbilityForbiddenResponse($request, 'The traffic split changes the proxy configuration of the server.')) {
            return $response;
        }

        try {
            SetSlotTrafficSplit::run($application, $request->input('weights'), 'api');
        } catch (ApplicationSlotException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json(['message' => 'Traffic split saved.']);
    }
}
