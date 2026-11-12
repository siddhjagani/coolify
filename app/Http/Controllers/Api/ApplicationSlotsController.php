<?php

namespace App\Http\Controllers\Api;

use App\Actions\Application\CreateApplicationSlot;
use App\Actions\Application\SwapApplicationSlot;
use App\Exceptions\ApplicationSlotException;
use App\Http\Controllers\Api\Concerns\RequiresDeployAbility;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Support\ValidationPatterns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ApplicationSlotsController extends Controller
{
    use RequiresDeployAbility;

    #[OA\Post(
        summary: 'Create Deployment Slot',
        description: 'Create the staging slot of an application: a clone on the same server with a generated domain, its own empty volumes, and no network aliases or scheduled tasks. The slot is not deployed. An application has at most one slot. Docker Compose applications, applications on more than one server, and applications with host port mappings or manually managed container labels cannot have a slot.',
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
            new OA\Response(response: 409, description: 'The application already has a deployment slot.'),
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
        $this->authorize('update', $application);
        $this->authorize('create', [Application::class, $application->environment]);
        $this->authorize('placeResource', $application->destination->server);

        try {
            $slot = CreateApplicationSlot::run($application, $request->string('name')->toString() ?: null, 'api');
        } catch (ApplicationSlotException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json(['uuid' => $slot->uuid, 'message' => 'Deployment slot created.'], 201);
    }

    #[OA\Post(
        summary: 'Swap Deployment Slots',
        description: 'Move the production traffic of an application to its staging slot without downtime. Call it with the UUID of either application of the pair. The staging slot first takes the production domains, proxy settings, HTTP basic auth, network aliases, and automatic deployment, and restarts at the commit it runs. When it runs, the old production application gets the staging routing and restarts, and the two applications swap their names, roles, and scheduled tasks. If the staging restart fails, the staging slot gets its own routing back. Code, environment variables, volumes, Git settings, deployment history, and UUIDs stay with each application, so a deployment by UUID reaches the other role after a swap. Both applications must run on the same server.',
        path: '/applications/{uuid}/slots/swap',
        operationId: 'swap-application-slots',
        security: [['bearerAuth' => []]],
        tags: ['Applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the application or of its staging slot.', schema: new OA\Schema(type: 'string')),
        ],
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
        $partner = $application?->slotPartner();
        if (! $application || ! $partner) {
            return response()->json(['message' => $application ? 'The application has no deployment slot.' : 'Application not found.'], 404);
        }
        $this->authorize('update', $application);
        $this->authorize('update', $partner);
        if ($response = $this->deployAbilityForbiddenResponse($request, 'A swap restarts both applications.')) {
            return $response;
        }

        try {
            $deploymentUuid = SwapApplicationSlot::run($application, 'api');
        } catch (ApplicationSlotException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json(['message' => 'Swap started.', 'deployment_uuid' => $deploymentUuid], 202);
    }
}
